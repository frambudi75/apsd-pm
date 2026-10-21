<?php
/**
 * Lembar Cetak Resmi Ringkasan Statistik Laporan Terpadu (PM & Daily Activity NOC)
 * PT Angkasa Pura Sarana Digital & IASS SBU ICT KNO
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/signature_helper.php';
require_once __DIR__ . '/includes/checklist_data.php';
if (!empty($_GET['pdf_token'])) {
    require_login();
} else {
    require_role(['superadmin', 'admin']);
}

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
} else {
    $periodLabel = 'Seluruh Periode Operasional';
}

// System settings for signatures & company name
$leaderName = get_system_setting('default_team_leader', 'Wahyu Wandira');
$leaderRole = get_system_setting('default_leader_role', 'Supervisor / Team Leader NOC KNO');
$companyName = get_system_setting('company_name', 'IASS SBU ICT KNO');
$unitName = get_system_setting('unit_name', 'KNO');

// Custom overrides via query params if provided
if (!empty($_GET['leader_name'])) {
    $leaderName = trim($_GET['leader_name']);
}
if (!empty($_GET['leader_role'])) {
    $leaderRole = trim($_GET['leader_role']);
}

// Current logged-in user / Admin info for reporter signature (left column)
$currentUser = current_user();
$adminName = get_system_setting('default_admin_name', '');
if (empty($adminName)) {
    if (!empty($currentUser['full_name'])) {
        $adminName = $currentUser['full_name'];
    } elseif (!empty($currentUser['username'])) {
        $adminName = $currentUser['username'];
    } else {
        $adminName = 'Administrator NOC';
    }
}
if (!empty($_GET['admin_name'])) {
    $adminName = trim($_GET['admin_name']);
}

$stmtUserJab = $pdo->prepare("SELECT jabatan FROM users WHERE id = ?");
$stmtUserJab->execute([$currentUser['id'] ?? 0]);
$userJabatan = $stmtUserJab->fetchColumn() ?: '';

$adminRole = get_system_setting('default_admin_role', '');
if (empty($adminRole)) {
    if (!empty($userJabatan)) {
        $adminRole = $userJabatan;
    } elseif (!empty($currentUser['role'])) {
        if ($currentUser['role'] === 'superadmin') {
            $adminRole = 'Super Administrator';
        } elseif ($currentUser['role'] === 'admin') {
            $adminRole = 'Administrator SBU ICT';
        } else {
            $adminRole = 'Petugas Operasional & Pemeliharaan ICT';
        }
    } else {
        $adminRole = 'Administrator SBU ICT';
    }
}
if (!empty($_GET['admin_role'])) {
    $adminRole = trim($_GET['admin_role']);
}

// Manager settings for escalation
$managerName = trim(get_system_setting('default_manager_name', ''));
$managerRole = get_system_setting('default_manager_role', 'Manager SBU ICT KNO');
$managerSignature = get_system_setting('default_manager_signature', '');

// Cek apakah pembuat/penyusun laporan sama dengan Team Leader
$isSelfReport = (strcasecmp(trim($adminName), trim($leaderName)) === 0);

// Digital signatures
$stmtAdminSig = $pdo->prepare("SELECT signature_path FROM users WHERE id = ?");
$stmtAdminSig->execute([$currentUser['id'] ?? 0]);
$adminSignature = $stmtAdminSig->fetchColumn() ?: get_system_setting('default_admin_signature', '');
$leaderSignature = get_system_setting('default_leader_signature', '');

// Load technicians list
$technicians = [];
try {
    $stmtTech = $pdo->query("SELECT id, username, full_name, role FROM users ORDER BY full_name ASC");
    $technicians = $stmtTech->fetchAll();
} catch (Exception $e) {}

$filterTechName = '';
if ($filterUserId > 0) {
    foreach ($technicians as $t) {
        if ((int)$t['id'] === $filterUserId) {
            $filterTechName = $t['full_name'] ?: $t['username'];
            break;
        }
    }
}

// ==========================================
// 1. QUERY PM REPORTS
// ==========================================
$pmWhere = [];
$pmParams = [];
if (!$isAllTime) {
    $pmWhere[] = "r.report_date LIKE ?";
    $pmParams[] = $filterMonth . '%';
}
if (!empty($filterTechName)) {
    $pmWhere[] = "(r.inspector_name LIKE ? OR r.team_leader LIKE ?)";
    $pmParams[] = '%' . $filterTechName . '%';
    $pmParams[] = '%' . $filterTechName . '%';
}
$pmWhereSql = !empty($pmWhere) ? "WHERE " . implode(" AND ", $pmWhere) : "";

$stmtPm = $pdo->prepare("
    SELECT r.*, s.site_name,
           ind.checklist_json as indoor_checklist,
           outd.grounding_json, outd.tower_json, outd.lighting_fence_other_json, outd.structural_json,
           outd.tenant_ac_json, outd.tenant_electrical_json, outd.pln_function_json
    FROM pm_reports r
    LEFT JOIN sites s ON r.site_id = s.id
    LEFT JOIN pm_indoor_details ind ON ind.report_id = r.id
    LEFT JOIN pm_outdoor_details outd ON outd.report_id = r.id
    {$pmWhereSql}
    ORDER BY r.report_date DESC
");
$stmtPm->execute($pmParams);
$pmReports = $stmtPm->fetchAll();

$totalPm = count($pmReports);
$pmIndoor = 0;
$pmOutdoor = 0;
$pmNok = 0;
$pmOk = 0;
$pmOperators = [];

foreach ($pmReports as &$p) {
    if ($p['report_type'] === 'indoor') $pmIndoor++;
    else $pmOutdoor++;

    $findings = get_report_findings_summary($p);
    if (!empty($findings['has_nok'])) $pmNok++;
    else $pmOk++;

    $op = trim($p['operator'] ?? '');
    if (empty($op)) $op = 'Umum / Bandara';
    $opKey = strtoupper($op);
    if (!isset($pmOperators[$opKey])) $pmOperators[$opKey] = ['name' => $op, 'count' => 0];
    $pmOperators[$opKey]['count']++;
}
unset($p);
uasort($pmOperators, function($a, $b) { return $b['count'] <=> $a['count']; });

// ==========================================
// 2. QUERY DAILY SHIFTS & ACTIVITIES
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
    'total_shifts' => 0, 'completed_shifts' => 0, 'active_shifts' => 0,
    'shifts_pagi' => 0, 'shifts_malam' => 0, 'shifts_oh' => 0, 'total_active_techs' => 0
];

// Query Categories Breakdown
$catQuery = "
    SELECT a.category, COUNT(a.id) AS total_count
    FROM daily_activities a
    INNER JOIN daily_shifts s ON a.shift_id = s.id
    {$shiftWhereSql}
    GROUP BY a.category
    ORDER BY total_count DESC
";
$stmtCat = $pdo->prepare($catQuery);
$stmtCat->execute($shiftParams);
$categories = $stmtCat->fetchAll();

$totalActivities = 0;
foreach ($categories as $c) {
    $totalActivities += (int)$c['total_count'];
}

$stmtPhotos = $pdo->prepare("
    SELECT COUNT(p.id) AS total_photos
    FROM daily_activity_photos p
    INNER JOIN daily_activities a ON p.activity_id = a.id
    INNER JOIN daily_shifts s ON a.shift_id = s.id
    {$shiftWhereSql}
");
$stmtPhotos->execute($shiftParams);
$totalPhotos = (int)($stmtPhotos->fetchColumn() ?: 0);

// ==========================================
// 3. QUERY TEAM PRODUCTIVITY
// ==========================================
$teamProductivity = [];
try {
    $tlWhere = !$isAllTime ? "AND s.shift_date LIKE ?" : "";
    $stmtTeam = $pdo->prepare("
        SELECT 
            u.id AS user_id, u.full_name, u.username, u.role,
            COUNT(DISTINCT s.id) AS shifts_count,
            (SELECT COUNT(*) FROM daily_activities a 
             INNER JOIN daily_shifts ds ON a.shift_id = ds.id 
             WHERE ds.user_id = u.id " . (!$isAllTime ? "AND ds.shift_date LIKE ?" : "") . ") AS activities_count,
            (SELECT COUNT(*) FROM daily_activity_photos p 
             INNER JOIN daily_activities a ON p.activity_id = a.id
             INNER JOIN daily_shifts ds ON a.shift_id = ds.id 
             WHERE ds.user_id = u.id " . (!$isAllTime ? "AND ds.shift_date LIKE ?" : "") . ") AS photos_count
        FROM users u
        LEFT JOIN daily_shifts s ON s.user_id = u.id {$tlWhere}
        GROUP BY u.id, u.full_name, u.username, u.role
        HAVING activities_count > 0 OR shifts_count > 0
        ORDER BY activities_count DESC, shifts_count DESC
    ");
    $teamParams = [];
    if (!$isAllTime) {
        $teamParams[] = $filterMonth . '%';
        $teamParams[] = $filterMonth . '%';
        $teamParams[] = $filterMonth . '%';
    }
    $stmtTeam->execute($teamParams);
    $teamProductivity = $stmtTeam->fetchAll();
} catch (Exception $e) {}

$page_title = 'Rekapitulasi Statistik Operasional PM & Daily - ' . $periodLabel;
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($page_title) ?></title>
  <link rel="icon" type="image/png" href="<?= htmlspecialchars(get_app_favicon_url()) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <style>
    @page {
      size: A4 portrait;
      margin: 6mm 8mm;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background: #e2e8f0;
      color: #0f172a;
      line-height: 1.35;
      padding: 16px 10px 40px 10px;
    }

    /* Screen Action Bar (hidden when printing) */
    .print-actions-bar {
      background: #ffffff;
      border: 1px solid #cbd5e1;
      padding: 10px 18px;
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: 0 4px 12px rgba(0,0,0,0.08);
      max-width: 920px;
      margin-left: auto;
      margin-right: auto;
      border-radius: 8px;
      flex-wrap: wrap;
      gap: 10px;
    }

    .btn-action {
      padding: 6px 12px;
      font-size: 12.5px;
      font-weight: 700;
      border-radius: 6px;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      border: 1px solid transparent;
      transition: all 0.15s ease;
    }

    .btn-print { background: #008080; color: #ffffff; }
    .btn-print:hover { background: #006666; }
    .btn-back { background: #f1f5f9; color: #475569; border-color: #cbd5e1; }
    .btn-back:hover { background: #e2e8f0; color: #0f172a; }
    .btn-density { background: #f8fafc; color: #64748b; border: 1px solid #cbd5e1; font-weight: 600; font-size: 11.5px; padding: 4px 8px; }
    .btn-density:hover { background: #e2e8f0; color: #0f172a; }
    .btn-density.active { background: #008080; color: #ffffff; border-color: #008080; }

    /* A4 Document Container */
    .a4-sheet {
      width: 210mm;
      max-width: 100%;
      min-height: 297mm;
      padding: 8mm 10mm;
      margin: 0 auto;
      background: #ffffff;
      box-shadow: 0 4px 20px rgba(0,0,0,0.08);
      border-radius: 2px;
      box-sizing: border-box;
    }

    /* Letterhead Table */
    .doc-header-table {
      width: 100%;
      border-bottom: 2px solid #008080;
      padding-bottom: 6px;
      margin-bottom: 8px;
      border-collapse: collapse;
    }
    .doc-header-table td { vertical-align: middle; }

    .doc-title-main {
      font-size: 11.5pt;
      font-weight: 800;
      color: #008080;
      text-transform: uppercase;
      letter-spacing: 0.02em;
      line-height: 1.2;
    }
    .doc-title-sub {
      font-size: 8.5pt;
      font-weight: 700;
      color: #0f172a;
      margin-top: 1px;
      line-height: 1.25;
    }
    .doc-title-location {
      font-size: 7.2pt;
      color: #64748b;
      margin-top: 1px;
    }

    /* Section Headers */
    .section-title {
      font-size: 8.2pt;
      font-weight: 800;
      color: #008080;
      text-transform: uppercase;
      letter-spacing: 0.02em;
      border-bottom: 1.5px solid #cbd5e1;
      padding-bottom: 2px;
      margin-top: 8px;
      margin-bottom: 5px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    /* Formal Tables */
    .formal-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 7.5pt;
      margin-bottom: 6px;
    }

    .formal-table th {
      background: #f1f5f9;
      color: #1e293b;
      font-weight: 700;
      padding: 3.5px 6px;
      border: 1px solid #94a3b8;
      text-align: left;
      font-size: 7.2pt;
      text-transform: uppercase;
      line-height: 1.2;
    }

    .formal-table td {
      padding: 3.5px 6px;
      border: 1px solid #94a3b8;
      vertical-align: middle;
      line-height: 1.25;
    }

    .formal-table tr:nth-child(even) td {
      background: #f8fafc;
    }

    /* KPI Highlights Box */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 6px;
      margin-bottom: 8px;
    }

    .kpi-box {
      border: 1px solid #cbd5e1;
      border-radius: 4px;
      padding: 4px 6px;
      background: #f8fafc;
      text-align: center;
    }

    .kpi-box-label {
      font-size: 6.8pt;
      font-weight: 700;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: 0.02em;
    }

    .kpi-box-value {
      font-size: 11pt;
      font-weight: 800;
      color: #008080;
      margin-top: 1px;
      line-height: 1.15;
    }

    .kpi-box-sub {
      font-size: 6.5pt;
      color: #64748b;
      margin-top: 1px;
    }

    /* Sign-off signatures */
    .sign-table {
      width: 100%;
      margin-top: 8px;
      border-collapse: collapse;
      page-break-inside: avoid;
      break-inside: avoid;
    }

    .sign-table td {
      width: 50%;
      text-align: center;
      vertical-align: top;
      font-size: 8pt;
      line-height: 1.3;
    }

    .sign-space {
      height: 38px;
      min-height: 38px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 2px 0;
    }

    .doc-signature-img {
      max-height: 36px !important;
      max-width: 140px !important;
      height: auto !important;
      width: auto !important;
      object-fit: contain;
      display: block;
      margin: 0 auto;
    }

    .sign-name {
      font-weight: 800;
      text-decoration: underline;
      color: #0f172a;
      font-size: 8.5pt;
    }

    .sign-role {
      font-size: 7.5pt;
      color: #64748b;
      margin-top: 1px;
    }

    /* Ultra Compact Mode (Optional fallback if user has very many rows) */
    .a4-sheet.ultra-compact {
      zoom: 0.92;
    }
    .a4-sheet.ultra-compact .formal-table {
      font-size: 7pt;
    }
    .a4-sheet.ultra-compact .formal-table th,
    .a4-sheet.ultra-compact .formal-table td {
      padding: 2.5px 5px;
    }

    /* Print Setup */
    @media print {
      @page {
        size: A4 portrait;
        margin: 6mm 8mm;
      }
      html, body {
        background: #ffffff !important;
        padding: 0 !important;
        margin: 0 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }
      .print-actions-bar {
        display: none !important;
      }
      .a4-sheet {
        box-shadow: none !important;
        padding: 0 !important;
        margin: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        min-height: auto !important;
      }
      thead {
        display: table-header-group;
      }
      tr, .kpi-box, .section-title, .sign-table {
        page-break-inside: avoid;
        break-inside: avoid;
      }
      .a4-sheet.ultra-compact {
        zoom: 0.90;
      }
    }
  </style>
</head>
<body>

  <!-- Screen Actions Bar -->
  <div class="print-actions-bar">
    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
      <a href="statistics?month=<?= urlencode($filterMonth) ?><?= $filterUserId > 0 ? '&user_id='.$filterUserId : '' ?>" class="btn-action btn-back">
        &larr; Kembali ke Tampilan Dashboard
      </a>
      <span style="font-size: 13px; font-weight: 700; color: #008080;">
        Format Cetak Resmi &bull; <?= htmlspecialchars($periodLabel) ?>
      </span>
    </div>

    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
      <div style="display: inline-flex; align-items: center; background: #f1f5f9; padding: 2px 4px; border-radius: 6px; gap: 2px; border: 1px solid #cbd5e1;">
        <span style="font-size: 11px; font-weight: 700; color: #475569; padding: 0 4px;">Kepadatan:</span>
        <button type="button" class="btn-action btn-density active" id="btnDenseNormal" onclick="setDensity('normal')" title="Tata letak proporsional pas 1 halaman">Pas 1 Hal</button>
        <button type="button" class="btn-action btn-density" id="btnDenseCompact" onclick="setDensity('compact')" title="Gunakan ini jika data kategori atau teknisi sangat banyak agar tetap pas 1 halaman">Super Padat</button>
      </div>
      <button type="button" class="btn-action btn-print" onclick="window.print()">
        🖨️ Cetak / Simpan PDF Sekarang
      </button>
    </div>
  </div>

  <!-- A4 Printable Sheet -->
  <div class="a4-sheet">

    <!-- Header Kop Surat Resmi -->
    <table class="doc-header-table">
      <tr>
        <td style="width: 70px;">
          <img src="<?= htmlspecialchars(get_report_kop_logo()) ?>" alt="Logo Kop" style="height: 38px; object-fit: contain;">
        </td>
        <td style="padding-left: 10px;">
          <div class="doc-title-main">Rekapitulasi &amp; Laporan Statistik Operasional</div>
          <div class="doc-title-sub">Preventive Maintenance (PM) &amp; Daily Activity Logbook NOC &bull; <?= htmlspecialchars($periodLabel) ?></div>
          <div class="doc-title-location"><?= htmlspecialchars($companyName) ?> &bull; Unit <?= htmlspecialchars($unitName) ?> &bull; Bandara Internasional Kualanamu</div>
        </td>
        <td style="width: 150px; text-align: right; vertical-align: top;">
          <div style="font-size: 7.5pt; color: #64748b; font-weight: 600;">Tanggal Dicetak:</div>
          <div style="font-size: 8pt; color: #0f172a; font-weight: 700; margin-top: 1px;">
            <?= date('d/m/Y H:i') ?> WIB
          </div>
          <?php if (!empty($filterTechName)): ?>
            <div style="font-size: 7pt; color: #008080; font-weight: 700; margin-top: 2px;">
              PIC: <?= htmlspecialchars($filterTechName) ?>
            </div>
          <?php endif; ?>
        </td>
      </tr>
    </table>

    <!-- Ringkasan Eksekutif (5 KPI Box) -->
    <div class="kpi-grid">
      <div class="kpi-box">
        <div class="kpi-box-label">Sesi Shift Tim</div>
        <div class="kpi-box-value"><?= (int)$shiftSummary['total_shifts'] ?></div>
        <div class="kpi-box-sub"><?= (int)$shiftSummary['completed_shifts'] ?> Selesai &bull; <?= (int)$shiftSummary['active_shifts'] ?> Aktif</div>
      </div>
      <div class="kpi-box">
        <div class="kpi-box-label">Kegiatan Logbook</div>
        <div class="kpi-box-value"><?= $totalActivities ?></div>
        <div class="kpi-box-sub"><?= count($categories) ?> Kategori</div>
      </div>
      <div class="kpi-box">
        <div class="kpi-box-label">Dokumen PM</div>
        <div class="kpi-box-value"><?= $totalPm ?></div>
        <div class="kpi-box-sub"><?= $pmIndoor ?> IBC &bull; <?= $pmOutdoor ?> Outdoor</div>
      </div>
      <div class="kpi-box">
        <div class="kpi-box-label">Foto Lapangan</div>
        <div class="kpi-box-value"><?= $totalPhotos ?></div>
        <div class="kpi-box-sub">Bukti Fisik</div>
      </div>
      <div class="kpi-box">
        <div class="kpi-box-label">Kondisi Site PM</div>
        <div class="kpi-box-value" style="color: <?= ($pmNok > 0) ? '#b45309' : '#047857' ?>;">
          <?= ($pmNok > 0) ? $pmNok . ' NOK' : '100% OK' ?>
        </div>
        <div class="kpi-box-sub"><?= $pmOk ?> Site Normal</div>
      </div>
    </div>

    <!-- Bagian 1: Distribusi Kategori Kegiatan Logbook NOC -->
    <div class="section-title">
      <span>1. Rincian Kegiatan Logbook Harian per Kategori</span>
      <span style="font-size: 8pt; font-weight: 700; color: #64748b;">Total: <?= $totalActivities ?> Kegiatan</span>
    </div>

    <?php if (empty($categories)): ?>
      <div style="font-size: 8.5pt; color: #64748b; font-style: italic; padding: 8px 0;">Tidak ada catatan kegiatan pada periode ini.</div>
    <?php else: ?>
      <table class="formal-table">
        <thead>
          <tr>
            <th style="width: 35px; text-align: center;">No</th>
            <th>Kategori Aktivitas Pekerjaan</th>
            <th style="width: 110px; text-align: center;">Jumlah Kegiatan</th>
            <th style="width: 90px; text-align: center;">Persentase</th>
            <th>Keterangan / Ruang Lingkup Operasional</th>
          </tr>
        </thead>
        <tbody>
          <?php 
          $noCat = 1;
          foreach ($categories as $cat): 
            $cnt = (int)$cat['total_count'];
            $pct = $totalActivities > 0 ? round(($cnt / $totalActivities) * 100, 1) : 0;
            $catName = htmlspecialchars($cat['category'] ?: 'Lainnya');
          ?>
            <tr>
              <td style="text-align: center; font-weight: 700; color: #64748b;"><?= $noCat++ ?></td>
              <td style="font-weight: 700; color: #0f172a;"><?= $catName ?></td>
              <td style="text-align: center; font-weight: 800; color: #008080;"><?= $cnt ?></td>
              <td style="text-align: center; font-weight: 700;"><?= $pct ?>%</td>
              <td style="color: #475569; font-size: 7.2pt; line-height: 1.25;">
                <?php 
                $lowerCat = strtolower($catName);
                if (strpos($lowerCat, 'maintenance') !== false) {
                    echo 'Pemeliharaan berkala perangkat transmisi, radio, UPS, AC, dan infrastruktur ICT.';
                } elseif (strpos($lowerCat, 'kunjungan') !== false) {
                    echo 'Pendampingan vendor telco, koordinasi stakeholder, dan izin kerja teknis bandara.';
                } elseif (strpos($lowerCat, 'troubleshooting') !== false) {
                    echo 'Penanganan gangguan link, perbaikan kabel fiber optik, dan pemulihan anomali sistem.';
                } elseif (strpos($lowerCat, 'monitoring') !== false) {
                    echo 'Pengawasan berkala NMS, status alarm perangkat, dan parameter suhu ruang server.';
                } elseif (strpos($lowerCat, 'patroli') !== false) {
                    echo 'Inspeksi fisik shelter, tiang pole tower, dan grounding perimeter bandara.';
                } elseif (strpos($lowerCat, 'administrasi') !== false) {
                    echo 'Pencatatan serah terima shift, dokumentasi arsip, dan laporan operasional harian.';
                } else {
                    echo 'Aktivitas pendukung operasional fasilitas telekomunikasi dan jaringan bandara.';
                }
                ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <!-- Bagian 2: Ringkasan Analitik Preventive Maintenance (PM) -->
    <div class="section-title">
      <span>2. Analitik Preventive Maintenance (PM) Bulanan</span>
      <span style="font-size: 8pt; font-weight: 700; color: #64748b;">Total: <?= $totalPm ?> Dokumen Laporan</span>
    </div>

    <table class="formal-table">
      <thead>
        <tr>
          <th>Tipe Pemeriksaan PM</th>
          <th style="width: 100px; text-align: center;">Jumlah Site</th>
          <th>Distribusi Operator Seluler</th>
          <th style="width: 120px; text-align: center;">Status Kondisi</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>
            <strong style="color: #0f172a;">PM Indoor (IBC)</strong>
            <div style="font-size: 7.5pt; color: #64748b;">Gedung Terminal Bandara Internasional Kualanamu</div>
          </td>
          <td style="text-align: center; font-weight: 800; color: #008080;"><?= $pmIndoor ?> Laporan</td>
          <td rowspan="2" style="vertical-align: top;">
            <?php if (empty($pmOperators)): ?>
              <span style="color: #94a3b8; font-style: italic;">Tidak ada data operator</span>
            <?php else: ?>
              <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                <?php foreach ($pmOperators as $op): ?>
                  <span style="background: #f1f5f9; padding: 2px 6px; border-radius: 3px; font-weight: 700; font-size: 7.8pt;">
                    <?= htmlspecialchars($op['name']) ?>: <?= $op['count'] ?>
                  </span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </td>
          <td rowspan="2" style="text-align: center; vertical-align: middle;">
            <div style="font-weight: 800; color: #047857; font-size: 9pt;"><?= $pmOk ?> Site Normal</div>
            <?php if ($pmNok > 0): ?>
              <div style="font-weight: 700; color: #b45309; font-size: 8pt; margin-top: 2px;"><?= $pmNok ?> Site Ada Temuan</div>
            <?php endif; ?>
          </td>
        </tr>
        <tr>
          <td>
            <strong style="color: #0f172a;">PM Outdoor (Pole &amp; Tower)</strong>
            <div style="font-size: 7.5pt; color: #64748b;">Perimeter &amp; Fasilitas Luar Bandara Kualanamu</div>
          </td>
          <td style="text-align: center; font-weight: 800; color: #0284c7;"><?= $pmOutdoor ?> Laporan</td>
        </tr>
      </tbody>
    </table>

    <!-- Bagian 3: Produktivitas & Kontribusi Personel Tim -->
    <div class="section-title">
      <span>3. Produktivitas &amp; Kontribusi Personel Tim</span>
      <span style="font-size: 8pt; font-weight: 700; color: #64748b;"><?= count($teamProductivity) ?> Anggota Berpartisipasi</span>
    </div>

    <table class="formal-table">
      <thead>
        <tr>
          <th style="width: 35px; text-align: center;">No</th>
          <th>Nama Personel / Teknisi Jaga</th>
          <th style="width: 100px;">Role / Jabatan</th>
          <th style="width: 90px; text-align: center;">Jumlah Shift</th>
          <th style="width: 100px; text-align: center;">Total Kegiatan</th>
          <th style="width: 80px; text-align: center;">Foto Bukti</th>
          <th style="width: 90px; text-align: center;">Kontribusi</th>
        </tr>
      </thead>
      <tbody>
        <?php 
        $noTeam = 1;
        foreach ($teamProductivity as $tp): 
          $tName = $tp['full_name'] ?: $tp['username'];
          $tActs = (int)$tp['activities_count'];
          $tPct = $totalActivities > 0 ? round(($tActs / $totalActivities) * 100, 1) : 0;
        ?>
          <tr>
            <td style="text-align: center; color: #64748b; font-weight: 700;"><?= $noTeam++ ?></td>
            <td style="font-weight: 700; color: #0f172a;">
              <?= htmlspecialchars($tName) ?>
              <span style="font-size: 7.5pt; color: #64748b; font-weight: 400;">(@<?= htmlspecialchars($tp['username']) ?>)</span>
            </td>
            <td>
              <?php 
              $roleLabel = ($tp['role'] === 'superadmin') ? 'Super Admin' : (($tp['role'] === 'admin') ? 'Admin' : 'Teknisi Jaga NOC');
              ?>
              <span style="font-size: 7.2pt; font-weight: 700; color: #475569;">
                <?= htmlspecialchars($roleLabel) ?>
              </span>
            </td>
            <td style="text-align: center; font-weight: 700;"><?= (int)$tp['shifts_count'] ?> Sesi</td>
            <td style="text-align: center; font-weight: 800; color: #008080;"><?= $tActs ?></td>
            <td style="text-align: center;"><?= (int)$tp['photos_count'] ?></td>
            <td style="text-align: center; font-weight: 700;"><?= $tPct ?>%</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Tanda Tangan & Pengesahan Resmi -->
    <?php if ($isSelfReport && empty($managerName)): ?>
      <table class="sign-table" style="max-width: 380px; margin: 12px auto 0 auto;">
        <tr>
          <td style="width: 100%; text-align: center;">
            <div>Deli Serdang, <?= date('d') . ' ' . ($isAllTime ? ($indoMonths[(int)date('n')] . ' ' . date('Y')) : $periodLabel) ?></div>
            <div style="font-weight: 700; margin-top: 2px;">Disusun &amp; Disetujui oleh Supervisor / Leader</div>
            <div class="sign-space">
              <?php 
                $sigToShow = !empty($leaderSignature) ? $leaderSignature : $adminSignature;
                if (!empty($sigToShow)): 
              ?>
                <?= render_signature_img($sigToShow, 'TTD Leader') ?>
              <?php endif; ?>
            </div>
            <div class="sign-name"><?= htmlspecialchars($leaderName) ?></div>
            <div class="sign-role"><?= htmlspecialchars($leaderRole) ?> &bull; <?= htmlspecialchars($unitName) ?></div>
          </td>
        </tr>
      </table>
    <?php elseif ($isSelfReport && !empty($managerName)): ?>
      <table class="sign-table">
        <tr>
          <td>
            <div>Deli Serdang, <?= date('d') . ' ' . ($isAllTime ? ($indoMonths[(int)date('n')] . ' ' . date('Y')) : $periodLabel) ?></div>
            <div style="font-weight: 700; margin-top: 2px;">Dibuat / Dilaporkan oleh,</div>
            <div class="sign-space">
              <?php 
                $sigToShow = !empty($adminSignature) ? $adminSignature : $leaderSignature;
                if (!empty($sigToShow)): 
              ?>
                <?= render_signature_img($sigToShow, 'TTD ' . $adminName) ?>
              <?php endif; ?>
            </div>
            <div class="sign-name"><?= htmlspecialchars($adminName) ?></div>
            <div class="sign-role"><?= htmlspecialchars($leaderRole) ?></div>
          </td>
          <td>
            <div>Mengetahui &amp; Menyetujui,</div>
            <div style="font-weight: 700; margin-top: 2px;"><?= htmlspecialchars($managerRole) ?></div>
            <div class="sign-space">
              <?php if (!empty($managerSignature)): ?>
                <?= render_signature_img($managerSignature, 'TTD Manager') ?>
              <?php endif; ?>
            </div>
            <div class="sign-name"><?= htmlspecialchars($managerName) ?></div>
            <div class="sign-role"><?= htmlspecialchars($unitName) ?></div>
          </td>
        </tr>
      </table>
    <?php else: ?>
      <table class="sign-table">
        <tr>
          <td>
            <div>Deli Serdang, <?= date('d') . ' ' . ($isAllTime ? ($indoMonths[(int)date('n')] . ' ' . date('Y')) : $periodLabel) ?></div>
            <div style="font-weight: 700; margin-top: 2px;">Dibuat / Dilaporkan oleh,</div>
            <div class="sign-space">
              <?php if (!empty($adminSignature)): ?>
                <?= render_signature_img($adminSignature, 'TTD ' . $adminName) ?>
              <?php endif; ?>
            </div>
            <div class="sign-name"><?= htmlspecialchars($adminName) ?></div>
            <div class="sign-role"><?= htmlspecialchars($adminRole) ?></div>
          </td>
          <td>
            <div>Mengetahui &amp; Menyetujui,</div>
            <div style="font-weight: 700; margin-top: 2px;"><?= htmlspecialchars($leaderRole) ?></div>
            <div class="sign-space">
              <?php if (!empty($leaderSignature)): ?>
                <?= render_signature_img($leaderSignature, 'TTD Leader') ?>
              <?php endif; ?>
            </div>
            <div class="sign-name"><?= htmlspecialchars($leaderName) ?></div>
            <div class="sign-role"><?= htmlspecialchars($unitName) ?></div>
          </td>
        </tr>
      </table>
    <?php endif; ?>

  </div>

  <script>
    function setDensity(mode) {
      var sheet = document.querySelector('.a4-sheet');
      var btnNorm = document.getElementById('btnDenseNormal');
      var btnComp = document.getElementById('btnDenseCompact');
      if (!sheet || !btnNorm || !btnComp) return;
      if (mode === 'compact') {
        sheet.classList.add('ultra-compact');
        btnComp.classList.add('active');
        btnNorm.classList.remove('active');
        try { localStorage.setItem('stat_print_density', 'compact'); } catch (e) {}
      } else {
        sheet.classList.remove('ultra-compact');
        btnNorm.classList.add('active');
        btnComp.classList.remove('active');
        try { localStorage.setItem('stat_print_density', 'normal'); } catch (e) {}
      }
    }
    try {
      if (localStorage.getItem('stat_print_density') === 'compact') {
        setDensity('compact');
      }
    } catch (e) {}
  </script>
</body>
</html>
