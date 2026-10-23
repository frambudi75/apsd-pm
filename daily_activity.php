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
$userSignature = $pdo->query("SELECT signature_path FROM users WHERE id = " . $userId)->fetchColumn() ?: null;

// Pastikan zona waktu konsisten ke Waktu Indonesia Barat (WIB)
if (date_default_timezone_get() !== 'Asia/Jakarta') {
    date_default_timezone_set('Asia/Jakarta');
}

// Deteksi shift operasional dan tanggal berdasarkan jam nyata WIB
$currentHour = (int)date('G'); // 0 - 23 WIB
$currentTimeFormatted = date('H:i') . ' WIB';

if ($currentHour >= 8 && $currentHour < 20) {
    // 08:00 - 19:59 WIB: Shift Pagi
    $realtimeShift = 'pagi';
    $realtimeDate = date('Y-m-d');
} elseif ($currentHour >= 20) {
    // 20:00 - 23:59 WIB: Shift Malam (dimulai hari ini)
    $realtimeShift = 'malam';
    $realtimeDate = date('Y-m-d');
} else {
    // 00:00 - 07:59 WIB: Masih jam Shift Malam (dimulai kemarin pukul 20:00)
    $realtimeShift = 'malam';
    $realtimeDate = date('Y-m-d', strtotime('-1 day'));
}

$filterUserId = $isAdmin ? (int)($_GET['user_id'] ?? $userId) : $userId;

