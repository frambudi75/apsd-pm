<?php
/**
 * Rekap & Laporan Harian NOC (Daily Activity Shifts & Monthly Recap)
 * IASS SBU ICT KNO
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
require_role(['superadmin', 'admin']);

$page_title = 'Rekap & Laporan Harian NOC';
$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$isAdmin = true;

$pdo = get_db_connection();

// Active Tab: 'shifts' (Laporan Harian Shift) or 'monthly' (Rekap Bulanan Semua Anggota)
$activeTab = strtolower(trim($_GET['tab'] ?? 'shifts'));
if (!in_array($activeTab, ['shifts', 'monthly'])) {
    $activeTab = 'shifts';
}

// Filter parameters
$currentMonth = date('Y-m');
$filterMonth = trim($_GET['month'] ?? $currentMonth);
if (!preg_match('/^\d{4}-\d{2}$/', $filterMonth)) {
    $filterMonth = $currentMonth;
}

$monthTime = strtotime($filterMonth . '-01');
$indoMonths = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
$mNum = (int)date('n', $monthTime);
$mYear = date('Y', $monthTime);
$periodLabel = ($indoMonths[$mNum] ?? date('F', $monthTime)) . ' ' . $mYear;

$filterDate = trim($_GET['date'] ?? '');
$filterShiftType = strtolower(trim($_GET['shift_type'] ?? ''));
$filterUserId = $isAdmin ? (int)($_GET['user_id'] ?? 0) : 0;
$filterStatus = trim($_GET['status'] ?? '');

// Load all technicians for filter dropdown
$technicians = [];
try {
    $stmtTech = $pdo->query("SELECT id, username, full_name, role FROM users ORDER BY full_name ASC");
    $technicians = $stmtTech->fetchAll();
} catch (Exception $e) {}

// Build Query for Shift List (Tab: shifts)
$where = [];
$params = [];

if (!empty($filterDate)) {
    $where[] = "s.shift_date = ?";
    $params[] = $filterDate;
} else {
    // Filter by month
    $where[] = "s.shift_date LIKE ?";
    $params[] = $filterMonth . '%';
}

if (!empty($filterShiftType) && in_array($filterShiftType, ['oh', 'pagi', 'malam'])) {
    $where[] = "s.shift_type = ?";
    $params[] = $filterShiftType;
}

if ($isAdmin && $filterUserId > 0) {
    $where[] = "s.user_id = ?";
    $params[] = $filterUserId;
}

if (!empty($filterStatus) && in_array($filterStatus, ['active', 'completed'])) {
    $where[] = "s.status = ?";
    $params[] = $filterStatus;
}

$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Fetch Shifts with activity count & photo count
$query = "
    SELECT s.*, 
           u.full_name AS user_full_name, 
           u.username, 
           u.role AS user_role,
           (SELECT COUNT(*) FROM daily_activities a WHERE a.shift_id = s.id) AS total_activities,
           (SELECT COUNT(*) FROM daily_activity_photos p 
            INNER JOIN daily_activities a ON p.activity_id = a.id 
            WHERE a.shift_id = s.id) AS total_photos
    FROM daily_shifts s
    LEFT JOIN users u ON s.user_id = u.id
    {$whereClause}
    ORDER BY s.shift_date DESC, s.id DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$shifts = $stmt->fetchAll();

// Calculate Monthly Statistics for the filtered month
$statStmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT s.id) AS total_shifts,
        SUM(CASE WHEN s.status = 'completed' THEN 1 ELSE 0 END) AS completed_shifts,
        SUM(CASE WHEN s.status = 'active' THEN 1 ELSE 0 END) AS active_shifts,
        COUNT(DISTINCT s.user_id) AS active_techs,
        (SELECT COUNT(*) FROM daily_activities a 
         INNER JOIN daily_shifts ds ON a.shift_id = ds.id 
         WHERE ds.shift_date LIKE ?) AS total_month_activities,
        (SELECT COUNT(*) FROM daily_activity_photos p 
         INNER JOIN daily_activities a ON p.activity_id = a.id
         INNER JOIN daily_shifts ds ON a.shift_id = ds.id 
         WHERE ds.shift_date LIKE ?) AS total_month_photos
    FROM daily_shifts s
    WHERE s.shift_date LIKE ?
");
$monthParam = $filterMonth . '%';
$statStmt->execute([$monthParam, $monthParam, $monthParam]);
$stats = $statStmt->fetch() ?: [
    'total_shifts' => 0,
    'completed_shifts' => 0,
    'active_shifts' => 0,
    'active_techs' => 0,
    'total_month_activities' => 0,
    'total_month_photos' => 0
];

// Tab 2 (monthly) Data: Per-Technician Contribution & Chronological Monthly Activities
$techStats = [];
$monthlyActivities = [];
if ($activeTab === 'monthly') {
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $tQuery = "
                SELECT 
                    u.id AS user_id, 
                    u.full_name, 
                    u.username, 
                    u.role,
                    COUNT(DISTINCT s.id) AS total_shifts,
                    COUNT(DISTINCT a.id) AS total_activities,
                    COUNT(p.id) AS total_photos
                FROM users u
                LEFT JOIN daily_shifts s ON s.user_id = u.id AND strftime('%Y-%m', s.shift_date) = ?
                LEFT JOIN daily_activities a ON a.shift_id = s.id
                LEFT JOIN daily_activity_photos p ON p.activity_id = a.id
                GROUP BY u.id
                ORDER BY total_activities DESC, total_shifts DESC, u.full_name ASC
            ";
            $maQuery = "
                SELECT a.*, s.shift_date, s.shift_type, s.status AS shift_status, s.technician_name,
                       u.full_name AS user_full_name,
                       (SELECT COUNT(*) FROM daily_activity_photos p WHERE p.activity_id = a.id) AS photo_count
                FROM daily_activities a
                JOIN daily_shifts s ON a.shift_id = s.id
                LEFT JOIN users u ON a.user_id = u.id
                WHERE strftime('%Y-%m', s.shift_date) = ?
                ORDER BY s.shift_date ASC, 
                         CASE WHEN s.shift_type = 'oh' THEN 1 WHEN s.shift_type = 'pagi' THEN 2 ELSE 3 END,
                         a.activity_time ASC, a.id ASC
            ";
        } else {
            $tQuery = "
                SELECT 
                    u.id AS user_id, 
                    u.full_name, 
                    u.username, 
                    u.role,
                    COUNT(DISTINCT s.id) AS total_shifts,
                    COUNT(DISTINCT a.id) AS total_activities,
                    COUNT(p.id) AS total_photos
                FROM users u
                LEFT JOIN daily_shifts s ON s.user_id = u.id AND DATE_FORMAT(s.shift_date, '%Y-%m') = ?
                LEFT JOIN daily_activities a ON a.shift_id = s.id
                LEFT JOIN daily_activity_photos p ON p.activity_id = a.id
                GROUP BY u.id
                ORDER BY total_activities DESC, total_shifts DESC, u.full_name ASC
            ";
            $maQuery = "
                SELECT a.*, s.shift_date, s.shift_type, s.status AS shift_status, s.technician_name,
                       u.full_name AS user_full_name,
                       (SELECT COUNT(*) FROM daily_activity_photos p WHERE p.activity_id = a.id) AS photo_count
                FROM daily_activities a
                JOIN daily_shifts s ON a.shift_id = s.id
                LEFT JOIN users u ON a.user_id = u.id
                WHERE DATE_FORMAT(s.shift_date, '%Y-%m') = ?
                ORDER BY s.shift_date ASC, 
                         CASE WHEN s.shift_type = 'oh' THEN 1 WHEN s.shift_type = 'pagi' THEN 2 ELSE 3 END,
                         a.activity_time ASC, a.id ASC
            ";
        }

        $tStmt = $pdo->prepare($tQuery);
        $tStmt->execute([$filterMonth]);
        $techStats = $tStmt->fetchAll();

        $maStmt = $pdo->prepare($maQuery);
        $maStmt->execute([$filterMonth]);
        $monthlyActivities = $maStmt->fetchAll();
    } catch (Exception $e) {}
}

include __DIR__ . '/includes/header.php';
?>

<div class="container py-4">

  <!-- Breadcrumb & Top Bar -->
  <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem;">
    <div>
      <div style="font-size: 0.8rem; color: var(--text-subtle); display: flex; align-items: center; gap: 6px;">
        <a href="./" style="color: inherit; text-decoration: none;">Dashboard</a>
        <span>/</span>
        <span style="color: var(--primary); font-weight: 600;">Rekap &amp; Laporan Harian NOC</span>
      </div>
      <h2 style="font-size: 1.45rem; font-weight: 800; color: var(--text-main); margin-top: 0.25rem; letter-spacing: -0.02em; display: flex; align-items: center; gap: 8px;">
        <?= app_icon('document', 22, 'text-primary') ?>
        <span>Pusat Laporan &amp; Rekapitulasi NOC Seluruh Tim</span>
      </h2>
      <p style="font-size: 0.82rem; color: var(--text-subtle); margin: 0.2rem 0 0 0;">
        Pusat audit laporan harian shift dan rekapitulasi logbook bulanan gabungan seluruh anggota teknisi SBU ICT KNO.
      </p>
    </div>

    <!-- Quick Action Buttons -->
    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
      <a href="print_monthly_logbook?month=<?= urlencode($filterMonth) ?>" target="_blank" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;" title="Buka Pratinjau Rekap Bulanan Semua Anggota">
        <?= app_icon('calendar', 14) ?>
        <span>Format Cetak Rekap Bulanan</span>
      </a>
      <a href="download_pdf?month=<?= urlencode($filterMonth) ?>" class="btn btn-sm btn-primary-soft" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;" title="Unduh langsung file PDF Rekap Bulanan Semua Anggota (Vector HD)">
        <?= app_icon('download', 14) ?>
        <span>Unduh PDF Rekap Bulanan</span>
      </a>
      <a href="daily_activity" class="btn btn-primary btn-sm" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 700;">
        <?= app_icon('plus', 14) ?>
        <span>Input Kegiatan Shift</span>
      </a>
    </div>
  </div>

  <!-- Summary Statistics Grid -->
  <div class="stats-grid mb-4" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    <div class="card stat-filter-card" style="margin-bottom: 0; border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden; background: var(--bg-card);">
      <div class="card-body" style="display: flex; align-items: center; justify-content: space-between; padding: 1.15rem 1.25rem;">
        <div>
          <span style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; letter-spacing: 0.03em;">Total Sesi Shift Tim</span>
          <p style="font-size: 1.65rem; font-weight: 800; color: var(--primary); margin: 0.2rem 0 0.15rem 0;"><?= (int)$stats['total_shifts'] ?></p>
          <span style="font-size: 0.72rem; color: var(--text-subtle);">Periode <?= htmlspecialchars($periodLabel) ?></span>
        </div>
        <div class="stat-icon-wrapper" style="width: 44px; height: 44px; border-radius: 12px; background: var(--primary-subtle); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
          <?= app_icon('clock', 22) ?>
        </div>
      </div>
    </div>

    <div class="card stat-filter-card" style="margin-bottom: 0; border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden; background: var(--bg-card);">
      <div class="card-body" style="display: flex; align-items: center; justify-content: space-between; padding: 1.15rem 1.25rem;">
        <div>
          <span style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; letter-spacing: 0.03em;">Total Kegiatan Tercatat</span>
          <p style="font-size: 1.65rem; font-weight: 800; color: #0284c7; margin: 0.2rem 0 0.15rem 0;"><?= (int)$stats['total_month_activities'] ?></p>
          <span style="font-size: 0.72rem; color: var(--text-subtle);">Aktivitas seluruh anggota tim</span>
        </div>
        <div class="stat-icon-wrapper" style="width: 44px; height: 44px; border-radius: 12px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
          <?= app_icon('file-text', 22) ?>
        </div>
      </div>
    </div>

    <div class="card stat-filter-card" style="margin-bottom: 0; border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden; background: var(--bg-card);">
      <div class="card-body" style="display: flex; align-items: center; justify-content: space-between; padding: 1.15rem 1.25rem;">
        <div>
          <span style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; letter-spacing: 0.03em;">Foto Dokumentasi</span>
          <p style="font-size: 1.65rem; font-weight: 800; color: #16a34a; margin: 0.2rem 0 0.15rem 0;"><?= (int)$stats['total_month_photos'] ?></p>
          <span style="font-size: 0.72rem; color: var(--text-subtle);">Dokumentasi visual terverifikasi</span>
        </div>
        <div class="stat-icon-wrapper" style="width: 44px; height: 44px; border-radius: 12px; background: #dcfce7; color: #16a34a; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
          <?= app_icon('camera', 22) ?>
        </div>
      </div>
    </div>

    <div class="card stat-filter-card" style="margin-bottom: 0; border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden; background: var(--bg-card);">
      <div class="card-body" style="display: flex; align-items: center; justify-content: space-between; padding: 1.15rem 1.25rem;">
        <div>
          <span style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; letter-spacing: 0.03em;">Teknisi Berpartisipasi</span>
          <p style="font-size: 1.65rem; font-weight: 800; color: #7c3aed; margin: 0.2rem 0 0.15rem 0;"><?= (int)$stats['active_techs'] ?></p>
          <span style="font-size: 0.72rem; color: var(--text-subtle);"><?= (int)$stats['completed_shifts'] ?> sesi selesai &bull; <?= (int)$stats['active_shifts'] ?> aktif</span>
        </div>
        <div class="stat-icon-wrapper" style="width: 44px; height: 44px; border-radius: 12px; background: #ede9fe; color: #7c3aed; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
          <?= app_icon('users', 22) ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Primary Tab Navigation (Executive Segmented Control) -->
  <div style="display: flex; gap: 8px; background: var(--bg-alt); padding: 5px; border-radius: var(--radius-md); border: 1px solid var(--border); margin-bottom: 1.5rem; width: fit-content; max-width: 100%; overflow-x: auto;">
    <a href="daily_reports?tab=shifts&month=<?= urlencode($filterMonth) ?>" style="display: inline-flex; align-items: center; gap: 8px; padding: 0.55rem 1.25rem; border-radius: var(--radius-sm); font-size: 0.84rem; font-weight: 700; text-decoration: none; transition: all 0.2s ease; <?= ($activeTab === 'shifts') ? 'background: var(--bg-card); color: var(--primary); box-shadow: var(--shadow-sm); border: 1px solid var(--border);' : 'color: var(--text-muted);' ?>">
      <?= app_icon('clock', 16, ($activeTab === 'shifts') ? 'text-primary' : '') ?>
      <span>Daftar Laporan Shift Harian</span>
      <span style="padding: 2px 8px; border-radius: 9999px; font-size: 0.72rem; font-weight: 700; <?= ($activeTab === 'shifts') ? 'background: var(--primary-subtle); color: var(--primary);' : 'background: var(--border); color: var(--text-subtle);' ?>">
        <?= count($shifts) ?> Sesi
      </span>
    </a>
    <a href="daily_reports?tab=monthly&month=<?= urlencode($filterMonth) ?>" style="display: inline-flex; align-items: center; gap: 8px; padding: 0.55rem 1.25rem; border-radius: var(--radius-sm); font-size: 0.84rem; font-weight: 700; text-decoration: none; transition: all 0.2s ease; <?= ($activeTab === 'monthly') ? 'background: var(--bg-card); color: var(--primary); box-shadow: var(--shadow-sm); border: 1px solid var(--border);' : 'color: var(--text-muted);' ?>">
      <?= app_icon('document', 16, ($activeTab === 'monthly') ? 'text-primary' : '') ?>
      <span>Rekap Bulanan Semua Anggota (Logbook Gabungan)</span>
      <span style="padding: 2px 8px; border-radius: 9999px; font-size: 0.72rem; font-weight: 700; <?= ($activeTab === 'monthly') ? 'background: var(--primary-subtle); color: var(--primary);' : 'background: var(--border); color: var(--text-subtle);' ?>">
        <?= htmlspecialchars($periodLabel) ?>
      </span>
    </a>
  </div>

  <?php if ($activeTab === 'shifts'): ?>
    <!-- TAB 1: DAFTAR LAPORAN SHIFT HARIAN -->

    <!-- Filter Card -->
    <div style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.15rem 1.35rem; margin-bottom: 1.5rem; box-shadow: var(--shadow-sm);">
      <form method="GET" action="daily_reports" style="display: flex; flex-direction: column; gap: 1rem;">
        <input type="hidden" name="tab" value="shifts">
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.85rem; align-items: flex-end;">
          <!-- Filter Bulan -->
          <div>
            <label style="font-size: 0.76rem; font-weight: 700; color: var(--text-subtle); margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
              <?= app_icon('calendar', 12) ?>
              <span>Periode Bulan</span>
            </label>
            <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($filterMonth) ?>" style="min-height: 38px;">
          </div>

          <!-- Filter Tanggal Spesifik -->
          <div>
            <label style="font-size: 0.76rem; font-weight: 700; color: var(--text-subtle); margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
              <?= app_icon('calendar', 12) ?>
              <span>Tanggal Spesifik</span>
            </label>
            <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($filterDate) ?>" style="min-height: 38px;">
          </div>

          <!-- Filter Jenis Shift -->
          <div>
            <label style="font-size: 0.76rem; font-weight: 700; color: var(--text-subtle); margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
              <?= app_icon('clock', 12) ?>
              <span>Kategori Shift</span>
            </label>
            <select name="shift_type" class="form-control" style="min-height: 38px; font-weight: 600;">
              <option value="">Semua Shift</option>
              <option value="oh" <?= ($filterShiftType === 'oh') ? 'selected' : '' ?>>Office Hour (OH)</option>
              <option value="pagi" <?= ($filterShiftType === 'pagi') ? 'selected' : '' ?>>Shift Pagi</option>
              <option value="malam" <?= ($filterShiftType === 'malam') ? 'selected' : '' ?>>Shift Malam</option>
            </select>
          </div>

          <!-- Filter Teknisi (Admin only) -->
          <?php if ($isAdmin): ?>
          <div>
            <label style="font-size: 0.76rem; font-weight: 700; color: var(--text-subtle); margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
              <?= app_icon('user', 12) ?>
              <span>Teknisi Jaga</span>
            </label>
            <select name="user_id" class="form-control" style="min-height: 38px;">
              <option value="0">Semua Teknisi</option>
              <?php foreach ($technicians as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= ($filterUserId === (int)$t['id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($t['full_name'] ?: $t['username']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <!-- Filter Status -->
          <div>
            <label style="font-size: 0.76rem; font-weight: 700; color: var(--text-subtle); margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
              <?= app_icon('check-circle', 12) ?>
              <span>Status Shift</span>
            </label>
            <select name="status" class="form-control" style="min-height: 38px;">
              <option value="">Semua Status</option>
              <option value="active" <?= ($filterStatus === 'active') ? 'selected' : '' ?>>Sedang Berjalan (Aktif)</option>
              <option value="completed" <?= ($filterStatus === 'completed') ? 'selected' : '' ?>>Selesai &amp; Terkunci</option>
            </select>
          </div>
        </div>

        <!-- Buttons -->
        <div style="display: flex; justify-content: flex-end; align-items: center; gap: 8px; padding-top: 0.75rem; border-top: 1px dashed var(--border);">
          <a href="daily_reports?tab=shifts" class="btn btn-secondary btn-sm" style="padding: 0.45rem 1rem; display: inline-flex; align-items: center; gap: 6px;">
            <?= app_icon('refresh', 13) ?>
            <span>Reset Filter</span>
          </a>
          <button type="submit" class="btn btn-primary btn-sm" style="padding: 0.45rem 1.35rem; display: inline-flex; align-items: center; gap: 6px; font-weight: 700;">
            <?= app_icon('filter', 13) ?>
            <span>Terapkan Filter</span>
          </button>
        </div>

      </form>
    </div>

    <!-- Shifts List Card -->
    <div class="card" style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden;">
      
      <div class="card-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; padding: 1rem 1.25rem; background: var(--bg-alt); border-bottom: 1px solid var(--border);">
        <div>
          <h4 style="font-size: 1.05rem; font-weight: 800; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 6px;">
            <?= app_icon('clock', 16, 'text-primary') ?>
            <span>Daftar Sesi Shift Logbook (<?= count($shifts) ?> Sesi Ditemukan)</span>
          </h4>
          <span style="font-size: 0.75rem; color: var(--text-subtle);">
            Menampilkan riwayat sesi shift harian seluruh anggota tim
          </span>
        </div>

        <div style="font-size: 0.78rem; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 5px;">
          <?= app_icon('calendar', 13) ?>
          <span>Periode: <?= htmlspecialchars($periodLabel) ?></span>
        </div>
      </div>

      <?php if (empty($shifts)): ?>
        <div class="p-5 text-center">
          <div style="margin-bottom: 0.75rem; color: var(--text-subtle);">
            <?= app_icon('document', 42) ?>
          </div>
          <h4 style="font-size: 1.1rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.25rem;">Tidak Ada Sesi Shift</h4>
          <p style="font-size: 0.85rem; color: var(--text-subtle); max-width: 450px; margin: 0 auto 1.25rem auto;">
            Belum ada catatan sesi shift untuk filter yang dipilih. Silakan ubah filter periode atau buka sesi shift baru.
          </p>
          <a href="daily_activity" class="btn btn-primary btn-sm" style="display: inline-flex; align-items: center; gap: 6px;">
            <?= app_icon('plus', 14) ?>
            <span>Buka Sesi Shift Baru</span>
          </a>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table mb-0" style="width: 100%; border-collapse: collapse;">
            <thead>
              <tr style="background: var(--bg-alt); font-size: 0.78rem; font-weight: 800; color: var(--text-subtle); text-transform: uppercase;">
                <th style="padding: 0.75rem 1rem; width: 40px; text-align: center;">No</th>
                <th style="padding: 0.75rem 1rem;">Tanggal &amp; Waktu</th>
                <th style="padding: 0.75rem 1rem;">Jenis Shift</th>
                <th style="padding: 0.75rem 1rem;">Teknisi Jaga</th>
                <th style="padding: 0.75rem 1rem; text-align: center;">Kegiatan</th>
                <th style="padding: 0.75rem 1rem; text-align: center;">Foto</th>
                <th style="padding: 0.75rem 1rem; text-align: center;">Status</th>
                <th style="padding: 0.75rem 1rem; text-align: right; width: 220px;">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php $no = 1; foreach ($shifts as $s): 
                $sInfo = get_shift_info($s['shift_type']);
                $tech = $s['technician_name'] ?: ($s['user_full_name'] ?: $s['username']);
                $initials = strtoupper(substr($tech, 0, 2));
              ?>
                <tr style="border-top: 1px solid var(--border); font-size: 0.84rem;">
                  <td style="padding: 0.85rem 1rem; text-align: center; color: var(--text-subtle); font-weight: 700;">
                    <?= $no++ ?>
                  </td>
                  <td style="padding: 0.85rem 1rem;">
                    <div style="font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 5px;">
                      <?= app_icon('calendar', 13, 'text-primary') ?>
                      <span><?= date('d M Y', strtotime($s['shift_date'])) ?></span>
                    </div>
                    <div style="font-size: 0.74rem; color: var(--text-subtle); margin-top: 2px;">
                      Dibuat: <?= date('H:i', strtotime($s['created_at'])) ?> WIB
                      <?php if (!empty($s['completed_at'])): ?>
                        &bull; Selesai: <?= date('H:i', strtotime($s['completed_at'])) ?> WIB
                      <?php endif; ?>
                    </div>
                  </td>
                  <td style="padding: 0.85rem 1rem;">
                    <?= render_shift_badge($s['shift_type'], true, $sInfo['time']) ?>
                  </td>
                  <td style="padding: 0.85rem 1rem;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                      <div style="width: 30px; height: 30px; border-radius: 50%; background: var(--primary-subtle); color: var(--primary); font-size: 0.72rem; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                        <?= $initials ?>
                      </div>
                      <div>
                        <div style="font-weight: 700; color: var(--text-main);"><?= htmlspecialchars($tech) ?></div>
                        <div style="font-size: 0.72rem; color: var(--text-subtle);"><?= htmlspecialchars($s['user_role'] ?? 'Teknisi') ?></div>
                      </div>
                    </div>
                  </td>
                  <td style="padding: 0.85rem 1rem; text-align: center;">
                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 9999px; background: var(--primary-subtle); color: var(--primary); font-weight: 700; font-size: 0.78rem;">
                      <?= app_icon('activity', 12) ?>
                      <span><?= (int)$s['total_activities'] ?></span>
                    </span>
                  </td>
                  <td style="padding: 0.85rem 1rem; text-align: center;">
                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 9999px; background: #e0f2fe; color: #0284c7; font-weight: 700; font-size: 0.78rem;">
                      <?= app_icon('camera', 12) ?>
                      <span><?= (int)$s['total_photos'] ?></span>
                    </span>
                  </td>
                  <td style="padding: 0.85rem 1rem; text-align: center;">
                    <?php if ($s['status'] === 'completed'): ?>
                      <span class="sla-status-pill sla-normal" style="font-size: 0.72rem; display: inline-flex; align-items: center; gap: 4px;">
                        <?= app_icon('check', 11) ?> Selesai
                      </span>
                    <?php else: ?>
                      <span class="sla-status-pill sla-warning" style="font-size: 0.72rem; display: inline-flex; align-items: center; gap: 4px;">
                        <?= app_icon('clock', 11) ?> Aktif
                      </span>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 0.85rem 1rem; text-align: right;">
                    <div style="display: inline-flex; align-items: center; gap: 4px;">
                      <a href="daily_activity?shift_id=<?= (int)$s['id'] ?>" class="btn btn-secondary btn-sm" style="padding: 0.25rem 0.6rem; font-size: 0.75rem; display: inline-flex; align-items: center; gap: 4px;" title="Buka Detail Logbook Shift">
                        <?= app_icon('eye', 12) ?>
                        <span>Buka</span>
                      </a>
                      <a href="print_shift?shift_id=<?= (int)$s['id'] ?>" target="_blank" class="btn btn-secondary btn-sm" style="padding: 0.25rem 0.6rem; font-size: 0.75rem; display: inline-flex; align-items: center; gap: 4px;" title="Lihat Pratinjau Format Cetak PDF">
                        <?= app_icon('file-text', 12) ?>
                        <span>PDF</span>
                      </a>
                      <a href="download_pdf?shift_id=<?= (int)$s['id'] ?>" class="btn btn-sm" style="background: #0284c7; color: #ffffff; padding: 0.25rem 0.6rem; font-size: 0.75rem; display: inline-flex; align-items: center; gap: 4px;" title="Unduh File PDF Resmi Vector HD">
                        <?= app_icon('download', 12) ?>
                        <span>Unduh</span>
                      </a>
                      <?php if (has_role('superadmin')): ?>
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteReportShift(<?= (int)$s['id'] ?>, '<?= htmlspecialchars(addslashes($tech)) ?>', '<?= date('d/m/Y', strtotime($s['shift_date'])) ?>', this)" style="padding: 0.25rem 0.6rem; font-size: 0.75rem; display: inline-flex; align-items: center; gap: 4px;" title="Hapus seluruh laporan sesi shift ini (Khusus Superadmin)">
                          <?= app_icon('trash', 12) ?>
                          <span>Hapus</span>
                        </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

    </div>

  <?php else: ?>
    <!-- TAB 2: REKAP BULANAN SEMUA ANGGOTA (LOGBOOK GABUNGAN) -->

    <!-- Month Picker Card for Monthly Recap -->
    <div style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.15rem 1.35rem; margin-bottom: 1.5rem; box-shadow: var(--shadow-sm);">
      <form method="GET" action="daily_reports" style="display: flex; align-items: flex-end; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
        <input type="hidden" name="tab" value="monthly">
        
        <div style="flex: 1; min-width: 240px; max-width: 340px;">
          <label style="font-size: 0.76rem; font-weight: 700; color: var(--text-subtle); margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
            <?= app_icon('calendar', 12) ?>
            <span>Pilih Periode Rekap Bulanan</span>
          </label>
          <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($filterMonth) ?>" style="min-height: 38px;">
        </div>

        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
          <button type="submit" class="btn btn-primary btn-sm" style="padding: 0.5rem 1.25rem; display: inline-flex; align-items: center; gap: 6px; font-weight: 700;">
            <?= app_icon('filter', 13) ?>
            <span>Tampilkan Periode Ini</span>
          </button>
          <a href="print_monthly_logbook?month=<?= urlencode($filterMonth) ?>" target="_blank" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 6px;">
            <?= app_icon('calendar', 14) ?>
            <span>Format Cetak PDF</span>
          </a>
          <a href="download_pdf?month=<?= urlencode($filterMonth) ?>" class="btn btn-sm" style="background: #0284c7; color: #ffffff; display: inline-flex; align-items: center; gap: 6px; font-weight: 700;">
            <?= app_icon('download', 14) ?>
            <span>Unduh PDF Resmi</span>
          </a>
        </div>
      </form>
    </div>

    <!-- Team Members Contribution Table -->
    <div class="card mb-4" style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden;">
      <div class="card-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; padding: 1rem 1.25rem; background: var(--bg-alt); border-bottom: 1px solid var(--border);">
        <div>
          <h4 style="font-size: 1.05rem; font-weight: 800; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 6px;">
            <?= app_icon('users', 16, 'text-primary') ?>
            <span>Partisipasi &amp; Kontribusi Seluruh Anggota Tim</span>
          </h4>
          <span style="font-size: 0.75rem; color: var(--text-subtle);">
            Distribusi sesi shift, pencatatan kegiatan, dan dokumentasi foto per anggota di bulan <?= htmlspecialchars($periodLabel) ?>
          </span>
        </div>
        <div style="font-size: 0.78rem; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 5px;">
          <?= app_icon('users', 13) ?>
          <span><?= count($techStats) ?> Anggota Terdaftar</span>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table mb-0" style="width: 100%; border-collapse: collapse;">
          <thead>
            <tr style="background: var(--bg-alt); font-size: 0.78rem; font-weight: 800; color: var(--text-subtle); text-transform: uppercase;">
              <th style="padding: 0.75rem 1rem; width: 40px; text-align: center;">No</th>
              <th style="padding: 0.75rem 1rem;">Nama Anggota Tim</th>
              <th style="padding: 0.75rem 1rem;">Role / Hak Akses</th>
              <th style="padding: 0.75rem 1rem; text-align: center;">Jumlah Shift</th>
              <th style="padding: 0.75rem 1rem; text-align: center;">Total Kegiatan</th>
              <th style="padding: 0.75rem 1rem; text-align: center;">Foto Terlampir</th>
              <th style="padding: 0.75rem 1rem; text-align: center;">Kontribusi</th>
              <th style="padding: 0.75rem 1rem; text-align: right; width: 140px;">Filter Shift</th>
            </tr>
          </thead>
          <tbody>
            <?php 
            $noT = 1; 
            $totalMonthAct = max(1, (int)$stats['total_month_activities']);
            foreach ($techStats as $ts): 
              $tName = $ts['full_name'] ?: $ts['username'];
              $tInitials = strtoupper(substr($tName, 0, 2));
              $actCount = (int)$ts['total_activities'];
              $pct = round(($actCount / $totalMonthAct) * 100, 1);
            ?>
              <tr style="border-top: 1px solid var(--border); font-size: 0.84rem;">
                <td style="padding: 0.85rem 1rem; text-align: center; color: var(--text-subtle); font-weight: 700;">
                  <?= $noT++ ?>
                </td>
                <td style="padding: 0.85rem 1rem;">
                  <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--primary-subtle); color: var(--primary); font-size: 0.75rem; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                      <?= $tInitials ?>
                    </div>
                    <div>
                      <div style="font-weight: 700; color: var(--text-main);"><?= htmlspecialchars($tName) ?></div>
                      <div style="font-size: 0.72rem; color: var(--text-subtle);">@<?= htmlspecialchars($ts['username']) ?></div>
                    </div>
                  </div>
                </td>
                <td style="padding: 0.85rem 1rem;">
                  <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.72rem; font-weight: 700; background: var(--bg-alt); color: var(--text-subtle); text-transform: uppercase;">
                    <?= htmlspecialchars($ts['role']) ?>
                  </span>
                </td>
                <td style="padding: 0.85rem 1rem; text-align: center; font-weight: 700;">
                  <span style="display: inline-flex; align-items: center; gap: 4px;">
                    <?= app_icon('clock', 12, 'text-primary') ?>
                    <span><?= (int)$ts['total_shifts'] ?> sesi</span>
                  </span>
                </td>
                <td style="padding: 0.85rem 1rem; text-align: center;">
                  <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 9999px; background: var(--primary-subtle); color: var(--primary); font-weight: 700; font-size: 0.78rem;">
                    <?= app_icon('document', 12) ?>
                    <span><?= $actCount ?></span>
                  </span>
                </td>
                <td style="padding: 0.85rem 1rem; text-align: center;">
                  <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 9999px; background: #e0f2fe; color: #0284c7; font-weight: 700; font-size: 0.78rem;">
                    <?= app_icon('camera', 12) ?>
                    <span><?= (int)$ts['total_photos'] ?></span>
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
                <td style="padding: 0.85rem 1rem; text-align: right;">
                  <a href="daily_reports?tab=shifts&month=<?= urlencode($filterMonth) ?>&user_id=<?= (int)$ts['user_id'] ?>" class="btn btn-secondary btn-sm" style="padding: 0.25rem 0.65rem; font-size: 0.74rem; display: inline-flex; align-items: center; gap: 4px;" title="Lihat semua shift oleh anggota ini">
                    <?= app_icon('filter', 11) ?>
                    <span>Filter</span>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Chronological Combined Monthly Logbook Table -->
    <div class="card" style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden;">
      <div class="card-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; padding: 1rem 1.25rem; background: var(--bg-alt); border-bottom: 1px solid var(--border);">
        <div>
          <h4 style="font-size: 1.05rem; font-weight: 800; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 6px;">
            <?= app_icon('file-text', 16, 'text-primary') ?>
            <span>Logbook Kegiatan Gabungan Seluruh Anggota (<?= count($monthlyActivities) ?> Kegiatan)</span>
          </h4>
          <span style="font-size: 0.75rem; color: var(--text-subtle);">
            Rekap kronologis seluruh aktivitas operasional NOC sepanjang bulan <?= htmlspecialchars($periodLabel) ?>
          </span>
        </div>

        <div style="display: flex; align-items: center; gap: 8px;">
          <a href="print_monthly_logbook?month=<?= urlencode($filterMonth) ?>" target="_blank" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 4px;">
            <?= app_icon('calendar', 13) ?>
            <span>Format Cetak PDF</span>
          </a>
          <a href="download_pdf?month=<?= urlencode($filterMonth) ?>" class="btn btn-sm" style="background: #0284c7; color: #ffffff; display: inline-flex; align-items: center; gap: 4px;">
            <?= app_icon('download', 13) ?>
            <span>Unduh Dokumen PDF</span>
          </a>
        </div>
      </div>

      <?php if (empty($monthlyActivities)): ?>
        <div class="p-5 text-center">
          <div style="margin-bottom: 0.75rem; color: var(--text-subtle);">
            <?= app_icon('document', 42) ?>
          </div>
          <h4 style="font-size: 1.1rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.25rem;">Belum Ada Rekap Kegiatan Bulan Ini</h4>
          <p style="font-size: 0.85rem; color: var(--text-subtle); max-width: 450px; margin: 0 auto 1.25rem auto;">
            Belum ada kegiatan yang dicatat oleh anggota tim pada periode bulan <?= htmlspecialchars($periodLabel) ?>.
          </p>
          <a href="daily_activity" class="btn btn-primary btn-sm" style="display: inline-flex; align-items: center; gap: 6px;">
            <?= app_icon('plus', 14) ?>
            <span>Mulai Catat Kegiatan Shift</span>
          </a>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table mb-0" style="width: 100%; border-collapse: collapse;">
            <thead>
              <tr style="background: var(--bg-alt); font-size: 0.78rem; font-weight: 800; color: var(--text-subtle); text-transform: uppercase;">
                <th style="padding: 0.75rem 1rem; width: 40px; text-align: center;">No</th>
                <th style="padding: 0.75rem 1rem;">Tanggal &amp; Shift</th>
                <th style="padding: 0.75rem 1rem;">Waktu</th>
                <th style="padding: 0.75rem 1rem;">Pelaksana Jaga</th>
                <th style="padding: 0.75rem 1rem;">Kategori &amp; Lokasi</th>
                <th style="padding: 0.75rem 1rem;">Uraian Pekerjaan / Penanganan</th>
                <th style="padding: 0.75rem 1rem; text-align: center;">Foto</th>
                <th style="padding: 0.75rem 1rem; text-align: center;">Status</th>
                <th style="padding: 0.75rem 1rem; text-align: right; width: 90px;">Detail</th>
              </tr>
            </thead>
            <tbody>
              <?php $noM = 1; foreach ($monthlyActivities as $ma): 
                $sInfo = get_shift_info($ma['shift_type']);
                $techM = $ma['technician_name'] ?: ($ma['user_full_name'] ?: 'Teknisi');
                $catM = strtolower($ma['category']);
                $catClass = 'cat-' . $catM;
                if (!in_array($catM, ['monitoring', 'patroli', 'troubleshooting', 'maintenance', 'administrasi'])) {
                    $catClass = 'cat-lainnya';
                }
              ?>
                <tr style="border-top: 1px solid var(--border); font-size: 0.82rem;">
                  <td style="padding: 0.75rem 1rem; text-align: center; color: var(--text-subtle); font-weight: 700;">
                    <?= $noM++ ?>
                  </td>
                  <td style="padding: 0.75rem 1rem;">
                    <div style="font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 4px;">
                      <?= app_icon('calendar', 12, 'text-primary') ?>
                      <span><?= date('d M Y', strtotime($ma['shift_date'])) ?></span>
                    </div>
                    <div style="margin-top: 2px;">
                      <?= render_shift_badge($ma['shift_type']) ?>
                    </div>
                  </td>
                  <td style="padding: 0.75rem 1rem; font-weight: 700; color: var(--text-main); white-space: nowrap;">
                    <?php
                      $cleanTime = trim($ma['activity_time']);
                      if (!preg_match('/wib$/i', $cleanTime)) {
                          $cleanTime .= ' WIB';
                      }
                      echo htmlspecialchars($cleanTime);
                    ?>
                  </td>
                  <td style="padding: 0.75rem 1rem;">
                    <span style="font-weight: 600; color: var(--text-main);"><?= htmlspecialchars($techM) ?></span>
                  </td>
                  <td style="padding: 0.75rem 1rem;">
                    <span class="cat-badge <?= $catClass ?>" style="font-size: 0.7rem; padding: 2px 6px;">
                      <?= htmlspecialchars($ma['category']) ?>
                    </span>
                    <div style="font-size: 0.74rem; color: var(--text-subtle); margin-top: 3px; display: flex; align-items: center; gap: 3px;">
                      <?= app_icon('map-pin', 12, 'text-subtle') ?>
                      <span><?= htmlspecialchars($ma['location'] ?: 'Area Kerja KNO') ?></span>
                    </div>
                  </td>
                  <td style="padding: 0.75rem 1rem; max-width: 320px;">
                    <div style="font-weight: 700; color: var(--text-main); margin-bottom: 2px;">
                      <?= htmlspecialchars($ma['title']) ?>
                    </div>
                    <?php if (!empty($ma['description'])): ?>
                      <div style="font-size: 0.76rem; color: var(--text-subtle); line-height: 1.35;">
                        <?= nl2br(htmlspecialchars($ma['description'])) ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 0.75rem 1rem; text-align: center;">
                    <?php if ((int)$ma['photo_count'] > 0): ?>
                      <span style="display: inline-flex; align-items: center; gap: 3px; padding: 2px 7px; border-radius: 9999px; background: #dcfce7; color: #16a34a; font-weight: 700; font-size: 0.72rem;">
                        <?= app_icon('camera', 11) ?>
                        <span><?= (int)$ma['photo_count'] ?></span>
                      </span>
                    <?php else: ?>
                      <span style="color: var(--text-subtle); font-size: 0.72rem;">-</span>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 0.75rem 1rem; text-align: center;">
                    <?php if ($ma['shift_status'] === 'completed'): ?>
                      <span class="sla-status-pill sla-normal" style="font-size: 0.7rem; display: inline-flex; align-items: center; gap: 3px;">
                        <?= app_icon('check', 10) ?> Selesai
                      </span>
                    <?php else: ?>
                      <span class="sla-status-pill sla-warning" style="font-size: 0.7rem; display: inline-flex; align-items: center; gap: 3px;">
                        <?= app_icon('clock', 10) ?> Shift Berjalan
                      </span>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 0.75rem 1rem; text-align: right;">
                    <a href="daily_activity?shift_id=<?= (int)$ma['shift_id'] ?>" class="btn btn-secondary btn-sm" style="padding: 0.2rem 0.55rem; font-size: 0.72rem; display: inline-flex; align-items: center; gap: 3px;" title="Buka Detail Sesi Shift">
                      <?= app_icon('eye', 11) ?>
                      <span>Buka</span>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

    </div>

  <?php endif; ?>

</div>

<script>
function deleteReportShift(shiftId, tech, shiftDate, btn) {
  var msg = 'PERINGATAN SUPERADMIN:\nApakah Anda yakin ingin menghapus laporan sesi shift teknisi ' + tech + ' tanggal ' + shiftDate + '?\n\nSemua data kegiatan dan dokumentasi foto di dalamnya akan dihapus permanen.';
  if (!confirm(msg)) {
    return;
  }
  btn.disabled = true;
  var fd = new FormData();
  fd.append('shift_id', shiftId);

  fetch('api/delete_daily_shift.php', {
    method: 'POST',
    body: fd
  })
  .then(function(res) { return res.json(); })
  .then(function(data) {
    if (data.success) {
      alert(data.message);
      var tr = btn.closest('tr');
      if (tr) {
        tr.remove();
      } else {
        window.location.reload();
      }
    } else {
      btn.disabled = false;
      alert('Gagal: ' + (data.message || 'Terjadi kesalahan.'));
    }
  })
  .catch(function(err) {
    btn.disabled = false;
    alert('Error: ' + err.message);
  });
}
</script>

<?php
include __DIR__ . '/includes/footer.php';
?>
