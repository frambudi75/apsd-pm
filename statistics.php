<?php
/**
 * Statistik & Analitik Laporan Terpadu (PM & Daily Activity NOC)
 * IASS SBU ICT KNO
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/checklist_data.php';
require_login();

$page_title = 'Statistik & Analitik Laporan';
$currentUser = current_user();
$pdo = get_db_connection();

// Filter parameters
$currentMonth = date('Y-m');
$filterMonth = trim($_GET['month'] ?? $currentMonth);
$isAllTime = ($filterMonth === 'all');
if (!$isAllTime && !preg_match('/^\d{4}-\d{2}$/', $filterMonth)) {
    $filterMonth = $currentMonth;
}

$filterUserId = (int)($_GET['user_id'] ?? 0);

// Indonesian month names
$indoMonths = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

if (!$isAllTime) {
    $monthTime = strtotime($filterMonth . '-01');
    $mNum = (int)date('n', $monthTime);
    $mYear = date('Y', $monthTime);
    $periodLabel = ($indoMonths[$mNum] ?? date('F', $monthTime)) . ' ' . $mYear;
    $daysInMonth = (int)date('t', $monthTime);
} else {
    $periodLabel = 'Seluruh Periode Operasional';
    $daysInMonth = 31;
}

// Load list of technicians for filter dropdown
$technicians = [];
try {
    $stmtTech = $pdo->query("SELECT id, username, full_name, role FROM users ORDER BY full_name ASC");
    $technicians = $stmtTech->fetchAll();
} catch (Exception $e) {}

// ==========================================
// 1. QUERY PM REPORTS STATISTICS
// ==========================================
$pmWhere = [];
$pmParams = [];

if (!$isAllTime) {
    $pmWhere[] = "r.report_date LIKE ?";
    $pmParams[] = $filterMonth . '%';
}
if ($filterUserId > 0) {
    // Find user name
    $userNameFilter = '';
    foreach ($technicians as $t) {
        if ((int)$t['id'] === $filterUserId) {
            $userNameFilter = $t['full_name'] ?: $t['username'];
            break;
        }
    }
    if (!empty($userNameFilter)) {
        $pmWhere[] = "(r.inspector_name LIKE ? OR r.team_leader LIKE ?)";
        $pmParams[] = '%' . $userNameFilter . '%';
        $pmParams[] = '%' . $userNameFilter . '%';
    }
}

$pmWhereSql = !empty($pmWhere) ? "WHERE " . implode(" AND ", $pmWhere) : "";

$pmReportsQuery = "
    SELECT r.*, s.site_name, s.location_detail,
           ind.checklist_json as indoor_checklist,
           outd.grounding_json, outd.tower_json, outd.lighting_fence_other_json, outd.structural_json,
           outd.tenant_ac_json, outd.tenant_electrical_json, outd.pln_function_json
    FROM pm_reports r
    LEFT JOIN sites s ON r.site_id = s.id
    LEFT JOIN pm_indoor_details ind ON ind.report_id = r.id
    LEFT JOIN pm_outdoor_details outd ON outd.report_id = r.id
    {$pmWhereSql}
    ORDER BY r.report_date DESC, r.id DESC
";
$stmtPm = $pdo->prepare($pmReportsQuery);
$stmtPm->execute($pmParams);
$pmReports = $stmtPm->fetchAll();

$totalPm = count($pmReports);
$pmIndoorCount = 0;
$pmOutdoorCount = 0;
$pmNokCount = 0;
$pmOkCount = 0;
$pmOperatorStats = [];

foreach ($pmReports as &$pmItem) {
    if ($pmItem['report_type'] === 'indoor') {
        $pmIndoorCount++;
    } else {
        $pmOutdoorCount++;
    }

    $findings = get_report_findings_summary($pmItem);
    if (!empty($findings['has_nok'])) {
        $pmNokCount++;
    } else {
        $pmOkCount++;
    }

    $opName = trim($pmItem['operator'] ?? '');
    if (empty($opName)) $opName = 'Umum / Bandara';
    $opKey = strtoupper($opName);
    if (!isset($pmOperatorStats[$opKey])) {
        $pmOperatorStats[$opKey] = ['name' => $opName, 'count' => 0];
    }
    $pmOperatorStats[$opKey]['count']++;
}
unset($pmItem);
uasort($pmOperatorStats, function($a, $b) { return $b['count'] <=> $a['count']; });

// ==========================================
// 2. QUERY DAILY SHIFTS & ACTIVITIES STATS
// ==========================================
$shiftWhere = [];
$shiftParams = [];

if (!$isAllTime) {
    $shiftWhere[] = "s.shift_date LIKE ?";
    $shiftParams[] = $filterMonth . '%';
}
if ($filterUserId > 0) {
    $shiftWhere[] = "s.user_id = ?";
    $shiftParams[] = $filterUserId;
}

$shiftWhereSql = !empty($shiftWhere) ? "WHERE " . implode(" AND ", $shiftWhere) : "";

$shiftStmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT s.id) AS total_shifts,
        SUM(CASE WHEN s.status = 'completed' THEN 1 ELSE 0 END) AS completed_shifts,
        SUM(CASE WHEN s.status = 'active' THEN 1 ELSE 0 END) AS active_shifts,
        SUM(CASE WHEN s.shift_type = 'pagi' THEN 1 ELSE 0 END) AS shifts_pagi,
        SUM(CASE WHEN s.shift_type = 'malam' THEN 1 ELSE 0 END) AS shifts_malam,
        SUM(CASE WHEN s.shift_type IN ('oh', 'office_hour') THEN 1 ELSE 0 END) AS shifts_oh,
        COUNT(DISTINCT s.user_id) AS total_active_techs
    FROM daily_shifts s
    {$shiftWhereSql}
");
$shiftStmt->execute($shiftParams);
$shiftSummary = $shiftStmt->fetch() ?: [
    'total_shifts' => 0,
    'completed_shifts' => 0,
    'active_shifts' => 0,
    'shifts_pagi' => 0,
    'shifts_malam' => 0,
    'shifts_oh' => 0,
    'total_active_techs' => 0
];

// Query Activities Breakdown by Category
$actWhere = [];
$actParams = [];
if (!$isAllTime) {
    $actWhere[] = "s.shift_date LIKE ?";
    $actParams[] = $filterMonth . '%';
}
if ($filterUserId > 0) {
    $actWhere[] = "s.user_id = ?";
    $actParams[] = $filterUserId;
}
$actWhereSql = !empty($actWhere) ? "WHERE " . implode(" AND ", $actWhere) : "";

$catQuery = "
    SELECT a.category, COUNT(a.id) AS total_count,
           COUNT(DISTINCT a.shift_id) AS shifts_affected
    FROM daily_activities a
    INNER JOIN daily_shifts s ON a.shift_id = s.id
    {$actWhereSql}
    GROUP BY a.category
    ORDER BY total_count DESC
";
$stmtCat = $pdo->prepare($catQuery);
$stmtCat->execute($actParams);
$categoryRows = $stmtCat->fetchAll();

// Total activities and photos
$totalActivities = 0;
foreach ($categoryRows as $cr) {
    $totalActivities += (int)$cr['total_count'];
}

$stmtPhotos = $pdo->prepare("
    SELECT COUNT(p.id) AS total_photos
    FROM daily_activity_photos p
    INNER JOIN daily_activities a ON p.activity_id = a.id
    INNER JOIN daily_shifts s ON a.shift_id = s.id
    {$actWhereSql}
");
$stmtPhotos->execute($actParams);
$totalPhotos = (int)($stmtPhotos->fetchColumn() ?: 0);

// Pre-define standard categories colors & icons for visual aesthetic
$categoryMeta = [
    'maintenance' => ['label' => 'Maintenance', 'color' => '#0284c7', 'bg' => '#e0f2fe', 'icon' => 'settings'],
    'kunjungan' => ['label' => 'Kunjungan / Vendor', 'color' => '#8b5cf6', 'bg' => '#f3e8ff', 'icon' => 'user'],
    'troubleshooting' => ['label' => 'Troubleshooting', 'color' => '#ef4444', 'bg' => '#fee2e2', 'icon' => 'alert-triangle'],
    'monitoring' => ['label' => 'Monitoring', 'color' => '#10b981', 'bg' => '#d1fae5', 'icon' => 'activity'],
    'patroli' => ['label' => 'Patroli Lapangan', 'color' => '#f59e0b', 'bg' => '#fef3c7', 'icon' => 'shield'],
    'administrasi' => ['label' => 'Administrasi', 'color' => '#64748b', 'bg' => '#f1f5f9', 'icon' => 'file-text'],
    'instalasi' => ['label' => 'Instalasi', 'color' => '#06b6d4', 'bg' => '#cffafe', 'icon' => 'plus'],
    'lainnya' => ['label' => 'Lainnya', 'color' => '#6b7280', 'bg' => '#f3f4f6', 'icon' => 'grid']
];

$processedCategories = [];
foreach ($categoryRows as $cr) {
    $rawCat = trim($cr['category'] ?: 'Lainnya');
    $catKey = strtolower($rawCat);
    $meta = $categoryMeta[$catKey] ?? [
        'label' => htmlspecialchars($rawCat),
        'color' => '#059669',
        'bg' => 'rgba(16, 185, 129, 0.1)',
        'icon' => 'tag'
    ];
    $count = (int)$cr['total_count'];
    $pct = $totalActivities > 0 ? round(($count / $totalActivities) * 100, 1) : 0;

    $processedCategories[] = [
        'name' => $rawCat,
        'label' => $meta['label'],
        'color' => $meta['color'],
        'bg' => $meta['bg'],
        'icon' => $meta['icon'],
        'count' => $count,
        'percentage' => $pct
    ];
}

// ==========================================
// 3. DAILY ACTIVITY VOLUME BY DAY OF MONTH
// ==========================================
$dailyVolume = [];
if (!$isAllTime) {
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dailyVolume[$d] = 0;
    }

    $stmtDayVol = $pdo->prepare("
        SELECT SUBSTRING(s.shift_date, 9, 2) AS day_num, COUNT(a.id) AS act_count
        FROM daily_activities a
        INNER JOIN daily_shifts s ON a.shift_id = s.id
        {$actWhereSql}
        GROUP BY day_num
    ");
    $stmtDayVol->execute($actParams);
    while ($row = $stmtDayVol->fetch()) {
        $dNum = (int)$row['day_num'];
        if (isset($dailyVolume[$dNum])) {
            $dailyVolume[$dNum] = (int)$row['act_count'];
        }
    }
}
$maxDailyVolume = !empty($dailyVolume) ? max(max($dailyVolume), 1) : 1;

// ==========================================
// 4. TECHNICIAN LEADERBOARD & PRODUCTIVITY
// ==========================================
$techLeaderboard = [];
try {
    $tlWhere = [];
    $tlParams = [];
    if (!$isAllTime) {
        $tlWhere[] = "s.shift_date LIKE ?";
        $tlParams[] = $filterMonth . '%';
    }
    $tlWhereSql = !empty($tlWhere) ? "AND " . implode(" AND ", $tlWhere) : "";

    $stmtTL = $pdo->prepare("
        SELECT 
            u.id AS user_id,
            u.full_name,
            u.username,
            u.role,
            COUNT(DISTINCT s.id) AS shifts_count,
            (SELECT COUNT(*) FROM daily_activities a 
             INNER JOIN daily_shifts ds ON a.shift_id = ds.id 
             WHERE ds.user_id = u.id " . (!$isAllTime ? "AND ds.shift_date LIKE ?" : "") . ") AS activities_count,
            (SELECT COUNT(*) FROM daily_activity_photos p 
             INNER JOIN daily_activities a ON p.activity_id = a.id
             INNER JOIN daily_shifts ds ON a.shift_id = ds.id 
             WHERE ds.user_id = u.id " . (!$isAllTime ? "AND ds.shift_date LIKE ?" : "") . ") AS photos_count
        FROM users u
        LEFT JOIN daily_shifts s ON s.user_id = u.id {$tlWhereSql}
        GROUP BY u.id, u.full_name, u.username, u.role
        ORDER BY activities_count DESC, shifts_count DESC
    ");

    $execParams = $tlParams;
    if (!$isAllTime) {
        $execParams[] = $filterMonth . '%';
        $execParams[] = $filterMonth . '%';
    }
    $stmtTL->execute($execParams);
    $techLeaderboard = $stmtTL->fetchAll();
} catch (Exception $e) {}

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid" style="padding: 1.5rem 1rem; max-width: 1400px; margin: 0 auto;">

  <!-- Page Header & Action Controls -->
  <div class="card mb-4" style="background: linear-gradient(135deg, rgba(20, 184, 166, 0.08) 0%, rgba(14, 165, 233, 0.08) 100%), var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);">
    <div class="card-body" style="padding: 1.5rem 1.75rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
      <div>
        <div style="font-size: 0.78rem; font-weight: 800; color: var(--primary); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
          <?= app_icon('trending-up', 14) ?>
          <span>Pusat Analitik &amp; Laporan Eksekutif</span>
        </div>
        <h2 class="page-header-title" style="font-weight: 800; color: var(--text-main); margin: 0; font-size: 1.5rem;">
          Statistik &amp; Rekapitulasi Laporan Terpadu
        </h2>
        <p style="color: var(--text-subtle); font-size: 0.88rem; margin: 4px 0 0 0;">
          Ringkasan komprehensif performa Preventive Maintenance (PM) dan Logbook Harian Shift NOC &bull; Periode: <strong style="color: var(--text-main);"><?= htmlspecialchars($periodLabel) ?></strong>
        </p>
      </div>

      <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;" class="no-print">
        <a href="print_statistics?month=<?= urlencode($filterMonth) ?><?= $filterUserId > 0 ? '&user_id=' . $filterUserId : '' ?>" target="_blank" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px; min-height: 42px; font-weight: 700; padding: 0.5rem 1rem;" title="Buka lembar cetak resmi dokumen A4">
          <?= app_icon('document', 15) ?>
          <span>Format Cetak Resmi</span>
        </a>
        <a href="daily_reports" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px; min-height: 42px; font-weight: 700; padding: 0.5rem 1.25rem;">
          <?= app_icon('clipboard-list', 15) ?>
          <span>Buka Rekap NOC</span>
        </a>
      </div>
    </div>
  </div>

  <!-- Filter Bar (Month Selector & Technician) -->
  <div class="card mb-4 no-print" style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); padding: 1.15rem 1.35rem;">
    <form method="GET" action="statistics" style="display: flex; align-items: flex-end; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
      <div style="display: flex; align-items: flex-end; gap: 1rem; flex-wrap: wrap; flex: 1;">
        
        <!-- Month Picker -->
        <div style="min-width: 200px; flex: 1;">
          <label style="font-size: 0.76rem; font-weight: 700; color: var(--text-subtle); margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
            <?= app_icon('calendar', 12) ?>
            <span>Pilih Bulan Periode</span>
          </label>
          <input type="month" name="month" class="form-control" value="<?= htmlspecialchars(!$isAllTime ? $filterMonth : date('Y-m')) ?>" style="min-height: 38px;">
        </div>

        <!-- Technician Filter -->
        <div style="min-width: 220px; flex: 1;">
          <label style="font-size: 0.76rem; font-weight: 700; color: var(--text-subtle); margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
            <?= app_icon('user', 12) ?>
            <span>Personel / Anggota Tim</span>
          </label>
          <select name="user_id" class="form-control" style="min-height: 38px;">
            <option value="0">-- Semua Anggota Tim --</option>
            <?php foreach ($technicians as $t): ?>
              <option value="<?= (int)$t['id'] ?>" <?= ($filterUserId === (int)$t['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($t['full_name'] ?: $t['username']) ?> (<?= htmlspecialchars($t['role']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Filter Submit Button -->
        <div>
          <button type="submit" class="btn btn-primary" style="padding: 0.5rem 1.25rem; min-height: 38px; display: inline-flex; align-items: center; gap: 6px; font-weight: 700;">
            <?= app_icon('filter', 13) ?>
            <span>Terapkan Filter</span>
          </button>
        </div>

      </div>

      <!-- Quick Preset Buttons -->
      <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
        <a href="statistics?month=<?= date('Y-m') ?>" class="btn btn-sm <?= ($filterMonth === date('Y-m') && !$isAllTime) ? 'btn-primary' : 'btn-secondary' ?>" style="font-size: 0.75rem; padding: 0.4rem 0.8rem; font-weight: 700;">
          Bulan Ini
        </a>
        <a href="statistics?month=<?= date('Y-m', strtotime('-1 month')) ?>" class="btn btn-sm <?= ($filterMonth === date('Y-m', strtotime('-1 month'))) ? 'btn-primary' : 'btn-secondary' ?>" style="font-size: 0.75rem; padding: 0.4rem 0.8rem; font-weight: 700;">
          Bulan Lalu
        </a>
        <a href="statistics?month=all" class="btn btn-sm <?= ($isAllTime) ? 'btn-primary' : 'btn-secondary' ?>" style="font-size: 0.75rem; padding: 0.4rem 0.8rem; font-weight: 700;">
          Semua Data (All)
        </a>
      </div>
    </form>
  </div>

  <!-- Section 1: Executive KPI Summary Cards -->
  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    
    <!-- KPI 1: Total Sesi Shift -->
    <div class="card" style="padding: 1.25rem; border-radius: var(--radius-md); border: 1px solid var(--border); box-shadow: var(--shadow-sm); background: var(--bg-card);">
      <div style="display: flex; justify-content: space-between; align-items: flex-start;">
        <div>
          <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase;">Total Sesi Shift</div>
          <div style="font-size: 1.85rem; font-weight: 800; color: var(--text-main); line-height: 1.2; margin-top: 4px;">
            <?= (int)$shiftSummary['total_shifts'] ?>
          </div>
          <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 4px;">
            <span style="color: var(--status-ok); font-weight: 700;"><?= (int)$shiftSummary['completed_shifts'] ?> Selesai</span> &bull; 
            <span style="color: #f59e0b; font-weight: 700;"><?= (int)$shiftSummary['active_shifts'] ?> Aktif</span>
          </div>
        </div>
        <div style="width: 40px; height: 40px; border-radius: var(--radius-sm); background: var(--primary-subtle); color: var(--primary); display: flex; align-items: center; justify-content: center;">
          <?= app_icon('clock', 20) ?>
        </div>
      </div>
    </div>

    <!-- KPI 2: Total Kegiatan Logbook -->
    <div class="card" style="padding: 1.25rem; border-radius: var(--radius-md); border: 1px solid var(--border); box-shadow: var(--shadow-sm); background: var(--bg-card);">
      <div style="display: flex; justify-content: space-between; align-items: flex-start;">
        <div>
          <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase;">Kegiatan Logbook</div>
          <div style="font-size: 1.85rem; font-weight: 800; color: var(--primary); line-height: 1.2; margin-top: 4px;">
            <?= $totalActivities ?>
          </div>
          <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 4px;">
            Tercatat di <?= count($processedCategories) ?> kategori aktivitas
          </div>
        </div>
        <div style="width: 40px; height: 40px; border-radius: var(--radius-sm); background: rgba(20, 184, 166, 0.12); color: var(--primary); display: flex; align-items: center; justify-content: center;">
          <?= app_icon('activity', 20) ?>
        </div>
      </div>
    </div>

    <!-- KPI 3: Total Laporan PM -->
    <div class="card" style="padding: 1.25rem; border-radius: var(--radius-md); border: 1px solid var(--border); box-shadow: var(--shadow-sm); background: var(--bg-card);">
      <div style="display: flex; justify-content: space-between; align-items: flex-start;">
        <div>
          <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase;">Dokumen PM Bulanan</div>
          <div style="font-size: 1.85rem; font-weight: 800; color: #0284c7; line-height: 1.2; margin-top: 4px;">
            <?= $totalPm ?>
          </div>
          <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 4px;">
            <span style="color: var(--primary); font-weight: 700;"><?= $pmIndoorCount ?> Indoor</span> &bull; 
            <span style="color: #0284c7; font-weight: 700;"><?= $pmOutdoorCount ?> Outdoor</span>
          </div>
        </div>
        <div style="width: 40px; height: 40px; border-radius: var(--radius-sm); background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center;">
          <?= app_icon('clipboard-list', 20) ?>
        </div>
      </div>
    </div>

    <!-- KPI 4: Total Foto Dokumentasi -->
    <div class="card" style="padding: 1.25rem; border-radius: var(--radius-md); border: 1px solid var(--border); box-shadow: var(--shadow-sm); background: var(--bg-card);">
      <div style="display: flex; justify-content: space-between; align-items: flex-start;">
        <div>
          <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase;">Foto Dokumentasi</div>
          <div style="font-size: 1.85rem; font-weight: 800; color: #8b5cf6; line-height: 1.2; margin-top: 4px;">
            <?= $totalPhotos ?>
          </div>
          <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 4px;">
            Bukti verifikasi fisik lapangan terunggah
          </div>
        </div>
        <div style="width: 40px; height: 40px; border-radius: var(--radius-sm); background: #f3e8ff; color: #8b5cf6; display: flex; align-items: center; justify-content: center;">
          <?= app_icon('camera', 20) ?>
        </div>
      </div>
    </div>

    <!-- KPI 5: Kesehatan & Temuan Site -->
    <div class="card" style="padding: 1.25rem; border-radius: var(--radius-md); border: 1px solid var(--border); box-shadow: var(--shadow-sm); background: var(--bg-card);">
      <div style="display: flex; justify-content: space-between; align-items: flex-start;">
        <div>
          <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase;">Kondisi Site PM</div>
          <div style="font-size: 1.85rem; font-weight: 800; color: <?= ($pmNokCount > 0) ? '#f59e0b' : 'var(--status-ok)' ?>; line-height: 1.2; margin-top: 4px;">
            <?= ($pmNokCount > 0) ? $pmNokCount . ' Temuan' : '100% OK' ?>
          </div>
          <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 4px;">
            <?= $pmOkCount ?> Site Normal tanpa anomali
          </div>
        </div>
        <div style="width: 40px; height: 40px; border-radius: var(--radius-sm); background: <?= ($pmNokCount > 0) ? '#fef3c7' : 'var(--status-ok-bg)' ?>; color: <?= ($pmNokCount > 0) ? '#b45309' : 'var(--status-ok)' ?>; display: flex; align-items: center; justify-content: center;">
          <?= app_icon(($pmNokCount > 0) ? 'alert-triangle' : 'check-circle', 20) ?>
        </div>
      </div>
    </div>

  </div>

<style>
/* Responsive layout rules for Statistics Dashboard */
.stats-two-col {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 1fr));
  gap: 1.25rem;
  margin-bottom: 1.5rem;
}
.stats-pm-ratio {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
  gap: 10px;
}
@media (max-width: 768px) {
  .stats-two-col {
    grid-template-columns: 1fr !important;
  }
  .stats-pm-ratio {
    grid-template-columns: 1fr !important;
  }
}
@media print {
  body {
    background: #ffffff !important;
    color: #000000 !important;
  }
  .app-sidebar, .app-topbar, .no-print, .btn {
    display: none !important;
  }
  .app-main-viewport, .container-fluid {
    padding: 0 !important;
    margin: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
  }
  .card {
    box-shadow: none !important;
    border: 1px solid #cbd5e1 !important;
    background: #ffffff !important;
    break-inside: avoid;
    page-break-inside: avoid;
  }
}
</style>

  <!-- Section 2: Grid 2 Kolom (Rincian Kategori Kegiatan & Analitik PM) -->
  <div class="stats-two-col">
    
    <!-- Kolom A: Rincian Kategori Kegiatan Daily Activity (Maintenance, Kunjungan, dll) -->
    <div class="card" style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); display: flex; flex-direction: column;">
      <div class="card-header" style="padding: 1.15rem 1.35rem; background: var(--bg-alt); border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
        <div>
          <h4 style="margin: 0; font-size: 1.05rem; font-weight: 800; color: var(--text-main); display: flex; align-items: center; gap: 6px;">
            <?= app_icon('activity', 16, 'text-primary') ?>
            <span>Distribusi Kategori Kegiatan Logbook</span>
          </h4>
          <span style="font-size: 0.75rem; color: var(--text-subtle);">
            Rincian maintenance, kunjungan vendor, patroli, monitoring, dan gangguan
          </span>
        </div>
        <span style="font-size: 0.78rem; font-weight: 800; color: var(--primary);">
          <?= $totalActivities ?> Kegiatan
        </span>
      </div>

      <div class="card-body" style="padding: 1.25rem; flex: 1;">
        <?php if (empty($processedCategories)): ?>
          <div style="text-align: center; padding: 2rem; color: var(--text-subtle);">
            <?= app_icon('info', 36) ?>
            <p style="margin-top: 0.5rem; font-size: 0.88rem;">Belum ada aktivitas kegiatan pada periode ini.</p>
          </div>
        <?php else: ?>
          <div style="display: flex; flex-direction: column; gap: 1rem;">
            <?php foreach ($processedCategories as $cat): ?>
              <div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; font-size: 0.84rem;">
                  <div style="display: flex; align-items: center; gap: 6px; font-weight: 700; color: var(--text-main);">
                    <span style="width: 22px; height: 22px; border-radius: 4px; background: <?= $cat['bg'] ?>; color: <?= $cat['color'] ?>; display: flex; align-items: center; justify-content: center; font-size: 0.75rem;">
                      <?= app_icon($cat['icon'], 12) ?>
                    </span>
                    <span><?= htmlspecialchars($cat['label']) ?></span>
                  </div>
                  <div style="display: flex; align-items: center; gap: 8px;">
                    <strong style="color: var(--text-main); font-size: 0.88rem;"><?= $cat['count'] ?></strong>
                    <span style="color: var(--text-subtle); font-size: 0.74rem; width: 45px; text-align: right;"><?= $cat['percentage'] ?>%</span>
                  </div>
                </div>
                
                <!-- Progress Bar -->
                <div style="width: 100%; height: 8px; background: var(--border); border-radius: 4px; overflow: hidden;">
                  <div style="width: <?= min(100, $cat['percentage']) ?>%; height: 100%; background: <?= $cat['color'] ?>; border-radius: 4px; transition: width 0.3s ease;"></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Kolom B: Analitik Preventive Maintenance (PM Bulanan) -->
    <div class="card" style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); display: flex; flex-direction: column;">
      <div class="card-header" style="padding: 1.15rem 1.35rem; background: var(--bg-alt); border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
        <div>
          <h4 style="margin: 0; font-size: 1.05rem; font-weight: 800; color: var(--text-main); display: flex; align-items: center; gap: 6px;">
            <?= app_icon('clipboard-list', 16, 'text-primary') ?>
            <span>Analitik Preventive Maintenance (PM)</span>
          </h4>
          <span style="font-size: 0.75rem; color: var(--text-subtle);">
            Distribusi tipe inspeksi, operator seluler, dan evaluasi kondisi site
          </span>
        </div>
        <span style="font-size: 0.78rem; font-weight: 800; color: #0284c7;">
          <?= $totalPm ?> Dokumen
        </span>
      </div>

      <div class="card-body" style="padding: 1.25rem; flex: 1; display: flex; flex-direction: column; gap: 1.25rem;">
        
        <!-- Sub-block 1: PM Indoor vs Outdoor Ratio -->
        <div>
          <div style="font-size: 0.78rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; margin-bottom: 8px;">
            Rasio Tipe Inspeksi
          </div>
          <div class="stats-pm-ratio">
            <div style="background: var(--primary-subtle); border: 1px solid rgba(20, 184, 166, 0.2); border-radius: var(--radius-md); padding: 0.85rem; display: flex; align-items: center; gap: 10px;">
              <div style="width: 36px; height: 36px; border-radius: var(--radius-sm); background: var(--primary); color: #ffffff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.8rem;">
                IN
              </div>
              <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-subtle);">PM Indoor (IBC)</div>
                <div style="font-size: 1.25rem; font-weight: 800; color: var(--primary);"><?= $pmIndoorCount ?> Dokumen</div>
              </div>
            </div>

            <div style="background: #e0f2fe; border: 1px solid rgba(2, 132, 199, 0.2); border-radius: var(--radius-md); padding: 0.85rem; display: flex; align-items: center; gap: 10px;">
              <div style="width: 36px; height: 36px; border-radius: var(--radius-sm); background: #0284c7; color: #ffffff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.8rem;">
                OUT
              </div>
              <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-subtle);">PM Outdoor (Pole)</div>
                <div style="font-size: 1.25rem; font-weight: 800; color: #0284c7;"><?= $pmOutdoorCount ?> Dokumen</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Sub-block 2: Operator Breakdown -->
        <div>
          <div style="font-size: 0.78rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; margin-bottom: 8px;">
            Sebaran Operator PM
          </div>
          <?php if (empty($pmOperatorStats)): ?>
            <div style="font-size: 0.82rem; color: var(--text-subtle); font-style: italic;">Belum ada data operator tercatat.</div>
          <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 8px;">
              <?php foreach ($pmOperatorStats as $op): 
                $opPct = $totalPm > 0 ? round(($op['count'] / $totalPm) * 100, 1) : 0;
              ?>
                <div>
                  <div style="display: flex; justify-content: space-between; font-size: 0.82rem; margin-bottom: 3px;">
                    <span style="font-weight: 700; color: var(--text-main);"><?= htmlspecialchars($op['name']) ?></span>
                    <span style="color: var(--text-subtle); font-weight: 600;"><?= $op['count'] ?> Laporan (<?= $opPct ?>%)</span>
                  </div>
                  <div style="width: 100%; height: 6px; background: var(--border); border-radius: 3px; overflow: hidden;">
                    <div style="width: <?= min(100, $opPct) ?>%; height: 100%; background: #0284c7; border-radius: 3px;"></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Sub-block 3: Shift Distribution (Pagi vs Malam vs OH) -->
        <div>
          <div style="font-size: 0.78rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; margin-bottom: 8px;">
            Sebaran Sesi Shift NOC
          </div>
          <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; text-align: center;">
            <div style="background: var(--bg-alt); padding: 0.65rem 0.5rem; border-radius: var(--radius-sm); border: 1px solid var(--border);">
              <div style="font-size: 0.7rem; color: var(--text-subtle); font-weight: 700;">SHIFT PAGI</div>
              <div style="font-size: 1.15rem; font-weight: 800; color: #f59e0b;"><?= (int)$shiftSummary['shifts_pagi'] ?></div>
            </div>
            <div style="background: var(--bg-alt); padding: 0.65rem 0.5rem; border-radius: var(--radius-sm); border: 1px solid var(--border);">
              <div style="font-size: 0.7rem; color: var(--text-subtle); font-weight: 700;">SHIFT MALAM</div>
              <div style="font-size: 1.15rem; font-weight: 800; color: #8b5cf6;"><?= (int)$shiftSummary['shifts_malam'] ?></div>
            </div>
            <div style="background: var(--bg-alt); padding: 0.65rem 0.5rem; border-radius: var(--radius-sm); border: 1px solid var(--border);">
              <div style="font-size: 0.7rem; color: var(--text-subtle); font-weight: 700;">OFFICE HOUR</div>
              <div style="font-size: 1.15rem; font-weight: 800; color: var(--primary);"><?= (int)$shiftSummary['shifts_oh'] ?></div>
            </div>
          </div>
        </div>

      </div>
    </div>

  </div>

  <!-- Section 3: Dinamika Harian Operasional (Responsive Visual Rhythm) -->
  <?php if (!$isAllTime && !empty($dailyVolume)): ?>
    <div class="card mb-4" style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden;">
      <div class="card-header" style="padding: 1.15rem 1.35rem; background: var(--bg-alt); border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
        <div>
          <h4 style="margin: 0; font-size: 1.05rem; font-weight: 800; color: var(--text-main); display: flex; align-items: center; gap: 6px;">
            <?= app_icon('calendar', 16, 'text-primary') ?>
            <span>Ritme Aktivitas Harian Logbook &bull; <?= htmlspecialchars($periodLabel) ?></span>
          </h4>
          <span style="font-size: 0.75rem; color: var(--text-subtle);">
            Volume jumlah kegiatan per tanggal sepanjang bulan (1 &ndash; <?= $daysInMonth ?>)
          </span>
        </div>
        <div style="font-size: 0.76rem; color: var(--text-subtle); font-weight: 600;">
          Puncak Tertinggi: <strong style="color: var(--primary);"><?= $maxDailyVolume ?> Kegiatan/Hari</strong>
        </div>
      </div>

      <div class="card-body" style="padding: 1.25rem;">
        <div style="overflow-x: auto; padding-bottom: 0.5rem;">
          <div style="display: flex; align-items: flex-end; gap: 6px; min-width: 650px; height: 140px; padding: 10px 0;">
            <?php for ($d = 1; $d <= $daysInMonth; $d++): 
              $vol = $dailyVolume[$d] ?? 0;
              $barPct = ($vol > 0) ? max(10, round(($vol / $maxDailyVolume) * 100)) : 4;
              $isWeekend = in_array(date('N', strtotime($filterMonth . '-' . sprintf('%02d', $d))), [6, 7]);
            ?>
              <div style="flex: 1; display: flex; flex-direction: column; align-items: center; height: 100%; justify-content: flex-end;" title="Tanggal <?= $d ?> <?= htmlspecialchars($periodLabel) ?>: <?= $vol ?> Kegiatan">
                <div style="font-size: 0.68rem; font-weight: 700; color: <?= $vol > 0 ? 'var(--primary)' : 'transparent' ?>; margin-bottom: 4px;">
                  <?= $vol > 0 ? $vol : '0' ?>
                </div>
                <div style="width: 100%; height: <?= $barPct ?>%; background: <?= $vol > 0 ? 'var(--primary)' : 'var(--border)' ?>; border-radius: 3px 3px 0 0; transition: height 0.2s ease;"></div>
                <div style="font-size: 0.68rem; font-weight: 700; color: <?= $isWeekend ? '#ef4444' : 'var(--text-subtle)' ?>; margin-top: 6px; border-top: 1px solid var(--border); width: 100%; text-align: center; padding-top: 3px;">
                  <?= $d ?>
                </div>
              </div>
            <?php endfor; ?>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Section 4: Produktivitas & Leaderboard Tim Teknisi -->
  <div class="card mb-4" style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden;">
    <div class="card-header" style="padding: 1.15rem 1.35rem; background: var(--bg-alt); border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
      <div>
        <h4 style="margin: 0; font-size: 1.05rem; font-weight: 800; color: var(--text-main); display: flex; align-items: center; gap: 6px;">
          <?= app_icon('users', 16, 'text-primary') ?>
          <span>Produktivitas &amp; Kontribusi Personel Tim</span>
        </h4>
        <span style="font-size: 0.75rem; color: var(--text-subtle);">
          Pencatatan sesi shift, kegiatan operasional, dan bukti foto per anggota
        </span>
      </div>
      <span style="font-size: 0.78rem; font-weight: 800; color: var(--primary);">
        <?= count($techLeaderboard) ?> Anggota Terdata
      </span>
    </div>

    <!-- Desktop View Table (Screens > 768px) -->
    <div class="desktop-table-container">
      <div class="table-responsive">
        <table class="table mb-0" style="width: 100%; border-collapse: collapse;">
          <thead>
            <tr style="background: var(--bg-alt); font-size: 0.78rem; font-weight: 800; color: var(--text-subtle); text-transform: uppercase;">
              <th style="padding: 0.75rem 1rem; width: 50px; text-align: center;">Peringkat</th>
              <th style="padding: 0.75rem 1rem;">Nama Lengkap &amp; Username</th>
              <th style="padding: 0.75rem 1rem;">Role / Hak Akses</th>
              <th style="padding: 0.75rem 1rem; text-align: center;">Total Shift</th>
              <th style="padding: 0.75rem 1rem; text-align: center;">Total Kegiatan</th>
              <th style="padding: 0.75rem 1rem; text-align: center;">Foto Bukti</th>
              <th style="padding: 0.75rem 1rem; text-align: center;">Kontribusi</th>
              <th style="padding: 0.75rem 1rem; text-align: right; width: 140px;" class="no-print">Filter Logbook</th>
            </tr>
          </thead>
          <tbody>
            <?php 
            $rank = 1;
            foreach ($techLeaderboard as $tl): 
              $tName = $tl['full_name'] ?: $tl['username'];
              $tInitials = strtoupper(substr($tName, 0, 2));
              $actCount = (int)$tl['activities_count'];
              $pct = $totalActivities > 0 ? round(($actCount / $totalActivities) * 100, 1) : 0;
            ?>
              <tr style="border-top: 1px solid var(--border); font-size: 0.84rem;">
                <td style="padding: 0.85rem 1rem; text-align: center;">
                  <?php if ($rank === 1 && $actCount > 0): ?>
                    <span style="font-weight: 800; color: #f59e0b; font-size: 1rem;">🥇 1</span>
                  <?php elseif ($rank === 2 && $actCount > 0): ?>
                    <span style="font-weight: 800; color: #94a3b8; font-size: 1rem;">🥈 2</span>
                  <?php elseif ($rank === 3 && $actCount > 0): ?>
                    <span style="font-weight: 800; color: #b45309; font-size: 1rem;">🥉 3</span>
                  <?php else: ?>
                    <span style="font-weight: 700; color: var(--text-subtle);"><?= $rank ?></span>
                  <?php endif; ?>
                </td>
                <td style="padding: 0.85rem 1rem;">
                  <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--primary-subtle); color: var(--primary); font-size: 0.75rem; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                      <?= $tInitials ?>
                    </div>
                    <div>
                      <div style="font-weight: 700; color: var(--text-main);"><?= htmlspecialchars($tName) ?></div>
                      <div style="font-size: 0.72rem; color: var(--text-subtle);">@<?= htmlspecialchars($tl['username']) ?></div>
                    </div>
                  </div>
                </td>
                <td style="padding: 0.85rem 1rem;">
                  <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.72rem; font-weight: 700; background: var(--bg-alt); color: var(--text-subtle); text-transform: uppercase;">
                    <?= htmlspecialchars($tl['role']) ?>
                  </span>
                </td>
                <td style="padding: 0.85rem 1rem; text-align: center; font-weight: 700;">
                  <span style="display: inline-flex; align-items: center; gap: 4px;">
                    <?= app_icon('clock', 12, 'text-primary') ?>
                    <span><?= (int)$tl['shifts_count'] ?> sesi</span>
                  </span>
                </td>
                <td style="padding: 0.85rem 1rem; text-align: center;">
                  <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 9999px; background: var(--primary-subtle); color: var(--primary); font-weight: 700; font-size: 0.78rem;">
                    <?= app_icon('activity', 12) ?>
                    <span><?= $actCount ?></span>
                  </span>
                </td>
                <td style="padding: 0.85rem 1rem; text-align: center;">
                  <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 9999px; background: #e0f2fe; color: #0284c7; font-weight: 700; font-size: 0.78rem;">
                    <?= app_icon('camera', 12) ?>
                    <span><?= (int)$tl['photos_count'] ?></span>
                  </span>
                </td>
                <td style="padding: 0.85rem 1rem; text-align: center;">
                  <div style="display: flex; align-items: center; justify-content: center; gap: 6px;">
                    <div style="width: 60px; height: 6px; background: var(--border); border-radius: 3px; overflow: hidden;">
                      <div style="width: <?= min(100, $pct) ?>%; height: 100%; background: var(--primary); border-radius: 3px;"></div>
                    </div>
                    <span style="font-size: 0.75rem; font-weight: 700; color: var(--text-main); width: 40px; text-align: right;"><?= $pct ?>%</span>
                  </div>
                </td>
                <td style="padding: 0.85rem 1rem; text-align: right;" class="no-print">
                  <a href="statistics?month=<?= urlencode($filterMonth) ?>&user_id=<?= (int)$tl['user_id'] ?>" class="btn btn-secondary btn-sm" style="padding: 0.25rem 0.65rem; font-size: 0.74rem; display: inline-flex; align-items: center; gap: 4px;" title="Filter statistik teknisi ini">
                    <?= app_icon('filter', 11) ?>
                    <span>Filter</span>
                  </a>
                </td>
              </tr>
            <?php $rank++; endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Mobile View Cards (Screens <= 768px, zero overflow clipping) -->
    <div class="mobile-cards-container" style="padding: 0.75rem; gap: 0.75rem;">
      <?php 
      $rankM = 1;
      foreach ($techLeaderboard as $tl): 
        $tName = $tl['full_name'] ?: $tl['username'];
        $tInitials = strtoupper(substr($tName, 0, 2));
        $actCount = (int)$tl['activities_count'];
        $pct = $totalActivities > 0 ? round(($actCount / $totalActivities) * 100, 1) : 0;
      ?>
        <div style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 0.95rem; box-shadow: 0 1px 3px rgba(0,0,0,0.04); display: flex; flex-direction: column; gap: 0.65rem;">
          <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
            <div style="display: flex; align-items: center; gap: 8px;">
              <div style="font-size: 0.88rem; font-weight: 800; width: 26px; text-align: center; color: var(--text-subtle);">
                #<?= $rankM ?>
              </div>
              <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--primary-subtle); color: var(--primary); font-size: 0.75rem; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <?= $tInitials ?>
              </div>
              <div>
                <div style="font-weight: 700; font-size: 0.88rem; color: var(--text-main); line-height: 1.2;"><?= htmlspecialchars($tName) ?></div>
                <div style="font-size: 0.72rem; color: var(--text-subtle);">@<?= htmlspecialchars($tl['username']) ?></div>
              </div>
            </div>
            <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.7rem; font-weight: 700; background: var(--bg-alt); color: var(--text-subtle); text-transform: uppercase;">
              <?= htmlspecialchars($tl['role']) ?>
            </span>
          </div>

          <!-- Stats Grid -->
          <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; background: var(--bg-alt); padding: 0.5rem 0.6rem; border-radius: var(--radius-sm); text-align: center;">
            <div>
              <div style="font-size: 0.68rem; color: var(--text-subtle); font-weight: 700;">SHIFT</div>
              <div style="font-size: 0.88rem; font-weight: 800; color: var(--primary);"><?= (int)$tl['shifts_count'] ?></div>
            </div>
            <div>
              <div style="font-size: 0.68rem; color: var(--text-subtle); font-weight: 700;">KEGIATAN</div>
              <div style="font-size: 0.88rem; font-weight: 800; color: var(--text-main);"><?= $actCount ?></div>
            </div>
            <div>
              <div style="font-size: 0.68rem; color: var(--text-subtle); font-weight: 700;">FOTO</div>
              <div style="font-size: 0.88rem; font-weight: 800; color: #0284c7;"><?= (int)$tl['photos_count'] ?></div>
            </div>
          </div>

          <!-- Contribution Progress -->
          <div>
            <div style="display: flex; justify-content: space-between; font-size: 0.72rem; margin-bottom: 3px;">
              <span style="color: var(--text-subtle); font-weight: 600;">Kontribusi Tim</span>
              <span style="font-weight: 800; color: var(--primary);"><?= $pct ?>%</span>
            </div>
            <div style="width: 100%; height: 6px; background: var(--border); border-radius: 3px; overflow: hidden;">
              <div style="width: <?= min(100, $pct) ?>%; height: 100%; background: var(--primary); border-radius: 3px;"></div>
            </div>
          </div>

          <!-- Filter Link -->
          <a href="statistics?month=<?= urlencode($filterMonth) ?>&user_id=<?= (int)$tl['user_id'] ?>" class="btn btn-secondary btn-sm" style="min-height: 40px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-weight: 700; font-size: 0.78rem;">
            <?= app_icon('filter', 13) ?>
            <span>Filter Statistik Anggota Ini</span>
          </a>
        </div>
      <?php $rankM++; endforeach; ?>
    </div>

  </div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