// Cek apakah ada sesi shift aktif untuk teknisi ini saat dini hari (00:00 - 07:59)
if ($currentHour < 8 && !isset($_GET['date'])) {
    try {
        $chkActive = $pdo->prepare("
            SELECT shift_date FROM daily_shifts 
            WHERE user_id = ? AND shift_type = 'malam' AND status = 'active' 
            ORDER BY id DESC LIMIT 1
        ");
        $chkActive->execute([$filterUserId]);
        $foundDate = $chkActive->fetchColumn();
        if ($foundDate) {
            $realtimeDate = $foundDate;
        }
    } catch (Exception $e) {}
}

// Filter parameters: jika pengguna tidak menentukan di GET, gunakan hasil deteksi real-time
$filterDate = trim($_GET['date'] ?? $realtimeDate);
$filterShiftType = strtolower(trim($_GET['shift_type'] ?? $realtimeShift));
if (!in_array($filterShiftType, ['oh', 'pagi', 'malam'])) {
    $filterShiftType = $realtimeShift;
}
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

// Validasi Disiplin SOP: Deteksi apakah teknisi masih memiliki sesi shift aktif sebelumnya yang belum ditutup
$pendingUnclosedShift = null;
if (!$currentShift && !$isAdmin) {
    $stmtPending = $pdo->prepare("
        SELECT s.*, 
               (SELECT COUNT(*) FROM daily_activities a WHERE a.shift_id = s.id) AS total_activities
        FROM daily_shifts s
        WHERE s.user_id = ? 
          AND s.status = 'active'
          AND (s.shift_date < ? OR (s.shift_date = ? AND s.shift_type != ?))
        ORDER BY s.shift_date DESC, s.id DESC
        LIMIT 1
    ");
    $stmtPending->execute([$filterUserId, $filterDate, $filterDate, $filterShiftType]);
    $pendingUnclosedShift = $stmtPending->fetch();
}

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

// Hitung status pengingat serah terima shift (30 menit sebelum shift berakhir)
$currentMinute = (int)date('i');
$currentTotalMinutes = ($currentHour * 60) + $currentMinute; // 0 - 1439 menit dari jam 00:00

$isHandoverPeriod = false;
$handoverMessage = '';
$handoverTitle = 'Shift Akan Segera Berakhir';
$handoverUrgent = false;

if ($currentShift && $currentShift['status'] === 'active') {
    if ($filterShiftType === 'pagi') {
        // Shift Pagi berakhir pukul 20:00 WIB (1200 menit). Pengingat mulai 19:30 WIB (1170 menit)
        if ($currentTotalMinutes >= 1170 && $currentTotalMinutes < 1200) {
            $isHandoverPeriod = true;
            $minsLeft = 1200 - $currentTotalMinutes;
            $handoverTitle = 'Shift Pagi Akan Berakhir (' . $minsLeft . ' Menit Lagi)';
            $handoverMessage = 'Shift Pagi akan berakhir dalam ' . $minsLeft . ' menit (pukul 20:00 WIB). Pastikan seluruh kegiatan telah tercatat, status shift diserahkan, dan laporan telah dibagikan ke WhatsApp.';
        } elseif ($currentTotalMinutes >= 1200 || $currentHour < 8) {
            $isHandoverPeriod = true;
            $handoverUrgent = true;
            $handoverTitle = 'Jam Operasional Shift Pagi Telah Berakhir';
            $handoverMessage = 'Jam operasional Shift Pagi telah berakhir (pukul 20:00 WIB). Mohon segera serahkan status shift dan bagikan laporan ke grup WhatsApp untuk kelancaran pergantian shift.';
        }
    } elseif ($filterShiftType === 'malam') {
        // Shift Malam berakhir pukul 08:00 WIB (480 menit). Pengingat mulai 07:30 WIB (450 menit)
        if ($currentTotalMinutes >= 450 && $currentTotalMinutes < 480) {
            $isHandoverPeriod = true;
            $minsLeft = 480 - $currentTotalMinutes;
            $handoverTitle = 'Shift Malam Akan Berakhir (' . $minsLeft . ' Menit Lagi)';
            $handoverMessage = 'Shift Malam akan berakhir dalam ' . $minsLeft . ' menit (pukul 08:00 WIB). Pastikan seluruh kegiatan telah tercatat, status shift diserahkan, dan laporan telah dibagikan ke WhatsApp.';
        } elseif ($currentTotalMinutes >= 480 && $currentHour < 12) {
            $isHandoverPeriod = true;
            $handoverUrgent = true;
            $handoverTitle = 'Jam Operasional Shift Malam Telah Berakhir';
            $handoverMessage = 'Jam operasional Shift Malam telah berakhir (pukul 08:00 WIB). Mohon segera serahkan status shift dan bagikan laporan ke grup WhatsApp untuk kelancaran pergantian shift.';
        }
    } elseif ($filterShiftType === 'oh') {
        // Office Hour berakhir pukul 17:00 WIB (1020 menit). Pengingat mulai 16:30 WIB (990 menit)
        if ($currentTotalMinutes >= 990 && $currentTotalMinutes < 1020) {
            $isHandoverPeriod = true;
            $minsLeft = 1020 - $currentTotalMinutes;
            $handoverTitle = 'Jam Kerja OH Akan Berakhir (' . $minsLeft . ' Menit Lagi)';
            $handoverMessage = 'Jam kerja Office Hour akan berakhir dalam ' . $minsLeft . ' menit (pukul 17:00 WIB). Pastikan seluruh kegiatan telah tercatat, status shift diserahkan, dan laporan telah dibagikan ke WhatsApp.';
        } elseif ($currentTotalMinutes >= 1020) {
            $isHandoverPeriod = true;
            $handoverUrgent = true;
            $handoverTitle = 'Jam Kerja Office Hour Telah Berakhir';
            $handoverMessage = 'Jam kerja Office Hour telah berakhir (pukul 17:00 WIB). Mohon segera serahkan status shift dan bagikan laporan ke WhatsApp.';
        }
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

$shiftId = (int)($currentShift['id'] ?? 0);
$pdfUrl = get_full_app_url('print_shift?shift_id=' . $shiftId);

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

if ($shiftId > 0) {
    $waMessageLines[] = "";
    $waMessageLines[] = "📄 *Unduh / Tinjau Dokumen PDF Resmi Shift*:";
    $waMessageLines[] = $pdfUrl;
}
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
      <h2 class="daily-page-title" style="font-weight: 800; color: var(--text-main); margin: 0; letter-spacing: -0.02em; display: flex; align-items: center; gap: 8px;">
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
      
      <?php if ($filterShiftType !== $realtimeShift && $filterShiftType !== 'oh'): ?>
        <div style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.25); border-left: 4px solid #f59e0b; padding: 0.75rem 1rem; border-radius: var(--radius-sm); margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;">
          <div style="display: flex; align-items: center; gap: 8px; font-size: 0.84rem; color: #92400e;">
            <?= app_icon('clock', 16, '#f59e0b') ?>
            <span>Anda sedang melihat <strong><?= htmlspecialchars($shiftInfo['name']) ?></strong>. Jam operasional saat ini: <strong><?= $realtimeShift === 'malam' ? 'Shift Malam (20:00 - 08:00 WIB)' : 'Shift Pagi (08:00 - 20:00 WIB)' ?></strong> (<?= $currentTimeFormatted ?>).</span>
          </div>
          <a href="daily_activity" class="btn btn-secondary btn-sm" style="font-size: 0.78rem; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
            <?= app_icon('arrow-right', 12) ?>
            <span>Beralih ke Shift <?= ucfirst($realtimeShift) ?> Aktif</span>
          </a>
        </div>
      <?php endif; ?>

      <!-- Shift Handover Alert Banner (30 Menit Sebelum Pergantian Shift) -->
      <?php if ($currentShift && $currentShift['status'] === 'active'): ?>
        <div id="shift_handover_alert" class="shift-handover-banner <?= $handoverUrgent ? 'urgent' : '' ?>" style="<?= $isHandoverPeriod ? '' : 'display: none;' ?>">
          <div class="handover-banner-inner">
            <div class="handover-icon-box">
              <?= app_icon('bell', 22) ?>
            </div>
            <div class="handover-content">
              <div class="handover-title">
                <span class="handover-badge"><?= $handoverUrgent ? 'PERHATIAN SEGERA' : 'PENGINGAT SERAH TERIMA' ?></span>
                <strong id="handover_title_text"><?= htmlspecialchars($handoverTitle) ?></strong>
              </div>
              <div class="handover-desc" id="handover_desc_text">
                <?= htmlspecialchars($handoverMessage ?: 'Shift akan berakhir dalam 30 menit. Pastikan seluruh kegiatan telah tercatat, status shift diserahkan, dan laporan telah dibagikan ke WhatsApp.') ?>
              </div>
            </div>
            <div class="handover-actions">
              <button type="button" class="btn btn-whatsapp btn-sm" onclick="document.getElementById('btn_open_wa_modal').click()" title="Bagikan Ringkasan ke Grup WhatsApp">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766.001-3.187-2.575-5.77-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.312.045-.634.053-1.02-.072-.258-.084-.582-.206-1.007-.393-1.782-.784-2.944-2.587-3.033-2.706-.089-.12-1.084-1.442-1.084-2.75 0-1.309.686-1.954.929-2.217.243-.262.531-.328.708-.328.177 0 .354.008.509.016.166.009.388-.063.607.462.227.544.773 1.884.841 2.02.067.137.112.298.022.477-.089.178-.133.29-.266.444-.133.155-.28.345-.399.463-.133.134-.271.278-.117.543.155.266.689 1.134 1.477 1.836 1.013.902 1.868 1.182 2.134 1.315.266.133.421.111.576-.067.155-.178.664-.775.841-1.041.177-.266.354-.222.597-.133.244.089 1.549.731 1.815.864.266.133.443.199.509.31.066.111.066.643-.078 1.048z"/></svg>
                <span>Kirim WA</span>
              </button>
              <button type="button" class="btn btn-outline-danger btn-sm" onclick="document.getElementById('btn_complete_shift').click()" title="Selesaikan &amp; Serahkan Shift">
                <?= app_icon('check-circle', 13) ?>
                <span>Serahkan Shift</span>
              </button>
              <button type="button" class="btn btn-secondary btn-sm" onclick="dismissHandoverAlert()" title="Tutup pengingat sementara (15 menit)" style="padding: 4px 8px; font-weight: 700;">
                &times;
              </button>
            </div>
          </div>
        </div>
      <?php endif; ?>

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
                  <h4 class="daily-shift-title" style="margin: 0; color: var(--text-main);">
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
                <button type="button" class="btn btn-outline-danger btn-sm" id="btn_complete_shift" onclick="openCompleteShiftModal(<?= (int)$currentShift['id'] ?>, '<?= htmlspecialchars(addslashes($shiftInfo['name'])) ?> (<?= date('d/m/Y', strtotime($filterDate)) ?>)')" style="display: inline-flex; align-items: center; gap: 4px;" title="Selesaikan shift dan kirim laporan logbook">
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
              <?php if (has_role('superadmin')): ?>
                <button type="button" class="btn btn-outline-danger btn-sm" id="btn_open_delete_shift_modal" onclick="openDeleteShiftModal(<?= (int)$currentShift['id'] ?>, '<?= htmlspecialchars(addslashes($shiftInfo['name'])) ?>', '<?= date('d/m/Y', strtotime($filterDate)) ?>', '<?= htmlspecialchars(addslashes($techName)) ?>')" style="display: inline-flex; align-items: center; gap: 4px;" title="Hapus seluruh laporan sesi shift ini (Khusus Superadmin)">
                  <?= app_icon('trash', 13) ?>
                  <span>Hapus Laporan</span>
                </button>
              <?php endif; ?>
            </div>
          </div>

          <?php if (!empty($currentShift['notes'])): ?>
            <div style="margin-top: 1rem; padding: 0.75rem 1rem; background: var(--bg-alt); border-radius: var(--radius-sm); font-size: 0.82rem; color: var(--text-muted); border-left: 3px solid var(--primary);">
              <strong>Catatan Serah Terima / Penutupan Shift:</strong> <?= nl2br(htmlspecialchars($currentShift['notes'])) ?>
            </div>
          <?php endif; ?>
        </div>
      <?php elseif ($pendingUnclosedShift): ?>
        <?php
          $pShiftDate = date('d F Y', strtotime($pendingUnclosedShift['shift_date']));
          $pShiftInfo = get_shift_info($pendingUnclosedShift['shift_type']);
          $pActCount = (int)$pendingUnclosedShift['total_activities'];
          $pJumpUrl = 'daily_activity.php?shift_id=' . (int)$pendingUnclosedShift['id'];
          $cleanWa = preg_replace('/[^0-9]/', '', $defaultWaPhone);
          if (str_starts_with($cleanWa, '08')) {
              $cleanWa = '62' . substr($cleanWa, 1);
          }
          $waText = "Halo Superadmin NOC, saya " . ($techName ?: 'Teknisi') . " ingin melaporkan kendala sesi shift kosong yang belum ditutup pada Shift " . $pShiftInfo['name'] . " (" . $pShiftDate . "). Mohon bantuan untuk evaluasi dan reset penutupan sesi tersebut.";
          $superadminWaUrl = !empty($cleanWa) ? "https://wa.me/{$cleanWa}?text=" . rawurlencode($waText) : '';
        ?>
        <?php if ($pActCount > 0): ?>
          <!-- Case A: Pending Shift with Activities (Warning Card) -->
          <div class="daily-shift-card" style="border: 2px solid #f59e0b; background: rgba(245, 158, 11, 0.08); padding: 1.4rem; border-radius: var(--radius-md);">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
              <div style="display: flex; align-items: flex-start; gap: 1rem;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #fef3c7; color: #d97706; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 2px;">
                  <?= app_icon('alert-triangle', 24, '#d97706') ?>
                </div>
                <div>
                  <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <h4 class="daily-shift-title" style="margin: 0; color: #b45309; font-weight: 800;">
                      Akses Buka Shift Baru Ditahan: Sesi Shift Sebelumnya Belum Ditutup
                    </h4>
                    <span class="sla-status-pill sla-warning" style="display: inline-flex; align-items: center; gap: 4px;">
                      <?= app_icon('clock', 11) ?> SOP Handover
                    </span>
                  </div>
                  <div style="font-size: 0.86rem; color: var(--text-main); margin-top: 6px; line-height: 1.5; max-width: 720px;">
                    Anda masih memiliki sesi <strong><?= htmlspecialchars($pShiftInfo['name']) ?></strong> (<strong><?= $pShiftDate ?></strong>) yang berstatus aktif dengan <strong><?= $pActCount ?> kegiatan tercatat</strong> dan belum diselesaikan. Sesuai SOP disiplin NOC, selesaikan dan serahkan shift sebelumnya terlebih dahulu sebelum membuka sesi shift baru.
                  </div>
                </div>
              </div>

              <div>
                <a href="<?= $pJumpUrl ?>" class="btn btn-warning" style="padding: 0.65rem 1.4rem; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(217, 119, 6, 0.25);">
                  <?= app_icon('arrow-right', 14) ?>
                  <span>Selesaikan & Serahkan Shift Sebelumnya</span>
                </a>
              </div>
            </div>
          </div>
        <?php else: ?>
          <!-- Case B: Pending Empty Shift (Strict Lockout Red Card) -->
          <div class="daily-shift-card" style="border: 2px solid #ef4444; background: rgba(239, 68, 68, 0.08); padding: 1.4rem; border-radius: var(--radius-md);">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
              <div style="display: flex; align-items: flex-start; gap: 1rem;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 2px;">
                  <?= app_icon('shield-alert', 24, '#dc2626') ?>
                </div>
                <div>
                  <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <h4 class="daily-shift-title" style="margin: 0; color: #b91c1c; font-weight: 800;">
                      Akses Ditahan Sistem (Pelanggaran SOP): Shift Kosong Belum Ditutup
                    </h4>
                    <span class="sla-status-pill sla-danger" style="display: inline-flex; align-items: center; gap: 4px;">
                      <?= app_icon('lock', 11) ?> Terkunci Sistem
                    </span>
                  </div>
                  <div style="font-size: 0.86rem; color: var(--text-main); margin-top: 6px; line-height: 1.5; max-width: 720px;">
                    Sistem mendeteksi Anda membuka sesi <strong><?= htmlspecialchars($pShiftInfo['name']) ?></strong> (<strong><?= $pShiftDate ?></strong>) tanpa catatan kegiatan (0 kegiatan) dan meninggalkannya tanpa ditutup. Sesuai SOP disiplin logbook NOC, Anda <strong>tidak dapat membuka shift baru</strong> dan <strong>tidak diizinkan menutup shift kosong secara mandiri</strong>. Silakan hubungi Superadmin / Team Leader NOC untuk evaluasi dan reset penutupan sesi tersebut.
                  </div>
                </div>
              </div>

              <div>
                <?php if (!empty($superadminWaUrl)): ?>
                  <a href="<?= $superadminWaUrl ?>" target="_blank" rel="noopener noreferrer" class="btn btn-danger" style="padding: 0.65rem 1.4rem; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.25);">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766.001-3.187-2.575-5.77-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.312.045-.634.053-1.02-.072-.258-.084-.582-.206-1.007-.393-1.782-.784-2.944-2.587-3.033-2.706-.089-.12-1.084-1.442-1.084-2.75 0-1.309.686-1.954.929-2.217.243-.262.531-.328.708-.328.177 0 .354.008.509.016.166.009.388-.063.607.462.227.544.773 1.884.841 2.02.067.137.112.298.022.477-.089.178-.133.29-.266.444-.133.155-.28.345-.399.463-.133.134-.271.278-.117.543.155.266.689 1.134 1.477 1.836 1.013.902 1.868 1.182 2.134 1.315.266.133.421.111.576-.067.155-.178.664-.775.841-1.041.177-.266.354-.222.597-.133.244.089 1.549.731 1.815.864.266.133.443.199.509.31.066.111.066.643-.078 1.048z"/></svg>
                    <span>Hubungi Superadmin via WA</span>
                  </a>
                <?php else: ?>
                  <span class="btn btn-outline-danger btn-sm disabled" style="cursor: not-allowed; display: inline-flex; align-items: center; gap: 6px; padding: 0.6rem 1rem;">
                    <?= app_icon('phone', 14) ?>
                    <span>Hubungi Superadmin NOC</span>
                  </span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>
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
                  <h4 class="daily-shift-title" style="margin: 0; color: var(--text-main);">
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
        <h5 class="daily-section-title" style="color: var(--text-main); margin: 0;">
          Daftar Kegiatan Shift Ini (<?= count($activities) ?>)
        </h5>
        <span style="font-size: 0.78rem; color: var(--text-subtle);">Urut berdasarkan waktu kegiatan</span>
      </div>

      <?php if (!$currentShift): ?>
        <?php if ($pendingUnclosedShift): ?>
          <?php if ((int)$pendingUnclosedShift['total_activities'] > 0): ?>
            <div style="background: var(--bg-card); border: 2px dashed #f59e0b; border-radius: var(--radius-lg); padding: 3rem 2rem; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center; box-shadow: var(--shadow-sm);">
              <div style="width: 64px; height: 64px; border-radius: 50%; background: #fef3c7; color: #d97706; display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                <?= app_icon('alert-triangle', 32, '#d97706') ?>
              </div>
              <h4 style="font-size: 1.2rem; font-weight: 800; color: #b45309; margin: 0 0 0.5rem 0;">Akses Shift Baru Ditahan</h4>
              <p style="font-size: 0.88rem; color: var(--text-subtle); max-width: 480px; margin: 0 auto 1.5rem auto; line-height: 1.5;">
                Anda tidak dapat membuka sesi shift baru karena masih memiliki sesi <strong><?= htmlspecialchars($pShiftInfo['name']) ?></strong> (<?= $pShiftDate ?>) yang belum ditutup (<?= (int)$pendingUnclosedShift['total_activities'] ?> kegiatan tercatat). Silakan selesaikan sesi tersebut terlebih dahulu.
              </p>
              <a href="<?= $pJumpUrl ?>" class="btn btn-warning" style="padding: 0.65rem 1.6rem; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(217, 119, 6, 0.25);">
                <?= app_icon('arrow-right', 15) ?>
                <span>Buka & Selesaikan Shift Sebelumnya</span>
              </a>
            </div>
          <?php else: ?>
            <div style="background: var(--bg-card); border: 2px dashed #ef4444; border-radius: var(--radius-lg); padding: 3rem 2rem; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center; box-shadow: var(--shadow-sm);">
              <div style="width: 64px; height: 64px; border-radius: 50%; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                <?= app_icon('lock', 32, '#dc2626') ?>
              </div>
              <h4 style="font-size: 1.2rem; font-weight: 800; color: #b91c1c; margin: 0 0 0.5rem 0;">Pencatatan Kegiatan Terkunci</h4>
              <p style="font-size: 0.88rem; color: var(--text-subtle); max-width: 480px; margin: 0 auto 1.5rem auto; line-height: 1.5;">
                Sesi shift baru ditahan oleh sistem karena sesi <strong><?= htmlspecialchars($pShiftInfo['name']) ?></strong> (<?= $pShiftDate ?>) berstatus kosong (0 kegiatan) dan belum ditutup. Sesuai SOP, silakan hubungi Superadmin NOC untuk evaluasi dan reset sesi tersebut.
              </p>
              <?php if (!empty($superadminWaUrl)): ?>
                <a href="<?= $superadminWaUrl ?>" target="_blank" rel="noopener noreferrer" class="btn btn-danger" style="padding: 0.65rem 1.6rem; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.25);">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766.001-3.187-2.575-5.77-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.312.045-.634.053-1.02-.072-.258-.084-.582-.206-1.007-.393-1.782-.784-2.944-2.587-3.033-2.706-.089-.12-1.084-1.442-1.084-2.75 0-1.309.686-1.954.929-2.217.243-.262.531-.328.708-.328.177 0 .354.008.509.016.166.009.388-.063.607.462.227.544.773 1.884.841 2.02.067.137.112.298.022.477-.089.178-.133.29-.266.444-.133.155-.28.345-.399.463-.133.134-.271.278-.117.543.155.266.689 1.134 1.477 1.836 1.013.902 1.868 1.182 2.134 1.315.266.133.421.111.576-.067.155-.178.664-.775.841-1.041.177-.266.354-.222.597-.133.244.089 1.549.731 1.815.864.266.133.443.199.509.31.066.111.066.643-.078 1.048z"/></svg>
                  <span>Hubungi Superadmin via WhatsApp</span>
                </a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        <?php else: ?>
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
        <?php endif; ?>
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
                  <h4 class="activity-item-title" style="color: var(--text-main); margin: 0;">
                    <?= htmlspecialchars($act['title']) ?>
                  </h4>
                </div>

                <!-- Delete Action (if shift active and permitted, or superadmin anytime) -->
                <?php 
                $canDeleteActivity = (
                    ($currentShift && $currentShift['status'] === 'active' && ($isAdmin || (int)$act['user_id'] === $userId)) ||
                    has_role('superadmin')
                );
                if ($canDeleteActivity): ?>
                  <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteActivity(<?= (int)$act['id'] ?>)" title="Hapus Kegiatan" style="padding: 2px 8px; font-size: 0.75rem; display: inline-flex; align-items: center; gap: 4px;">
                    <?= app_icon('trash', 12) ?>
                    <span>Hapus</span>
                  </button>
                <?php endif; ?>
              </div>

              <!-- Activity Detail Description -->
              <div class="activity-item-desc" style="color: var(--text-muted); margin-top: 0.5rem; white-space: pre-line;">
                <?= nl2br(htmlspecialchars($act['description'])) ?>
              </div>

              <!-- Photo Attachments -->
              <?php if (!empty($act['photos'])): ?>
                <div style="margin-top: 0.85rem; border-top: 1px dashed var(--border); padding-top: 0.75rem;">
                  <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-subtle); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 4px;">
                    <?= app_icon('camera', 13) ?>
                    <span>DOKUMENTASI FOTO (<?= count($act['photos']) ?>):</span>
                  </div>
                  <div class="activity-photos-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 0.75rem; margin-top: 0.5rem;">
                    <?php foreach ($act['photos'] as $pIdx => $photo): 
                      $cap1 = !empty($photo['caption']) ? $photo['caption'] : $act['title'];
                      $cap2 = $photo['caption_2'] ?? '';
                    ?>
                      <div class="activity-photo-card" style="border: 1px solid var(--border); border-radius: var(--radius-sm); overflow: hidden; background: var(--bg-card); display: flex; flex-direction: column; box-shadow: var(--shadow-xs);">
                        <div style="padding: 4px 8px; font-size: 0.73rem; font-weight: 700; color: var(--text-main); background: var(--bg-alt); border-bottom: 1px solid var(--border); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($cap1) ?>">
                          <?= htmlspecialchars($cap1) ?>
                        </div>
                        <div class="activity-photo-item" style="cursor: pointer; aspect-ratio: 4/3; position: relative;" onclick="viewPhotoFull('<?= htmlspecialchars($photo['photo_path']) ?>', '<?= htmlspecialchars(addslashes($cap1)) ?>', '<?= htmlspecialchars(addslashes($cap2)) ?>')">
                          <img src="<?= htmlspecialchars($photo['photo_path']) ?>" alt="Foto Kegiatan" loading="lazy">
                        </div>
                        <?php if (!empty($cap2)): ?>
                          <div style="padding: 4px 8px; font-size: 0.7rem; color: var(--text-muted); background: var(--bg-card); border-top: 1px solid var(--border); line-height: 1.25; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;" title="<?= htmlspecialchars($cap2) ?>">
                            <?= htmlspecialchars($cap2) ?>
                          </div>
                        <?php endif; ?>
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
  <form id="form_save_activity" class="modal-dialog-custom" enctype="multipart/form-data">
    
    <div class="modal-header-custom">
      <div style="display: flex; align-items: center; gap: 8px;">
        <?= app_icon('file-text', 18, 'text-primary') ?>
        <h4 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text-main);">Catat Kegiatan Harian NOC</h4>
      </div>
      <button type="button" class="modal-close-btn" id="btn_close_activity_modal" aria-label="Tutup">&times;</button>
    </div>

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

          <!-- Row 5: Flexible Photo Slots with Keterangan 1 & Keterangan 2 -->
          <div class="mb-2">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.6rem;">
              <label class="form-label mb-0" style="font-size: 0.8rem; font-weight: 700; color: var(--text-main);">
                Lampiran Dokumentasi Foto Kegiatan
              </label>
              <span style="font-size: 0.72rem; color: var(--text-subtle);">Bebas tambah foto (fleksibel)</span>
            </div>

            <!-- Dynamic Photo Slots Container -->
            <div id="daily_photo_slots_container" style="display: flex; flex-direction: column; gap: 0.85rem; margin-bottom: 0.75rem;"></div>

            <!-- Tombol Tambah Slot Foto & Batch Upload -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
              <button type="button" class="btn btn-secondary btn-sm" id="btn_add_photo_slot" style="border: 1.5px dashed var(--border-strong); padding: 0.55rem 0.65rem; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-size: 0.8rem;">
                <?= app_icon('plus', 14) ?>
                <span>+ Tambah 1 Slot</span>
              </button>
              <button type="button" class="btn btn-outline-primary btn-sm" id="btn_batch_upload_photos" style="border: 1.5px dashed var(--primary); padding: 0.55rem 0.65rem; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-size: 0.8rem; background: rgba(0, 128, 128, 0.06); color: var(--primary);">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                <span>📁 Upload Banyak Foto</span>
              </button>
            </div>
            <input type="file" id="batch_photo_file_input" accept="image/*" multiple style="display: none;">
            <div id="batch_upload_status" style="display: none; font-size: 0.75rem; color: var(--primary); font-weight: 600; text-align: center; margin-top: 6px; padding: 4px 8px; background: rgba(0, 128, 128, 0.08); border-radius: 4px;"></div>
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
  <div style="max-width: 90vw; max-height: 90vh; margin: auto; position: relative;" onclick="event.stopPropagation()">
    <button type="button" class="modal-close-btn" onclick="document.getElementById('photo_viewer_modal').style.display='none'" style="position: absolute; top: -38px; right: 0; color: #fff; font-size: 1.8rem; background: transparent; border: none; cursor: pointer;">&times;</button>
    <img id="full_photo_img" src="" alt="Foto Full" style="max-width: 100%; max-height: 75vh; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.5); object-fit: contain; display: block; margin: auto;">
    <div style="background: rgba(15, 23, 42, 0.9); backdrop-filter: blur(6px); padding: 0.75rem 1rem; border-radius: 8px; margin-top: 8px; text-align: center; color: #fff;">
      <div id="full_photo_caption" style="font-size: 0.95rem; font-weight: 700; color: #ffffff;"></div>
      <div id="full_photo_caption_2" style="font-size: 0.82rem; color: #94a3b8; margin-top: 3px; display: none;"></div>
    </div>
  </div>
</div>

<!-- Modal: Selesaikan & Serahkan Shift -->
<div id="complete_shift_modal" class="modal-backdrop-custom" style="display: none;">
  <div class="modal-dialog-custom" style="max-width: 520px;">
    <div class="modal-header-custom">
      <div style="display: flex; align-items: center; gap: 8px;">
        <?= app_icon('check-circle', 20, '#008080') ?>
        <h4 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text-main);">Selesaikan &amp; Serahkan Shift</h4>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeCompleteShiftModal()" aria-label="Tutup">&times;</button>
    </div>

    <div class="modal-body-custom">
      <div style="background: rgba(0, 128, 128, 0.08); border: 1px solid rgba(0, 128, 128, 0.2); border-radius: var(--radius-sm); padding: 0.85rem 1rem; margin-bottom: 1rem; font-size: 0.84rem; color: var(--text-main); line-height: 1.5;">
        Sesi shift <strong id="complete_modal_shift_title">Shift Ini</strong> akan diselesaikan dan logbook dikunci secara resmi. Ringkasan laporan serta dokumen PDF resmi akan otomatis dikirimkan ke Discord NOC.
      </div>

      <div class="mb-3">
        <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: var(--text-main);">
          Catatan Serah Terima / Handover: <span style="font-weight: 400; color: var(--text-muted);">(opsional)</span>
        </label>
        <textarea id="complete_shift_notes_input" class="form-control" rows="3" placeholder="Masukkan catatan serah terima untuk teknisi shift berikutnya, kondisi perangkat, atau tindak lanjut..."></textarea>
      </div>

      <!-- Verifikasi Tanda Tangan Selesai Shift -->
      <div style="border-top: 1px solid var(--border); padding-top: 0.85rem; margin-top: 0.5rem;">
        <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
          <span>Tanda Tangan Pelaksana Shift:</span>
          <?php if (!empty($userSignature) && file_exists(__DIR__ . '/' . $userSignature)): ?>
            <span class="user-role-badge role-admin" style="font-size: 0.7rem; padding: 2px 7px;">
              TTD Akun Siap
            </span>
          <?php endif; ?>
        </label>

        <?php if (!empty($userSignature) && file_exists(__DIR__ . '/' . $userSignature)): ?>
          <div id="box_shift_profile_sig" style="background: var(--bg-alt); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 10px 14px; margin-bottom: 8px;">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px;">
              <div style="display: flex; align-items: center; gap: 12px;">
                <div style="background: #ffffff; border: 1px dashed #cbd5e1; border-radius: 6px; padding: 4px 10px; display: inline-flex; align-items: center; justify-content: center; min-width: 90px; height: 44px;">
                  <img src="<?= htmlspecialchars($userSignature) ?>?v=<?= time() ?>" alt="TTD Profil" style="max-height: 38px; max-width: 90px; object-fit: contain;">
                </div>
                <div>
                  <div style="font-size: 0.8rem; font-weight: 700; color: var(--text-main);">Gunakan TTD Akun Saya</div>
                  <div style="font-size: 0.72rem; color: var(--text-subtle);">Otomatis diterapkan ke lembar logbook shift ini.</div>
                </div>
              </div>
              <button type="button" class="btn btn-outline-secondary btn-xs" id="btn_toggle_custom_shift_sig" onclick="toggleCustomShiftSig(true)" style="font-size: 0.72rem; padding: 3px 8px; font-weight: 600;">
                Ganti TTD Khusus
              </button>
            </div>
            <input type="hidden" id="use_profile_shift_sig" value="1">
          </div>
        <?php else: ?>
          <input type="hidden" id="use_profile_shift_sig" value="0">
        <?php endif; ?>

        <!-- Container Buat / Gores TTD Baru -->
        <div id="container_custom_shift_sig" style="<?= (!empty($userSignature) && file_exists(__DIR__ . '/' . $userSignature)) ? 'display: none;' : '' ?> background: var(--bg-alt); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 10px; margin-top: 8px;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
            <div style="display: flex; gap: 6px;">
              <button type="button" class="btn btn-xs btn-secondary shift-sig-tab active" id="tab_btn_shift_draw" onclick="switchShiftSigTab('draw')" style="font-size: 0.72rem; font-weight: 700;">
                Gores di Layar
              </button>
              <button type="button" class="btn btn-xs btn-secondary shift-sig-tab" id="tab_btn_shift_upload" onclick="switchShiftSigTab('upload')" style="font-size: 0.72rem; font-weight: 700; opacity: 0.75;">
                Upload File
              </button>
            </div>
            <?php if (!empty($userSignature) && file_exists(__DIR__ . '/' . $userSignature)): ?>
              <button type="button" class="btn btn-link btn-xs" onclick="toggleCustomShiftSig(false)" style="font-size: 0.72rem; color: var(--text-muted); text-decoration: none; padding: 0;">
                Batal (Pakai TTD Akun)
              </button>
            <?php endif; ?>
          </div>

          <!-- Gores Layar Canvas -->
          <div id="panel_shift_sig_draw">
            <div style="position: relative; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; overflow: hidden; width: 100%; height: 130px;">
              <canvas id="canvas_shift_signature" style="width: 100%; height: 130px; display: block; cursor: crosshair;"></canvas>
              <div style="position: absolute; bottom: 4px; right: 6px;">
                <button type="button" class="btn btn-light btn-xs" id="btn_clear_shift_canvas" style="font-size: 0.68rem; padding: 2px 6px; background: rgba(255,255,255,0.92); border: 1px solid #cbd5e1; border-radius: 4px;">
                  Bersihkan
                </button>
              </div>
              <div style="position: absolute; top: 4px; left: 8px; font-size: 0.68rem; color: #94a3b8; pointer-events: none;">
                Gores tanda tangan di sini (Touch / Mouse)
              </div>
            </div>
          </div>

          <!-- Upload File -->
          <div id="panel_shift_sig_upload" style="display: none;">
            <input type="file" id="input_shift_signature_file" accept="image/png,image/jpeg,image/webp" class="form-control form-control-sm" style="font-size: 0.78rem;">
            <div style="font-size: 0.7rem; color: var(--text-subtle); margin-top: 4px;">
              Disarankan PNG transparan atau foto tanda tangan di kertas putih.
            </div>
          </div>

          <div style="margin-top: 8px;">
            <label style="font-size: 0.75rem; color: var(--text-main); display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
              <input type="checkbox" id="chk_shift_save_to_profile" value="1" checked>
              <span>Simpan juga sebagai tanda tangan utama di profil saya</span>
            </label>
          </div>
        </div>
      </div>

      <input type="hidden" id="complete_modal_shift_id" value="">
    </div>

    <div class="modal-footer-custom" style="display: flex; justify-content: flex-end; gap: 8px;">
      <button type="button" class="btn btn-secondary btn-sm" onclick="closeCompleteShiftModal()">Batal</button>
      <button type="button" class="btn btn-primary btn-sm" id="btn_confirm_complete_shift" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 700;">
        <?= app_icon('check', 14) ?>
        <span>Kunci &amp; Selesaikan Shift</span>
      </button>
    </div>
  </div>
