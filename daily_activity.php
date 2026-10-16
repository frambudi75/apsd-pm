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

// Determine default shift type by current hour (OH: 08-17, Pagi: 08-20, Malam: 20-08)
$currentHour = (int)date('H');
if ($currentHour >= 8 && $currentHour < 17) {
    $suggestedShift = 'oh';
} elseif ($currentHour >= 17 && $currentHour < 20) {
    $suggestedShift = 'pagi';
} else {
    $suggestedShift = 'malam';
}
$todayDate = date('Y-m-d');

// Filter parameters
$filterDate = trim($_GET['date'] ?? $todayDate);
$filterShiftType = strtolower(trim($_GET['shift_type'] ?? ''));
if (empty($filterShiftType)) {
    $filterShiftType = $suggestedShift;
}
if (!in_array($filterShiftType, ['oh', 'pagi', 'malam'])) {
    $filterShiftType = 'pagi';
}

$filterUserId = $isAdmin ? (int)($_GET['user_id'] ?? $userId) : $userId;
$selectedShiftId = (int)($_GET['shift_id'] ?? 0);

// Load default settings
$defaultLeader = get_system_setting('default_team_leader', 'Eko Sulistyo');
$defaultRole = get_system_setting('default_leader_role', 'Supervisor / Team Leader NOC KNO');
$defaultWaPhone = get_system_setting('whatsapp_target_phone', '');

// Load category configuration & existing categories
$defaultCategoriesStr = get_system_setting('activity_categories', 'Monitoring, Patroli, Troubleshooting, Maintenance, Administrasi, Lainnya');
$defaultCats = array_filter(array_map('trim', explode(',', $defaultCategoriesStr)));
if (empty($defaultCats)) {
    $defaultCats = ['Monitoring', 'Patroli', 'Troubleshooting', 'Maintenance', 'Administrasi', 'Lainnya'];
}

$dbCategories = [];
try {
    $catQuery = $pdo->query("SELECT DISTINCT category FROM daily_activities WHERE category IS NOT NULL AND TRIM(category) != '' ORDER BY category ASC");
    $dbCategories = $catQuery->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$allCategories = [];
$seenCats = [];
foreach (array_merge($defaultCats, $dbCategories) as $c) {
    $clean = trim($c);
    if ($clean === '__custom__') continue;
    $lower = strtolower($clean);
    if (!empty($clean) && !isset($seenCats[$lower])) {
        $seenCats[$lower] = true;
        $allCategories[] = $clean;
    }
}

// Load all technicians for admin filter
$technicians = [];
if ($isAdmin) {
    try {
        $stmtTech = $pdo->query("SELECT id, username, full_name, role FROM users ORDER BY full_name ASC");
        $technicians = $stmtTech->fetchAll();
    } catch (Exception $e) {}
}

// Load master sites for location selector
$siteList = [];
try {
    $siteList = $pdo->query("SELECT id, site_id_str, site_name, location_detail, site_type FROM sites ORDER BY site_name ASC")->fetchAll();
} catch (Exception $e) {}

// Find current active shift session
$currentShift = null;
if ($selectedShiftId > 0) {
    $stmtShift = $pdo->prepare("SELECT * FROM daily_shifts WHERE id = ?");
    $stmtShift->execute([$selectedShiftId]);
    $currentShift = $stmtShift->fetch();
    if ($currentShift) {
        $filterDate = $currentShift['shift_date'];
        $filterShiftType = $currentShift['shift_type'];
        $filterUserId = (int)$currentShift['user_id'];
    }
} else {
    $stmtShift = $pdo->prepare("
        SELECT * FROM daily_shifts 
        WHERE user_id = ? AND shift_date = ? AND shift_type = ? 
        ORDER BY id DESC LIMIT 1
    ");
    $stmtShift->execute([$filterUserId, $filterDate, $filterShiftType]);
    $currentShift = $stmtShift->fetch();
}

$shiftInfo = get_shift_info($filterShiftType);

// If shift exists, fetch its activities and attached photos
$activities = [];
$catSummary = [];
$totalPhotos = 0;

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
            $totalPhotos++;
        }

        foreach ($activities as &$act) {
            $act['photos'] = $photosByAct[$act['id']] ?? [];
            $c = $act['category'];
            $catSummary[$c] = ($catSummary[$c] ?? 0) + 1;
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
            LIMIT 15
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
            LIMIT 15
        ");
        $stmtHist->execute([$filterUserId]);
        $historyShifts = $stmtHist->fetchAll();
    }
} catch (Exception $e) {}

// Construct WhatsApp Report Message
$techName = $currentShift['technician_name'] ?? ($currentUser['full_name'] ?? $currentUser['username']);
$shiftNameLabel = $shiftInfo['name'] . ' (' . $shiftInfo['time'] . ')';
$formattedDateIndo = date('d F Y', strtotime($filterDate));

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$pdfUrl = $protocol . $host . '/apsd/print_shift?shift_id=' . ($currentShift['id'] ?? 0);

$waMessageLines = [];
$waMessageLines[] = "*LAPORAN LOGBOOK HARIAN SHIFT NOC*";
$waMessageLines[] = "*" . get_system_setting('company_name', 'IASS SBU ICT KNO') . "*";
$waMessageLines[] = "=====================================";
$waMessageLines[] = "📅 *Tanggal*: " . $formattedDateIndo;
$waMessageLines[] = "⏱️ *Shift*: " . $shiftNameLabel;
$waMessageLines[] = "👤 *Teknisi Jaga*: " . $techName;
$waMessageLines[] = "👔 *Supervisor*: " . $defaultLeader;
$waMessageLines[] = "📊 *Status*: " . (($currentShift && $currentShift['status'] === 'completed') ? 'Selesai & Diserahkan' : 'Sedang Berjalan');
$waMessageLines[] = "📝 *Total Kegiatan*: " . count($activities) . " Catatan";
$waMessageLines[] = "📸 *Total Foto*: " . $totalPhotos . " Foto Terlampir";
$waMessageLines[] = "=====================================";
$waMessageLines[] = "";
$waMessageLines[] = "*DAFTAR KEGIATAN SHIFT:*";

if (empty($activities)) {
    $waMessageLines[] = "-(Belum ada catatan kegiatan)-";
} else {
    $num = 1;
    foreach ($activities as $act) {
        $waMessageLines[] = $num . ". [" . $act['activity_time'] . "] *" . strtoupper($act['category']) . "*";
        $waMessageLines[] = "   *Judul*: " . $act['title'];
        if (!empty($act['location'])) {
            $waMessageLines[] = "   *Lokasi*: " . $act['location'];
        }
        $waMessageLines[] = "   *Detail*: " . trim($act['description']);
        $num++;
    }
}

