<?php
/**
 * Daily Activity - Logbook Kegiatan Harian NOC Shift
 * IASS SBU ICT KNO
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$page_title = 'Daily Activity - Logbook NOC';
$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$isAdmin = has_role(['superadmin', 'admin']);

$pdo = get_db_connection();

// Determine default shift type by current hour
$currentHour = (int)date('H');
$suggestedShift = ($currentHour >= 8 && $currentHour < 20) ? 'pagi' : 'malam';
$todayDate = date('Y-m-d');

// Filter parameters
$filterDate = trim($_GET['date'] ?? $todayDate);
$filterShiftType = trim($_GET['shift_type'] ?? $suggestedShift);
$filterUserId = $isAdmin ? (int)($_GET['user_id'] ?? $userId) : $userId;
$selectedShiftId = (int)($_GET['shift_id'] ?? 0);

// Load all technicians for admin filter
$technicians = [];
if ($isAdmin) {
    try {
        $stmtTech = $pdo->query("SELECT id, username, full_name, role FROM users ORDER BY full_name ASC");
        $technicians = $stmtTech->fetchAll();
    } catch (Exception $e) {}
}

// Load master sites for autocomplete
$siteList = [];
try {
    $siteList = $pdo->query("SELECT site_name, location_detail FROM sites ORDER BY site_name ASC")->fetchAll();
} catch (Exception $e) {}

// Find current active shift session
$currentShift = null;
if ($selectedShiftId > 0) {
    $stmtShift = $pdo->prepare("SELECT * FROM daily_shifts WHERE id = ?");
    $stmtShift->execute([$selectedShiftId]);
    $currentShift = $stmtShift->fetch();
} else {
    $stmtShift = $pdo->prepare("
        SELECT * FROM daily_shifts 
        WHERE user_id = ? AND shift_date = ? AND shift_type = ? 
        ORDER BY id DESC LIMIT 1
    ");
    $stmtShift->execute([$filterUserId, $filterDate, $filterShiftType]);
    $currentShift = $stmtShift->fetch();
}

// If shift exists, fetch its activities and attached photos
$activities = [];
if ($currentShift) {
    $stmtAct = $pdo->prepare("
        SELECT a.*, u.full_name AS tech_name, u.username 
        FROM daily_activities a
        LEFT JOIN users u ON a.user_id = u.id
        WHERE a.shift_id = ? 
        ORDER BY a.activity_time ASC, a.id ASC
    ");
    $stmtAct->execute([$currentShift['id']]);
    $activities = $stmtAct->fetchAll();

    // Attach photos to each activity
    if (!empty($activities)) {
        $actIds = array_column($activities, 'id');
        $placeholders = implode(',', array_fill(0, count($actIds), '?'));
        $stmtPhotos = $pdo->prepare("
            SELECT * FROM daily_activity_photos 
            WHERE activity_id IN ($placeholders) 
            ORDER BY id ASC
        ");
        $stmtPhotos->execute($actIds);
        $allPhotos = $stmtPhotos->fetchAll();

        $photosByAct = [];
        foreach ($allPhotos as $p) {
            $photosByAct[$p['activity_id']][] = $p;
        }

        foreach ($activities as &$act) {
            $act['photos'] = $photosByAct[$act['id']] ?? [];
        }
        unset($act);
    }
}

// Fetch list of recent shifts for history drawer / list
$historyShifts = [];
try {
    if ($isAdmin && empty($_GET['user_id'])) {
        $stmtHist = $pdo->query("
            SELECT s.*, COUNT(a.id) AS total_activities 
            FROM daily_shifts s
            LEFT JOIN daily_activities a ON s.id = a.shift_id
            GROUP BY s.id
            ORDER BY s.shift_date DESC, s.shift_type ASC, s.id DESC
            LIMIT 20
        ");
        $historyShifts = $stmtHist->fetchAll();
    } else {
        $stmtHist = $pdo->prepare("
            SELECT s.*, COUNT(a.id) AS total_activities 
            FROM daily_shifts s
            LEFT JOIN daily_activities a ON s.id = a.shift_id
            WHERE s.user_id = ?
            GROUP BY s.id
            ORDER BY s.shift_date DESC, s.shift_type ASC, s.id DESC
            LIMIT 20
        ");
        $stmtHist->execute([$filterUserId]);
        $historyShifts = $stmtHist->fetchAll();
    }
} catch (Exception $e) {}

include __DIR__ . '/includes/header.php';
?>

<div class="container py-4">

  <!-- Breadcrumb & Top Bar -->
  <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
    <div>
      <div style="font-size: 0.8rem; color: var(--text-subtle); display: flex; align-items: center; gap: 6px;">
        <a href="./" style="color: inherit; text-decoration: none;">Dashboard</a>
        <span>/</span>
        <span style="color: var(--primary); font-weight: 600;">Daily Activity NOC</span>
      </div>
      <h2 style="font-size: 1.5rem; font-weight: 800; color: var(--text-main); margin-top: 0.25rem; letter-spacing: -0.02em;">
        Logbook Kegiatan Harian Shift
      </h2>
    </div>

    <!-- Right Actions -->
    <div style="display: flex; align-items: center; gap: 8px;">
      <?php if ($currentShift): ?>
        <a href="print_shift?shift_id=<?= (int)$currentShift['id'] ?>" target="_blank" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 6px;">
          <span>📄</span> Unduh PDF Shift
        </a>
      <?php endif; ?>
      <button type="button" class="btn btn-primary btn-sm" id="btn_open_activity_modal" style="display: inline-flex; align-items: center; gap: 6px;">
        <span>+</span> Catat Kegiatan
      </button>
    </div>
  </div>

  <!-- Filter & Shift Selector Card -->
  <div class="card p-3 mb-4" style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg);">
    <form method="GET" action="daily_activity" style="display: flex; flex-wrap: wrap; gap: 0.85rem; align-items: flex-end;">
      
      <!-- Date Picker -->
      <div style="flex: 1; min-width: 150px;">
        <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-subtle); display: block; margin-bottom: 4px;">
          Tanggal Shift
        </label>
        <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($filterDate) ?>" required>
      </div>

      <!-- Shift Type -->
      <div style="flex: 1; min-width: 150px;">
        <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-subtle); display: block; margin-bottom: 4px;">
          Jenis Shift
        </label>
        <select name="shift_type" class="form-control">
          <option value="pagi" <?= ($filterShiftType === 'pagi') ? 'selected' : '' ?>>Shift Pagi (08:00 - 20:00)</option>
          <option value="malam" <?= ($filterShiftType === 'malam') ? 'selected' : '' ?>>Shift Malam (20:00 - 08:00)</option>
        </select>
      </div>

      <!-- Technician Filter (Admin / Superadmin only) -->
      <?php if ($isAdmin): ?>
      <div style="flex: 1.2; min-width: 180px;">
        <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-subtle); display: block; margin-bottom: 4px;">
          Teknisi NOC
        </label>
        <select name="user_id" class="form-control">
          <?php foreach ($technicians as $tech): ?>
            <option value="<?= (int)$tech['id'] ?>" <?= ($filterUserId === (int)$tech['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($tech['full_name'] ?: $tech['username']) ?> (<?= htmlspecialchars($tech['role']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div style="display: flex; gap: 6px;">
        <button type="submit" class="btn btn-secondary btn-sm" style="min-height: 40px; padding: 0 1.25rem;">
          Pilih Shift
        </button>
      </div>
    </form>
  </div>

  <div class="row g-4">
    <!-- Main Left Column: Current Shift Banner & Activity Timeline -->
    <div class="col-lg-8">
      
      <!-- Shift Header Banner -->
      <div class="daily-shift-card">
        <div class="daily-shift-header">
          <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 44px; height: 44px; border-radius: 50%; background: var(--primary-subtle); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 1.3rem;">
              <?= ($filterShiftType === 'pagi') ? '☀️' : '🌙' ?>
            </div>
            <div>
              <div style="display: flex; align-items: center; gap: 8px;">
                <h4 style="font-size: 1.15rem; font-weight: 700; margin: 0; color: var(--text-main);">
                  Shift <?= ($filterShiftType === 'pagi') ? 'Pagi (08:00 - 20:00)' : 'Malam (20:00 - 08:00)' ?>
                </h4>
                <span class="shift-badge <?= ($filterShiftType === 'pagi') ? 'shift-badge-pagi' : 'shift-badge-malam' ?>">
                  <?= strtoupper($filterShiftType) ?>
                </span>
                <?php if ($currentShift && $currentShift['status'] === 'completed'): ?>
                  <span class="sla-status-pill sla-normal">Selesai &amp; Terkunci</span>
                <?php else: ?>
                  <span class="sla-status-pill sla-warning">Sedang Berjalan</span>
                <?php endif; ?>
              </div>
              <div style="font-size: 0.8rem; color: var(--text-subtle); margin-top: 2px;">
                <span>📅 <?= date('d F Y', strtotime($filterDate)) ?></span>
                &bull;
                <span>👤 Teknisi: <strong><?= htmlspecialchars($currentShift['technician_name'] ?? $currentUser['full_name'] ?? $currentUser['username']) ?></strong></span>
              </div>
            </div>
          </div>

          <!-- Shift Actions -->
          <div style="display: flex; align-items: center; gap: 8px;">
            <?php if ($currentShift && $currentShift['status'] === 'active'): ?>
              <button type="button" class="btn btn-outline-danger btn-sm" id="btn_complete_shift" data-shift-id="<?= (int)$currentShift['id'] ?>" title="Selesaikan shift dan kirim laporan logbook">
                Selesaikan Shift
              </button>
            <?php endif; ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('btn_open_activity_modal').click()">
              + Catat Kegiatan
            </button>
          </div>
        </div>

        <?php if ($currentShift && !empty($currentShift['notes'])): ?>
          <div style="margin-top: 1rem; padding: 0.75rem 1rem; background: var(--bg-alt); border-radius: var(--radius-sm); font-size: 0.82rem; color: var(--text-muted); border-left: 3px solid var(--primary);">
            <strong>Catatan Serah Terima / Penutupan Shift:</strong> <?= nl2br(htmlspecialchars($currentShift['notes'])) ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Activities Timeline List -->
      <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
        <h5 style="font-size: 0.95rem; font-weight: 700; color: var(--text-main); margin: 0;">
          Daftar Kegiatan Shift Ini (<?= count($activities) ?>)
        </h5>
        <span style="font-size: 0.78rem; color: var(--text-subtle);">Urut berdasarkan waktu kegiatan</span>
      </div>

      <?php if (empty($activities)): ?>
        <div class="card p-5 text-center" style="background: var(--bg-card); border: 1px dashed var(--border-strong); border-radius: var(--radius-lg);">
          <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">📝</div>
          <h4 style="font-size: 1.1rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.25rem;">Belum Ada Catatan Kegiatan</h4>
          <p style="font-size: 0.85rem; color: var(--text-subtle); max-width: 450px; margin: 0 auto 1.25rem auto;">
            Belum ada kegiatan yang dicatat untuk shift ini. Klik tombol di bawah untuk mulai mencatat kegiatan monitoring, patroli, atau penanganan gangguan NOC.
          </p>
          <div>
            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('btn_open_activity_modal').click()">
              + Catat Kegiatan Pertama
            </button>
          </div>
        </div>
      <?php else: ?>
        <div class="activity-timeline-wrapper">
          <?php foreach ($activities as $act): 
            $cat = strtolower($act['category']);
            $catClass = 'cat-' . $cat;
            if (!in_array($cat, ['monitoring', 'patroli', 'troubleshooting', 'maintenance', 'administrasi'])) {
                $catClass = 'cat-lainnya';
            }
          ?>
            <div class="activity-card" id="act_card_<?= (int)$act['id'] ?>">
              <div class="activity-card-header">
                <div>
                  <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 4px;">
                    <span class="activity-time-pill">⏰ <?= htmlspecialchars($act['activity_time']) ?></span>
                    <span class="cat-badge <?= $catClass ?>"><?= htmlspecialchars($act['category']) ?></span>
                    <?php if (!empty($act['location'])): ?>
                      <span style="font-size: 0.75rem; color: var(--text-subtle); display: inline-flex; align-items: center; gap: 4px;">
                        <span>📍</span> <?= htmlspecialchars($act['location']) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                  <h4 style="font-size: 1.05rem; font-weight: 700; color: var(--text-main); margin: 0;">
                    <?= htmlspecialchars($act['title']) ?>
                  </h4>
                </div>

                <!-- Delete Action (if shift active and permitted) -->
                <?php if ((!$currentShift || $currentShift['status'] === 'active') && ($isAdmin || (int)$act['user_id'] === $userId)): ?>
                  <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteActivity(<?= (int)$act['id'] ?>)" title="Hapus Kegiatan" style="padding: 2px 8px; font-size: 0.75rem;">
                    &times; Hapus
                  </button>
                <?php endif; ?>
              </div>

              <!-- Activity Detail Description -->
              <div style="font-size: 0.88rem; color: var(--text-muted); line-height: 1.6; margin-top: 0.5rem; white-space: pre-line;">
                <?= nl2br(htmlspecialchars($act['description'])) ?>
              </div>

              <!-- Photo Attachments -->
              <?php if (!empty($act['photos'])): ?>
                <div style="margin-top: 0.85rem; border-top: 1px dashed var(--border); padding-top: 0.75rem;">
                  <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-subtle); margin-bottom: 0.5rem;">
                    DOKUMENTASI FOTO (<?= count($act['photos']) ?>):
                  </div>
                  <div class="activity-photos-grid">
                    <?php foreach ($act['photos'] as $photo): ?>
                      <div class="activity-photo-item" onclick="viewPhotoFull('<?= htmlspecialchars($photo['photo_path']) ?>', '<?= htmlspecialchars($act['title']) ?>')">
                        <img src="<?= htmlspecialchars($photo['photo_path']) ?>" alt="Foto Kegiatan" loading="lazy">
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div>

    <!-- Right Sidebar Column: Shift History & Quick Links -->
    <div class="col-lg-4">
      
      <!-- Shift Summary Card -->
      <div class="card p-3 mb-4" style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg);">
        <h5 style="font-size: 0.9rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.75rem; display: flex; align-items: center; justify-content: space-between;">
          <span>Ringkasan Shift Ini</span>
          <?php if ($currentShift): ?>
            <a href="print_shift?shift_id=<?= (int)$currentShift['id'] ?>" target="_blank" class="btn btn-sm btn-primary-soft" style="font-size: 0.72rem; padding: 2px 8px;">
              Cetak PDF
            </a>
          <?php endif; ?>
        </h5>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 1rem;">
          <div style="background: var(--bg-alt); padding: 0.75rem; border-radius: var(--radius-sm); text-align: center;">
            <div style="font-size: 1.35rem; font-weight: 800; color: var(--primary);">
              <?= count($activities) ?>
            </div>
            <div style="font-size: 0.7rem; color: var(--text-subtle); text-transform: uppercase; font-weight: 600;">
              Total Kegiatan
            </div>
          </div>

          <div style="background: var(--bg-alt); padding: 0.75rem; border-radius: var(--radius-sm); text-align: center;">
            <div style="font-size: 1.35rem; font-weight: 800; color: var(--secondary);">
              <?php 
                $photoSum = 0;
                foreach ($activities as $a) { $photoSum += count($a['photos'] ?? []); }
                echo $photoSum;
              ?>
            </div>
            <div style="font-size: 0.7rem; color: var(--text-subtle); text-transform: uppercase; font-weight: 600;">
              Foto Terlampir
            </div>
          </div>
        </div>

        <div style="font-size: 0.78rem; color: var(--text-muted); line-height: 1.5;">
          <div>&bull; Jam Shift Pagi: <strong>08:00 - 20:00 WIB</strong></div>
          <div>&bull; Jam Shift Malam: <strong>20:00 - 08:00 WIB</strong></div>
          <div style="margin-top: 6px; color: var(--text-subtle); font-size: 0.72rem;">
            * Setelah shift selesai, tekan tombol "Selesaikan Shift" untuk mengunci dan mengirimkan rekapan resmi ke Admin.
          </div>
        </div>
      </div>

      <!-- Recent Shifts History -->
      <div class="card p-3" style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg);">
        <h5 style="font-size: 0.9rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.75rem;">
          Riwayat Shift Terkini
        </h5>

        <?php if (empty($historyShifts)): ?>
          <p style="font-size: 0.8rem; color: var(--text-subtle); margin: 0;">Belum ada riwayat shift terdahulu.</p>
        <?php else: ?>
          <div style="display: flex; flex-direction: column; gap: 8px;">
            <?php foreach ($historyShifts as $hs): 
              $isSel = ($currentShift && (int)$hs['id'] === (int)$currentShift['id']);
            ?>
              <div style="padding: 0.65rem 0.75rem; background: <?= $isSel ? 'var(--primary-subtle)' : 'var(--bg-alt)' ?>; border: 1px solid <?= $isSel ? 'var(--primary-border)' : 'var(--border)' ?>; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                <div>
                  <div style="font-size: 0.82rem; font-weight: 700; color: var(--text-main);">
                    <?= date('d M Y', strtotime($hs['shift_date'])) ?> &bull; Shift <?= ucfirst($hs['shift_type']) ?>
                  </div>
                  <div style="font-size: 0.72rem; color: var(--text-subtle);">
                    <span><?= htmlspecialchars($hs['technician_name']) ?></span> &bull; 
                    <span><?= (int)$hs['total_activities'] ?> kegiatan</span>
                  </div>
                </div>

                <div style="display: flex; align-items: center; gap: 4px;">
                  <a href="daily_activity?shift_id=<?= (int)$hs['id'] ?>" class="btn btn-sm btn-secondary" style="font-size: 0.7rem; padding: 2px 6px;" title="Lihat Shift">
                    Buka
                  </a>
                  <a href="print_shift?shift_id=<?= (int)$hs['id'] ?>" target="_blank" class="btn btn-sm btn-primary-soft" style="font-size: 0.7rem; padding: 2px 6px;" title="Unduh PDF">
                    PDF
                  </a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </div>

</div>

<!-- Modal: Catat Kegiatan Harian -->
<div id="activity_modal" class="modal-backdrop-custom" style="display: none;">
  <div class="modal-dialog-custom">
    <div class="modal-content-custom">
      
      <div class="modal-header-custom">
        <div style="display: flex; align-items: center; gap: 8px;">
          <span style="font-size: 1.25rem;">📝</span>
          <h4 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text-main);">Catat Kegiatan Harian NOC</h4>
        </div>
        <button type="button" class="modal-close-btn" id="btn_close_activity_modal">&times;</button>
      </div>

      <form id="form_save_activity" enctype="multipart/form-data">
        <input type="hidden" name="shift_id" value="<?= (int)($currentShift['id'] ?? 0) ?>">
        <input type="hidden" name="shift_date" value="<?= htmlspecialchars($filterDate) ?>">
        <input type="hidden" name="shift_type" value="<?= htmlspecialchars($filterShiftType) ?>">

        <div class="modal-body-custom">
          
          <!-- Row 1: Time & Category -->
          <div class="row g-3 mb-3">
            <div class="col-md-5">
              <label class="form-label" style="font-size: 0.8rem; font-weight: 700;">Waktu Kegiatan</label>
              <input type="text" name="activity_time" id="input_act_time" class="form-control" value="<?= date('H:i') ?> WIB" placeholder="Contoh: 09:30 WIB" required>
            </div>
            <div class="col-md-7">
              <label class="form-label" style="font-size: 0.8rem; font-weight: 700;">Kategori Kegiatan Cepat</label>
              <input type="hidden" name="category" id="input_category" value="Monitoring">
              <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 2px;" id="cat_quick_buttons">
                <button type="button" class="btn btn-sm btn-cat-badge active" data-cat="Monitoring">Monitoring</button>
                <button type="button" class="btn btn-sm btn-cat-badge" data-cat="Patroli">Patroli</button>
                <button type="button" class="btn btn-sm btn-cat-badge" data-cat="Troubleshooting">Troubleshoot</button>
                <button type="button" class="btn btn-sm btn-cat-badge" data-cat="Maintenance">Maintenance</button>
                <button type="button" class="btn btn-sm btn-cat-badge" data-cat="Administrasi">Administrasi</button>
                <button type="button" class="btn btn-sm btn-cat-badge" data-cat="Lainnya">Lainnya</button>
              </div>
            </div>
          </div>

          <!-- Row 2: Location / Site -->
          <div class="mb-3">
            <label class="form-label" style="font-size: 0.8rem; font-weight: 700;">Lokasi / Site (Opsional)</label>
            <input type="text" name="location" list="site_datalist" class="form-control" placeholder="Pilih atau ketik lokasi / site (Contoh: Ruang Server KNO, Tiang Pole 2)">
            <datalist id="site_datalist">
              <?php foreach ($siteList as $s): ?>
                <option value="<?= htmlspecialchars($s['site_name']) ?>"><?= htmlspecialchars($s['location_detail'] ?? '') ?></option>
              <?php endforeach; ?>
            </datalist>
          </div>

          <!-- Row 3: Title -->
          <div class="mb-3">
            <label class="form-label" style="font-size: 0.8rem; font-weight: 700;">Judul / Ringkasan Kegiatan <span style="color: red;">*</span></label>
            <input type="text" name="title" class="form-control" placeholder="Contoh: Monitoring suhu shelter &amp; status link Telkomsel" required>
          </div>

          <!-- Row 4: Detailed Description -->
          <div class="mb-3">
            <label class="form-label" style="font-size: 0.8rem; font-weight: 700;">Uraian Detail Kegiatan <span style="color: red;">*</span></label>
            <textarea name="description" class="form-control" rows="4" placeholder="Jelaskan secara rinci apa yang dilakukan, parameter yang dicek, tindakan perbaikan, atau status perangkat..." required></textarea>
          </div>

          <!-- Row 5: Multi-photo Attachment -->
          <div class="mb-2">
            <label class="form-label" style="font-size: 0.8rem; font-weight: 700; display: flex; align-items: center; justify-content: space-between;">
              <span>Lampiran Dokumentasi Foto</span>
              <span style="font-size: 0.72rem; color: var(--text-subtle);">Bisa 1 atau banyak foto</span>
            </label>
            
            <div style="display: flex; gap: 8px; margin-bottom: 0.75rem;">
              <label for="input_daily_photos" class="btn btn-secondary btn-sm" style="cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                <span>📁</span> Pilih dari Galeri / File
              </label>
              <input type="file" id="input_daily_photos" accept="image/*" multiple style="display: none;">

              <button type="button" class="btn btn-primary-soft btn-sm" id="btn_take_camera_photo" style="display: inline-flex; align-items: center; gap: 6px;">
                <span>📷</span> Ambil Foto Kamera
              </button>
            </div>

            <!-- Photos Preview Grid -->
            <div id="photos_preview_container" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 8px;"></div>
          </div>

        </div>

        <div class="modal-footer-custom">
          <button type="button" class="btn btn-secondary btn-sm" id="btn_cancel_activity">Batal</button>
          <button type="submit" class="btn btn-primary btn-sm" id="btn_submit_activity">Simpan Kegiatan</button>
        </div>
      </form>

    </div>
  </div>
</div>

<!-- Modal: View Photo Fullscreen -->
<div id="photo_viewer_modal" class="modal-backdrop-custom" style="display: none;" onclick="this.style.display='none'">
  <div style="max-width: 90vw; max-height: 90vh; margin: auto; position: relative;">
    <img id="full_photo_img" src="" alt="Foto Full" style="max-width: 100%; max-height: 85vh; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.5); object-fit: contain;">
    <div id="full_photo_caption" style="color: #ffffff; text-align: center; margin-top: 8px; font-size: 0.9rem; font-weight: 600;"></div>
  </div>
</div>

<style>
/* Custom Modal Styles */
.modal-backdrop-custom {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  backdrop-filter: blur(4px);
  z-index: 1100;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}