</div>

<!-- Modal: Hapus Laporan Shift (Superadmin) -->
<div id="delete_shift_modal" class="modal-backdrop-custom" style="display: none;">
  <div class="modal-dialog-custom" style="max-width: 500px;">
    <div class="modal-header-custom" style="border-bottom: 1px solid rgba(220, 38, 38, 0.2);">
      <div style="display: flex; align-items: center; gap: 8px;">
        <div style="width: 28px; height: 28px; border-radius: 50%; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center;">
          <?= app_icon('trash', 15, '#dc2626') ?>
        </div>
        <h4 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: #b91c1c;">Hapus Laporan Shift</h4>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeDeleteShiftModal()" aria-label="Tutup">&times;</button>
    </div>

    <div class="modal-body-custom">
      <div style="background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.25); border-radius: var(--radius-sm); padding: 0.9rem 1rem; margin-bottom: 1rem; font-size: 0.85rem; color: #7f1d1d; line-height: 1.5;">
        <strong>PERINGATAN KHUSUS SUPERADMIN:</strong><br>
        Apakah Anda yakin ingin menghapus permanen seluruh sesi laporan shift ini?
      </div>

      <div style="background: var(--bg-alt); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 0.75rem 1rem; font-size: 0.82rem; margin-bottom: 0.75rem;">
        <div style="margin-bottom: 4px;"><strong>Sesi Shift:</strong> <span id="del_modal_shift_name">-</span></div>
        <div style="margin-bottom: 4px;"><strong>Tanggal:</strong> <span id="del_modal_shift_date">-</span></div>
        <div><strong>Teknisi:</strong> <span id="del_modal_tech_name">-</span></div>
      </div>

      <p style="font-size: 0.78rem; color: var(--text-muted); margin: 0; line-height: 1.4;">
        Seluruh kegiatan dan file foto dokumentasi fisik pada shift ini akan dihapus permanen dari server dan tindakan ini akan dicatat ke audit log.
      </p>

      <input type="hidden" id="del_modal_shift_id" value="">
    </div>

    <div class="modal-footer-custom" style="display: flex; justify-content: flex-end; gap: 8px;">
      <button type="button" class="btn btn-secondary btn-sm" onclick="closeDeleteShiftModal()">Batal</button>
      <button type="button" class="btn btn-danger btn-sm" id="btn_confirm_delete_shift" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 700; background: #dc2626; border-color: #dc2626;">
        <?= app_icon('trash', 14) ?>
        <span>Ya, Hapus Permanen</span>
      </button>
    </div>
  </div>
