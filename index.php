<?php
/**
 * Dashboard & Laporan List
 * PT Angkasa Pura Sarana Digital
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/redis.php';
$pdo = get_db_connection();

$page_title = 'Dashboard Laporan PM';
include __DIR__ . '/includes/header.php';

// Fetch reports with Redis cache support
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

// Quick statistics with Redis cache support
$cachedStats = AppRedis::get('dashboard:stats');
if ($cachedStats !== null && is_array($cachedStats)) {
    $totalReports = $cachedStats['total'];
    $indoorCount = $cachedStats['indoor'];
    $outdoorCount = $cachedStats['outdoor'];
    $siteCount = $cachedStats['site_count'];
} else {
    $totalReports = count($reports);
    $indoorCount = 0;
    $outdoorCount = 0;
    foreach ($reports as $r) {
        if ($r['report_type'] === 'indoor') $indoorCount++;
        if ($r['report_type'] === 'outdoor') $outdoorCount++;
    }
    $siteCount = $pdo->query("SELECT COUNT(*) FROM sites")->fetchColumn();
    AppRedis::set('dashboard:stats', [
        'total' => $totalReports,
        'indoor' => $indoorCount,
        'outdoor' => $outdoorCount,
        'site_count' => $siteCount
    ], 60);
}

// Compute 6-Month PM Trend & Operator Breakdown
$indoMonths = [
    '01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr',
    '05' => 'Mei', '06' => 'Jun', '07' => 'Jul', '08' => 'Agu',
    '09' => 'Sep', '10' => 'Okt', '11' => 'Nov', '12' => 'Des'
];

$monthlyTrend = [];
$now = new DateTime();
for ($i = 5; $i >= 0; $i--) {
    $dt = (clone $now)->modify("-{$i} month");
    $k = $dt->format('Y-m');
    $mNum = $dt->format('m');
    $monthlyTrend[$k] = [
        'key' => $k,
        'label' => ($indoMonths[$mNum] ?? $dt->format('M')) . ' ' . $dt->format('y'),
        'total' => 0,
        'indoor' => 0,
        'outdoor' => 0
    ];
}

$operatorShare = [];
foreach ($reports as $r) {
    $rKey = date('Y-m', strtotime($r['report_date']));
    if (isset($monthlyTrend[$rKey])) {
        $monthlyTrend[$rKey]['total']++;
        if ($r['report_type'] === 'indoor') $monthlyTrend[$rKey]['indoor']++;
        if ($r['report_type'] === 'outdoor') $monthlyTrend[$rKey]['outdoor']++;
    }
    $op = !empty($r['operator_name']) ? strtoupper($r['operator_name']) : 'TELKOMSEL';
    if ($op === '-') $op = 'UMUM';
    $operatorShare[$op] = ($operatorShare[$op] ?? 0) + 1;
}
arsort($operatorShare);
$maxTrend = 1;
foreach ($monthlyTrend as $t) {
    if ($t['total'] > $maxTrend) $maxTrend = $t['total'];
}

// Compute Site PM Health & SLA (Days since last PM)
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

$siteHealth = [
    'normal' => [],
    'warning' => [],
    'overdue' => []
];
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
?>

<main class="container">

  <?php if (isset($_GET['msg']) && $_GET['msg'] === 'created'): ?>
    <div class="alert alert-success">
      Laporan baru berhasil disimpan! Silakan klik tombol <strong>Cetak / Lihat PDF</strong> untuk mengecek hasil cetak.
    </div>
  <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
    <div class="alert alert-success">
      Laporan berhasil dihapus.
    </div>
  <?php endif; ?>

  <!-- Hero Section with Executive Header -->
  <div class="hero-header">
    <div>
      <div class="hero-badge">
        <span>⚡</span> Bandara Internasional Kualanamu &bull; <?= APP_VERSION ?>
      </div>
      <h2 style="font-size: 1.35rem; font-weight: 800; margin-bottom: 0.35rem;">Portal Preventive Maintenance (PM)</h2>
      <p style="font-size: 0.875rem; opacity: 0.92; max-width: 620px; line-height: 1.5; margin: 0;">
        Generator checklist pemeliharaan berkala dan dokumentasi foto standar resmi IASS SBU ICT KNO & Operator Seluler.
      </p>
    </div>
    <div>
      <a href="reports" class="btn btn-secondary btn-sm" style="background: rgba(255,255,255,0.18); border: 1px solid rgba(255,255,255,0.35); color: #ffffff; font-weight: 600; padding: 0.45rem 0.85rem;">
        <span>📂</span> Buka Semua Arsip
      </a>
    </div>
  </div>

  <!-- Quick Start Inspection Cards (Indoor & Outdoor) -->
  <div class="quick-start-grid">
    <a href="form_indoor" class="quick-start-card quick-start-indoor">
      <div>
        <div class="quick-start-header">
          <div class="quick-start-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect>
              <rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect>
              <line x1="6" y1="6" x2="6.01" y2="6"></line>
              <line x1="6" y1="18" x2="6.01" y2="18"></line>
            </svg>
          </div>
          <div>
            <h3 class="quick-start-title">Checklist PM Indoor</h3>
            <p class="quick-start-desc">Formulir inspeksi perangkat IBC Telkomsel (7 Halaman). Pengukuran tegangan S/T/G-N, catu daya, dan temperatur ruangan.</p>
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
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 2v20"></path>
              <path d="M17 5H7"></path>
              <path d="M19 9H5"></path>
              <path d="M21 13H3"></path>
              <path d="M16 17H8"></path>
            </svg>
          </div>
          <div>
            <h3 class="quick-start-title">Checklist PM Outdoor</h3>
            <p class="quick-start-desc">Formulir inspeksi pole PJU & tower site (13 Halaman). Pemeriksaan tiang, grounding, catu daya PLN, dan 6 foto per halaman.</p>
          </div>
        </div>
      </div>
      <div class="quick-start-footer">
        <span class="quick-start-badge">13 HALAMAN STANDAR</span>
        <span class="quick-start-action">Mulai Checklist &rarr;</span>
      </div>
    </a>
  </div>

  <!-- Summary Stats Grid with Click-to-Filter -->
  <div class="form-grid" style="margin-bottom: 1.5rem;">
    <div class="card stat-filter-card active-filter" data-filter="all" style="margin-bottom: 0;" title="Klik untuk menampilkan semua laporan">
      <div class="card-body" style="display: flex; align-items: center; justify-content: space-between;">
        <div>
          <span style="font-size: 0.75rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; letter-spacing: 0.5px;">Total Laporan</span>
          <p style="font-size: 1.6rem; font-weight: 800; color: var(--text-main); margin-top: 0.2rem;"><?= $totalReports ?></p>
        </div>
        <div class="stat-icon-wrapper" style="background: var(--bg-alt); color: var(--text-main);">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        </div>
      </div>
    </div>

    <div class="card stat-filter-card" data-filter="indoor" style="margin-bottom: 0;" title="Klik untuk memfilter laporan PM Indoor">
      <div class="card-body" style="display: flex; align-items: center; justify-content: space-between;">
        <div>
          <span style="font-size: 0.75rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; letter-spacing: 0.5px;">PM Indoor</span>
          <p style="font-size: 1.6rem; font-weight: 800; color: var(--primary); margin-top: 0.2rem;"><?= $indoorCount ?></p>
        </div>
        <div class="stat-icon-wrapper" style="background: var(--primary-subtle); color: var(--primary);">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
        </div>
      </div>
    </div>

    <div class="card stat-filter-card" data-filter="outdoor" style="margin-bottom: 0;" title="Klik untuk memfilter laporan PM Outdoor">
      <div class="card-body" style="display: flex; align-items: center; justify-content: space-between;">
        <div>
          <span style="font-size: 0.75rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; letter-spacing: 0.5px;">PM Outdoor</span>
          <p style="font-size: 1.6rem; font-weight: 800; color: #0284c7; margin-top: 0.2rem;"><?= $outdoorCount ?></p>
        </div>
        <div class="stat-icon-wrapper" style="background: #e0f2fe; color: #0284c7;">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H7M19 9H5M21 13H3M16 17H8"/></svg>
        </div>
      </div>
    </div>

    <a href="sites" class="card stat-filter-card" style="margin-bottom: 0; text-decoration: none; color: inherit;" title="Buka Master Data Site Bandara">
      <div class="card-body" style="display: flex; align-items: center; justify-content: space-between;">
        <div>
          <span style="font-size: 0.75rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; letter-spacing: 0.5px;">Master Site</span>
          <p style="font-size: 1.6rem; font-weight: 800; color: var(--text-main); margin-top: 0.2rem;"><?= $siteCount ?></p>
        </div>
        <div class="stat-icon-wrapper" style="background: #f1f5f9; color: var(--text-main);">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>
        </div>
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
                  <span>🏢</span>
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
          <span style="font-size: 0.72rem; color: var(--text-subtle);">Status jadwal pemeriksaan rutin tiang & shelter</span>
        </div>
        <a href="sites" class="btn btn-sm btn-secondary" style="font-size: 0.72rem; padding: 0.2rem 0.5rem;">Lihat Semua Site</a>
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

  <!-- Reports Section with Live Filter & Search Toolbar -->
  <div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
      <div>
        <h3 class="card-title" style="margin-bottom: 0.15rem;">Daftar Arsip Laporan Pemeliharaan (PM)</h3>
        <span style="font-size: 0.825rem; color: var(--text-subtle);">Menampilkan <strong id="reports_count_display"><?= $totalReports ?></strong> dokumen tercatat</span>
      </div>
      <a href="reports" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;">
        <span>📋</span> Buka Riwayat Lengkap &rarr;
      </a>
    </div>

    <!-- Live Search & Filter Bar -->
    <div class="table-toolbar">
      <div class="search-input-wrapper">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
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
          <div style="font-size: 3rem; margin-bottom: 0.75rem;">📋</div>
          <h4 style="font-size: 1.1rem; font-weight: 700; color: var(--text-main);">Belum Ada Laporan PM</h4>
          <p style="max-width: 450px; margin: 0.5rem auto 1.5rem auto; font-size: 0.9rem;">
            Silakan buat laporan baru untuk modul Indoor (7 halaman) atau Outdoor (13 halaman) dengan mengklik salah satu tombol di bawah.
          </p>
          <div style="display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap;">
            <a href="form_indoor" class="btn btn-primary">+ Buat PM Indoor Baru</a>
            <a href="form_outdoor" class="btn btn-secondary">+ Buat PM Outdoor Baru</a>
          </div>
        </div>
      <?php else: ?>

        <!-- Desktop View Table (Visible on desktop screens >= 768px) -->
        <div class="desktop-table-container">
          <table class="checklist-table">
            <thead>
              <tr>
                <th style="width: 50px;">No</th>
                <th>Tipe PM</th>
                <th>Nama Site & Lokasi</th>
                <th>Tanggal Inspeksi</th>
                <th>Petugas / PIC</th>
                <th>Status</th>
                <th style="text-align: right; width: 230px;">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($reports as $index => $rep): ?>
                <?php 
                  $siteDisplayName = $rep['site_name'];
                  if (empty($siteDisplayName)) {
                      $siteDisplayName = ($rep['report_type'] === 'indoor') 
                          ? 'IBC Bandara International Kualanamu' 
                          : 'Bandara Kualanamu Pole ' . ($rep['site_id'] ?: '1');
                  }
                  $locDisplayName = $rep['location_detail'] ?: $rep['region'] ?: 'Area Bandara Kualanamu';
                  $printUrl = ($rep['report_type'] === 'indoor') 
                    ? 'print_indoor?id=' . $rep['id'] 
                    : 'print_outdoor?id=' . $rep['id'];
                ?>
                <tr data-type="<?= $rep['report_type'] ?>">
                  <td style="font-weight: bold;"><?= $index + 1 ?></td>
                  <td>
                    <?php if ($rep['report_type'] === 'indoor'): ?>
                      <span style="background: var(--primary-subtle); color: var(--primary); font-weight: 700; font-size: 0.75rem; padding: 0.25rem 0.6rem; border-radius: var(--radius-sm);">
                        PM Indoor (7 Hal)
                      </span>
                    <?php else: ?>
                      <span style="background: #e0f2fe; color: var(--secondary); font-weight: 700; font-size: 0.75rem; padding: 0.25rem 0.6rem; border-radius: var(--radius-sm);">
                        PM Outdoor (13 Hal)
                      </span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <strong style="display: block; color: var(--text-main); font-size: 0.9rem;"><?= htmlspecialchars($siteDisplayName) ?></strong>
                    <span style="font-size: 0.8rem; color: var(--text-subtle);">
                      <?= htmlspecialchars($locDisplayName) ?>
                    </span>
                  </td>
                  <td>
                    <div style="font-weight: 600;"><?= date('d/m/Y', strtotime($rep['report_date'])) ?></div>
                    <span style="font-size: 0.75rem; color: var(--text-subtle);"><?= htmlspecialchars($rep['inspection_time'] ?: '') ?> <?= htmlspecialchars($rep['timezone'] ?: 'WIB') ?></span>
                  </td>
                  <td>
                    <strong><?= htmlspecialchars($rep['inspector_name']) ?></strong>
                    <?php if (!empty($rep['team_leader'])): ?>
                      <div style="font-size: 0.75rem; color: var(--text-subtle);">Leader: <?= htmlspecialchars($rep['team_leader']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span style="display: inline-flex; align-items: center; gap: 5px; background: var(--status-ok-bg); color: var(--status-ok); font-weight: 600; font-size: 0.75rem; padding: 0.25rem 0.6rem; border-radius: 20px;">
                      <span style="width: 6px; height: 6px; border-radius: 50%; background: var(--status-ok);"></span>
                      Selesai
                    </span>
                  </td>
                  <td style="text-align: right;">
                    <div class="table-action-group">
                      <a href="<?= $printUrl ?>" target="_blank" class="btn-action-view" title="Buka Dokumen PDF Resmi">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        Cetak
                      </a>
                      <a href="download_pdf?id=<?= $rep['id'] ?>" class="btn-action-dl" title="Download File PDF">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Unduh
                      </a>
                      <a href="api/delete_report.php?id=<?= $rep['id'] ?>" onclick="return confirm('Apakah Anda yakin ingin menghapus laporan ini?');" class="btn-action-del" title="Hapus Laporan">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Mobile Card View (Optimized for Phone Screens < 768px) -->
        <div class="mobile-cards-container">
          <?php foreach ($reports as $index => $rep): ?>
            <?php 
              $siteDisplayName = $rep['site_name'];
              if (empty($siteDisplayName)) {
                  $siteDisplayName = ($rep['report_type'] === 'indoor') 
                      ? 'IBC Bandara International Kualanamu' 
                      : 'Bandara Kualanamu Pole ' . ($rep['site_id'] ?: '1');
              }
              $locDisplayName = $rep['location_detail'] ?: $rep['region'] ?: 'Area Bandara Kualanamu';
              $printUrl = ($rep['report_type'] === 'indoor') 
                ? 'print_indoor?id=' . $rep['id'] 
                : 'print_outdoor?id=' . $rep['id'];
            ?>
            <div class="mobile-report-card" data-type="<?= $rep['report_type'] ?>">
              <div class="mobile-card-top">
                <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                  <?php if ($rep['report_type'] === 'indoor'): ?>
                    <span class="mobile-badge mobile-badge-indoor">PM INDOOR (7 HAL)</span>
                  <?php else: ?>
                    <span class="mobile-badge mobile-badge-outdoor">PM OUTDOOR (13 HAL)</span>
                  <?php endif; ?>
                  <span class="mobile-badge mobile-badge-date"><?= date('d/m/Y', strtotime($rep['report_date'])) ?></span>
                </div>
                <a href="api/delete_report.php?id=<?= $rep['id'] ?>" onclick="return confirm('Hapus laporan ini?');" class="mobile-delete-btn" title="Hapus">&times;</a>
              </div>

              <h4 class="mobile-card-title"><?= htmlspecialchars($siteDisplayName) ?></h4>
              <p class="mobile-card-sub"><?= htmlspecialchars($locDisplayName) ?></p>

              <div class="mobile-card-meta">
                <div class="mobile-meta-item">
                  <span class="mobile-meta-label">Petugas PIC:</span>
                  <strong><?= htmlspecialchars($rep['inspector_name']) ?></strong>
                </div>
                <?php if (!empty($rep['team_leader'])): ?>
                  <div class="mobile-meta-item">
                    <span class="mobile-meta-label">Team Leader:</span>
                    <span><?= htmlspecialchars($rep['team_leader']) ?></span>
                  </div>
                <?php endif; ?>
              </div>

              <div class="mobile-card-actions">
                <a href="<?= $printUrl ?>" target="_blank" class="btn btn-secondary mobile-act-btn">
                  <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                  Lihat / Cetak
                </a>
                <a href="download_pdf?id=<?= $rep['id'] ?>" class="btn btn-primary mobile-act-btn">
                  <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                  Download PDF
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

      <?php endif; ?>
    </div>
  </div>

</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const searchInput = document.getElementById('report_live_search');
  const filterBtns = document.querySelectorAll('.filter-tab-btn, .stat-filter-card');
  const desktopRows = document.querySelectorAll('.desktop-table-container tbody tr');
  const mobileCards = document.querySelectorAll('.mobile-report-card');
  const countDisplay = document.getElementById('reports_count_display');

  let currentFilter = 'all';
  let searchQuery = '';

  function applyFilters() {
    let visibleCount = 0;

    desktopRows.forEach(row => {
      const type = row.getAttribute('data-type') || '';
      const text = row.innerText.toLowerCase();
      const matchesFilter = (currentFilter === 'all' || type === currentFilter);
      const matchesSearch = !searchQuery || text.includes(searchQuery);

      if (matchesFilter && matchesSearch) {
        row.style.display = '';
        visibleCount++;
      } else {
        row.style.display = 'none';
      }
    });

    mobileCards.forEach(card => {
      const type = card.getAttribute('data-type') || '';
      const text = card.innerText.toLowerCase();
      const matchesFilter = (currentFilter === 'all' || type === currentFilter);
      const matchesSearch = !searchQuery || text.includes(searchQuery);

      if (matchesFilter && matchesSearch) {
        card.style.display = '';
      } else {
        card.style.display = 'none';
      }
    });

    if (countDisplay) {
      countDisplay.innerText = visibleCount;
    }
  }

  if (searchInput) {
    searchInput.addEventListener('input', function () {
      searchQuery = this.value.trim().toLowerCase();
      applyFilters();
    });
  }

  filterBtns.forEach(btn => {
    btn.addEventListener('click', function () {
      const filter = this.getAttribute('data-filter');
      if (!filter) return;

      currentFilter = filter;

      document.querySelectorAll('.filter-tab-btn').forEach(b => {
        b.classList.toggle('active', b.getAttribute('data-filter') === filter);
      });

      document.querySelectorAll('.stat-filter-card').forEach(c => {
        c.classList.toggle('active-filter', c.getAttribute('data-filter') === filter);
      });

      applyFilters();
    });
  });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