.modal-dialog-custom {
  width: 100%;
  max-width: 620px;
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  box-shadow: 0 20px 40px rgba(0,0,0,0.25);
  animation: slideUpFade 0.2s cubic-bezier(0.16, 1, 0.3, 1);
  overflow: hidden;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
}

.modal-header-custom {
  padding: 1.1rem 1.25rem;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.modal-close-btn {
  background: transparent;
  border: none;
  font-size: 1.5rem;
  line-height: 1;
  color: var(--text-muted);
  cursor: pointer;
}

.modal-body-custom {
  padding: 1.25rem;
  overflow-y: auto;
}

.modal-footer-custom {
  padding: 0.85rem 1.25rem;
  border-top: 1px solid var(--border);
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  background: var(--bg-alt);
}

.btn-cat-badge {
  background: var(--bg-alt);
  color: var(--text-muted);
  border: 1px solid var(--border);
  font-size: 0.72rem;
  font-weight: 600;
  padding: 0.2rem 0.6rem;
  border-radius: 9999px;
  cursor: pointer;
  transition: all 0.15s ease;
}

.btn-cat-badge:hover {
  background: var(--primary-subtle);
  color: var(--primary);
  border-color: var(--primary-border);
}

.btn-cat-badge.active {
  background: var(--primary);
  color: #ffffff;
  border-color: var(--primary);
  font-weight: 700;
}
</style>

<script>
// Array to hold files & camera captures to upload
var queuedFiles = [];
var queuedBase64 = [];

document.addEventListener('DOMContentLoaded', function() {
  var modal = document.getElementById('activity_modal');
  var btnOpen = document.getElementById('btn_open_activity_modal');
  var btnClose = document.getElementById('btn_close_activity_modal');
  var btnCancel = document.getElementById('btn_cancel_activity');

  function openModal() {
    if (modal) modal.style.display = 'flex';
  }
  function closeModal() {
    if (modal) modal.style.display = 'none';
  }

  if (btnOpen) btnOpen.addEventListener('click', openModal);
  if (btnClose) btnClose.addEventListener('click', closeModal);
  if (btnCancel) btnCancel.addEventListener('click', closeModal);

  // Quick Category Buttons
  var catButtons = document.querySelectorAll('.btn-cat-badge');
  var inputCategory = document.getElementById('input_category');
  catButtons.forEach(function(btn) {
    btn.addEventListener('click', function() {
      catButtons.forEach(function(b) { b.classList.remove('active'); });
      this.classList.add('active');
      if (inputCategory) inputCategory.value = this.getAttribute('data-cat');
    });
  });

  // Multi-photo File Picker
  var photoInput = document.getElementById('input_daily_photos');
  var previewContainer = document.getElementById('photos_preview_container');

  if (photoInput) {
    photoInput.addEventListener('change', function(e) {
      var files = Array.from(e.target.files);
      files.forEach(function(file) {
        queuedFiles.push(file);
        renderPreview(file, 'file', queuedFiles.length - 1);
      });
      photoInput.value = '';
    });
  }

  function renderPreview(source, type, index) {
    var box = document.createElement('div');
    box.className = 'photo-preview-box';
    box.id = 'preview_' + type + '_' + index;

    var img = document.createElement('img');
    if (type === 'file') {
      img.src = URL.createObjectURL(source);
    } else {
      img.src = source;
    }

    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn-remove-preview';
    removeBtn.innerHTML = '&times;';
    removeBtn.onclick = function() {
      box.remove();
      if (type === 'file') {
        queuedFiles[index] = null;
      } else {
        queuedBase64[index] = null;
      }
    };

    box.appendChild(img);
    box.appendChild(removeBtn);
    previewContainer.appendChild(box);
  }

  // Camera integration trigger
  var btnCamera = document.getElementById('btn_take_camera_photo');
  if (btnCamera) {
    btnCamera.addEventListener('click', function() {
      if (typeof openCameraModal === 'function') {
        window.dailyCameraCallback = function(dataUrl) {
          queuedBase64.push(dataUrl);
          renderPreview(dataUrl, 'base64', queuedBase64.length - 1);
        };
        openCameraModal('daily_activity', 'Foto Kegiatan Shift');
      } else {
        alert('Modul kamera sedang dimuat, silakan gunakan tombol galeri.');
      }
    });
  }

  // Form Submission via AJAX
  var form = document.getElementById('form_save_activity');
  var btnSubmit = document.getElementById('btn_submit_activity');

  if (form) {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      btnSubmit.disabled = true;
      btnSubmit.textContent = 'Menyimpan...';

      var formData = new FormData(form);

      // Append queued files
      queuedFiles.forEach(function(f) {
        if (f) formData.append('photos[]', f);
      });

      // Append queued base64 photos
      var validBase64 = queuedBase64.filter(function(b) { return b !== null; });
      if (validBase64.length > 0) {
        formData.append('base64_photos', JSON.stringify(validBase64));
      }

      fetch('api/save_daily_activity.php', {
        method: 'POST',
        body: formData
      })
      .then(function(res) { return res.json(); })
      .then(function(data) {
        btnSubmit.disabled = false;
        btnSubmit.textContent = 'Simpan Kegiatan';
        if (data.success) {
          alert(data.message);
          window.location.reload();
        } else {
          alert('Gagal: ' + (data.message || 'Terjadi kesalahan sistem.'));
        }
      })
      .catch(function(err) {
        btnSubmit.disabled = false;
        btnSubmit.textContent = 'Simpan Kegiatan';
        alert('Terjadi error jaringan: ' + err.message);
      });
    });
  }

  // Complete Shift Action
  var btnComplete = document.getElementById('btn_complete_shift');
  if (btnComplete) {
    btnComplete.addEventListener('click', function() {
      var shiftId = this.getAttribute('data-shift-id');
      var notes = prompt('Masukkan catatan serah terima atau ringkasan penutupan shift (opsional):', '');
      if (notes === null) return; // User cancelled

      if (!confirm('Apakah Anda yakin ingin menyelesaikan shift ini? Logbook akan dikunci dan dilaporkan ke pimpinan/admin.')) {
        return;
      }

      var fd = new FormData();
      fd.append('shift_id', shiftId);
      fd.append('notes', notes);

      fetch('api/complete_shift.php', {
        method: 'POST',
        body: fd
      })
      .then(function(res) { return res.json(); })
      .then(function(data) {
        if (data.success) {
          alert(data.message);
          window.location.reload();
        } else {
          alert('Gagal: ' + (data.message || 'Terjadi kesalahan.'));
        }
      })
      .catch(function(err) {
        alert('Error: ' + err.message);
      });
    });
  }
});

// Delete Activity
function deleteActivity(actId) {
  if (!confirm('Apakah Anda yakin ingin menghapus kegiatan ini beserta foto dokumentasinya?')) {
    return;
  }
  var fd = new FormData();
  fd.append('activity_id', actId);

  fetch('api/delete_daily_activity.php', {
    method: 'POST',
    body: fd
  })
  .then(function(res) { return res.json(); })
  .then(function(data) {
    if (data.success) {
      var card = document.getElementById('act_card_' + actId);
      if (card) card.remove();
      alert(data.message);
    } else {
      alert('Gagal: ' + (data.message || 'Terjadi kesalahan.'));
    }
  })
  .catch(function(err) {
    alert('Error: ' + err.message);
  });
}

// Full Photo Viewer
function viewPhotoFull(src, caption) {
  var modal = document.getElementById('photo_viewer_modal');
  var img = document.getElementById('full_photo_img');
  var cap = document.getElementById('full_photo_caption');
  if (modal && img) {
    img.src = src;
    if (cap) cap.textContent = caption || '';
    modal.style.display = 'flex';
  }
}
</script>

<?php
include __DIR__ . '/includes/footer.php';
?>