</div>

<style>
/* Custom Modal Styles */
.modal-backdrop-custom {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.65);
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
  z-index: 1100;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
}

.modal-dialog-custom {
  width: 100%;
  max-width: 620px;
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  box-shadow: 0 20px 40px rgba(0,0,0,0.25);
  animation: slideUpFade 0.22s cubic-bezier(0.16, 1, 0.3, 1);
  overflow: hidden;
  max-height: 90vh;
  max-height: 90dvh;
  display: flex;
  flex-direction: column;
  margin: auto;
}

.modal-content-custom {
  display: flex;
  flex-direction: column;
  flex: 1 1 auto;
  min-height: 0;
  max-height: 100%;
  overflow: hidden;
}

.modal-header-custom {
  flex-shrink: 0;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: var(--bg-card);
  z-index: 2;
}

.modal-close-btn {
  background: transparent;
  border: none;
  font-size: 1.5rem;
  line-height: 1;
  color: var(--text-muted);
  cursor: pointer;
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-sm);
  transition: background 0.15s, color 0.15s;
}

.modal-close-btn:hover {
  background: var(--bg-alt);
  color: var(--text-main);
}

.modal-body-custom {
  flex: 1 1 auto;
  min-height: 0;
  padding: 1.25rem;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  overscroll-behavior: contain;
  touch-action: pan-y;
}

