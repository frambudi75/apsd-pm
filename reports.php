<?php
/**
 * Riwayat & Arsip Laporan PM
 * PT Angkasa Pura Sarana Digital
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/redis.php';
$pdo = get_db_connection();

$page_title = 'Riwayat & Arsip Laporan PM';
include __DIR__ . '/includes/header.php';

// Fetch reports with Redis cache support
$cachedReports = AppRedis::get('reports:all');
if ($cachedReports !== null && is_array($cachedReports)) {
    $reports = $cachedReports;
} else {
    $stmt = $pdo->query("
        SELECT r.*, s.site_name, s.location_detail, s.region, s.city
        FROM pm_reports r
        LEFT JOIN sites s ON r.site_id = s.id
        ORDER BY r.id DESC
    ");
    $reports = $stmt->fetchAll();
    AppRedis::set('reports:all', $reports, 60);
}

// Compute statistics
$totalReports = count($reports);
$indoorCount = 0;
$outdoorCount = 0;
foreach ($reports as $r) {
    if ($r['report_type'] === 'indoor') $indoorCount++;
    if ($r['report_type'] === 'outdoor') $outdoorCount++;
}
?>

<main class="container">

  <!-- Breadcrumb and Page Title -->
  <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem;">
    <div>
      <div style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; color: var(--text-subtle); margin-bottom: 0.35rem;">
        <a href="index.php" style="color: var(--primary); text-decoration: none; font-weight: 600;">Dashboard</a>
        <span>/</span>
        <span style="color: var(--text-main); font-weight: 600;">Riwayat Laporan PM</span>
      </div>
      <h2 style="font-size: 1.5rem; font-weight: 800; color: var(--text-main); line-height: 1.2;">
        Riwayat & Arsip Laporan PM
      </h2>
      <p style="font-size: 0.875rem; color: var(--text-subtle); margin-top: 0.25rem;">
        Arsip digital seluruh checklist pemeliharaan berkala indoor dan outdoor PT Angkasa Pura Sarana Digital.
      </p>
    </div>

    <!-- Quick Action New Reports -->
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
      <a href="form_indoor.php" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 700; min-height: 44px;">
        <span>+</span> Buat PM Indoor
      </a>
      <a href="form_outdoor.php" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 700; min-height: 44px; color: var(--secondary);">
        <span>+</span> Buat PM Outdoor
      </a>
    </div>
  </div>

  <?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
    <div class="alert alert-success" style="margin-bottom: 1.25rem;">
      Laporan berhasil dihapus dari arsip database.
    </div>
  <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'created'): ?>
    <div class="alert alert-success" style="margin-bottom: 1.25rem;">
      Laporan PM baru berhasil disimpan dan masuk ke arsip riwayat.
    </div>
  <?php endif; ?>

  <!-- Stats & Filter Bar Card -->
  <div class="card" style="margin-bottom: 1.25rem;">
    <div class="card-body" style="padding: 1.25rem;">
      
      <!-- Stats Row -->
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 1.25rem; padding-bottom: 1.25rem; border-bottom: 1px solid var(--border);">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
          <div style="width: 44px; height: 44px; background: var(--bg-alt); border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; font-size: 1.25rem; color: var(--text-main); font-weight: bold;">
            📋
          </div>
          <div>
            <div style="font-size: 0.75rem; color: var(--text-subtle); text-transform: uppercase; font-weight: 600;">Total Laporan</div>
            <div id="stat_total" style="font-size: 1.4rem; font-weight: 800; color: var(--text-main); line-height: 1.2;"><?= $totalReports ?></div>
          </div>
        </div>

        <div style="display: flex; align-items: center; gap: 0.75rem;">
          <div style="width: 44px; height: 44px; background: var(--primary-subtle); border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: var(--primary); font-weight: bold;">
            IN
          </div>
          <div>
            <div style="font-size: 0.75rem; color: var(--text-subtle); text-transform: uppercase; font-weight: 600;">PM Indoor (7 Hal)</div>
            <div id="stat_indoor" style="font-size: 1.4rem; font-weight: 800; color: var(--primary); line-height: 1.2;"><?= $indoorCount ?></div>
          </div>
        </div>

        <div style="display: flex; align-items: center; gap: 0.75rem;">
          <div style="width: 44px; height: 44px; background: #e0f2fe; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: var(--secondary); font-weight: bold;">
            OUT
          </div>
          <div>
            <div style="font-size: 0.75rem; color: var(--text-subtle); text-transform: uppercase; font-weight: 600;">PM Outdoor (13 Hal)</div>
            <div id="stat_outdoor" style="font-size: 1.4rem; font-weight: 800; color: var(--secondary); line-height: 1.2;"><?= $outdoorCount ?></div>
          </div>
        </div>
      </div>

      <!-- Live Search & Category Filter Controls -->
      <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; justify-content: space-between; align-items: center;">
        
        <!-- Live Search Input -->
        <div style="flex: 1; min-width: 260px; position: relative;">
          <input 
            type="text" 
            id="report_search_input" 
            class="form-control" 
            placeholder="Cari nama site, lokasi, petugas PIC, atau leader..." 
            style="padding-left: 2.25rem; font-size: 0.9rem;"
          >
          <span style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: var(--text-subtle); pointer-events: none;">
            🔍
          </span>
        </div>

        <!-- Filter Buttons (Type) -->
        <div style="display: flex; gap: 0.35rem; flex-wrap: wrap;">
          <button type="button" class="btn btn-sm btn-filter-type active" data-type="all" style="min-height: 38px; border-radius: 20px; font-weight: 600; padding: 0.35rem 0.9rem;">
            Semua (<?= $totalReports ?>)
          </button>
          <button type="button" class="btn btn-sm btn-filter-type" data-type="indoor" style="min-height: 38px; border-radius: 20px; font-weight: 600; padding: 0.35rem 0.9rem;">
            Indoor (<?= $indoorCount ?>)
          </button>
          <button type="button" class="btn btn-sm btn-filter-type" data-type="outdoor" style="min-height: 38px; border-radius: 20px; font-weight: 600; padding: 0.35rem 0.9rem;">
            Outdoor (<?= $outdoorCount ?>)
          </button>
        </div>

      </div>

    </div>
  </div>

  <!-- Reports Container -->
  <div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
      <h3 class="card-title" style="margin-bottom: 0;">Daftar Dokumen Laporan</h3>
      <span id="filtered_count_badge" style="font-size: 0.825rem; color: var(--text-subtle); font-weight: 600;">
        Menampilkan <?= $totalReports ?> laporan
      </span>
    </div>

    <div class="card-body" style="padding: 0;">
      <?php if (empty($reports)): ?>
        <div style="text-align: center; padding: 3.5rem 1.5rem; color: var(--text-subtle);">
          <div style="font-size: 3.5rem; margin-bottom: 0.75rem;">📋</div>
          <h4 style="font-size: 1.15rem; font-weight: 700; color: var(--text-main);">Belum Ada Riwayat Laporan</h4>
          <p style="max-width: 460px; margin: 0.5rem auto 1.5rem auto; font-size: 0.9rem;">
            Saat teknisi menyelesaikan inspeksi lapangan melalui form PM Indoor atau PM Outdoor, dokumen laporan akan tersimpan di sini secara otomatis.
          </p>
          <div style="display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap;">
            <a href="form_indoor.php" class="btn btn-primary" style="min-height: 44px;">+ Buat PM Indoor Baru</a>
            <a href="form_outdoor.php" class="btn btn-secondary" style="min-height: 44px; color: var(--secondary);">+ Buat PM Outdoor Baru</a>
          </div>
        </div>
      <?php else: ?>

        <!-- No Results Match State (Shown when filter yields 0 matches) -->
        <div id="no_filter_match" style="display: none; text-align: center; padding: 3rem 1.5rem; color: var(--text-subtle);">
          <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">🔎</div>
          <h4 style="font-size: 1.05rem; font-weight: 700; color: var(--text-main);">Tidak ada laporan yang cocok</h4>
          <p style="font-size: 0.85rem; margin-top: 0.25rem;">Coba ganti kata kunci pencarian atau ubah filter kategori.</p>
          <button type="button" id="btn_reset_filter" class="btn btn-secondary btn-sm" style="margin-top: 1rem; min-height: 40px;">
            Reset Filter
          </button>
        </div>

        <!-- Desktop View Table (Screens >= 768px) -->
        <div class="desktop-table-container">
          <table class="checklist-table" id="reports_table">
            <thead>
              <tr>
                <th style="width: 50px;">No</th>
                <th>Tipe PM</th>
                <th>Nama Site & Lokasi</th>
                <th>Tanggal & Waktu</th>
                <th>Petugas PIC & Leader</th>
                <th>Status</th>
                <th style="text-align: right; width: 270px;">Aksi Dokumen</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($reports as $index => $rep): ?>
                <?php 
                  $printUrl = ($rep['report_type'] === 'indoor') 
                    ? 'print_indoor.php?id=' . $rep['id'] 
                    : 'print_outdoor.php?id=' . $rep['id'];
                  $searchMeta = strtolower(
                    ($rep['site_name'] ?? '') . ' ' . 
                    ($rep['location_detail'] ?? '') . ' ' . 
                    ($rep['region'] ?? '') . ' ' . 
                    ($rep['city'] ?? '') . ' ' . 
                    ($rep['inspector_name'] ?? '') . ' ' . 
                    ($rep['team_leader'] ?? '') . ' ' . 
                    ($rep['report_type'] ?? '') . ' ' .
                    ($rep['report_date'] ?? '')
                  );
                ?>
                <tr class="report-row" data-type="<?= htmlspecialchars($rep['report_type']) ?>" data-search="<?= htmlspecialchars($searchMeta) ?>">
                  <td style="font-weight: 700; color: var(--text-subtle);"><?= $index + 1 ?></td>
                  <td>
                    <?php if ($rep['report_type'] === 'indoor'): ?>
                      <span style="background: var(--primary-subtle); color: var(--primary); font-weight: 700; font-size: 0.75rem; padding: 0.25rem 0.6rem; border-radius: var(--radius-sm); display: inline-block;">
                        PM Indoor (7 Hal)
                      </span>
                    <?php else: ?>
                      <span style="background: #e0f2fe; color: var(--secondary); font-weight: 700; font-size: 0.75rem; padding: 0.25rem 0.6rem; border-radius: var(--radius-sm); display: inline-block;">
                        PM Outdoor (13 Hal)
                      </span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <strong style="display: block; color: var(--text-main); font-size: 0.95rem;">
                      <?= htmlspecialchars($rep['site_name'] ?: 'Site #' . $rep['site_id']) ?>
                    </strong>
                    <span style="font-size: 0.8rem; color: var(--text-subtle);">
                      <?= htmlspecialchars($rep['location_detail'] ?: $rep['region'] ?: '-') ?>
                    </span>
                  </td>
                  <td>
                    <div style="font-weight: 600; color: var(--text-main); font-size: 0.9rem;">
                      <?= date('d/m/Y', strtotime($rep['report_date'])) ?>
                    </div>
                    <span style="font-size: 0.75rem; color: var(--text-subtle);">
                      <?= htmlspecialchars($rep['inspection_time'] ?: '') ?> <?= htmlspecialchars($rep['timezone'] ?: 'WIB') ?>
                    </span>
                  </td>
                  <td>
                    <strong style="color: var(--text-main);"><?= htmlspecialchars($rep['inspector_name']) ?></strong>
                    <?php if (!empty($rep['team_leader'])): ?>
                      <div style="font-size: 0.75rem; color: var(--text-subtle);">
                        Leader: <?= htmlspecialchars($rep['team_leader']) ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span style="background: var(--status-ok-bg); color: var(--status-ok); font-weight: 600; font-size: 0.75rem; padding: 0.2rem 0.55rem; border-radius: var(--radius-sm); display: inline-block;">
                      Selesai
                    </span>
                  </td>
                  <td style="text-align: right;">
                    <div style="display: inline-flex; gap: 0.35rem; align-items: center;">
                      <a href="<?= $printUrl ?>" target="_blank" class="btn btn-secondary btn-sm" title="Buka Pratinjau & Cetak PDF Resmi">
                        Cetak PDF
                      </a>
                      <a href="download_pdf.php?id=<?= $rep['id'] ?>" class="btn btn-primary btn-sm" title="Unduh File PDF">
                        Download
                      </a>
                      <a href="api/delete_report.php?id=<?= $rep['id'] ?>&return=reports" onclick="return confirm('Apakah Anda yakin ingin menghapus laporan ini? Tindakan ini tidak dapat dibatalkan.');" class="btn btn-danger btn-sm" title="Hapus Laporan">
                        &times;
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Mobile Card View (Screens < 768px) -->
        <div class="mobile-cards-container" id="reports_mobile_cards">
          <?php foreach ($reports as $index => $rep): ?>
            <?php 
              $printUrl = ($rep['report_type'] === 'indoor') 
                ? 'print_indoor.php?id=' . $rep['id'] 
                : 'print_outdoor.php?id=' . $rep['id'];
              $searchMeta = strtolower(
                ($rep['site_name'] ?? '') . ' ' . 
                ($rep['location_detail'] ?? '') . ' ' . 
                ($rep['region'] ?? '') . ' ' . 
                ($rep['city'] ?? '') . ' ' . 
                ($rep['inspector_name'] ?? '') . ' ' . 
                ($rep['team_leader'] ?? '') . ' ' . 
                ($rep['report_type'] ?? '') . ' ' .
                ($rep['report_date'] ?? '')
              );
            ?>
            <div class="mobile-report-card report-card-item" data-type="<?= htmlspecialchars($rep['report_type']) ?>" data-search="<?= htmlspecialchars($searchMeta) ?>">
              <div class="mobile-card-top">
                <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                  <?php if ($rep['report_type'] === 'indoor'): ?>
                    <span class="mobile-badge mobile-badge-indoor">PM INDOOR (7 HAL)</span>
                  <?php else: ?>
                    <span class="mobile-badge mobile-badge-outdoor">PM OUTDOOR (13 HAL)</span>
                  <?php endif; ?>
                  <span class="mobile-badge mobile-badge-date"><?= date('d/m/Y', strtotime($rep['report_date'])) ?></span>
                </div>
                <a href="api/delete_report.php?id=<?= $rep['id'] ?>&return=reports" onclick="return confirm('Hapus laporan ini dari riwayat?');" class="mobile-delete-btn" title="Hapus Laporan">&times;</a>
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
                <div class="mobile-meta-item">
                  <span class="mobile-meta-label">Waktu:</span>
                  <span><?= htmlspecialchars($rep['inspection_time'] ?: '-') ?> <?= htmlspecialchars($rep['timezone'] ?: 'WIB') ?></span>
                </div>
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

<script>
  // Realtime Client-Side Search and Type Filter
  (function () {
    const searchInput = document.getElementById('report_search_input');
    const filterButtons = document.querySelectorAll('.btn-filter-type');
    const tableRows = document.querySelectorAll('.report-row');
    const mobileCards = document.querySelectorAll('.report-card-item');
    const noMatchBox = document.getElementById('no_filter_match');
    const countBadge = document.getElementById('filtered_count_badge');
    const resetBtn = document.getElementById('btn_reset_filter');

    let currentType = 'all';
    let searchQuery = '';

    function applyFilter() {
      let visibleCount = 0;

      // Filter Desktop Rows
      tableRows.forEach(function (row) {
        const rowType = row.getAttribute('data-type');
        const rowSearch = row.getAttribute('data-search') || '';

        const typeMatch = (currentType === 'all' || rowType === currentType);
        const searchMatch = !searchQuery || rowSearch.includes(searchQuery);

        if (typeMatch && searchMatch) {
          row.style.display = '';
          visibleCount++;
        } else {
          row.style.display = 'none';
        }
      });

      // Filter Mobile Cards
      let mobileVisibleCount = 0;
      mobileCards.forEach(function (card) {
        const cardType = card.getAttribute('data-type');
        const cardSearch = card.getAttribute('data-search') || '';

        const typeMatch = (currentType === 'all' || cardType === currentType);
        const searchMatch = !searchQuery || cardSearch.includes(searchQuery);

        if (typeMatch && searchMatch) {
          card.style.display = '';
          mobileVisibleCount++;
        } else {
          card.style.display = 'none';
        }
      });

      const totalVisible = Math.max(visibleCount, mobileVisibleCount);
      if (countBadge) {
        countBadge.textContent = 'Menampilkan ' + totalVisible + ' laporan';
      }

      if (noMatchBox) {
        if (totalVisible === 0 && (tableRows.length > 0 || mobileCards.length > 0)) {
          noMatchBox.style.display = 'block';
        } else {
          noMatchBox.style.display = 'none';
        }
      }
    }

    if (searchInput) {
      searchInput.addEventListener('input', function (e) {
        searchQuery = (e.target.value || '').trim().toLowerCase();
        applyFilter();
      });
    }

    filterButtons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        filterButtons.forEach(function (b) {
          b.classList.remove('active');
          b.style.backgroundColor = '';
          b.style.color = '';
        });
        btn.classList.add('active');
        btn.style.backgroundColor = 'var(--primary)';
        btn.style.color = '#ffffff';

        currentType = btn.getAttribute('data-type');
        applyFilter();
      });
    });

    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        if (searchInput) searchInput.value = '';
        searchQuery = '';
        currentType = 'all';
        filterButtons.forEach(function (b) {
          if (b.getAttribute('data-type') === 'all') {
            b.classList.add('active');
            b.style.backgroundColor = 'var(--primary)';
            b.style.color = '#ffffff';
          } else {
            b.classList.remove('active');
            b.style.backgroundColor = '';
            b.style.color = '';
          }
        });
        applyFilter();
      });
    }

    // Set initial active button style
    filterButtons.forEach(function (btn) {
      if (btn.classList.contains('active')) {
        btn.style.backgroundColor = 'var(--primary)';
        btn.style.color = '#ffffff';
      } else {
        btn.style.backgroundColor = 'var(--bg-alt)';
        btn.style.color = 'var(--text-subtle)';
      }
    });
  })();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
