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

  <!-- Welcome Hero Banner -->
  <div style="background: linear-gradient(135deg, #008080 0%, #0369a1 100%); color: #ffffff; border-radius: var(--radius-md); padding: 1.5rem 1.25rem; margin-bottom: 1.5rem; box-shadow: var(--shadow-md);">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
      <div>
        <h2 style="font-size: 1.4rem; font-weight: 700; margin-bottom: 0.25rem;">Portal Preventive Maintenance (PM)</h2>
        <p style="font-size: 0.9rem; opacity: 0.9; max-width: 600px;">Generator checklist dan dokumentasi foto standar resmi IASS SBU ICT KNO & Operator Seluler.</p>
      </div>
      <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; width: 100%; max-width: 380px;">
        <a href="form_indoor.php" class="btn btn-secondary" style="flex: 1; color: #008080; font-weight: 700; min-height: 44px;">+ PM Indoor</a>
        <a href="form_outdoor.php" class="btn btn-secondary" style="flex: 1; color: #0369a1; font-weight: 700; min-height: 44px;">+ PM Outdoor</a>
      </div>
    </div>
  </div>

  <!-- Summary Stats Grid -->
  <div class="form-grid" style="margin-bottom: 1.5rem;">
    <div class="card" style="margin-bottom: 0;">
      <div class="card-body" style="display: flex; align-items: center; justify-content: space-between;">
        <div>
          <span style="font-size: 0.75rem; font-weight: 600; color: var(--text-subtle); text-transform: uppercase;">Total Laporan</span>
          <p style="font-size: 1.6rem; font-weight: 800; color: var(--text-main); margin-top: 0.2rem;"><?= $totalReports ?></p>
        </div>
        <div style="width: 44px; height: 44px; background: var(--primary-subtle); color: var(--primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; font-weight: bold;">
          #
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom: 0;">
      <div class="card-body" style="display: flex; align-items: center; justify-content: space-between;">
        <div>
          <span style="font-size: 0.75rem; font-weight: 600; color: var(--text-subtle); text-transform: uppercase;">PM Indoor</span>
          <p style="font-size: 1.6rem; font-weight: 800; color: var(--primary); margin-top: 0.2rem;"><?= $indoorCount ?></p>
        </div>
        <div style="width: 44px; height: 44px; background: var(--primary-subtle); color: var(--primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.95rem; font-weight: bold;">
          IN
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom: 0;">
      <div class="card-body" style="display: flex; align-items: center; justify-content: space-between;">
        <div>
          <span style="font-size: 0.75rem; font-weight: 600; color: var(--text-subtle); text-transform: uppercase;">PM Outdoor</span>
          <p style="font-size: 1.6rem; font-weight: 800; color: var(--secondary); margin-top: 0.2rem;"><?= $outdoorCount ?></p>
        </div>
        <div style="width: 44px; height: 44px; background: #e0f2fe; color: var(--secondary); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.95rem; font-weight: bold;">
          OUT
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom: 0;">
      <div class="card-body" style="display: flex; align-items: center; justify-content: space-between;">
        <div>
          <span style="font-size: 0.75rem; font-weight: 600; color: var(--text-subtle); text-transform: uppercase;">Master Site</span>
          <p style="font-size: 1.6rem; font-weight: 800; color: var(--text-main); margin-top: 0.2rem;"><?= $siteCount ?></p>
        </div>
        <div style="width: 44px; height: 44px; background: var(--bg-alt); color: var(--text-muted); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.95rem; font-weight: bold;">
          Site
        </div>
      </div>
    </div>
  </div>

  <!-- Reports Section -->
  <div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
      <div>
        <h3 class="card-title" style="margin-bottom: 0;">Daftar Arsip Laporan Pemeliharaan (PM)</h3>
        <span style="font-size: 0.85rem; color: var(--text-subtle);"><?= $totalReports ?> dokumen tercatat</span>
      </div>
      <a href="reports.php" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 4px; font-weight: 600;">
        <span>📋</span> Buka Riwayat Lengkap &rarr;
      </a>
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
            <a href="form_indoor.php" class="btn btn-primary">+ Buat PM Indoor Baru</a>
            <a href="form_outdoor.php" class="btn btn-secondary">+ Buat PM Outdoor Baru</a>
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
                <th style="text-align: right; width: 260px;">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($reports as $index => $rep): ?>
                <tr>
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
                    <strong style="display: block; color: var(--text-main);"><?= htmlspecialchars($rep['site_name'] ?: 'Site ID #' . $rep['site_id']) ?></strong>
                    <span style="font-size: 0.8rem; color: var(--text-subtle);">
                      <?= htmlspecialchars($rep['location_detail'] ?: $rep['region'] ?: '-') ?>
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
                    <span style="background: var(--status-ok-bg); color: var(--status-ok); font-weight: 600; font-size: 0.75rem; padding: 0.2rem 0.5rem; border-radius: var(--radius-sm);">
                      Selesai
                    </span>
                  </td>
                  <td style="text-align: right;">
                    <div style="display: inline-flex; gap: 0.35rem; align-items: center;">
                      <?php 
                        $printUrl = ($rep['report_type'] === 'indoor') 
                          ? 'print_indoor.php?id=' . $rep['id'] 
                          : 'print_outdoor.php?id=' . $rep['id'];
                      ?>
                      <a href="<?= $printUrl ?>" target="_blank" class="btn btn-secondary btn-sm" title="Buka Pratinjau & Cetak">
                        Cetak PDF
                      </a>
                      <a href="download_pdf.php?id=<?= $rep['id'] ?>" class="btn btn-primary btn-sm" title="Download File PDF Langsung">
                        Download
                      </a>
                      <a href="api/delete_report.php?id=<?= $rep['id'] ?>" onclick="return confirm('Apakah Anda yakin ingin menghapus laporan ini?');" class="btn btn-danger btn-sm" title="Hapus Laporan">
                        &times;
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
              $printUrl = ($rep['report_type'] === 'indoor') 
                ? 'print_indoor.php?id=' . $rep['id'] 
                : 'print_outdoor.php?id=' . $rep['id'];
            ?>
            <div class="mobile-report-card">
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

              <h4 class="mobile-card-title"><?= htmlspecialchars($rep['site_name'] ?: 'Site #' . $rep['site_id']) ?></h4>
              <p class="mobile-card-sub"><?= htmlspecialchars($rep['location_detail'] ?: $rep['region'] ?: '-') ?></p>

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
                <a href="download_pdf.php?id=<?= $rep['id'] ?>" class="btn btn-primary mobile-act-btn">
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

<?php include __DIR__ . '/includes/footer.php'; ?>