.modal-footer-custom {
  flex-shrink: 0;
  padding: 0.85rem 1.25rem;
  border-top: 1px solid var(--border);
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 8px;
  background: var(--bg-alt);
  z-index: 2;
}

@media (max-width: 640px) {
  .modal-backdrop-custom {
    padding: 0.5rem 0.5rem 0 0.5rem;
    align-items: flex-end;
  }

  .modal-dialog-custom {
    max-height: calc(100dvh - 0.75rem);
    max-height: calc(100vh - 0.75rem);
    border-bottom-left-radius: 0;
    border-bottom-right-radius: 0;
    margin-bottom: 0;
  }

  .modal-header-custom {
    padding: 0.85rem 1rem;
  }

  .modal-body-custom {
    padding: 1rem 0.85rem;
  }

  .modal-footer-custom {
    padding: 0.75rem 1rem;
    background: var(--bg-card);
    box-shadow: 0 -4px 16px rgba(0,0,0,0.06);
    gap: 10px;
  }

  .modal-footer-custom .btn {
    flex: 1;
    min-height: 44px;
    font-size: 0.88rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }
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
// Data Real-Time Shift & Jam Operasional NOC (WIB)
var REALTIME_SHIFT = <?= json_encode($realtimeShift) ?>;
var REALTIME_SHIFT_NAME = <?= json_encode($realtimeShift === 'malam' ? 'Shift Malam (20:00 - 08:00 WIB)' : 'Shift Pagi (08:00 - 20:00 WIB)') ?>;
var REALTIME_TIME = <?= json_encode($currentTimeFormatted) ?>;
var REALTIME_DATE = <?= json_encode($realtimeDate) ?>;

// Array to hold files & camera captures to upload
var queuedFiles = [];
var queuedBase64 = [];

// Global Modal Handlers (Hoisted globally for HTML onclick)
function openCompleteShiftModal(shiftId, shiftTitle) {
  var modal = document.getElementById('complete_shift_modal');
  var idInput = document.getElementById('complete_modal_shift_id');
  var titleEl = document.getElementById('complete_modal_shift_title');
  var notesInput = document.getElementById('complete_shift_notes_input');
  if (modal) {
    if (idInput) idInput.value = shiftId;
    if (titleEl && shiftTitle) titleEl.textContent = shiftTitle;
    if (notesInput) notesInput.value = '';
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    setTimeout(function() {
      if (window.initShiftSigPad) window.initShiftSigPad();
    }, 120);
    if (notesInput) {
      setTimeout(function() { notesInput.focus(); }, 150);
    }
  }
}

function closeCompleteShiftModal() {
  var modal = document.getElementById('complete_shift_modal');
  if (modal) {
    modal.style.display = 'none';
    document.body.style.overflow = '';
  }
}

function openDeleteShiftModal(shiftId, shiftName, shiftDate, techName) {
  var modal = document.getElementById('delete_shift_modal');
  var idInput = document.getElementById('del_modal_shift_id');
  var nameEl = document.getElementById('del_modal_shift_name');
  var dateEl = document.getElementById('del_modal_shift_date');
  var techEl = document.getElementById('del_modal_tech_name');
  if (modal) {
    if (idInput) idInput.value = shiftId;
    if (nameEl) nameEl.textContent = shiftName || '-';
    if (dateEl) dateEl.textContent = shiftDate || '-';
    if (techEl) techEl.textContent = techName || '-';
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }
}

function closeDeleteShiftModal() {
  var modal = document.getElementById('delete_shift_modal');
  if (modal) {
    modal.style.display = 'none';
    document.body.style.overflow = '';
  }
}

function deleteCurrentShift(shiftId, shiftName, shiftDate) {
  openDeleteShiftModal(shiftId, shiftName, shiftDate, '');
}

document.addEventListener('DOMContentLoaded', function() {
  var modal = document.getElementById('activity_modal');
  var btnOpen = document.getElementById('btn_open_activity_modal');
  var btnClose = document.getElementById('btn_close_activity_modal');
  var btnCancel = document.getElementById('btn_cancel_activity');

  // Smart Mismatch Confirmation saat pengguna submit filter form
  var filterForm = document.querySelector('.daily-filter-form');
  if (filterForm) {
    filterForm.addEventListener('submit', function(e) {
      var selectShift = filterForm.querySelector('select[name="shift_type"]');
      var inputDate = filterForm.querySelector('input[name="date"]');
      if (selectShift) {
        var val = selectShift.value;
        if (val !== REALTIME_SHIFT && val !== 'oh') {
          var targetName = val === 'malam' ? 'Shift Malam' : 'Shift Pagi';
          var dateVal = inputDate ? inputDate.value : '';
          var confirmMsg = 'Konfirmasi Pemilihan Shift:\n\n' +
            'Saat ini adalah jam operasional ' + REALTIME_SHIFT_NAME + ' (' + REALTIME_TIME + ').\n\n' +
            'Apakah Anda yakin ingin memilih ' + targetName + (dateVal ? ' (' + dateVal + ')' : '') + '?';
          if (!confirm(confirmMsg)) {
            e.preventDefault();
            return false;
          }
        }
      }
    });
  }

  function openModal() {
    var shiftExists = btnOpen ? btnOpen.getAttribute('data-shift-exists') : '0';
    var shiftStatus = btnOpen ? btnOpen.getAttribute('data-shift-status') : '';

    if (shiftExists !== '1') {
      var startBtn = document.getElementById('btn_start_shift_action');
      if (startBtn) {
        if (confirm('Sesi shift ini belum dibuka. Anda harus memilih dan memulai sesi shift terlebih dahulu sebelum dapat mencatat kegiatan.\n\nApakah Anda ingin membuka dan memulai sesi shift ini sekarang?')) {
          startBtn.click();
        }
      } else {
        alert('Akses buka shift ditahan: Anda masih memiliki sesi shift aktif sebelumnya yang belum diselesaikan / ditutup. Silakan periksa banner peringatan di atas.');
      }
      return;
    }

    if (shiftStatus === 'completed') {
      alert('Sesi shift ini telah selesai dan dikunci. Anda tidak dapat menambahkan kegiatan pada shift yang sudah ditutup.');
      return;
    }

    if (modal) {
      modal.style.display = 'flex';
      document.body.style.overflow = 'hidden';
      // Auto-focus first input after animation
      setTimeout(function() {
        var firstInput = document.getElementById('input_act_time');
        if (firstInput) firstInput.focus();
      }, 100);
    }
  }
  function closeModal() {
    if (modal) {
      modal.style.display = 'none';
      document.body.style.overflow = '';
    }
  }

  if (btnOpen) btnOpen.addEventListener('click', openModal);
  if (btnClose) btnClose.addEventListener('click', closeModal);
  if (btnCancel) btnCancel.addEventListener('click', closeModal);

  if (modal) {
    modal.addEventListener('click', function(e) {
      if (e.target === modal) {
        closeModal();
      }
    });
  }

  // Start Shift Handler
  var btnStartShift = document.getElementById('btn_start_shift_action');
  if (btnStartShift) {
    btnStartShift.addEventListener('click', function() {
      var shiftDate = this.getAttribute('data-date');
      var shiftType = this.getAttribute('data-type');
      var userId = this.getAttribute('data-user');

      // Validasi pintar: peringatan jika membuka shift yang tidak sesuai jam aktif saat ini
      if (shiftType !== REALTIME_SHIFT && shiftType !== 'oh') {
        var targetName = shiftType === 'malam' ? 'Shift Malam (20:00 - 08:00 WIB)' : 'Shift Pagi (08:00 - 20:00 WIB)';
        var confirmMsg = 'PERINGATAN SHIFT:\n\n' +
          'Saat ini adalah jam operasional ' + REALTIME_SHIFT_NAME + ' (' + REALTIME_TIME + ').\n\n' +
          'Apakah Anda yakin ingin MEMBUKA sesi ' + targetName + ' untuk tanggal ' + shiftDate + '?';
        if (!confirm(confirmMsg)) {
          return;
        }
      }

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
          if (data.pending_unclosed && !data.is_empty && data.pending_shift_id) {
            if (confirm(data.message + '\n\nApakah Anda ingin membuka sesi shift yang belum selesai tersebut sekarang?')) {
              window.location.href = 'daily_activity.php?shift_id=' + data.pending_shift_id;
              return;
            }
          } else {
            alert(data.message || 'Gagal membuka shift.');
          }
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

  // Dynamic Photo Slots Management & Batch Upload for Daily Activity
  var photoSlotsContainer = document.getElementById('daily_photo_slots_container');
  var btnAddPhotoSlot = document.getElementById('btn_add_photo_slot');
  var btnBatchUpload = document.getElementById('btn_batch_upload_photos');
  var batchPhotoInput = document.getElementById('batch_photo_file_input');
  var batchStatus = document.getElementById('batch_upload_status');
  var slotCounter = 0;
  var slotStore = {};

  // Client-side image compression to ensure fast uploads & prevent server limits
  function compressImageClient(file, callback) {
    if (!file || !file.type.match(/^image\//i)) {
      callback(file, null);
      return;
    }
    var reader = new FileReader();
    reader.onload = function(e) {
      var img = new Image();
      img.onload = function() {
        try {
          var maxDim = 1600;
          var w = img.naturalWidth || img.width;
          var h = img.naturalHeight || img.height;
          var targetW = w;
          var targetH = h;
          if (targetW > maxDim || targetH > maxDim) {
            if (targetW > targetH) {
              targetH = Math.round((targetH / targetW) * maxDim);
              targetW = maxDim;
            } else {
              targetW = Math.round((targetW / targetH) * maxDim);
              targetH = maxDim;
            }
          }
          var canvas = document.createElement('canvas');
          canvas.width = targetW;
          canvas.height = targetH;
          var ctx = canvas.getContext('2d');
          ctx.drawImage(img, 0, 0, targetW, targetH);

          var dataUrl = canvas.toDataURL('image/jpeg', 0.82);
          if (canvas.toBlob) {
            canvas.toBlob(function(blob) {
              if (blob) {
                var cleanName = (file.name || 'photo').replace(/\.[^/.]+$/, "") + '.jpg';
                var compressedFile = new File([blob], cleanName, {
                  type: 'image/jpeg',
                  lastModified: Date.now()
                });
                callback(compressedFile, dataUrl);
              } else {
                callback(file, dataUrl);
              }
            }, 'image/jpeg', 0.82);
          } else {
            callback(file, dataUrl);
          }
        } catch (err) {
          console.warn('Canvas compression error:', err);
          callback(file, e.target.result);
        }
      };
      img.onerror = function() {
        callback(file, e.target.result);
      };
      img.src = e.target.result;
    };
    reader.onerror = function() {
      callback(file, null);
    };
    reader.readAsDataURL(file);
  }

  function createPhotoSlot(initialData) {
    initialData = initialData || {};
    var slotId = 'slot_' + (++slotCounter);
    slotStore[slotId] = { file: null, base64: null };

    var card = document.createElement('div');
    card.className = 'photo-slot-card';
    card.id = 'card_' + slotId;
    card.setAttribute('data-slot-id', slotId);
    card.style.cssText = 'border: 1px solid var(--border); border-radius: var(--radius-md); background: var(--bg-card); padding: 0.85rem; display: flex; flex-direction: column; gap: 0.65rem;';

    var currentSlotsCount = photoSlotsContainer ? (photoSlotsContainer.querySelectorAll('.photo-slot-card').length + 1) : 1;

    card.innerHTML = `
      <div class="photo-slot-header" style="display: flex; align-items: center; justify-content: space-between;">
        <span class="photo-code-badge slot-badge-num" style="background: var(--primary); color: #ffffff; font-size: 0.75rem; font-weight: 700; padding: 0.2rem 0.55rem; border-radius: 4px;">
          Foto #${currentSlotsCount}
        </span>
        <button type="button" class="btn btn-sm btn-outline-danger btn-remove-slot" style="padding: 2px 8px; font-size: 0.72rem; display: inline-flex; align-items: center; gap: 4px;" title="Hapus slot foto ini">
          &times; Hapus Foto
        </button>
      </div>

      <!-- Keterangan 1 (di atas foto) -->
      <div>
        <label class="form-label" style="font-size: 0.76rem; color: var(--text-subtle); margin-bottom: 4px; font-weight: 700; display: flex; justify-content: space-between;">
          <span>Keterangan Foto 1 (Judul / Objek)</span>
          <span style="font-size: 0.7rem; font-weight: 400; color: var(--text-muted);">(Contoh: KWH Multi Panel)</span>
        </label>
        <input type="text" class="form-control slot-input-cap1" placeholder="Ketik judul / nama objek foto..." value="${initialData.caption || ''}" style="min-height: 42px; padding: 0.5rem 0.75rem; font-size: 0.9rem; font-weight: 600;">
      </div>

      <!-- Image Preview Box (tengah) -->
      <div class="photo-preview-box slot-box-preview" style="width: 100%; height: 165px; background-color: var(--bg-alt); border: 2px dashed var(--border-strong); border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; overflow: hidden; position: relative; cursor: pointer;">
        <img class="photo-preview-img slot-img-preview" alt="Preview Foto" style="max-width: 100%; max-height: 100%; width: auto; height: auto; object-fit: contain; display: none; margin: auto;">
        <div class="slot-box-placeholder" style="display: flex; flex-direction: column; align-items: center; gap: 6px; color: var(--text-subtle);">
          <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
          <span style="font-size: 0.78rem; font-weight: 600;">Ambil Kamera / Pilih Foto</span>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="photo-btn-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
        <button type="button" class="btn btn-primary btn-slot-cam" style="display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 42px; font-size: 0.84rem; font-weight: 600;">
          📷 Kamera Live
        </button>
        <button type="button" class="btn btn-secondary btn-slot-gal" style="display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 42px; font-size: 0.84rem; font-weight: 600;">
          📁 Galeri File
        </button>
      </div>

      <!-- Hidden inputs (with multiple support) -->
      <input type="file" accept="image/*" multiple class="slot-hidden-file" style="display: none;">
      <input type="file" accept="image/*" capture="environment" class="slot-hidden-cam" style="display: none;">

      <!-- Keterangan 2 (di bawah foto) -->
      <div>
        <label class="form-label" style="font-size: 0.76rem; color: var(--text-subtle); margin-top: 4px; margin-bottom: 4px; font-weight: 700; display: flex; justify-content: space-between;">
          <span>Keterangan Foto 2 (Catatan / Rincian / Status)</span>
          <span style="font-size: 0.7rem; font-weight: 400; color: var(--text-muted);">(Contoh: Stand Meter 12450 kWh, Normal)</span>
        </label>
        <input type="text" class="form-control slot-input-cap2" placeholder="Ketik catatan rincian kondisi, parameter, atau hasil cek..." value="${initialData.caption_2 || ''}" style="min-height: 42px; padding: 0.5rem 0.75rem; font-size: 0.9rem;">
      </div>
    `;

    photoSlotsContainer.appendChild(card);

    var previewBox = card.querySelector('.slot-box-preview');
    var previewImg = card.querySelector('.slot-img-preview');
    var placeholder = card.querySelector('.slot-box-placeholder');
    var btnCam = card.querySelector('.btn-slot-cam');
    var btnGal = card.querySelector('.btn-slot-gal');
    var fileInput = card.querySelector('.slot-hidden-file');
    var camInput = card.querySelector('.slot-hidden-cam');
    var btnRemove = card.querySelector('.btn-remove-slot');

    function setPreview(src, fileObj, base64Str) {
      previewImg.src = src;
      previewImg.style.display = 'block';
      placeholder.style.display = 'none';
      previewBox.style.borderColor = 'var(--primary)';
      card.classList.add('slot-filled');
      slotStore[slotId].file = fileObj || null;
      slotStore[slotId].base64 = base64Str || null;
    }

    card._setPreview = setPreview;
    card._slotId = slotId;

    fileInput.addEventListener('change', function(e) {
      if (e.target.files && e.target.files.length > 0) {
        if (e.target.files.length === 1) {
          var file = e.target.files[0];
          compressImageClient(file, function(compFile, dataUrl) {
            setPreview(dataUrl, compFile, null);
            var cap1Input = card.querySelector('.slot-input-cap1');
            if (cap1Input && !cap1Input.value.trim()) {
              cap1Input.value = file.name.replace(/\.[^/.]+$/, "");
            }
          });
        } else {
          // If multiple files selected from single slot button
          handleBatchFiles(Array.from(e.target.files), card);
        }
      }
    });

    camInput.addEventListener('change', function(e) {
      if (e.target.files && e.target.files[0]) {
        var file = e.target.files[0];
        compressImageClient(file, function(compFile, dataUrl) {
          setPreview(dataUrl, compFile, null);
        });
      }
    });

    btnGal.addEventListener('click', function() {
      fileInput.click();
    });

    btnCam.addEventListener('click', function() {
      if (typeof openCameraModal === 'function') {
        window.dailyCameraCallback = function(dataUrl) {
          setPreview(dataUrl, null, dataUrl);
        };
        openCameraModal('daily_activity', 'Foto Kegiatan #' + currentSlotsCount);
      } else {
        camInput.click();
      }
    });

    previewBox.addEventListener('click', function() {
      fileInput.click();
    });

    btnRemove.addEventListener('click', function() {
      card.remove();
      delete slotStore[slotId];
      renumberSlots();
    });

    renumberSlots();
    return card;
  }

  function renumberSlots() {
    if (!photoSlotsContainer) return;
    var cards = photoSlotsContainer.querySelectorAll('.photo-slot-card');
    cards.forEach(function(c, i) {
      var badge = c.querySelector('.slot-badge-num');
      if (badge) badge.textContent = 'Foto #' + (i + 1);
      var btnDel = c.querySelector('.btn-remove-slot');
      if (btnDel) {
        btnDel.style.display = (cards.length > 1) ? 'inline-flex' : 'none';
      }
    });
  }

  // Handle batch selection of multiple photos
  function handleBatchFiles(files, startCard) {
    if (!files || files.length === 0) return;

    if (batchStatus) {
      batchStatus.style.display = 'block';
      batchStatus.textContent = '⏳ Mengompres & memproses ' + files.length + ' foto...';
    }

    var cards = Array.from(photoSlotsContainer.querySelectorAll('.photo-slot-card'));
    var currentIndex = 0;

    // Determine target card for the first file
    var firstTarget = startCard;
    if (!firstTarget) {
      // Find first empty card or use first card if empty
      firstTarget = cards.find(function(c) {
        var sid = c.getAttribute('data-slot-id');
        return !slotStore[sid] || (!slotStore[sid].file && !slotStore[sid].base64);
      });
    }

    function processNext() {
      if (currentIndex >= files.length) {
        if (batchStatus) {
          batchStatus.textContent = '✅ Berhasil memuat ' + files.length + ' foto siap disimpan.';
          setTimeout(function() { batchStatus.style.display = 'none'; }, 3000);
        }
        renumberSlots();
        return;
      }

      var file = files[currentIndex];
      compressImageClient(file, function(compFile, dataUrl) {
        var targetCard;
        if (currentIndex === 0 && firstTarget) {
          targetCard = firstTarget;
        } else {
          targetCard = createPhotoSlot();
        }

        if (targetCard && typeof targetCard._setPreview === 'function') {
          targetCard._setPreview(dataUrl, compFile, null);
          var cap1 = targetCard.querySelector('.slot-input-cap1');
          if (cap1 && !cap1.value.trim()) {
            cap1.value = file.name.replace(/\.[^/.]+$/, "");
          }
        }

        currentIndex++;
        processNext();
      });
    }

    processNext();
  }

  if (btnAddPhotoSlot) {
    btnAddPhotoSlot.addEventListener('click', function() {
      createPhotoSlot();
    });
  }

  if (btnBatchUpload && batchPhotoInput) {
    btnBatchUpload.addEventListener('click', function() {
      batchPhotoInput.click();
    });

    batchPhotoInput.addEventListener('change', function(e) {
      if (e.target.files && e.target.files.length > 0) {
        handleBatchFiles(Array.from(e.target.files), null);
        batchPhotoInput.value = '';
      }
    });
  }

  // Initialize first slot
  if (photoSlotsContainer && photoSlotsContainer.children.length === 0) {
    createPhotoSlot();
  }

  // Form Submission via AJAX with resilient parsing
  var form = document.getElementById('form_save_activity');
  var btnSubmit = document.getElementById('btn_submit_activity');

  if (form) {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      btnSubmit.disabled = true;
      btnSubmit.innerHTML = '<span>⏳</span> Menyimpan...';

      var formData = new FormData(form);

      // Collect structured photo slots
      var slotCards = photoSlotsContainer ? photoSlotsContainer.querySelectorAll('.photo-slot-card') : [];
      var photoIdx = 0;
      slotCards.forEach(function(card) {
        var slotId = card.getAttribute('data-slot-id');
        var cap1Input = card.querySelector('.slot-input-cap1');
        var cap2Input = card.querySelector('.slot-input-cap2');
        var cap1 = cap1Input ? cap1Input.value.trim() : '';
        var cap2 = cap2Input ? cap2Input.value.trim() : '';
        var data = slotStore[slotId] || {};

        if (data.file || data.base64 || cap1 || cap2) {
          formData.append('activity_photos[' + photoIdx + '][caption]', cap1);
          formData.append('activity_photos[' + photoIdx + '][caption_2]', cap2);
          if (data.file) {
            formData.append('activity_photo_files[' + photoIdx + ']', data.file);
          } else if (data.base64) {
            formData.append('activity_photos[' + photoIdx + '][base64]', data.base64);
          }
          photoIdx++;
        }
      });

      fetch('api/save_daily_activity.php', {
        method: 'POST',
        body: formData
      })
      .then(function(res) {
        return res.text().then(function(text) {
          try {
            return JSON.parse(text);
          } catch (err) {
            console.error('Server returned non-JSON:', text);
            var cleanMsg = text.replace(/<[^>]*>?/gm, ' ').replace(/\s+/g, ' ').trim();
            if (cleanMsg.length > 250) cleanMsg = cleanMsg.substring(0, 250) + '...';
            throw new Error(cleanMsg || 'Respon server tidak valid (' + res.status + ')');
          }
        });
      })
      .then(function(data) {
        btnSubmit.disabled = false;
        btnSubmit.innerHTML = '<span>✔</span> Simpan Kegiatan';
        if (data.success) {
          alert(data.message || 'Kegiatan berhasil disimpan.');
          window.location.reload();
        } else {
          alert('Gagal: ' + (data.message || 'Terjadi kesalahan sistem.'));
        }
      })
      .catch(function(err) {
        btnSubmit.disabled = false;
        btnSubmit.innerHTML = '<span>✔</span> Simpan Kegiatan';
        alert('Terjadi kendala saat menyimpan: ' + err.message);
      });
    });
  }

  var completeModal = document.getElementById('complete_shift_modal');
  if (completeModal) {
    completeModal.addEventListener('click', function(e) {
      if (e.target === completeModal) closeCompleteShiftModal();
    });
  }

  var btnConfirmComplete = document.getElementById('btn_confirm_complete_shift');
  if (btnConfirmComplete) {
    btnConfirmComplete.addEventListener('click', function() {
      var shiftId = document.getElementById('complete_modal_shift_id') ? document.getElementById('complete_modal_shift_id').value : '';
      var notes = document.getElementById('complete_shift_notes_input') ? document.getElementById('complete_shift_notes_input').value.trim() : '';

      if (!shiftId) {
        alert('ID Shift tidak ditemukan.');
        return;
      }

      var origText = btnConfirmComplete.innerHTML;
      btnConfirmComplete.disabled = true;
      btnConfirmComplete.innerHTML = '<span>⏳</span> Memproses...';

      var useProfileSig = document.getElementById('use_profile_shift_sig') ? document.getElementById('use_profile_shift_sig').value : '1';
      var saveToProfile = document.getElementById('chk_shift_save_to_profile') && document.getElementById('chk_shift_save_to_profile').checked ? '1' : '0';
      var fileInput = document.getElementById('input_shift_signature_file');

      var fd = new FormData();
      fd.append('shift_id', shiftId);
      fd.append('notes', notes);
      fd.append('use_profile_sig', useProfileSig);
      fd.append('save_to_profile', saveToProfile);

      if (useProfileSig !== '1') {
        if (window.shiftSigPad && !window.shiftSigPad.isEmpty()) {
          fd.append('signature_canvas', window.shiftSigPad.toDataURL());
        } else if (fileInput && fileInput.files && fileInput.files[0]) {
          fd.append('signature_file', fileInput.files[0]);
        }
      }

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
          btnConfirmComplete.disabled = false;
          btnConfirmComplete.innerHTML = origText;
        }
      })
      .catch(function(err) {
        alert('Error: ' + err.message);
        btnConfirmComplete.disabled = false;
        btnConfirmComplete.innerHTML = origText;
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
    if (waModal) {
      waModal.style.display = 'flex';
      document.body.style.overflow = 'hidden';
    }
  }
  function closeWaModal() {
    if (waModal) {
      waModal.style.display = 'none';
      document.body.style.overflow = '';
    }
  }

  if (btnOpenWa) btnOpenWa.addEventListener('click', openWaModal);
  if (btnCloseWa) btnCloseWa.addEventListener('click', closeWaModal);
  if (btnCancelWa) btnCancelWa.addEventListener('click', closeWaModal);

  if (waModal) {
    waModal.addEventListener('click', function(e) {
      if (e.target === waModal) {
        closeWaModal();
      }
    });
  }

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

var deleteModal = document.getElementById('delete_shift_modal');
if (deleteModal) {
  deleteModal.addEventListener('click', function(e) {
    if (e.target === deleteModal) closeDeleteShiftModal();
  });
}

var btnConfirmDelete = document.getElementById('btn_confirm_delete_shift');
if (btnConfirmDelete) {
  btnConfirmDelete.addEventListener('click', function() {
    var shiftId = document.getElementById('del_modal_shift_id') ? document.getElementById('del_modal_shift_id').value : '';

    if (!shiftId) {
      alert('ID Shift tidak ditemukan.');
      return;
    }

    var origText = btnConfirmDelete.innerHTML;
    btnConfirmDelete.disabled = true;
    btnConfirmDelete.innerHTML = '<span>⏳</span> Menghapus...';

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
        window.location.href = 'daily_activity';
      } else {
        alert('Gagal: ' + (data.message || 'Terjadi kesalahan.'));
        btnConfirmDelete.disabled = false;
        btnConfirmDelete.innerHTML = origText;
      }
    })
    .catch(function(err) {
      alert('Error: ' + err.message);
      btnConfirmDelete.disabled = false;
      btnConfirmDelete.innerHTML = origText;
    });
  });
}

// Full Photo Viewer
function viewPhotoFull(src, caption, caption2) {
  var modal = document.getElementById('photo_viewer_modal');
  var img = document.getElementById('full_photo_img');
  var cap = document.getElementById('full_photo_caption');
  var cap2 = document.getElementById('full_photo_caption_2');
  if (modal && img) {
    img.src = src;
    if (cap) cap.textContent = caption || '';
    if (cap2) {
      cap2.textContent = caption2 || '';
      cap2.style.display = caption2 ? 'block' : 'none';
    }
    modal.style.display = 'flex';
  }
}

// Shift Handover Reminder Checker (runs immediately and every 30s)
(function initShiftHandoverReminder() {
  var isShiftActive = <?= ($currentShift && $currentShift['status'] === 'active') ? 'true' : 'false' ?>;
  var shiftType = '<?= htmlspecialchars($filterShiftType) ?>';
  var alertEl = document.getElementById('shift_handover_alert');
  if (!isShiftActive || !alertEl) return;

  function checkHandover() {
    var dismissedUntil = sessionStorage.getItem('handover_dismissed_until');
    if (dismissedUntil && Date.now() < parseInt(dismissedUntil, 10)) {
      alertEl.style.display = 'none';
      return;
    }

    var now = new Date();
    // Use WIB (UTC+7)
    var utc = now.getTime() + (now.getTimezoneOffset() * 60000);
    var wib = new Date(utc + (3600000 * 7));
    var hours = wib.getHours();
    var minutes = wib.getMinutes();
    var totalMins = (hours * 60) + minutes;

    var shouldShow = false;
    var isUrgent = false;
    var msg = '';
    var title = 'Shift Akan Segera Berakhir';

    if (shiftType === 'pagi') {
      if (totalMins >= 1170 && totalMins < 1200) {
        shouldShow = true;
        var left = 1200 - totalMins;
        title = 'Shift Pagi Akan Berakhir (' + left + ' Menit Lagi)';
        msg = 'Shift Pagi akan berakhir dalam ' + left + ' menit (pukul 20:00 WIB). Pastikan seluruh kegiatan telah tercatat, status shift diserahkan, dan laporan telah dibagikan ke WhatsApp.';
      } else if (totalMins >= 1200 || hours < 8) {
        shouldShow = true;
        isUrgent = true;
        title = 'Jam Operasional Shift Pagi Telah Berakhir';
        msg = 'Jam operasional Shift Pagi telah berakhir (pukul 20:00 WIB). Mohon segera serahkan status shift dan bagikan laporan ke grup WhatsApp untuk kelancaran pergantian shift.';
      }
    } else if (shiftType === 'malam') {
      if (totalMins >= 450 && totalMins < 480) {
        shouldShow = true;
        var left = 480 - totalMins;
        title = 'Shift Malam Akan Berakhir (' + left + ' Menit Lagi)';
        msg = 'Shift Malam akan berakhir dalam ' + left + ' menit (pukul 08:00 WIB). Pastikan seluruh kegiatan telah tercatat, status shift diserahkan, dan laporan telah dibagikan ke WhatsApp.';
      } else if (totalMins >= 480 && hours < 12) {
        shouldShow = true;
        isUrgent = true;
        title = 'Jam Operasional Shift Malam Telah Berakhir';
        msg = 'Jam operasional Shift Malam telah berakhir (pukul 08:00 WIB). Mohon segera serahkan status shift dan bagikan laporan ke grup WhatsApp untuk kelancaran pergantian shift.';
      }
    } else if (shiftType === 'oh') {
      if (totalMins >= 990 && totalMins < 1020) {
        shouldShow = true;
        var left = 1020 - totalMins;
        title = 'Jam Kerja OH Akan Berakhir (' + left + ' Menit Lagi)';
        msg = 'Jam kerja Office Hour akan berakhir dalam ' + left + ' menit (pukul 17:00 WIB). Pastikan seluruh kegiatan telah tercatat, status shift diserahkan, dan laporan telah dibagikan ke WhatsApp.';
      } else if (totalMins >= 1020) {
        shouldShow = true;
        isUrgent = true;
        title = 'Jam Kerja Office Hour Telah Berakhir';
        msg = 'Jam kerja Office Hour telah berakhir (pukul 17:00 WIB). Mohon segera serahkan status shift dan bagikan laporan ke WhatsApp.';
      }
    }

    if (shouldShow) {
      alertEl.style.display = 'block';
      if (isUrgent) {
        alertEl.classList.add('urgent');
      } else {
        alertEl.classList.remove('urgent');
      }
      var descEl = document.getElementById('handover_desc_text');
      var titleEl = document.getElementById('handover_title_text');
      if (descEl) descEl.textContent = msg;
      if (titleEl) titleEl.textContent = title;
    } else {
      alertEl.style.display = 'none';
    }
  }

  window.dismissHandoverAlert = function() {
    sessionStorage.setItem('handover_dismissed_until', String(Date.now() + (15 * 60 * 1000)));
    alertEl.style.display = 'none';
  };

  checkHandover();
  setInterval(checkHandover, 30000);
})();
</script>

<script src="assets/js/signature-pad.js"></script>
<script>
window.shiftSigPad = null;

function switchShiftSigTab(type) {
  var btnDraw = document.getElementById('tab_btn_shift_draw');
  var btnUp = document.getElementById('tab_btn_shift_upload');
  var pnlDraw = document.getElementById('panel_shift_sig_draw');
  var pnlUp = document.getElementById('panel_shift_sig_upload');

  if (type === 'draw') {
    if (btnDraw) btnDraw.style.opacity = '1';
    if (btnUp) btnUp.style.opacity = '0.75';
    if (pnlDraw) pnlDraw.style.display = 'block';
    if (pnlUp) pnlUp.style.display = 'none';
  } else {
    if (btnDraw) btnDraw.style.opacity = '0.75';
    if (btnUp) btnUp.style.opacity = '1';
    if (pnlDraw) pnlDraw.style.display = 'none';
    if (pnlUp) pnlUp.style.display = 'block';
  }
}

function toggleCustomShiftSig(showCustom) {
  var boxProfile = document.getElementById('box_shift_profile_sig');
  var boxCustom = document.getElementById('container_custom_shift_sig');
  var inputUseProfile = document.getElementById('use_profile_shift_sig');

  if (showCustom) {
    if (boxProfile) boxProfile.style.display = 'none';
    if (boxCustom) boxCustom.style.display = 'block';
    if (inputUseProfile) inputUseProfile.value = '0';
    setTimeout(function() { if (window.initShiftSigPad) window.initShiftSigPad(); }, 50);
  } else {
    if (boxProfile) boxProfile.style.display = 'block';
    if (boxCustom) boxCustom.style.display = 'none';
    if (inputUseProfile) inputUseProfile.value = '1';
  }
}

window.initShiftSigPad = function() {
  var canvasEl = document.getElementById('canvas_shift_signature');
  if (canvasEl && window.SignaturePad) {
    if (!window.shiftSigPad) {
      window.shiftSigPad = new window.SignaturePad(canvasEl, {
        strokeColor: '#0f172a',
        strokeWidth: 2.5
      });
      var clearBtn = document.getElementById('btn_clear_shift_canvas');
      if (clearBtn) {
        clearBtn.addEventListener('click', function(e) {
          e.preventDefault();
          window.shiftSigPad.clear();
        });
      }
    } else {
      window.shiftSigPad._initCanvas();
    }
  }
};
</script>

<?php
include __DIR__ . '/includes/footer.php';
?>