if ($currentShift && !empty($currentShift['notes'])) {
    $waMessageLines[] = "";
    $waMessageLines[] = "📌 *Catatan Serah Terima*:";
    $waMessageLines[] = $currentShift['notes'];
}

$waMessageLines[] = "";
$waMessageLines[] = "📄 *Unduh / Tinjau Dokumen PDF Resmi Shift*:";
$waMessageLines[] = $pdfUrl;
$waMessageLines[] = "=====================================";
$waMessageLines[] = "_Dikirim otomatis melalui Sistem PM & NOC IASS SBU ICT KNO_";

$fullWaMessage = implode("\n", $waMessageLines);

include __DIR__ . '/includes/header.php';
?>

<div class="container py-4">

  <!-- Breadcrumb & Top Bar -->
  <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem;">
    <div>
      <div style="font-size: 0.8rem; color: var(--text-subtle); display: flex; align-items: center; gap: 6px; margin-bottom: 0.35rem;">
        <a href="./" style="color: inherit; text-decoration: none;">Dashboard</a>
        <span>/</span>
        <span style="color: var(--primary); font-weight: 600;">Daily Activity NOC</span>
      </div>
      <h2 style="font-size: 1.45rem; font-weight: 800; color: var(--text-main); margin: 0; letter-spacing: -0.02em; display: flex; align-items: center; gap: 8px;">
        <?= app_icon('clock', 22, 'text-primary') ?>
        <span>Logbook Kegiatan Harian Shift</span>
      </h2>
    </div>

    <!-- Top Action -->
    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
      <?php if ($isAdmin): ?>
      <a href="daily_reports" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 6px;" title="Buka Menu Rekap &amp; Laporan Seluruh Tim NOC">
        <?= app_icon('document', 14) ?>
        <span>Rekap &amp; Laporan Tim</span>
      </a>
      <a href="print_monthly_logbook?month=<?= date('Y-m', strtotime($filterDate)) ?>" target="_blank" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 6px;" title="Cetak Rekap Laporan Bulanan Logbook NOC">
        <?= app_icon('calendar', 14) ?>
        <span>Rekap Bulanan PDF</span>
      </a>
      <?php endif; ?>
      <button type="button" class="btn btn-primary btn-sm" id="btn_open_activity_modal" 
        data-shift-exists="<?= $currentShift ? '1' : '0' ?>"
        data-shift-status="<?= htmlspecialchars($currentShift['status'] ?? '') ?>"
        style="display: inline-flex; align-items: center; gap: 6px;">
        <?= app_icon('plus', 14) ?>
        <span>Catat Kegiatan</span>
      </button>
    </div>
  </div>

  <!-- Filter & Shift Selector Bar (One Clean Row on Desktop) -->
  <div class="daily-filter-card">
    <form method="GET" action="daily_activity" class="daily-filter-form" style="display: flex; align-items: flex-end; gap: 0.85rem; flex-wrap: wrap;">
      
      <!-- Date Picker -->
      <div class="daily-filter-col" style="flex: 1; min-width: 170px;">
        <label style="font-size: 0.76rem; font-weight: 700; color: var(--text-subtle); display: flex; align-items: center; gap: 4px; margin-bottom: 4px;">
          <?= app_icon('calendar', 12) ?>
          <span>Tanggal Shift</span>
        </label>
        <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($filterDate) ?>" required style="min-height: 38px;">
      </div>

      <!-- Shift Type -->
      <div class="daily-filter-col" style="flex: 1.5; min-width: 250px;">
        <label style="font-size: 0.76rem; font-weight: 700; color: var(--text-subtle); display: flex; align-items: center; gap: 4px; margin-bottom: 4px;">
          <?= app_icon('clock', 12) ?>
          <span>Jenis Shift</span>
        </label>
        <select name="shift_type" class="form-control" style="font-weight: 600; min-height: 38px;">
          <option value="oh" <?= ($filterShiftType === 'oh') ? 'selected' : '' ?>>Office Hour (08:00 - 17:00)</option>
          <option value="pagi" <?= ($filterShiftType === 'pagi') ? 'selected' : '' ?>>Shift Pagi (08:00 - 20:00)</option>
          <option value="malam" <?= ($filterShiftType === 'malam') ? 'selected' : '' ?>>Shift Malam (20:00 - 08:00)</option>
        </select>
      </div>

      <!-- Technician Filter (Admin / Superadmin only) -->
      <?php if ($isAdmin): ?>
      <div class="daily-filter-col" style="flex: 1.4; min-width: 230px;">
        <label style="font-size: 0.76rem; font-weight: 700; color: var(--text-subtle); display: flex; align-items: center; gap: 4px; margin-bottom: 4px;">
          <?= app_icon('user', 12) ?>
          <span>Teknisi NOC</span>
        </label>
        <select name="user_id" class="form-control" style="min-height: 38px;">
          <?php foreach ($technicians as $tech): ?>
            <option value="<?= (int)$tech['id'] ?>" <?= ($filterUserId === (int)$tech['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($tech['full_name'] ?: $tech['username']) ?> (<?= htmlspecialchars($tech['role']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="daily-filter-btn" style="padding: 0; min-height: 38px; display: flex; align-items: flex-end;">
        <button type="submit" class="btn btn-secondary btn-sm" style="height: 38px; padding: 0 1.25rem; display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
          <?= app_icon('filter', 13) ?>
          <span>Pilih Shift</span>
        </button>
      </div>
    </form>
  </div>

  <!-- Main Grid: Left Column (Timeline) & Right Column (Widgets) Side-by-Side -->
  <div class="daily-main-grid">
    
    <!-- Left Column: Shift Banner & Activity Timeline -->
    <div class="daily-content-col">
      
      <?php if ($currentShift): ?>
        <!-- Shift Header Banner (Active or Completed Session) -->
        <div class="daily-shift-card">
          <div class="daily-shift-header">
            <div style="display: flex; align-items: center; gap: 0.85rem;">
              <div style="width: 46px; height: 46px; border-radius: 50%; background: var(--primary-subtle); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <?= app_icon($filterShiftType === 'malam' ? 'moon' : ($filterShiftType === 'oh' ? 'building' : 'sun'), 22) ?>
              </div>
              <div>
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                  <h4 style="font-size: 1.15rem; font-weight: 800; margin: 0; color: var(--text-main);">
                    <?= htmlspecialchars($shiftInfo['name']) ?> (<?= htmlspecialchars($shiftInfo['time']) ?>)
                  </h4>
                  <span class="shift-badge <?= $shiftInfo['badge_class'] ?>">
                    <?= strtoupper($filterShiftType) ?>
                  </span>
                  <?php if ($currentShift['status'] === 'completed'): ?>
                    <span class="sla-status-pill sla-normal" style="display: inline-flex; align-items: center; gap: 4px;">
                      <?= app_icon('check', 11) ?> Selesai &amp; Terkunci
                    </span>
                  <?php else: ?>
                    <span class="sla-status-pill sla-warning" style="display: inline-flex; align-items: center; gap: 4px;">
                      <?= app_icon('clock', 11) ?> Sedang Berjalan (Aktif)
                    </span>
                  <?php endif; ?>
                </div>
                <div style="font-size: 0.82rem; color: var(--text-subtle); margin-top: 4px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                  <span style="display: inline-flex; align-items: center; gap: 4px;">
                    <?= app_icon('calendar', 12, 'text-primary') ?>
                    <span><?= date('d F Y', strtotime($filterDate)) ?></span>
                  </span>
                  &bull;
                  <span style="display: inline-flex; align-items: center; gap: 4px;">
                    <?= app_icon('user', 12, 'text-primary') ?>
                    <span>Teknisi: <strong><?= htmlspecialchars($techName) ?></strong></span>
                  </span>
                  &bull;
                  <span style="display: inline-flex; align-items: center; gap: 4px;">
                    <?= app_icon('briefcase', 12, 'text-primary') ?>
                    <span>Supervisor: <strong><?= htmlspecialchars($defaultLeader) ?></strong></span>
                  </span>
                </div>
              </div>
            </div>

            <!-- Shift Action Buttons -->
            <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
              <button type="button" class="btn btn-whatsapp btn-sm" id="btn_open_wa_modal" title="Kirim dokumen dan ringkasan ke WhatsApp">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766.001-3.187-2.575-5.77-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.312.045-.634.053-1.02-.072-.258-.084-.582-.206-1.007-.393-1.782-.784-2.944-2.587-3.033-2.706-.089-.12-1.084-1.442-1.084-2.75 0-1.309.686-1.954.929-2.217.243-.262.531-.328.708-.328.177 0 .354.008.509.016.166.009.388-.063.607.462.227.544.773 1.884.841 2.02.067.137.112.298.022.477-.089.178-.133.29-.266.444-.133.155-.28.345-.399.463-.133.134-.271.278-.117.543.155.266.689 1.134 1.477 1.836 1.013.902 1.868 1.182 2.134 1.315.266.133.421.111.576-.067.155-.178.664-.775.841-1.041.177-.266.354-.222.597-.133.244.089 1.549.731 1.815.864.266.133.443.199.509.31.066.111.066.643-.078 1.048z"/></svg>
                <span>WhatsApp</span>
              </button>
              <a href="print_shift?shift_id=<?= (int)$currentShift['id'] ?>" target="_blank" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 4px;" title="Unduh / Cetak Dokumen PDF Resmi">
                <?= app_icon('file-text', 13) ?>
                <span>PDF</span>
              </a>
              <?php if ($currentShift['status'] === 'active'): ?>
                <button type="button" class="btn btn-outline-danger btn-sm" id="btn_complete_shift" data-shift-id="<?= (int)$currentShift['id'] ?>" style="display: inline-flex; align-items: center; gap: 4px;" title="Selesaikan shift dan kirim laporan logbook">
                  <?= app_icon('check-circle', 13) ?>
                  <span>Selesai Shift</span>
                </button>
                <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('btn_open_activity_modal').click()" style="display: inline-flex; align-items: center; gap: 4px;">
                  <?= app_icon('plus', 13) ?>
                  <span>Kegiatan</span>
                </button>
              <?php else: ?>
                <span class="btn btn-secondary btn-sm disabled" style="opacity: 0.65; cursor: not-allowed; display: inline-flex; align-items: center; gap: 4px;" title="Shift telah dikunci">
                  <?= app_icon('lock', 13) ?>
                  <span>Shift Terkunci</span>
                </span>
              <?php endif; ?>
            </div>
          </div>

          <?php if (!empty($currentShift['notes'])): ?>
            <div style="margin-top: 1rem; padding: 0.75rem 1rem; background: var(--bg-alt); border-radius: var(--radius-sm); font-size: 0.82rem; color: var(--text-muted); border-left: 3px solid var(--primary);">
              <strong>Catatan Serah Terima / Penutupan Shift:</strong> <?= nl2br(htmlspecialchars($currentShift['notes'])) ?>
            </div>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <!-- Shift Not Started Yet Banner -->
        <div class="daily-shift-card" style="border: 2px dashed var(--primary-border); background: var(--bg-card); padding: 1.4rem;">
          <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
            <div style="display: flex; align-items: center; gap: 1rem;">
              <div style="width: 48px; height: 48px; border-radius: 50%; background: var(--primary-subtle); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <?= app_icon($filterShiftType === 'malam' ? 'moon' : ($filterShiftType === 'oh' ? 'building' : 'sun'), 24) ?>
              </div>
              <div>
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                  <h4 style="font-size: 1.15rem; font-weight: 800; margin: 0; color: var(--text-main);">
                    <?= htmlspecialchars($shiftInfo['name']) ?> (<?= htmlspecialchars($shiftInfo['time']) ?>)
                  </h4>
                  <span class="sla-status-pill sla-warning" style="display: inline-flex; align-items: center; gap: 4px;">
                    <?= app_icon('clock', 11) ?> Belum Dibuka
                  </span>
                </div>
                <div style="font-size: 0.82rem; color: var(--text-subtle); margin-top: 4px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                  <span style="display: inline-flex; align-items: center; gap: 4px;">
                    <?= app_icon('calendar', 12, 'text-primary') ?>
                    <span>Tanggal: <strong><?= date('d F Y', strtotime($filterDate)) ?></strong></span>
                  </span>
                  &bull;
                  <span style="display: inline-flex; align-items: center; gap: 4px;">
                    <?= app_icon('user', 12, 'text-primary') ?>
                    <span>Teknisi: <strong><?= htmlspecialchars($techName) ?></strong></span>
                  </span>
                </div>
                <div style="font-size: 0.76rem; color: #b91c1c; font-weight: 600; margin-top: 5px; display: flex; align-items: center; gap: 4px;">
                  <?= app_icon('alert-triangle', 13, '#b91c1c') ?>
                  <span>Sesi shift ini belum dibuka. Buka sesi shift terlebih dahulu agar dapat mencatat kegiatan harian NOC.</span>
                </div>
              </div>
            </div>

            <div>
              <button type="button" class="btn btn-primary" id="btn_start_shift_action" data-date="<?= htmlspecialchars($filterDate) ?>" data-type="<?= htmlspecialchars($filterShiftType) ?>" data-user="<?= (int)$filterUserId ?>" style="padding: 0.65rem 1.4rem; font-weight: 700; display: inline-flex; align-items: center; gap: 8px;">
                <?= app_icon('play', 14) ?>
                <span>Buka / Mulai Shift Ini</span>
              </button>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- Activities Timeline Section -->
      <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; margin-top: 1.25rem;">
        <h5 style="font-size: 1rem; font-weight: 800; color: var(--text-main); margin: 0;">
          Daftar Kegiatan Shift Ini (<?= count($activities) ?>)
        </h5>
        <span style="font-size: 0.78rem; color: var(--text-subtle);">Urut berdasarkan waktu kegiatan</span>
      </div>

      <?php if (!$currentShift): ?>
        <div style="background: var(--bg-card); border: 2px dashed var(--border); border-radius: var(--radius-lg); padding: 3rem 2rem; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center; box-shadow: var(--shadow-sm);">
          <div style="width: 64px; height: 64px; border-radius: 50%; background: var(--primary-subtle); color: var(--primary); display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
            <?= app_icon('clock', 32) ?>
          </div>
          <h4 style="font-size: 1.2rem; font-weight: 800; color: var(--text-main); margin: 0 0 0.5rem 0;">Sesi Shift Belum Dimulai</h4>
          <p style="font-size: 0.88rem; color: var(--text-subtle); max-width: 480px; margin: 0 auto 1.5rem auto; line-height: 1.5;">
            Sesi <strong><?= htmlspecialchars($shiftInfo['name']) ?></strong> untuk tanggal <strong><?= date('d M Y', strtotime($filterDate)) ?></strong> belum dibuka. Silakan klik tombol di bawah untuk membuka sesi shift dan mulai mencatat kegiatan harian NOC.
          </p>
          <button type="button" class="btn btn-primary" onclick="document.getElementById('btn_start_shift_action').click()" style="padding: 0.65rem 1.6rem; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(0, 128, 128, 0.25);">
            <?= app_icon('play', 15) ?>
            <span>Buka / Mulai Shift Sekarang</span>
          </button>
        </div>
      <?php elseif (empty($activities)): ?>
        <div style="background: var(--bg-card); border: 2px dashed var(--border); border-radius: var(--radius-lg); padding: 3rem 2rem; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center; box-shadow: var(--shadow-sm);">
          <div style="width: 64px; height: 64px; border-radius: 50%; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
            <?= app_icon('file-text', 32) ?>
          </div>
          <h4 style="font-size: 1.2rem; font-weight: 800; color: var(--text-main); margin: 0 0 0.5rem 0;">Belum Ada Catatan Kegiatan</h4>
          <p style="font-size: 0.88rem; color: var(--text-subtle); max-width: 460px; margin: 0 auto 1.5rem auto; line-height: 1.5;">
            Sesi shift telah aktif! Belum ada kegiatan yang dicatat untuk shift ini. Klik tombol di bawah untuk mulai mencatat kegiatan monitoring, patroli, atau penanganan gangguan NOC.
          </p>
          <?php if ($currentShift['status'] === 'active'): ?>
            <button type="button" class="btn btn-primary" onclick="document.getElementById('btn_open_activity_modal').click()" style="padding: 0.65rem 1.6rem; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(0, 128, 128, 0.25);">
              <?= app_icon('plus', 15) ?>
              <span>Catat Kegiatan Pertama</span>
            </button>
          <?php endif; ?>
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
                    <span class="activity-time-pill" style="display: inline-flex; align-items: center; gap: 4px;">
                      <?= app_icon('clock', 12) ?>
                      <span><?= htmlspecialchars($act['activity_time']) ?></span>
                    </span>
                    <span class="cat-badge <?= $catClass ?>"><?= htmlspecialchars($act['category']) ?></span>
                    <?php if (!empty($act['location'])): ?>
                      <span style="font-size: 0.75rem; color: var(--text-subtle); display: inline-flex; align-items: center; gap: 4px;">
                        <?= app_icon('map-pin', 12) ?>
                        <span><?= htmlspecialchars($act['location']) ?></span>
                      </span>
                    <?php endif; ?>
                  </div>
                  <h4 style="font-size: 1.05rem; font-weight: 700; color: var(--text-main); margin: 0;">
                    <?= htmlspecialchars($act['title']) ?>
                  </h4>
                </div>

                <!-- Delete Action (if shift active and permitted) -->
                <?php if ((!$currentShift || $currentShift['status'] === 'active') && ($isAdmin || (int)$act['user_id'] === $userId)): ?>
                  <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteActivity(<?= (int)$act['id'] ?>)" title="Hapus Kegiatan" style="padding: 2px 8px; font-size: 0.75rem; display: inline-flex; align-items: center; gap: 4px;">
                    <?= app_icon('trash', 12) ?>
                    <span>Hapus</span>
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
                  <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-subtle); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 4px;">
                    <?= app_icon('camera', 13) ?>
                    <span>DOKUMENTASI FOTO (<?= count($act['photos']) ?>):</span>
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

    <!-- Right Sidebar Column: Refined Widgets & Recent Shifts -->
    <div class="daily-sidebar-col" style="display: flex; flex-direction: column; gap: 1.25rem;">
      
      <!-- Shift Summary Widget -->
      <div class="daily-widget-card">
        <div class="sidebar-widget-header">
          <div class="widget-title-group">
            <?= app_icon('activity', 16, 'text-primary') ?>
            <span class="widget-title">Ringkasan Shift</span>
          </div>
          <span class="shift-mini-badge"><?= strtoupper($filterShiftType) ?></span>
        </div>

        <!-- 2 Stat Tiles -->
        <div class="daily-stats-row">
          <div class="daily-stat-tile">
            <span class="stat-number stat-primary"><?= count($activities) ?></span>
            <span class="stat-label">Total Kegiatan</span>
          </div>

          <div class="daily-stat-tile">
            <span class="stat-number stat-secondary"><?= $totalPhotos ?></span>
            <span class="stat-label">Foto Terlampir</span>
          </div>
        </div>

        <!-- Category Breakdown -->
        <?php if (!empty($catSummary)): ?>
          <div class="daily-category-section">
            <div class="section-micro-label">Rincian Kategori:</div>
            <div class="category-pills-wrap">
              <?php foreach ($catSummary as $cName => $cCount): ?>
                <span class="cat-pill-badge">
                  <span class="pill-dot"></span>
                  <span><?= htmlspecialchars($cName) ?></span>
                  <strong class="pill-count"><?= $cCount ?></strong>
                </span>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- Quick WhatsApp Button inside widget -->
        <?php if ($currentShift): ?>
          <button type="button" class="btn btn-whatsapp w-100" style="width: 100%; padding: 0.55rem; font-size: 0.82rem;" onclick="document.getElementById('btn_open_wa_modal').click()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766.001-3.187-2.575-5.77-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.312.045-.634.053-1.02-.072-.258-.084-.582-.206-1.007-.393-1.782-.784-2.944-2.587-3.033-2.706-.089-.12-1.084-1.442-1.084-2.75 0-1.309.686-1.954.929-2.217.243-.262.531-.328.708-.328.177 0 .354.008.509.016.166.009.388-.063.607.462.227.544.773 1.884.841 2.02.067.137.112.298.022.477-.089.178-.133.29-.266.444-.133.155-.28.345-.399.463-.133.134-.271.278-.117.543.155.266.689 1.134 1.477 1.836 1.013.902 1.868 1.182 2.134 1.315.266.133.421.111.576-.067.155-.178.664-.775.841-1.041.177-.266.354-.222.597-.133.244.089 1.549.731 1.815.864.266.133.443.199.509.31.066.111.066.643-.078 1.048z"/></svg>
            <span>Kirim Laporan ke WhatsApp</span>
          </button>
        <?php endif; ?>

        <!-- Clean Schedule Box -->
        <div class="sidebar-schedule-box">
          <div class="schedule-item">
            <?= app_icon('building', 14, 'text-primary') ?>
            <span class="schedule-text">Office Hour: <strong><?= htmlspecialchars(get_system_setting('shift_oh_time', '08:00 - 17:00 WIB')) ?></strong></span>
          </div>
          <div class="schedule-item">
            <?= app_icon('sun', 14, 'text-warning') ?>
            <span class="schedule-text">Shift Pagi: <strong><?= htmlspecialchars(get_system_setting('shift_pagi_time', '08:00 - 20:00 WIB')) ?></strong></span>
          </div>
          <div class="schedule-item">
            <?= app_icon('moon', 14, 'text-secondary') ?>
            <span class="schedule-text">Shift Malam: <strong><?= htmlspecialchars(get_system_setting('shift_malam_time', '20:00 - 08:00 WIB')) ?></strong></span>
          </div>
        </div>
      </div>

      <!-- Recent Shifts History Widget -->
      <div class="daily-widget-card">
        <div class="sidebar-widget-header">
          <div class="widget-title-group">
            <?= app_icon('file-text', 16, 'text-primary') ?>
            <span class="widget-title">Riwayat Shift Terkini</span>
          </div>
          <span style="font-size: 0.7rem; font-weight: 700; color: var(--text-subtle);"><?= count($historyShifts) ?> Sesi</span>
        </div>

        <?php if (empty($historyShifts)): ?>
          <p style="font-size: 0.8rem; color: var(--text-subtle); margin: 0; text-align: center; padding: 1rem 0;">Belum ada riwayat shift terdahulu.</p>
        <?php else: ?>
          <div class="history-shift-list">
            <?php foreach ($historyShifts as $hs): 
              $isSel = ($currentShift && (int)$hs['id'] === (int)$currentShift['id']);
            ?>
              <div class="history-shift-item <?= $isSel ? 'history-item-active' : '' ?>">
                <div class="history-shift-main">
                  <div class="history-shift-title">
                    <span><?= date('d M Y', strtotime($hs['shift_date'])) ?></span>
                    <span class="history-shift-type"><?= ucfirst($hs['shift_type']) ?></span>
                  </div>
                  <div class="history-shift-sub">
                    <span><?= htmlspecialchars($hs['technician_name']) ?></span> &bull; 
                    <span><?= (int)$hs['total_activities'] ?> kegiatan</span>
                  </div>
                </div>

                <div class="history-shift-actions">
                  <a href="daily_activity?shift_id=<?= (int)$hs['id'] ?>" class="btn-micro <?= $isSel ? 'btn-micro-active' : 'btn-micro-secondary' ?>" title="Lihat Shift">
                    <?= app_icon('eye', 11) ?>
                    <span>Buka</span>
                  </a>
                  <a href="print_shift?shift_id=<?= (int)$hs['id'] ?>" target="_blank" class="btn-micro btn-micro-teal" title="Unduh PDF Resmi">
                    <?= app_icon('file-text', 11) ?>
                    <span>PDF</span>
                  </a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($isAdmin): ?>
        <div style="padding: 0.75rem 0.9rem; background: var(--bg-alt); border-top: 1px solid var(--border); border-radius: 0 0 var(--radius-md) var(--radius-md); text-align: center;">
          <a href="daily_reports" class="btn btn-primary btn-sm w-100" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 6px; font-weight: 700; text-decoration: none;">
            <?= app_icon('document', 14) ?>
            <span>Buka Rekap &amp; Laporan Semua Anggota</span>
            <?= app_icon('arrow-right', 13) ?>
          </a>
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
          <?= app_icon('file-text', 18, 'text-primary') ?>
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
              <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 2px;">
                <label class="form-label mb-0" style="font-size: 0.8rem; font-weight: 700;">Kategori Kegiatan Cepat</label>
                <?php if (has_role('superadmin')): ?>
                <a href="settings" target="_blank" style="font-size: 0.72rem; color: var(--primary); text-decoration: none; font-weight: 600;">
                  + Kelola Kategori &rarr;
                </a>
                <?php endif; ?>
              </div>
              <input type="hidden" name="category" id="input_category" value="<?= htmlspecialchars($allCategories[0] ?? 'Monitoring') ?>">
              <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px;" id="cat_quick_buttons">
                <?php foreach ($allCategories as $idx => $catItem): ?>
                  <button type="button" class="btn btn-sm btn-cat-badge <?= ($idx === 0) ? 'active' : '' ?>" data-cat="<?= htmlspecialchars($catItem) ?>">
                    <?= htmlspecialchars($catItem) ?>
                  </button>
                <?php endforeach; ?>
                <button type="button" class="btn btn-sm btn-cat-badge" id="btn_custom_cat_pill" data-cat="__custom__" style="border: 1px dashed var(--primary); color: var(--primary); background: var(--primary-subtle); font-weight: 700;">
                  + Tambah Kategori Baru...
                </button>
              </div>

              <!-- Input Kategori Baru / Kustom -->
              <div id="custom_category_container" style="display: none; margin-top: 8px;">
                <div style="display: flex; align-items: center; gap: 6px;">
                  <input type="text" id="input_custom_category" class="form-control" placeholder="Ketik nama kategori baru (contoh: Instalasi, Koordinasi, Emergency, Backup)..." style="font-weight: 600; font-size: 0.82rem; height: 36px;">
                  <button type="button" class="btn btn-sm btn-secondary" id="btn_cancel_custom_cat" style="padding: 0.35rem 0.65rem; font-size: 0.75rem; white-space: nowrap;">Batal</button>
                </div>
                <div style="font-size: 0.7rem; color: var(--text-subtle); margin-top: 3px;">
                  Kategori baru akan tersimpan dan otomatis menjadi pilihan cepat untuk sesi berikutnya.
                </div>
              </div>
            </div>
          </div>

          <!-- Row 2: Location / Site from Master Data Site -->
          <div class="mb-3">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
              <label class="form-label mb-0" style="font-size: 0.8rem; font-weight: 700;">
                Lokasi / Site (Master Data Site)
              </label>
              <?php if (has_role('superadmin')): ?>
              <a href="sites" target="_blank" style="font-size: 0.72rem; color: var(--primary); text-decoration: none; font-weight: 600;">
                + Kelola Master Site &rarr;
              </a>
              <?php endif; ?>
            </div>

            <select id="select_site_dropdown" class="form-control mb-2" style="font-weight: 600;">
              <option value="">-- Pilih dari Master Data Site (Opsional) --</option>
              <?php foreach ($siteList as $s): ?>
                <?php
                  $sCode = !empty($s['site_id_str']) ? ' [' . $s['site_id_str'] . ']' : '';
                  $sLoc = !empty($s['location_detail']) ? ' (' . $s['location_detail'] . ')' : '';
                  $sLabel = $s['site_name'] . $sCode . $sLoc;
                ?>
                <option value="<?= htmlspecialchars($s['site_name']) ?>">
                  <?= htmlspecialchars($sLabel) ?>
                </option>
              <?php endforeach; ?>
              <option value="__custom__">Ketik Lokasi Lainnya / Non-Site...</option>
            </select>

            <div id="custom_location_container" style="display: none;">
              <input type="text" id="input_custom_loc" class="form-control" placeholder="Ketik nama lokasi atau ruangan (Contoh: Ruang NOC Server Domestik, Shelter Barat)">
            </div>
            <input type="hidden" name="location" id="input_final_location" value="">
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
            
            <div style="display: flex; gap: 8px; margin-bottom: 0.75rem; flex-wrap: wrap;">
              <label for="input_daily_photos" class="btn btn-secondary btn-sm" style="cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                <?= app_icon('file-text', 14) ?>
                <span>Pilih dari Galeri / File</span>
              </label>
              <input type="file" id="input_daily_photos" accept="image/*" multiple style="display: none;">

              <button type="button" class="btn btn-primary-soft btn-sm" id="btn_take_camera_photo" style="display: inline-flex; align-items: center; gap: 6px;">
                <?= app_icon('camera', 14) ?>
                <span>Ambil Foto Kamera</span>
              </button>
              <input type="file" id="input_daily_camera" accept="image/*" capture="environment" style="display: none;">
            </div>

            <!-- Photos Preview Grid -->
            <div id="photos_preview_container" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 8px;"></div>
          </div>

        </div>

        <div class="modal-footer-custom">
          <button type="button" class="btn btn-secondary btn-sm" id="btn_cancel_activity">Batal</button>
          <button type="submit" class="btn btn-primary btn-sm" id="btn_submit_activity" style="display: inline-flex; align-items: center; gap: 6px;">
            <?= app_icon('check', 14) ?>
            <span>Simpan Kegiatan</span>
          </button>
        </div>
      </form>

    </div>
  </div>
</div>

<!-- Modal: Kirim Laporan & Info PDF ke WhatsApp -->
<div id="wa_share_modal" class="modal-backdrop-custom" style="display: none;">
  <div class="modal-dialog-custom" style="max-width: 650px;">
    <div class="modal-content-custom">
      
      <div class="modal-header-custom" style="background: #f0fdf4; border-bottom: 1px solid #bbf7d0;">
        <div style="display: flex; align-items: center; gap: 10px;">
          <div style="width: 36px; height: 36px; border-radius: 50%; background: #dcfce7; color: #166534; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766.001-3.187-2.575-5.77-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.312.045-.634.053-1.02-.072-.258-.084-.582-.206-1.007-.393-1.782-.784-2.944-2.587-3.033-2.706-.089-.12-1.084-1.442-1.084-2.75 0-1.309.686-1.954.929-2.217.243-.262.531-.328.708-.328.177 0 .354.008.509.016.166.009.388-.063.607.462.227.544.773 1.884.841 2.02.067.137.112.298.022.477-.089.178-.133.29-.266.444-.133.155-.28.345-.399.463-.133.134-.271.278-.117.543.155.266.689 1.134 1.477 1.836 1.013.902 1.868 1.182 2.134 1.315.266.133.421.111.576-.067.155-.178.664-.775.841-1.041.177-.266.354-.222.597-.133.244.089 1.549.731 1.815.864.266.133.443.199.509.31.066.111.066.643-.078 1.048z"/></svg>
          </div>
          <div>
            <h4 style="margin: 0; font-size: 1.1rem; font-weight: 800; color: #166534;">Kirim Laporan Shift ke WhatsApp</h4>
            <div style="font-size: 0.74rem; color: #15803d;">Bagikan ringkasan kegiatan dan tautan PDF dokumen resmi ke WhatsApp Leader / Grup</div>
          </div>
        </div>
        <button type="button" class="modal-close-btn" id="btn_close_wa_modal">&times;</button>
      </div>

      <div class="modal-body-custom">
        <!-- Target Phone Input -->
        <div class="mb-3">
          <label class="form-label" style="font-size: 0.8rem; font-weight: 700; color: var(--text-main); display: flex; justify-content: space-between;">
            <span>Nomor WhatsApp Tujuan (Opsional)</span>
            <span style="font-size: 0.72rem; color: var(--text-subtle);">Kosongkan untuk memilih kontak/grup di WA</span>
          </label>
          <div style="display: flex; gap: 8px;">
            <input type="text" id="input_wa_phone" class="form-control" value="<?= htmlspecialchars($defaultWaPhone) ?>" placeholder="Contoh: 6281234567890 (awali 62)">
          </div>
        </div>

        <!-- Message Preview -->
        <div class="mb-3">
          <label class="form-label" style="font-size: 0.8rem; font-weight: 700; color: var(--text-main); display: flex; justify-content: space-between;">
            <span>Teks Informasi &amp; Ringkasan Laporan</span>
            <span style="font-size: 0.72rem; color: var(--primary); cursor: pointer; display: inline-flex; align-items: center; gap: 4px;" id="btn_copy_wa_text">
              <?= app_icon('document', 13) ?>
              <span>Salin Teks</span>
            </span>
          </label>
          <textarea id="textarea_wa_message" class="form-control whatsapp-preview-box" rows="10"><?= htmlspecialchars($fullWaMessage) ?></textarea>
        </div>

        <div style="background: var(--bg-alt); padding: 0.75rem 1rem; border-radius: var(--radius-sm); border: 1px solid var(--border); font-size: 0.78rem; color: var(--text-muted); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
          <div>
            <strong>File Dokumen PDF Resmi:</strong> Siap diunduh untuk dilampirkan pada chat WA.
          </div>
          <a href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank" class="btn btn-sm btn-secondary" style="font-size: 0.74rem; padding: 4px 10px; display: inline-flex; align-items: center; gap: 4px;">
            <?= app_icon('file-text', 13) ?>
            <span>Unduh File PDF</span>
          </a>
        </div>
      </div>

      <div class="modal-footer-custom" style="display: flex; justify-content: space-between; align-items: center;">
        <button type="button" class="btn btn-secondary btn-sm" id="btn_cancel_wa">Tutup</button>
        <div style="display: flex; gap: 8px;">
          <button type="button" class="btn btn-secondary btn-sm" id="btn_copy_wa_modal" style="display: inline-flex; align-items: center; gap: 5px;">
            <?= app_icon('document', 13) ?>
            <span>Salin Pesan</span>
          </button>
          <button type="button" class="btn btn-whatsapp btn-sm" id="btn_send_whatsapp" style="display: inline-flex; align-items: center; gap: 6px;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766.001-3.187-2.575-5.77-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.312.045-.634.053-1.02-.072-.258-.084-.582-.206-1.007-.393-1.782-.784-2.944-2.587-3.033-2.706-.089-.12-1.084-1.442-1.084-2.75 0-1.309.686-1.954.929-2.217.243-.262.531-.328.708-.328.177 0 .354.008.509.016.166.009.388-.063.607.462.227.544.773 1.884.841 2.02.067.137.112.298.022.477-.089.178-.133.29-.266.444-.133.155-.28.345-.399.463-.133.134-.271.278-.117.543.155.266.689 1.134 1.477 1.836 1.013.902 1.868 1.182 2.134 1.315.266.133.421.111.576-.067.155-.178.664-.775.841-1.041.177-.266.354-.222.597-.133.244.089 1.549.731 1.815.864.266.133.443.199.509.31.066.111.066.643-.078 1.048z"/></svg>
            <span>Buka WhatsApp &amp; Kirim</span>
          </button>
        </div>
      </div>

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
    var shiftExists = btnOpen ? btnOpen.getAttribute('data-shift-exists') : '0';
    var shiftStatus = btnOpen ? btnOpen.getAttribute('data-shift-status') : '';

    if (shiftExists !== '1') {
      if (confirm('Sesi shift ini belum dibuka. Anda harus memilih dan memulai sesi shift terlebih dahulu sebelum dapat mencatat kegiatan.\n\nApakah Anda ingin membuka dan memulai sesi shift ini sekarang?')) {
        var startBtn = document.getElementById('btn_start_shift_action');
        if (startBtn) {
          startBtn.click();
        }
      }
      return;
    }

    if (shiftStatus === 'completed') {
      alert('Sesi shift ini telah selesai dan dikunci. Anda tidak dapat menambahkan kegiatan pada shift yang sudah ditutup.');
      return;
    }

    if (modal) modal.style.display = 'flex';
  }
  function closeModal() {
    if (modal) modal.style.display = 'none';
  }

  if (btnOpen) btnOpen.addEventListener('click', openModal);
  if (btnClose) btnClose.addEventListener('click', closeModal);
  if (btnCancel) btnCancel.addEventListener('click', closeModal);

  // Start Shift Handler
  var btnStartShift = document.getElementById('btn_start_shift_action');
  if (btnStartShift) {
    btnStartShift.addEventListener('click', function() {
      var shiftDate = this.getAttribute('data-date');
      var shiftType = this.getAttribute('data-type');
      var userId = this.getAttribute('data-user');

      this.disabled = true;
      var origText = this.innerHTML;
      this.innerHTML = '<span>⏳</span> Membuka Shift...';

      var fd = new FormData();
      fd.append('shift_date', shiftDate);
      fd.append('shift_type', shiftType);
      if (userId) fd.append('user_id', userId);

      fetch('api/start_shift.php', {
        method: 'POST',
        body: fd
      })
      .then(function(res) { return res.json(); })
      .then(function(data) {
        if (data.success) {
          alert(data.message);
          window.location.reload();
        } else {
          alert('Gagal membuka shift: ' + (data.message || 'Terjadi kesalahan sistem.'));
          btnStartShift.disabled = false;
          btnStartShift.innerHTML = origText;
        }
      })
      .catch(function(err) {
        alert('Terjadi kesalahan jaringan: ' + err.message);
        btnStartShift.disabled = false;
        btnStartShift.innerHTML = origText;
      });
    });
  }

  // Quick Category Buttons & Custom Category Input
  var catButtons = document.querySelectorAll('#cat_quick_buttons .btn-cat-badge');
  var inputCategory = document.getElementById('input_category');
  var customCatContainer = document.getElementById('custom_category_container');
  var inputCustomCat = document.getElementById('input_custom_category');
  var btnCancelCustomCat = document.getElementById('btn_cancel_custom_cat');

  catButtons.forEach(function(btn) {
    btn.addEventListener('click', function() {
      catButtons.forEach(function(b) { b.classList.remove('active'); });
      this.classList.add('active');
      var catVal = this.getAttribute('data-cat');

      if (catVal === '__custom__' || catVal.toLowerCase() === 'lainnya') {
        if (customCatContainer) customCatContainer.style.display = 'block';
        if (inputCustomCat) {
          inputCustomCat.focus();
          var typed = inputCustomCat.value.trim();
          if (inputCategory) inputCategory.value = typed ? typed : 'Lainnya';
        }
      } else {
        if (customCatContainer) customCatContainer.style.display = 'none';
        if (inputCategory) inputCategory.value = catVal;
      }
    });
  });

  if (inputCustomCat) {
    inputCustomCat.addEventListener('input', function() {
      var val = this.value.trim();
      if (inputCategory) {
        inputCategory.value = val ? val : 'Lainnya';
      }
    });
  }

  if (btnCancelCustomCat) {
    btnCancelCustomCat.addEventListener('click', function() {
      if (customCatContainer) customCatContainer.style.display = 'none';
      if (inputCustomCat) inputCustomCat.value = '';
      var firstBtn = document.querySelector('#cat_quick_buttons .btn-cat-badge:not(#btn_custom_cat_pill)');
      if (firstBtn) {
        catButtons.forEach(function(b) { b.classList.remove('active'); });
        firstBtn.classList.add('active');
        if (inputCategory) inputCategory.value = firstBtn.getAttribute('data-cat');
      }
    });
  }

  // Master Data Site Selector & Custom Location Sync
  var siteSelect = document.getElementById('select_site_dropdown');
  var customLocBox = document.getElementById('custom_location_container');
  var inputCustomLoc = document.getElementById('input_custom_loc');
  var inputFinalLoc = document.getElementById('input_final_location');

  function syncSiteLocation() {
    if (!siteSelect || !inputFinalLoc) return;
    if (siteSelect.value === '__custom__') {
      if (customLocBox) customLocBox.style.display = 'block';
      if (inputCustomLoc) {
        inputCustomLoc.focus();
        inputFinalLoc.value = inputCustomLoc.value.trim();
      }
    } else {
      if (customLocBox) customLocBox.style.display = 'none';
      inputFinalLoc.value = siteSelect.value;
    }
  }

  if (siteSelect) {
    siteSelect.addEventListener('change', syncSiteLocation);
  }
  if (inputCustomLoc) {
    inputCustomLoc.addEventListener('input', function() {
      if (siteSelect && siteSelect.value === '__custom__' && inputFinalLoc) {
        inputFinalLoc.value = this.value.trim();
      }
    });
  }

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

  // Camera direct capture & picker trigger
  var btnCamera = document.getElementById('btn_take_camera_photo');
  var cameraInput = document.getElementById('input_daily_camera');

  if (cameraInput) {
    cameraInput.addEventListener('change', function(e) {
      var files = Array.from(e.target.files);
      files.forEach(function(file) {
        queuedFiles.push(file);
        renderPreview(file, 'file', queuedFiles.length - 1);
      });
      cameraInput.value = '';
    });
  }

  if (btnCamera && cameraInput) {
    btnCamera.addEventListener('click', function() {
      if (typeof openCameraModal === 'function') {
        window.dailyCameraCallback = function(dataUrl) {
          queuedBase64.push(dataUrl);
          renderPreview(dataUrl, 'base64', queuedBase64.length - 1);
        };
        openCameraModal('daily_activity', 'Foto Kegiatan Shift');
      } else {
        cameraInput.click();
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

  // Complete Shift Action with WhatsApp prompt
  var btnComplete = document.getElementById('btn_complete_shift');
  if (btnComplete) {
    btnComplete.addEventListener('click', function() {
      var shiftId = this.getAttribute('data-shift-id');
      var notes = prompt('Masukkan catatan serah terima atau ringkasan penutupan shift (opsional):', '');
      if (notes === null) return; // User cancelled

      if (!confirm('Apakah Anda yakin ingin menyelesaikan shift ini? Logbook akan dikunci dan Anda akan diarahkan untuk membagikan laporan ke WhatsApp.')) {
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
          // Open WhatsApp modal directly or reload
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

  // WhatsApp Modal Handlers
  var waModal = document.getElementById('wa_share_modal');
  var btnOpenWa = document.getElementById('btn_open_wa_modal');
  var btnCloseWa = document.getElementById('btn_close_wa_modal');
  var btnCancelWa = document.getElementById('btn_cancel_wa');
  var btnSendWa = document.getElementById('btn_send_whatsapp');
  var btnCopyWa = document.getElementById('btn_copy_wa_modal');
  var btnCopyText = document.getElementById('btn_copy_wa_text');
  var textareaMsg = document.getElementById('textarea_wa_message');
  var inputWaPhone = document.getElementById('input_wa_phone');

  function openWaModal() {
    if (waModal) waModal.style.display = 'flex';
  }
  function closeWaModal() {
    if (waModal) waModal.style.display = 'none';
  }

  if (btnOpenWa) btnOpenWa.addEventListener('click', openWaModal);
  if (btnCloseWa) btnCloseWa.addEventListener('click', closeWaModal);
  if (btnCancelWa) btnCancelWa.addEventListener('click', closeWaModal);

  function copyTextToClipboard() {
    if (textareaMsg) {
      textareaMsg.select();
      navigator.clipboard.writeText(textareaMsg.value).then(function() {
        alert('Teks laporan shift berhasil disalin ke clipboard!');
      }).catch(function() {
        document.execCommand('copy');
        alert('Teks laporan shift berhasil disalin!');
      });
    }
  }

  if (btnCopyWa) btnCopyWa.addEventListener('click', copyTextToClipboard);
  if (btnCopyText) btnCopyText.addEventListener('click', copyTextToClipboard);

  if (btnSendWa) {
    btnSendWa.addEventListener('click', function() {
      var phone = inputWaPhone ? inputWaPhone.value.trim().replace(/[^0-9]/g, '') : '';
      if (phone.startsWith('0')) {
        phone = '62' + phone.substring(1);
      }
      var text = textareaMsg ? textareaMsg.value : '';
      var waUrl = '';
      if (phone) {
        waUrl = 'https://api.whatsapp.com/send?phone=' + phone + '&text=' + encodeURIComponent(text);
      } else {
        waUrl = 'https://api.whatsapp.com/send?text=' + encodeURIComponent(text);
      }
      window.open(waUrl, '_blank');
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
