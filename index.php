<?php
/**
 * Dashboard & Pusat Kendali Operasional
 * IASS SBU ICT KNO
 *
 * Pemisahan Antarmuka:
 * - Teknisi Lapangan (User): Fokus operasional hari ini, shift aktif, logbook, dan personal stats.
 * - Administrator & Superadmin: Fokus pengawasan tim, live monitoring lapangan, rekapitulasi, dan analitik PM/SLA.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/redis.php';
require_once __DIR__ . '/includes/auth.php';

if (!is_logged_in()) {
    require __DIR__ . '/login.php';
    exit;
}

$pdo = get_db_connection();
$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$isAdmin = has_role(['superadmin', 'admin']);
$isSuperadmin = has_role('superadmin');
$page_title = $isAdmin ? 'Dashboard Eksekutif & Tim' : 'Dashboard Operasional Teknisi';

$curMonth = date('Y-m');
$monthParam = $curMonth . '%';
$todayDate = date('Y-m-d');

// 1. Data Umum: Laporan PM (dengan Redis Cache)
$cachedReports = AppRedis::get('dashboard:reports');
if ($cachedReports !== null && is_array($cachedReports)) {
    $reports = $cachedReports;
} else {
    $stmt = $pdo->query("
        SELECT r.*, s.site_name, s.location_detail, s.region
        FROM pm_reports r
        LEFT JOIN sites s ON r.site_id = s.id
        ORDER BY r.id DESC
    ");
    $reports = $stmt->fetchAll();
    AppRedis::set('dashboard:reports', $reports, 60);
}

// Hitung total PM Indoor & Outdoor
$totalReports = count($reports);
$indoorCount = 0;
$outdoorCount = 0;
foreach ($reports as $r) {
    if ($r['report_type'] === 'indoor') $indoorCount++;
    if ($r['report_type'] === 'outdoor') $outdoorCount++;
}
$siteCount = (int)$pdo->query("SELECT COUNT(*) FROM sites")->fetchColumn();

// 2. Data Khusus Teknisi Lapangan (Non-Admin)
$userActiveShift = null;
$myStats = ['total_my_shifts' => 0, 'total_my_activities' => 0, 'total_my_photos' => 0, 'total_my_pms' => 0];
$myRecentShifts = [];

if (!$isAdmin) {
    // A. Cek apakah teknisi memiliki sesi shift aktif hari ini
    $stmtUserActive = $pdo->prepare("
        SELECT s.*, 
               (SELECT COUNT(*) FROM daily_activities a WHERE a.shift_id = s.id) AS total_activities,
               (SELECT COUNT(*) FROM daily_activity_photos p INNER JOIN daily_activities a ON p.activity_id = a.id WHERE a.shift_id = s.id) AS total_photos
        FROM daily_shifts s
        WHERE s.user_id = ? AND s.shift_date = ? AND s.status = 'active'
        ORDER BY s.id DESC LIMIT 1
    ");
    $stmtUserActive->execute([$userId, $todayDate]);
    $userActiveShift = $stmtUserActive->fetch();

    // B. Statistik personal teknisi bulan ini
    $userNameMatch = '%' . ($currentUser['full_name'] ?: $currentUser['username']) . '%';
    $stmtMyStats = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT s.id) AS total_my_shifts,
            (SELECT COUNT(*) FROM daily_activities a INNER JOIN daily_shifts ds ON a.shift_id = ds.id WHERE ds.user_id = ? AND ds.shift_date LIKE ?) AS total_my_activities,
            (SELECT COUNT(*) FROM daily_activity_photos p INNER JOIN daily_activities a ON p.activity_id = a.id INNER JOIN daily_shifts ds ON a.shift_id = ds.id WHERE ds.user_id = ? AND ds.shift_date LIKE ?) AS total_my_photos,
            (SELECT COUNT(*) FROM pm_reports r WHERE (r.inspector_name LIKE ? OR r.inspector_name = ?) AND r.report_date LIKE ?) AS total_my_pms
        FROM daily_shifts s
        WHERE s.user_id = ? AND s.shift_date LIKE ?
    ");
    $stmtMyStats->execute([
        $userId, $monthParam,
        $userId, $monthParam,
        $userNameMatch, $currentUser['username'] ?? '', $monthParam,
        $userId, $monthParam
    ]);
    $myStats = $stmtMyStats->fetch() ?: [
        'total_my_shifts' => 0, 'total_my_activities' => 0, 'total_my_photos' => 0, 'total_my_pms' => 0
    ];

    // C. Riwayat 5 sesi shift terakhir teknisi ini
    $stmtMyRecent = $pdo->prepare("
        SELECT s.*,
               (SELECT COUNT(*) FROM daily_activities a WHERE a.shift_id = s.id) AS total_activities,
               (SELECT COUNT(*) FROM daily_activity_photos p INNER JOIN daily_activities a ON p.activity_id = a.id WHERE a.shift_id = s.id) AS total_photos
        FROM daily_shifts s
        WHERE s.user_id = ?
        ORDER BY s.shift_date DESC, s.id DESC
        LIMIT 5
    ");
    $stmtMyRecent->execute([$userId]);
    $myRecentShifts = $stmtMyRecent->fetchAll();
}

// 3. Data Khusus Administrator & Superadmin
$liveTeamShifts = [];
$nocStats = ['total_shifts' => 0, 'active_shifts' => 0, 'completed_shifts' => 0, 'total_activities' => 0, 'total_photos' => 0];
$topTechs = [];
$monthlyTrend = [];
$operatorShare = [];
$siteHealth = ['normal' => [], 'warning' => [], 'overdue' => []];

if ($isAdmin) {
    // A. Sesi shift tim yang sedang aktif bertugas di lapangan hari ini
    $stmtLiveShifts = $pdo->prepare("
        SELECT s.*, u.full_name AS user_full_name, u.username,
               (SELECT COUNT(*) FROM daily_activities a WHERE a.shift_id = s.id) AS total_activities,
               (SELECT COUNT(*) FROM daily_activity_photos p INNER JOIN daily_activities a ON p.activity_id = a.id WHERE a.shift_id = s.id) AS total_photos
        FROM daily_shifts s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.shift_date = ? AND s.status = 'active'
        ORDER BY s.id DESC
    ");
    $stmtLiveShifts->execute([$todayDate]);
    $liveTeamShifts = $stmtLiveShifts->fetchAll();

    // B. Statistik operasional seluruh tim bulan ini
    $stmtNocMonth = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT s.id) AS total_shifts,
            SUM(CASE WHEN s.status = 'active' THEN 1 ELSE 0 END) AS active_shifts,
            SUM(CASE WHEN s.status = 'completed' THEN 1 ELSE 0 END) AS completed_shifts,
            (SELECT COUNT(*) FROM daily_activities a INNER JOIN daily_shifts ds ON a.shift_id = ds.id WHERE ds.shift_date LIKE ?) AS total_activities,
            (SELECT COUNT(*) FROM daily_activity_photos p INNER JOIN daily_activities a ON p.activity_id = a.id INNER JOIN daily_shifts ds ON a.shift_id = ds.id WHERE ds.shift_date LIKE ?) AS total_photos
        FROM daily_shifts s
        WHERE s.shift_date LIKE ?
    ");
    $stmtNocMonth->execute([$monthParam, $monthParam, $monthParam]);
    $nocStats = $stmtNocMonth->fetch() ?: [
        'total_shifts' => 0, 'active_shifts' => 0, 'completed_shifts' => 0, 'total_activities' => 0, 'total_photos' => 0
    ];

    // C. Leaderboard teknisi aktif bulan ini
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmtLeader = $pdo->prepare("
                SELECT u.id, u.full_name, u.username, u.role,
                       COUNT(DISTINCT s.id) AS shift_count,
                       COUNT(DISTINCT a.id) AS activity_count,
                       COUNT(p.id) AS photo_count
                FROM users u
                LEFT JOIN daily_shifts s ON s.user_id = u.id AND strftime('%Y-%m', s.shift_date) = ?
                LEFT JOIN daily_activities a ON a.shift_id = s.id
                LEFT JOIN daily_activity_photos p ON p.activity_id = a.id
                GROUP BY u.id
                ORDER BY activity_count DESC, shift_count DESC, u.full_name ASC
                LIMIT 5
            ");
        } else {
            $stmtLeader = $pdo->prepare("
                SELECT u.id, u.full_name, u.username, u.role,
                       COUNT(DISTINCT s.id) AS shift_count,
                       COUNT(DISTINCT a.id) AS activity_count,
                       COUNT(p.id) AS photo_count
                FROM users u
                LEFT JOIN daily_shifts s ON s.user_id = u.id AND DATE_FORMAT(s.shift_date, '%Y-%m') = ?
                LEFT JOIN daily_activities a ON a.shift_id = s.id
                LEFT JOIN daily_activity_photos p ON p.activity_id = a.id
                GROUP BY u.id
                ORDER BY activity_count DESC, shift_count DESC, u.full_name ASC
                LIMIT 5
            ");
        }
        $stmtLeader->execute([$curMonth]);
        $topTechs = $stmtLeader->fetchAll();
    } catch (Exception $e) {}

    // D. Tren Pemeliharaan 6 Bulan Terakhir & Porsi Operator
    $indoMonths = ['01'=>'Jan','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'Mei','06'=>'Jun','07'=>'Jul','08'=>'Agu','09'=>'Sep','10'=>'Okt','11'=>'Nov','12'=>'Des'];
    $now = new DateTime();
    for ($i = 5; $i >= 0; $i--) {
        $dt = (clone $now)->modify("-{$i} month");
        $k = $dt->format('Y-m');
        $mNum = $dt->format('m');
        $monthlyTrend[$k] = [
            'key' => $k,
            'label' => ($indoMonths[$mNum] ?? $dt->format('M')) . ' ' . $dt->format('y'),
            'total' => 0, 'indoor' => 0, 'outdoor' => 0
        ];
    }
    foreach ($reports as $r) {
        $rKey = date('Y-m', strtotime($r['report_date']));
        if (isset($monthlyTrend[$rKey])) {
            $monthlyTrend[$rKey]['total']++;
            if ($r['report_type'] === 'indoor') $monthlyTrend[$rKey]['indoor']++;
            if ($r['report_type'] === 'outdoor') $monthlyTrend[$rKey]['outdoor']++;
        }
        $op = !empty($r['operator']) ? strtoupper($r['operator']) : (!empty($r['operator_name']) ? strtoupper($r['operator_name']) : 'TELKOMSEL');
        if ($op === '-') $op = 'UMUM';
        $operatorShare[$op] = ($operatorShare[$op] ?? 0) + 1;
    }
    arsort($operatorShare);
    $maxTrend = 1;
    foreach ($monthlyTrend as $t) {
        if ($t['total'] > $maxTrend) $maxTrend = $t['total'];
    }

    // E. Kepatuhan SLA Site (Days since last PM)
    $allSites = $pdo->query("SELECT id, site_name, location_detail FROM sites ORDER BY site_name ASC")->fetchAll();
    $latestReportPerSite = [];
    $latestReportByName = [];
    foreach ($reports as $r) {
        $sid = intval($r['site_id'] ?? 0);
        if ($sid > 0 && !isset($latestReportPerSite[$sid])) {
            $latestReportPerSite[$sid] = $r['report_date'];
        }
        if (!empty($r['site_name'])) {
            $norm = strtolower(trim($r['site_name']));
            if (!isset($latestReportByName[$norm])) {
                $latestReportByName[$norm] = $r['report_date'];
            }
        }
    }

    $todayTime = time();
    foreach ($allSites as $s) {
        $sid = intval($s['id']);
        $norm = strtolower(trim($s['site_name']));
        $lastDate = $latestReportPerSite[$sid] ?? ($latestReportByName[$norm] ?? null);
        if ($lastDate) {
            $diffDays = max(0, floor(($todayTime - strtotime($lastDate)) / 86400));
            $s['days_ago'] = $diffDays;
            $s['last_date'] = $lastDate;
            if ($diffDays <= 30) {
                $siteHealth['normal'][] = $s;
            } elseif ($diffDays <= 60) {
                $siteHealth['warning'][] = $s;
            } else {
                $siteHealth['overdue'][] = $s;
            }
        } else {
            $s['days_ago'] = 999;
            $s['last_date'] = null;
            $siteHealth['overdue'][] = $s;
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<main class="container py-4">

  <?php if (isset($_GET['msg']) && $_GET['msg'] === 'created'): ?>
    <div class="alert alert-success" style="background: var(--status-ok-bg); color: var(--status-ok); border: 1px solid var(--status-ok-border); border-radius: var(--radius-md); padding: 0.85rem 1.15rem; margin-bottom: 1.5rem; font-weight: 600; display: flex; align-items: center; gap: 8px;">
      <?= app_icon('check-circle', 18) ?>
      <span>Laporan baru berhasil disimpan! Silakan klik tombol <strong>Cetak / Lihat PDF</strong> untuk mengecek hasil cetak.</span>
    </div>
  <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
    <div class="alert alert-success" style="background: var(--status-ok-bg); color: var(--status-ok); border: 1px solid var(--status-ok-border); border-radius: var(--radius-md); padding: 0.85rem 1.15rem; margin-bottom: 1.5rem; font-weight: 600; display: flex; align-items: center; gap: 8px;">
      <?= app_icon('check-circle', 18) ?>
      <span>Laporan berhasil dihapus.</span>
    </div>
  <?php endif; ?>

  <?php if (!$isAdmin): ?>
    <!-- ==================================================================== -->
    <!-- DASHBOARD TEKNISI LAPANGAN (USER BIASA)                              -->
    <!-- ==================================================================== -->

    <!-- Hero Header Teknisi -->
    <div class="hero-header" style="background: linear-gradient(135deg, var(--primary) 0%, #005f73 100%); border-radius: var(--radius-lg); padding: 1.5rem 1.75rem; color: #ffffff; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; box-shadow: var(--shadow-sm);">
      <div>
        <div class="hero-badge" style="display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,0.18); padding: 3px 10px; border-radius: 9999px; font-size: 0.76rem; font-weight: 700; margin-bottom: 0.5rem; letter-spacing: 0.02em;">
          <?= app_icon('user', 13) ?> Teknisi Lapangan NOC KNO &bull; <?= APP_VERSION ?>
        </div>
        <h2 style="font-size: 1.45rem; font-weight: 800; margin: 0 0 0.35rem 0; letter-spacing: -0.02em;">
          Halo, <?= htmlspecialchars($currentUser['full_name'] ?: $currentUser['username']) ?>!
        </h2>
        <p style="font-size: 0.84rem; opacity: 0.92; max-width: 650px; line-height: 1.5; margin: 0;">
          Selamat bertugas. Pantau sesi shift Anda hari ini, catat logbook kegiatan harian secara berkala, dan lengkapi checklist Preventive Maintenance.
        </p>
      </div>
      <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="profile" class="btn btn-sm" style="background: rgba(255,255,255,0.18); border: 1px solid rgba(255,255,255,0.35); color: #ffffff; font-weight: 600; padding: 0.5rem 1rem; display: inline-flex; align-items: center; gap: 6px;">
          <?= app_icon('user', 14) ?>
          <span>Profil Saya</span>
        </a>
      </div>
    </div>

    <!-- Live Shift Card Status Hari Ini -->
    <?php if ($userActiveShift): 
      $sInfo = get_shift_info($userActiveShift['shift_type']);
    ?>
      <div class="dash-shift-card dash-shift-card-active">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
          <div>
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 0.4rem;">
              <span class="sla-status-pill sla-normal" style="font-size: 0.75rem; padding: 3px 10px; display: inline-flex; align-items: center; gap: 5px;">
                <?= app_icon('clock', 12) ?> Shift Anda Sedang Berjalan
              </span>
              <span style="font-size: 0.78rem; color: var(--text-subtle);">Dimulai pukul <?= date('H:i', strtotime($userActiveShift['created_at'])) ?> WIB</span>
            </div>
            <h3 style="font-size: 1.25rem; font-weight: 800; color: var(--text-main); margin: 0 0 0.4rem 0; display: flex; align-items: center; gap: 8px;">
              <?= render_shift_badge($userActiveShift['shift_type'], true, $sInfo['time']) ?>
            </h3>
            <p style="font-size: 0.82rem; color: var(--text-subtle); margin: 0;">
              Tercatat <strong><?= (int)$userActiveShift['total_activities'] ?> kegiatan</strong> dan <strong><?= (int)$userActiveShift['total_photos'] ?> foto dokumentasi</strong> pada sesi shift ini.
            </p>
          </div>

          <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <a href="daily_activity?shift_id=<?= (int)$userActiveShift['id'] ?>" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 700; padding: 0.55rem 1.25rem;">
              <?= app_icon('plus', 14) ?>
              <span>+ Catat Kegiatan Sekarang</span>
            </a>
            <a href="print_shift?shift_id=<?= (int)$userActiveShift['id'] ?>" target="_blank" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600; padding: 0.55rem 1rem;">
              <?= app_icon('file-text', 14) ?>
              <span>Format Cetak PDF</span>
            </a>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="dash-shift-card dash-shift-card-empty">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
          <div>
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 0.4rem;">
              <span class="sla-status-pill sla-warning" style="font-size: 0.75rem; padding: 3px 10px; display: inline-flex; align-items: center; gap: 5px;">
                <?= app_icon('alert-triangle', 12) ?> Belum Ada Sesi Shift Aktif Hari Ini
              </span>
              <span style="font-size: 0.78rem; color: var(--text-subtle);"><?= date('d F Y') ?></span>
            </div>
            <h3 style="font-size: 1.15rem; font-weight: 800; color: var(--text-main); margin: 0 0 0.35rem 0;">
              Siap Memulai Pekerjaan Shift Anda Hari Ini?
            </h3>
            <p style="font-size: 0.82rem; color: var(--text-subtle); margin: 0;">
              Buka sesi shift kerja baru untuk mulai mencatat logbook operasional NOC dan mengunggah dokumentasi visual lapangan.
            </p>
          </div>

          <div>
            <a href="daily_activity" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 700; padding: 0.6rem 1.4rem; box-shadow: 0 4px 12px rgba(0, 128, 128, 0.25);">
              <?= app_icon('clock', 16) ?>
              <span>Buka Sesi Shift Hari Ini &rarr;</span>
            </a>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Action Grid Cepat Teknisi (4 Cards) -->
    <div class="quick-start-grid">
      <a href="daily_activity" class="quick-start-card" style="--card-accent: var(--primary);">
        <div>
          <div class="quick-start-header">
            <div class="quick-start-icon" style="background: var(--primary-subtle); color: var(--primary);">
              <?= app_icon('clock', 24) ?>
            </div>
            <div>
              <h3 class="quick-start-title">Daily Activity NOC</h3>
              <p class="quick-start-desc">Pencatatan kegiatan harian shift kerja (OH, Pagi, Malam), dokumentasi visual foto lapangan, dan serah terima shift.</p>
            </div>
          </div>
        </div>
        <div class="quick-start-footer">
          <span class="quick-start-badge" style="background: var(--primary-subtle); color: var(--primary);">LOGBOOK SHIFT</span>
          <span class="quick-start-action">Buka Logbook &rarr;</span>
        </div>
      </a>

      <a href="form_indoor" class="quick-start-card quick-start-indoor">
        <div>
          <div class="quick-start-header">
            <div class="quick-start-icon">
              <?= app_icon('building', 24) ?>
            </div>
            <div>
              <h3 class="quick-start-title">Checklist PM Indoor</h3>
              <p class="quick-start-desc">Formulir inspeksi perangkat shelter IBC Telkomsel (7 Halaman). Pengukuran tegangan, catu daya, dan temperatur ruangan.</p>
            </div>
          </div>
        </div>
        <div class="quick-start-footer">
          <span class="quick-start-badge">7 HALAMAN STANDAR</span>
          <span class="quick-start-action">Mulai Checklist &rarr;</span>
        </div>
      </a>

      <a href="form_outdoor" class="quick-start-card quick-start-outdoor">
        <div>
          <div class="quick-start-header">
            <div class="quick-start-icon">
              <?= app_icon('tower', 24) ?>
            </div>
            <div>
              <h3 class="quick-start-title">Checklist PM Outdoor</h3>
              <p class="quick-start-desc">Formulir inspeksi pole PJU & tower site (13 Halaman). Pemeriksaan fisik tiang, grounding, PLN, dan 6 foto per halaman.</p>
            </div>
          </div>
        </div>
        <div class="quick-start-footer">
          <span class="quick-start-badge">13 HALAMAN STANDAR</span>
          <span class="quick-start-action">Mulai Checklist &rarr;</span>
        </div>
      </a>

      <a href="profile" class="quick-start-card" style="--card-accent: #6b21a8;">
        <div>
          <div class="quick-start-header">
            <div class="quick-start-icon" style="background: #f3e8ff; color: #6b21a8;">
              <?= app_icon('user', 24) ?>
            </div>
            <div>
              <h3 class="quick-start-title">Profil &amp; Akun Saya</h3>
              <p class="quick-start-desc">Kelola nama resmi akun Anda, perbarui kontak telepon/email, dan lakukan penggantian password akun secara mandiri.</p>
            </div>
          </div>
        </div>
        <div class="quick-start-footer">
          <span class="quick-start-badge" style="background: #f3e8ff; color: #6b21a8;">PENGATURAN AKUN</span>
          <span class="quick-start-action" style="color: #6b21a8;">Kelola Profil &rarr;</span>
        </div>
      </a>
    </div>

    <!-- Statistik Kontribusi Pribadi Teknisi Bulan Ini -->
    <div style="margin-bottom: 1.5rem;">
      <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
        <h4 style="font-size: 0.95rem; font-weight: 800; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 6px;">
          <?= app_icon('activity', 16, 'text-primary') ?>
          <span>Capaian Kinerja Saya Bulan Ini (<?= date('F Y') ?>)</span>
        </h4>
        <span style="font-size: 0.75rem; color: var(--text-subtle);">Data terverifikasi otomatis sistem</span>
      </div>

      <div class="form-grid" style="margin-bottom: 0;">
        <div class="card stat-filter-card" style="margin-bottom: 0;">
          <div class="card-body" style="display: flex; align-items: center; justify-content: space-between;">
            <div>
              <span style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase;">Sesi Shift Saya</span>
              <p style="font-size: 1.6rem; font-weight: 800; color: var(--primary); margin-top: 0.2rem;"><?= (int)$myStats['total_my_shifts'] ?></p>
            </div>
            <div class="stat-icon-wrapper" style="background: var(--primary-subtle); color: var(--primary);">
              <?= app_icon('clock', 22) ?>
            </div>
          </div>
        </div>

        <div class="card stat-filter-card" style="margin-bottom: 0;">
          <div class="card-body" style="display: flex; align-items: center; justify-content: space-between;">
            <div>
              <span style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase;">Kegiatan Tercatat</span>
              <p style="font-size: 1.6rem; font-weight: 800; color: #0284c7; margin-top: 0.2rem;"><?= (int)$myStats['total_my_activities'] ?></p>
            </div>
            <div class="stat-icon-wrapper" style="background: #e0f2fe; color: #0284c7;">
              <?= app_icon('file-text', 22) ?>
            </div>
          </div>
        </div>

        <div class="card stat-filter-card" style="margin-bottom: 0;">
          <div class="card-body" style="display: flex; align-items: center; justify-content: space-between;">
            <div>
              <span style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase;">Foto Dokumentasi</span>
              <p style="font-size: 1.6rem; font-weight: 800; color: #16a34a; margin-top: 0.2rem;"><?= (int)$myStats['total_my_photos'] ?></p>
            </div>
            <div class="stat-icon-wrapper" style="background: #dcfce7; color: #16a34a;">
              <?= app_icon('camera', 22) ?>
            </div>
          </div>
        </div>

        <div class="card stat-filter-card" style="margin-bottom: 0;">
          <div class="card-body" style="display: flex; align-items: center; justify-content: space-between;">
            <div>
              <span style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase;">Inspeksi PM Saya</span>
              <p style="font-size: 1.6rem; font-weight: 800; color: #6b21a8; margin-top: 0.2rem;"><?= (int)$myStats['total_my_pms'] ?></p>
            </div>
            <div class="stat-icon-wrapper" style="background: #f3e8ff; color: #6b21a8;">
              <?= app_icon('clipboard-list', 22) ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Riwayat Sesi Shift Terakhir Saya -->
    <div class="card mb-4" style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden;">
      <div class="card-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; padding: 1rem 1.25rem; background: var(--bg-alt); border-bottom: 1px solid var(--border);">
        <div>
          <h4 style="font-size: 1.05rem; font-weight: 800; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 6px;">
            <?= app_icon('clock', 16, 'text-primary') ?>
            <span>Riwayat Sesi Shift Terakhir Saya</span>
          </h4>
          <span style="font-size: 0.75rem; color: var(--text-subtle);">Catatan 5 sesi shift terakhir yang Anda ikuti</span>
        </div>
        <a href="daily_activity" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 4px;">
          <?= app_icon('plus', 12) ?>
          <span>Buka Daily Activity</span>
        </a>
      </div>

      <?php if (empty($myRecentShifts)): ?>
        <div style="text-align: center; padding: 2.5rem 1rem; color: var(--text-subtle);">
          <div style="margin-bottom: 0.5rem;"><?= app_icon('document', 36) ?></div>
          <p style="margin: 0; font-size: 0.85rem;">Anda belum memiliki riwayat sesi shift. Silakan buka sesi shift pertama Anda!</p>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table mb-0" style="width: 100%; border-collapse: collapse;">
            <thead>
              <tr style="background: var(--bg-alt); font-size: 0.78rem; font-weight: 800; color: var(--text-subtle); text-transform: uppercase;">
                <th style="padding: 0.75rem 1rem; width: 40px; text-align: center;">No</th>
                <th style="padding: 0.75rem 1rem;">Tanggal &amp; Waktu</th>
                <th style="padding: 0.75rem 1rem;">Jenis Shift</th>
                <th style="padding: 0.75rem 1rem; text-align: center;">Kegiatan</th>
                <th style="padding: 0.75rem 1rem; text-align: center;">Foto</th>
                <th style="padding: 0.75rem 1rem; text-align: center;">Status</th>
                <th style="padding: 0.75rem 1rem; text-align: right; width: 170px;">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php $no = 1; foreach ($myRecentShifts as $ms): 
                $sInfo = get_shift_info($ms['shift_type']);
              ?>
                <tr style="border-top: 1px solid var(--border); font-size: 0.84rem;">
                  <td style="padding: 0.85rem 1rem; text-align: center; color: var(--text-subtle); font-weight: 700;">
                    <?= $no++ ?>
                  </td>
                  <td style="padding: 0.85rem 1rem;">
                    <div style="font-weight: 700; color: var(--text-main);">
                      <?= date('d M Y', strtotime($ms['shift_date'])) ?>
                    </div>
                    <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 2px;">
                      Dibuat: <?= date('H:i', strtotime($ms['created_at'])) ?> WIB
                    </div>
                  </td>
                  <td style="padding: 0.85rem 1rem;">
                    <?= render_shift_badge($ms['shift_type'], true, $sInfo['time']) ?>
                  </td>
                  <td style="padding: 0.85rem 1rem; text-align: center;">
                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 9999px; background: var(--primary-subtle); color: var(--primary); font-weight: 700; font-size: 0.78rem;">
                      <?= app_icon('activity', 12) ?> <?= (int)$ms['total_activities'] ?>
                    </span>
                  </td>
                  <td style="padding: 0.85rem 1rem; text-align: center;">
                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 9999px; background: #e0f2fe; color: #0284c7; font-weight: 700; font-size: 0.78rem;">
                      <?= app_icon('camera', 12) ?> <?= (int)$ms['total_photos'] ?>
                    </span>
                  </td>
                  <td style="padding: 0.85rem 1rem; text-align: center;">
                    <?php if ($ms['status'] === 'completed'): ?>
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
                    <div style="display: inline-flex; align-items: center; gap: 5px; justify-content: flex-end;">
                      <a href="daily_activity?shift_id=<?= (int)$ms['id'] ?>" class="btn btn-sm btn-primary-soft" style="padding: 0.3rem 0.65rem; font-size: 0.76rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                        <?= app_icon('eye', 13) ?>
                        <span>Buka</span>
                      </a>
                      <a href="print_shift?shift_id=<?= (int)$ms['id'] ?>" target="_blank" class="btn btn-sm btn-secondary" style="padding: 0.3rem 0.65rem; font-size: 0.76rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                        <?= app_icon('file-text', 13) ?>
                        <span>PDF</span>
                      </a>
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
    <!-- ==================================================================== -->
    <!-- DASHBOARD EKSEKUTIF UNTUK SUPERADMIN & ADMIN                         -->
    <!-- ==================================================================== -->

    <!-- Hero Header Eksekutif -->
    <div class="hero-header" style="background: linear-gradient(135deg, var(--primary) 0%, #005f73 100%); border-radius: var(--radius-lg); padding: 1.5rem 1.75rem; color: #ffffff; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; box-shadow: var(--shadow-sm);">
      <div>
        <div class="hero-badge" style="display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,0.18); padding: 3px 10px; border-radius: 9999px; font-size: 0.76rem; font-weight: 700; margin-bottom: 0.5rem; letter-spacing: 0.02em;">
          <?= app_icon('shield', 13) ?> Pusat Kendali Eksekutif &bull; <?= APP_VERSION ?>
        </div>
        <h2 style="font-size: 1.45rem; font-weight: 800; margin: 0 0 0.35rem 0; letter-spacing: -0.02em;">
          Monitoring Operasional NOC &amp; Preventive Maintenance
        </h2>
        <p style="font-size: 0.84rem; opacity: 0.92; max-width: 650px; line-height: 1.5; margin: 0;">
          Pengawasan terpusat aktivitas seluruh shift teknisi, kepatuhan jadwal pemeliharaan berkala, rekapitulasi dokumen resmi, dan audit logbook SBU ICT KNO.
        </p>
      </div>
      <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="daily_reports" class="btn btn-sm" style="background: #ffffff; color: var(--primary); font-weight: 700; padding: 0.5rem 1rem; display: inline-flex; align-items: center; gap: 6px;">
          <?= app_icon('document', 14) ?>
          <span>Pusat Rekapitulasi NOC</span>
        </a>
        <a href="reports" class="btn btn-sm" style="background: rgba(255,255,255,0.18); border: 1px solid rgba(255,255,255,0.35); color: #ffffff; font-weight: 600; padding: 0.5rem 1rem; display: inline-flex; align-items: center; gap: 6px;">
          <?= app_icon('clipboard-list', 14) ?>
          <span>Arsip Lengkap PM</span>
        </a>
      </div>
    </div>

    <!-- Live Shifts Monitoring: Teknisi Yang Sedang Bertugas Hari Ini -->
    <div class="card mb-4" style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden;">
      <div class="card-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; padding: 0.9rem 1.25rem; background: var(--bg-alt); border-bottom: 1px solid var(--border);">
        <div style="display: flex; align-items: center; gap: 8px;">
          <span style="width: 8px; height: 8px; border-radius: 50%; background: #16a34a; display: inline-block;"></span>
          <h4 style="font-size: 0.95rem; font-weight: 800; color: var(--text-main); margin: 0;">
            Live Monitoring: Sesi Shift Aktif di Lapangan Hari Ini (<?= count($liveTeamShifts) ?> Shift)
          </h4>
        </div>
        <span style="font-size: 0.74rem; color: var(--text-subtle);"><?= date('d F Y') ?></span>
      </div>

      <?php if (empty($liveTeamShifts)): ?>
        <div style="padding: 1.25rem; text-align: center; color: var(--text-subtle); font-size: 0.82rem;">
          <?= app_icon('info', 16) ?> Saat ini belum ada sesi shift yang tercatat sedang berjalan untuk hari ini (<?= date('d M Y') ?>).
        </div>
      <?php else: ?>
        <div>
          <?php foreach ($liveTeamShifts as $ls): 
            $sInfo = get_shift_info($ls['shift_type']);
            $techName = $ls['technician_name'] ?: ($ls['user_full_name'] ?: $ls['username']);
          ?>
            <div class="dash-live-shift-row">
              <div style="display: flex; align-items: center; gap: 12px; min-width: 0;">
                <div style="width: 38px; height: 38px; border-radius: 50%; background: var(--primary-subtle); color: var(--primary); font-weight: 700; font-size: 0.82rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                  <?= strtoupper(substr($techName, 0, 2)) ?>
                </div>
                <div>
                  <div style="font-weight: 800; color: var(--text-main); font-size: 0.88rem;">
                    <?= htmlspecialchars($techName) ?>
                  </div>
                  <div style="display: flex; align-items: center; gap: 6px; margin-top: 2px;">
                    <?= render_shift_badge($ls['shift_type']) ?>
                    <span style="font-size: 0.72rem; color: var(--text-subtle);">Mulai: <?= date('H:i', strtotime($ls['created_at'])) ?> WIB</span>
                  </div>
                </div>
              </div>

              <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                <div style="display: flex; gap: 8px;">
                  <span style="font-size: 0.76rem; font-weight: 700; background: var(--primary-subtle); color: var(--primary); padding: 3px 8px; border-radius: 9999px;">
                    <?= (int)$ls['total_activities'] ?> Kegiatan
                  </span>
                  <span style="font-size: 0.76rem; font-weight: 700; background: #e0f2fe; color: #0284c7; padding: 3px 8px; border-radius: 9999px;">
                    <?= (int)$ls['total_photos'] ?> Foto
                  </span>
                </div>
                <a href="daily_activity?shift_id=<?= (int)$ls['id'] ?>" class="btn btn-sm btn-primary-soft" style="display: inline-flex; align-items: center; gap: 5px; font-weight: 700; font-size: 0.76rem; padding: 0.35rem 0.85rem;">
                  <?= app_icon('eye', 13) ?>
                  <span>Pantau Logbook Live</span>
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Summary Stats Grid (4 Cards) -->
    <div class="form-grid" style="margin-bottom: 1.5rem;">
      <a href="daily_reports?tab=shifts" class="card stat-filter-card" style="margin-bottom: 0; text-decoration: none; color: inherit;" title="Total sesi shift seluruh tim bulan ini">
        <div class="card-body" style="display: flex; align-items: center; justify-content: space-between;">
          <div>
            <span style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase;">Sesi Shift Tim (Bln Ini)</span>
            <p style="font-size: 1.6rem; font-weight: 800; color: var(--primary); margin-top: 0.2rem;"><?= (int)$nocStats['total_shifts'] ?></p>
          </div>
          <div class="stat-icon-wrapper" style="background: var(--primary-subtle); color: var(--primary);">
            <?= app_icon('clock', 22) ?>
          </div>
        </div>
      </a>

      <a href="daily_reports?tab=monthly" class="card stat-filter-card" style="margin-bottom: 0; text-decoration: none; color: inherit;" title="Total kegiatan logbook dicatat seluruh tim bulan ini">
        <div class="card-body" style="display: flex; align-items: center; justify-content: space-between;">
          <div>
            <span style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase;">Kegiatan NOC Tim</span>
            <p style="font-size: 1.6rem; font-weight: 800; color: #0284c7; margin-top: 0.2rem;"><?= (int)$nocStats['total_activities'] ?></p>
          </div>
          <div class="stat-icon-wrapper" style="background: #e0f2fe; color: #0284c7;">
            <?= app_icon('file-text', 22) ?>
          </div>
        </div>
      </a>

      <a href="reports" class="card stat-filter-card" style="margin-bottom: 0; text-decoration: none; color: inherit;" title="Total laporan preventive maintenance tersimpan">
        <div class="card-body" style="display: flex; align-items: center; justify-content: space-between;">
          <div>
            <span style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase;">Total Laporan PM</span>
            <p style="font-size: 1.6rem; font-weight: 800; color: #16a34a; margin-top: 0.2rem;"><?= $totalReports ?></p>
          </div>
          <div class="stat-icon-wrapper" style="background: #dcfce7; color: #16a34a;">
            <?= app_icon('clipboard-list', 22) ?>
          </div>
        </div>
      </a>

      <a href="sites" class="card stat-filter-card" style="margin-bottom: 0; text-decoration: none; color: inherit;" title="Buka Master Data Site Bandara">
        <div class="card-body" style="display: flex; align-items: center; justify-content: space-between;">
          <div>
            <span style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase;">Master Site Bandara</span>
            <p style="font-size: 1.6rem; font-weight: 800; color: #6b21a8; margin-top: 0.2rem;"><?= $siteCount ?></p>
          </div>
          <div class="stat-icon-wrapper" style="background: #f3e8ff; color: #6b21a8;">
            <?= app_icon('map-pin', 22) ?>
          </div>
        </div>
      </a>
    </div>

    <!-- Quick Start Inspection & Management Grid (4 Cards) -->
    <div class="quick-start-grid">
      <a href="daily_reports" class="quick-start-card" style="--card-accent: #0284c7;">
        <div>
          <div class="quick-start-header">
            <div class="quick-start-icon" style="background: #e0f2fe; color: #0284c7;">
              <?= app_icon('document', 24) ?>
            </div>
            <div>
              <h3 class="quick-start-title">Rekap &amp; Laporan NOC</h3>
              <p class="quick-start-desc">Pusat monitoring seluruh laporan harian shift dan rekapitulasi logbook bulanan gabungan seluruh anggota teknisi.</p>
            </div>
          </div>
        </div>
        <div class="quick-start-footer">
          <span class="quick-start-badge" style="background: #e0f2fe; color: #0284c7;">REKAPITULASI TIM</span>
          <span class="quick-start-action" style="color: #0284c7;">Buka Laporan &rarr;</span>
        </div>
      </a>

      <a href="form_indoor" class="quick-start-card quick-start-indoor">
        <div>
          <div class="quick-start-header">
            <div class="quick-start-icon">
              <?= app_icon('building', 24) ?>
            </div>
            <div>
              <h3 class="quick-start-title">Checklist PM Indoor</h3>
              <p class="quick-start-desc">Formulir inspeksi perangkat shelter IBC Telkomsel (7 Halaman). Pengukuran tegangan, catu daya, dan temperatur ruangan.</p>
            </div>
          </div>
        </div>
        <div class="quick-start-footer">
          <span class="quick-start-badge">7 HALAMAN STANDAR</span>
          <span class="quick-start-action">Mulai Checklist &rarr;</span>
        </div>
      </a>

      <a href="form_outdoor" class="quick-start-card quick-start-outdoor">
        <div>
          <div class="quick-start-header">
            <div class="quick-start-icon">
              <?= app_icon('tower', 24) ?>
            </div>
            <div>
              <h3 class="quick-start-title">Checklist PM Outdoor</h3>
              <p class="quick-start-desc">Formulir inspeksi pole PJU & tower site (13 Halaman). Pemeriksaan fisik tiang, grounding, PLN, dan 6 foto per halaman.</p>
            </div>
          </div>
        </div>
        <div class="quick-start-footer">
          <span class="quick-start-badge">13 HALAMAN STANDAR</span>
          <span class="quick-start-action">Mulai Checklist &rarr;</span>
        </div>
      </a>

      <a href="users" class="quick-start-card" style="--card-accent: #16a34a;">
        <div>
          <div class="quick-start-header">
            <div class="quick-start-icon" style="background: #dcfce7; color: #16a34a;">
              <?= app_icon('users', 24) ?>
            </div>
            <div>
              <h3 class="quick-start-title">Kelola Pengguna Sistem</h3>
              <p class="quick-start-desc">Pusat kendali akun teknisi, penugasan hak akses (Role-Based Access Control), dan reset password akun terpusat.</p>
            </div>
          </div>
        </div>
        <div class="quick-start-footer">
          <span class="quick-start-badge" style="background: #dcfce7; color: #16a34a;">MANAJEMEN PENGGUNA</span>
          <span class="quick-start-action" style="color: #16a34a;">Kelola Akun &rarr;</span>
        </div>
      </a>
    </div>

    <!-- Executive Dashboard Analytics & Site PM SLA Section -->
    <div class="analytics-grid">
      <!-- Card 1: Monthly PM Trend & Operator Breakdown -->
      <div class="card" style="margin-bottom: 0;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
          <div>
            <h4 style="font-size: 0.95rem; font-weight: 700; margin: 0; color: var(--text-main);">Tren Pemeliharaan (6 Bulan Terakhir)</h4>
            <span style="font-size: 0.72rem; color: var(--text-subtle);">Distribusi penyelesaian inspeksi per bulan</span>
          </div>
          <span style="font-size: 0.75rem; font-weight: 700; color: var(--primary);">Total: <?= $totalReports ?></span>
        </div>
        <div class="card-body">
          <div class="chart-bar-container">
            <?php foreach ($monthlyTrend as $t): ?>
              <?php 
                $heightPct = $maxTrend > 0 ? max(8, round(($t['total'] / $maxTrend) * 95)) : 8;
              ?>
              <div class="chart-bar-col" title="<?= htmlspecialchars($t['label']) ?>: <?= $t['total'] ?> laporan (<?= $t['indoor'] ?> indoor, <?= $t['outdoor'] ?> outdoor)">
                <span class="chart-bar-count"><?= $t['total'] ?></span>
                <div class="chart-bar-pill" style="height: <?= $heightPct ?>px;"></div>
                <span class="chart-bar-label"><?= htmlspecialchars($t['label']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>

          <div style="border-top: 1px solid var(--border); padding-top: 0.75rem; margin-top: 0.5rem;">
            <div style="font-size: 0.72rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; margin-bottom: 0.4rem;">Porsi Operator Seluler / Tenant</div>
            <div style="display: flex; gap: 0.4rem; flex-wrap: wrap;">
              <?php if (!empty($operatorShare)): ?>
                <?php foreach (array_slice($operatorShare, 0, 5, true) as $opName => $opCnt): ?>
                  <div class="operator-share-badge">
                    <span><?= app_icon('building', 12) ?></span>
                    <span><?= htmlspecialchars($opName) ?>:</span>
                    <strong style="color: var(--primary);"><?= $opCnt ?></strong>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <span style="font-size: 0.75rem; color: var(--text-subtle);">Belum ada data operator.</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Card 2: Site PM Health & SLA Status -->
      <div class="card" style="margin-bottom: 0;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
          <div>
            <h4 style="font-size: 0.95rem; font-weight: 700; margin: 0; color: var(--text-main);">Kepatuhan Siklus PM Site (SLA)</h4>
            <span style="font-size: 0.72rem; color: var(--text-subtle);">Status jadwal pemeriksaan rutin tiang &amp; shelter</span>
          </div>
          <?php if (has_role('superadmin')): ?>
          <a href="sites" class="btn btn-sm btn-secondary" style="font-size: 0.72rem; padding: 0.2rem 0.5rem;">Lihat Semua Site</a>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.85rem;">
            <div class="sla-status-pill sla-normal" title="Site telah diperiksa dalam 30 hari terakhir">
              <span>✓</span> <?= count($siteHealth['normal']) ?> Site Aman (&lt;30 hr)
            </div>
            <div class="sla-status-pill sla-warning" title="Site perlu dijadwalkan inspeksi baru">
              <span>⚠️</span> <?= count($siteHealth['warning']) ?> Perlu Jadwal (30-60 hr)
            </div>
            <div class="sla-status-pill sla-overdue" title="Site telah lewat batas waktu rutin inspeksi">
              <span>🔴</span> <?= count($siteHealth['overdue']) ?> Jatuh Tempo (&gt;60 hr)
            </div>
          </div>

          <div>
            <div style="font-size: 0.72rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; margin-bottom: 0.4rem;">Site Perlu Perhatian / Kunjungan Segera</div>
            <div style="display: flex; flex-direction: column; gap: 0.4rem;">
              <?php 
                $prioritySites = array_merge($siteHealth['overdue'], $siteHealth['warning']);
                $prioritySites = array_slice($prioritySites, 0, 3);
              ?>
              <?php if (!empty($prioritySites)): ?>
                <?php foreach ($prioritySites as $ps): ?>
                  <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.45rem 0.65rem; background: var(--bg-alt); border-radius: var(--radius-sm); border: 1px solid var(--border);">
                    <div style="min-width: 0; flex: 1;">
                      <div style="font-size: 0.82rem; font-weight: 700; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($ps['site_name']) ?></div>
                      <div style="font-size: 0.7rem; color: var(--text-subtle);">
                        <?= $ps['last_date'] ? ('PM Terakhir: ' . date('d M Y', strtotime($ps['last_date'])) . ' (' . $ps['days_ago'] . ' hari lalu)') : 'Belum pernah diinspeksi' ?>
                      </div>
                    </div>
                    <div style="display: flex; gap: 4px; margin-left: 8px;">
                      <a href="form_outdoor" class="btn btn-sm btn-primary" style="font-size: 0.7rem; padding: 0.25rem 0.5rem; text-decoration: none;">+ PM</a>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div style="text-align: center; padding: 1rem; color: var(--text-subtle); font-size: 0.8rem;">
                  ✓ Semua site dalam status aman dan telah dipelihara berkala.
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Leaderboard Partisipasi Anggota Tim Bulan Ini -->
    <div class="card mb-4" style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden;">
      <div class="card-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; padding: 1rem 1.25rem; background: var(--bg-alt); border-bottom: 1px solid var(--border);">
        <div>
          <h4 style="font-size: 1.05rem; font-weight: 800; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 6px;">
            <?= app_icon('users', 16, 'text-primary') ?>
            <span>Partisipasi Anggota Tim NOC Teraktif (<?= date('F Y') ?>)</span>
          </h4>
          <span style="font-size: 0.75rem; color: var(--text-subtle);">Distribusi kegiatan logbook dan kehadiran shift per teknisi</span>
        </div>
        <a href="daily_reports?tab=monthly" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 4px;">
          <?= app_icon('eye', 12) ?>
          <span>Buka Rekapitulasi Tim Lengkap</span>
        </a>
      </div>

      <div class="table-responsive">
        <table class="table mb-0" style="width: 100%; border-collapse: collapse;">
          <thead>
            <tr style="background: var(--bg-alt); font-size: 0.78rem; font-weight: 800; color: var(--text-subtle); text-transform: uppercase;">
              <th style="padding: 0.75rem 1rem; width: 40px; text-align: center;">No</th>
              <th style="padding: 0.75rem 1rem;">Nama Teknisi</th>
              <th style="padding: 0.75rem 1rem;">Role Akses</th>
              <th style="padding: 0.75rem 1rem; text-align: center;">Sesi Shift</th>
              <th style="padding: 0.75rem 1rem; text-align: center;">Total Kegiatan</th>
              <th style="padding: 0.75rem 1rem; text-align: center;">Foto Terlampir</th>
            </tr>
          </thead>
          <tbody>
            <?php $noL = 1; foreach ($topTechs as $tt): 
              $tName = $tt['full_name'] ?: $tt['username'];
              $tInitials = strtoupper(substr($tName, 0, 2));
            ?>
              <tr style="border-top: 1px solid var(--border); font-size: 0.84rem;">
                <td style="padding: 0.75rem 1rem; text-align: center; color: var(--text-subtle); font-weight: 700;">
                  <?= $noL++ ?>
                </td>
                <td style="padding: 0.75rem 1rem;">
                  <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--primary-subtle); color: var(--primary); font-size: 0.75rem; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                      <?= $tInitials ?>
                    </div>
                    <div>
                      <div style="font-weight: 700; color: var(--text-main);"><?= htmlspecialchars($tName) ?></div>
                      <div style="font-size: 0.72rem; color: var(--text-subtle);">@<?= htmlspecialchars($tt['username']) ?></div>
                    </div>
                  </div>
                </td>
                <td style="padding: 0.75rem 1rem;">
                  <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.72rem; font-weight: 700; background: var(--bg-alt); color: var(--text-subtle); text-transform: uppercase;">
                    <?= htmlspecialchars($tt['role']) ?>
                  </span>
                </td>
                <td style="padding: 0.75rem 1rem; text-align: center; font-weight: 700;">
                  <span style="display: inline-flex; align-items: center; gap: 4px;">
                    <?= app_icon('clock', 12, 'text-primary') ?>
                    <span><?= (int)$tt['shift_count'] ?> sesi</span>
                  </span>
                </td>
                <td style="padding: 0.75rem 1rem; text-align: center;">
                  <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 9999px; background: var(--primary-subtle); color: var(--primary); font-weight: 700; font-size: 0.78rem;">
                    <?= app_icon('file-text', 12) ?>
                    <span><?= (int)$tt['activity_count'] ?></span>
                  </span>
                </td>
                <td style="padding: 0.75rem 1rem; text-align: center;">
                  <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 9999px; background: #e0f2fe; color: #0284c7; font-weight: 700; font-size: 0.78rem;">
                    <?= app_icon('camera', 12) ?>
                    <span><?= (int)$tt['photo_count'] ?></span>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php endif; ?>

  <!-- Reports Section with Live Filter & Search Toolbar (Shared PM Archive) -->
  <div class="card" style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; padding: 1rem 1.25rem; background: var(--bg-alt); border-bottom: 1px solid var(--border);">
      <div>
        <h3 class="card-title" style="margin: 0 0 0.15rem 0; font-size: 1.05rem; font-weight: 800; display: flex; align-items: center; gap: 6px;">
          <?= app_icon('clipboard-list', 16, 'text-primary') ?>
          <span>Daftar Arsip Laporan Pemeliharaan (PM)</span>
        </h3>
        <span style="font-size: 0.8rem; color: var(--text-subtle);">Menampilkan <strong id="reports_count_display"><?= $totalReports ?></strong> dokumen tercatat</span>
      </div>
      <a href="reports" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;">
        <?= app_icon('clipboard-list', 13) ?>
        <span>Buka Riwayat Lengkap &rarr;</span>
      </a>
    </div>

    <!-- Live Search & Filter Bar -->
    <div class="table-toolbar">
      <div class="search-input-wrapper">
        <?= app_icon('search', 14) ?>
        <input type="text" id="report_live_search" class="table-search-input" placeholder="Cari site, lokasi, PIC, atau tanggal...">
      </div>
      <div class="filter-tab-group">
        <button type="button" class="filter-tab-btn active" data-filter="all">Semua (<?= $totalReports ?>)</button>
        <button type="button" class="filter-tab-btn" data-filter="indoor">Indoor (<?= $indoorCount ?>)</button>
        <button type="button" class="filter-tab-btn" data-filter="outdoor">Outdoor (<?= $outdoorCount ?>)</button>
      </div>
    </div>

    <div class="card-body" style="padding: 0;">
      <?php if (empty($reports)): ?>
        <div style="text-align: center; padding: 3rem 1.5rem; color: var(--text-subtle);">
          <div style="font-size: 2.5rem; margin-bottom: 0.75rem;"><?= app_icon('document', 42) ?></div>
          <h4 style="color: var(--text-main); margin-bottom: 0.25rem;">Belum Ada Laporan PM</h4>
          <p style="font-size: 0.875rem; max-width: 420px; margin: 0 auto 1.25rem auto;">
            Belum ada formulir checklist PM yang tersimpan di sistem. Mulai buat laporan inspeksi baru sekarang.
          </p>
          <div style="display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap;">
            <a href="form_indoor" class="btn btn-primary btn-sm">+ Buat PM Indoor</a>
            <a href="form_outdoor" class="btn btn-primary btn-sm">+ Buat PM Outdoor</a>
          </div>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table mb-0" id="reports_table" style="width: 100%; border-collapse: collapse;">
            <thead>
              <tr style="background: var(--bg-alt); font-size: 0.78rem; font-weight: 800; color: var(--text-subtle); text-transform: uppercase;">
                <th style="padding: 0.75rem 1rem; width: 45px; text-align: center;">No</th>
                <th style="padding: 0.75rem 1rem;">Tipe</th>
                <th style="padding: 0.75rem 1rem;">Site / Lokasi</th>
                <th style="padding: 0.75rem 1rem;">Tanggal &amp; Waktu</th>
                <th style="padding: 0.75rem 1rem;">Operator</th>
                <th style="padding: 0.75rem 1rem;">Teknisi / PIC</th>
                <th style="padding: 0.75rem 1rem; text-align: right; width: 220px;">Aksi Dokumen</th>
              </tr>
            </thead>
            <tbody>
              <?php $i = 1; foreach ($reports as $r): ?>
                <?php
                  $isIndoor = ($r['report_type'] === 'indoor');
                  $printPage = $isIndoor ? 'print_indoor' : 'print_outdoor';
                  $editPage = $isIndoor ? 'form_indoor' : 'form_outdoor';
                  $badgeCls = $isIndoor ? 'badge-indoor' : 'badge-outdoor';
                  $badgeTxt = $isIndoor ? 'INDOOR' : 'OUTDOOR';
                  $rawOp = !empty($r['operator']) ? $r['operator'] : (!empty($r['operator_name']) ? $r['operator_name'] : 'Telkomsel');
                  $opCode = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $rawOp));
                  $siteNameDisplay = $r['site_name'] ?: 'Site #' . $r['site_id'];
                  $searchable = strtolower($siteNameDisplay . ' ' . ($r['location_detail'] ?? '') . ' ' . ($r['inspector_name'] ?? '') . ' ' . $rawOp . ' ' . $r['report_date']);
                ?>
                <tr class="report-row" data-type="<?= $r['report_type'] ?>" data-search="<?= htmlspecialchars($searchable) ?>" style="border-top: 1px solid var(--border); font-size: 0.84rem;">
                  <td style="padding: 0.85rem 1rem; text-align: center; color: var(--text-subtle); font-weight: 700;"><?= $i++ ?></td>
                  <td style="padding: 0.85rem 1rem;">
                    <span class="badge <?= $badgeCls ?>" style="font-size: 0.72rem; padding: 3px 8px; font-weight: 700;">
                      <?= $badgeTxt ?>
                    </span>
                  </td>
                  <td style="padding: 0.85rem 1rem;">
                    <div style="font-weight: 700; color: var(--text-main);"><?= htmlspecialchars($siteNameDisplay) ?></div>
                    <?php if (!empty($r['location_detail'])): ?>
                      <div style="font-size: 0.74rem; color: var(--text-subtle); margin-top: 2px;">
                        <?= htmlspecialchars($r['location_detail']) ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 0.85rem 1rem;">
                    <div style="font-weight: 600; color: var(--text-main);"><?= date('d M Y', strtotime($r['report_date'])) ?></div>
                    <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 2px;">
                      <?= htmlspecialchars($r['inspection_time'] ?? '-') ?> <?= htmlspecialchars($r['timezone'] ?? 'WIB') ?>
                    </div>
                  </td>
                  <td style="padding: 0.85rem 1rem;">
                    <span class="operator-badge op-<?= $opCode ?>" style="font-size: 0.74rem; padding: 2px 8px; font-weight: 700;">
                      <?= htmlspecialchars($rawOp) ?>
                    </span>
                  </td>
                  <td style="padding: 0.85rem 1rem;">
                    <div style="font-weight: 600; color: var(--text-main);"><?= htmlspecialchars($r['inspector_name']) ?></div>
                    <?php if (!empty($r['inspector_phone'])): ?>
                      <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 2px;"><?= htmlspecialchars($r['inspector_phone']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 0.85rem 1rem; text-align: right;">
                    <div class="table-action-group">
                      <a href="<?= $printPage ?>?id=<?= $r['id'] ?>" target="_blank" class="btn btn-sm btn-primary-soft" style="padding: 0.3rem 0.65rem; font-size: 0.76rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;" title="Lihat dan Cetak Format PDF Resmi">
                        <?= app_icon('file-text', 13) ?>
                        <span>Cetak</span>
                      </a>
                      <a href="<?= $editPage ?>?edit=<?= $r['id'] ?>" class="btn btn-sm btn-secondary" style="padding: 0.3rem 0.65rem; font-size: 0.76rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;" title="Edit data laporan">
                        <?= app_icon('edit', 13) ?>
                        <span>Edit</span>
                      </a>
                      <a href="reports?delete=<?= $r['id'] ?>" class="btn btn-sm btn-danger-soft" style="padding: 0.3rem 0.55rem; font-size: 0.76rem; color: var(--status-nok); border: 1px solid var(--status-nok-border); background: var(--status-nok-bg); display: inline-flex; align-items: center;" onclick="return confirm('Apakah Anda yakin ingin menghapus laporan ini? Seluruh data foto terkait juga akan dihapus permanen.')" title="Hapus laporan ini">
                        <?= app_icon('trash', 13) ?>
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

</main>

<script>
// Filter & Live Search Logic for Reports Table
document.addEventListener('DOMContentLoaded', function() {
  var searchInput = document.getElementById('report_live_search');
  var filterButtons = document.querySelectorAll('.filter-tab-btn');
  var statFilterCards = document.querySelectorAll('.stat-filter-card[data-filter]');
  var rows = document.querySelectorAll('.report-row');
  var countDisplay = document.getElementById('reports_count_display');

  var currentFilter = 'all';
  var currentSearch = '';

  function applyFilters() {
    var visibleCount = 0;
    rows.forEach(function(row) {
      var rowType = row.getAttribute('data-type');
      var rowSearch = row.getAttribute('data-search') || '';

      var typeMatch = (currentFilter === 'all' || rowType === currentFilter);
      var searchMatch = (!currentSearch || rowSearch.indexOf(currentSearch) !== -1);

      if (typeMatch && searchMatch) {
        row.style.display = '';
        visibleCount++;
      } else {
        row.style.display = 'none';
      }
    });

    if (countDisplay) countDisplay.textContent = visibleCount;
  }

  filterButtons.forEach(function(btn) {
    btn.addEventListener('click', function() {
      filterButtons.forEach(function(b) { b.classList.remove('active'); });
      this.classList.add('active');
      currentFilter = this.getAttribute('data-filter');
      applyFilters();
    });
  });

  statFilterCards.forEach(function(card) {
    card.addEventListener('click', function() {
      var target = this.getAttribute('data-filter');
      var matchingBtn = document.querySelector('.filter-tab-btn[data-filter="' + target + '"]');
      if (matchingBtn) matchingBtn.click();
      var tbl = document.getElementById('reports_table');
      if (tbl) tbl.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  if (searchInput) {
    searchInput.addEventListener('input', function() {
      currentSearch = this.value.toLowerCase().trim();
      applyFilters();
    });
  }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
