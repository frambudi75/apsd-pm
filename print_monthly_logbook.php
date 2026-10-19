<?php
/**
 * Cetak Rekap Laporan Bulanan Logbook Daily Activity NOC
 * Sesuai Format Standar SBU ICT KNO (Seperti Format Excel Multi-Hari)
 * IASS SBU ICT KNO - PT Angkasa Pura Sarana Digital
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = get_db_connection();

// Determine filter month (format: YYYY-MM)
$selectedMonth = trim($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

$monthTime = strtotime($selectedMonth . '-01');
$indoMonths = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
$mNum = (int)date('n', $monthTime);
$mYear = date('Y', $monthTime);
$periodLabel = ($indoMonths[$mNum] ?? date('F', $monthTime)) . ' ' . $mYear;

// Hari dalam bahasa Indonesia
$indoDays = [
    'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
    'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
];

// Query all activities in this month
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($driver === 'sqlite') {
    $sql = "
        SELECT a.*, s.shift_date, s.shift_type, s.technician_name, s.notes AS shift_notes,
               u.full_name AS tech_user_name
        FROM daily_activities a
        JOIN daily_shifts s ON a.shift_id = s.id
        LEFT JOIN users u ON a.user_id = u.id
        WHERE strftime('%Y-%m', s.shift_date) = ?
        ORDER BY s.shift_date ASC, 
                 CASE WHEN s.shift_type = 'pagi' THEN 1 ELSE 2 END,
                 a.activity_time ASC, a.id ASC
    ";
} else {
    $sql = "
        SELECT a.*, s.shift_date, s.shift_type, s.technician_name, s.notes AS shift_notes,
               u.full_name AS tech_user_name
        FROM daily_activities a
        JOIN daily_shifts s ON a.shift_id = s.id
        LEFT JOIN users u ON a.user_id = u.id
        WHERE DATE_FORMAT(s.shift_date, '%Y-%m') = ?
        ORDER BY s.shift_date ASC, 
                 CASE WHEN s.shift_type = 'pagi' THEN 1 ELSE 2 END,
                 a.activity_time ASC, a.id ASC
    ";
}

$stmt = $pdo->prepare($sql);
$stmt->execute([$selectedMonth]);
$activities = $stmt->fetchAll();

// Attach photos to all activities
$photosByAct = [];
if (!empty($activities)) {
    $actIds = array_column($activities, 'id');
    $placeholders = implode(',', array_fill(0, count($actIds), '?'));
    $stmtPhotos = $pdo->prepare("SELECT * FROM daily_activity_photos WHERE activity_id IN ($placeholders) ORDER BY id ASC");
    $stmtPhotos->execute($actIds);
    $allPhotos = $stmtPhotos->fetchAll();

    foreach ($allPhotos as $p) {
        $photosByAct[$p['activity_id']][] = $p;
    }
}

// System settings for signatures & company name
$leaderName = get_system_setting('default_team_leader', 'Eko Sulistyo');
$leaderRole = get_system_setting('default_leader_role', 'Supervisor / Team Leader NOC KNO');
$companyName = get_system_setting('company_name', 'IASS SBU ICT KNO');
$unitName = get_system_setting('unit_name', 'IASS SBU ICT KNO');

$page_title = 'Rekap Daily Activity Unit SBU ICT KNO - Periode ' . $periodLabel;
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($page_title) ?></title>
  <link rel="icon" type="image/png" href="<?= htmlspecialchars(get_app_favicon_url()) ?>">
  <link rel="shortcut icon" type="image/png" href="<?= htmlspecialchars(get_app_favicon_url()) ?>">
  <link rel="apple-touch-icon" href="<?= htmlspecialchars(get_app_favicon_url()) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background: #e2e8f0;
      color: #0f172a;
      line-height: 1.4;
      padding: 20px 10px 40px 10px;
    }

    /* Screen Action Bar */
    .print-actions-bar {
      background: #ffffff;
      border: 1px solid #cbd5e1;
      padding: 10px 18px;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: 0 4px 12px rgba(0,0,0,0.08);
      max-width: 1020px;
      margin-left: auto;
      margin-right: auto;
      border-radius: 8px;
      flex-wrap: wrap;
      gap: 10px;
    }

    .btn-action {
      padding: 7px 14px;
      font-size: 13px;
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

    /* A4 Document Container */
    .a4-sheet {
      width: 210mm;
      min-height: 297mm;
      padding: 12mm 14mm;
      margin: 0 auto;
      background: #ffffff;
      box-shadow: 0 4px 20px rgba(0,0,0,0.08);
      border-radius: 2px;
    }

    /* Letterhead Table */
    .doc-header-table {
      width: 100%;
      border-bottom: 2px solid #008080;
      padding-bottom: 8px;
      margin-bottom: 12px;
      border-collapse: collapse;
    }
    .doc-header-table td { vertical-align: middle; }

    .doc-title-main {
      font-size: 13pt;
      font-weight: 800;
      color: #008080;
      text-transform: uppercase;
      letter-spacing: 0.02em;
    }
    .doc-title-sub {
      font-size: 10pt;
      font-weight: 700;
      color: #0f172a;
      margin-top: 2px;
    }
    .doc-title-location {
      font-size: 8pt;
      color: #64748b;
      margin-top: 1px;
    }

    /* Main Logbook Table (Matches Excel Reference) */
    .recap-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 8.5pt;
      margin-bottom: 20px;
    }

    .recap-table th {
      background: #f1f5f9;
      color: #1e293b;
      font-weight: 700;
      padding: 7px 6px;
      border: 1px solid #94a3b8;
      text-align: center;
      font-size: 8pt;
      text-transform: uppercase;
    }

    .recap-table td {
      padding: 6px 6px;
      border: 1px solid #94a3b8;
      vertical-align: top;
      line-height: 1.35;
    }

    .col-no { width: 26px; text-align: center; font-weight: 700; }
    .col-date { width: 95px; font-weight: 600; color: #1e293b; }
    .col-shift { width: 55px; text-align: center; font-weight: 700; }
    .col-tech { width: 95px; font-weight: 600; color: #0f172a; }
    .col-desc { vertical-align: top; }
    .col-photo { width: 155px; text-align: center; background: #fafafa; }
    .col-ket { width: 70px; text-align: center; }

    .badge-shift-pagi {
      background: #fef3c7;
      color: #b45309;
      font-size: 7pt;
      font-weight: 800;
      padding: 1px 5px;
      border-radius: 3px;
      display: inline-block;
    }

    .badge-shift-malam {
      background: #e0e7ff;
      color: #3730a3;
      font-size: 7pt;
      font-weight: 800;
      padding: 1px 5px;
      border-radius: 3px;
      display: inline-block;
    }

    .cat-mini-pill {
      font-size: 6.8pt;
      font-weight: 800;
      padding: 1px 4px;
      border-radius: 3px;
      background: #e2e8f0;
      color: #334155;
      text-transform: uppercase;
      display: inline-block;
      margin-right: 4px;
    }

    .photo-thumb-wrap {
      border: 1px solid #cbd5e1;
      border-radius: 3px;
      overflow: hidden;
      background: #f8fafc;
      padding: 3px;
      margin-bottom: 4px;
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 85px;
      box-sizing: border-box;
    }

    .photo-thumb-wrap img {
      max-width: 100%;
      max-height: 120px;
      width: auto;
      height: auto;
      object-fit: contain;
      display: block;
      margin: 0 auto;
      border-radius: 2px;
    }

    .photo-caption-text {
      font-size: 6.8pt;
      color: #64748b;
      padding-top: 2px;
      line-height: 1.15;
    }

    /* Signature Sign-Off Box */
    .sign-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 25px;
      page-break-inside: avoid;
    }

    .sign-table td {
      width: 50%;
      text-align: center;
      vertical-align: top;
      font-size: 9pt;
    }

    .sign-space { height: 60px; }
    .sign-name {
      font-weight: 800;
      text-decoration: underline;
      color: #0f172a;
    }
    .sign-role {
      font-size: 8pt;
      color: #64748b;
      margin-top: 2px;
    }

    /* Print Setup */
    @media print {
      body {
        background: #ffffff !important;
        padding: 0 !important;
      }
      .print-actions-bar {
        display: none !important;
      }
      .a4-sheet {
        box-shadow: none !important;
        padding: 0 !important;
        width: 100% !important;
      }
      tr {
        page-break-inside: avoid;
      }
      .photo-thumb-wrap {
        background: #ffffff !important;
      }
      .photo-thumb-wrap img {
        max-width: 100% !important;
        max-height: 120px !important;
        width: auto !important;
        height: auto !important;
        object-fit: contain !important;
      }
    }
  </style>
</head>
<body>

  <!-- Screen Actions Bar -->
  <div class="print-actions-bar">
    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
      <a href="daily_activity" class="btn-action btn-back">
        &larr; Kembali ke Daily Activity
      </a>
      <span style="font-size: 13px; font-weight: 700; color: #008080;">
        Rekap Logbook NOC: <?= htmlspecialchars($periodLabel) ?>
      </span>
    </div>

    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
      <!-- Month Selector -->
      <form method="GET" action="print_monthly_logbook" style="display: flex; align-items: center; gap: 6px; margin: 0;">
        <input type="month" name="month" value="<?= htmlspecialchars($selectedMonth) ?>" class="form-control" style="padding: 5px 8px; font-size: 12px; border-radius: 4px; border: 1px solid #cbd5e1;" onchange="this.form.submit()">
      </form>

      <a href="download_pdf?month=<?= urlencode($selectedMonth) ?>" class="btn-action btn-pdf" style="background: #0284c7; color: #ffffff; padding: 6px 12px; text-decoration: none; border-radius: 4px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;" title="Unduh langsung PDF kompilasi bulanan berkualitas tinggi">
        <span>📥</span> Unduh File PDF
      </a>
      <button type="button" class="btn-action btn-print" onclick="window.print()">
        🖨️ Cetak / Simpan PDF
      </button>
    </div>
  </div>

  <!-- A4 Printable Sheet -->
  <div class="a4-sheet">
    
    <!-- Header Kop Surat -->
    <table class="doc-header-table">
      <tr>
        <td style="width: 75px;">
          <img src="<?= htmlspecialchars(get_report_kop_logo()) ?>" alt="Logo Kop" style="height: 42px; object-fit: contain;">
        </td>
        <td style="padding-left: 10px;">
          <div class="doc-title-main">Laporan Pekerjaan Daily Unit SBU ICT IASS</div>
          <div class="doc-title-sub">Bandara Internasional Kualanamu - Periode <?= htmlspecialchars($periodLabel) ?></div>
          <div class="doc-title-location"><?= htmlspecialchars($companyName) ?> &bull; Unit <?= htmlspecialchars($unitName) ?></div>
        </td>
        <td style="width: 140px; text-align: right; vertical-align: top;">
          <div style="font-size: 7.5pt; color: #64748b; font-weight: 600;">Kompilasi Otomatis</div>
          <div style="font-size: 8pt; color: #008080; font-weight: 800; margin-top: 2px;">
            <?= count($activities) ?> Total Kegiatan
          </div>
        </td>
      </tr>
    </table>

    <!-- Main Table: Exact Side-by-Side Structure as Reference Excel -->
    <?php if (empty($activities)): ?>
      <div style="text-align: center; padding: 40px 20px; color: #64748b; font-size: 9pt; border: 1px dashed #cbd5e1; border-radius: 4px; margin-top: 15px;">
        Belum ada catatan kegiatan logbook NOC yang terekam pada periode <strong><?= htmlspecialchars($periodLabel) ?></strong>.
      </div>
    <?php else: ?>
      <table class="recap-table">
        <thead>
          <tr>
            <th class="col-no">No.</th>
            <th class="col-date">Hari / Tanggal</th>
            <th class="col-shift">Shift</th>
            <th class="col-tech">EOS Duty (Teknisi)</th>
            <th class="col-desc">Pekerjaan &amp; Uraian Tindakan</th>
            <th class="col-photo">Dokumentasi</th>
            <th class="col-ket">Keterangan</th>
          </tr>
        </thead>
        <tbody>
          <?php 
            $no = 1;
            foreach ($activities as $act):
              $rawDay = date('l', strtotime($act['shift_date']));
              $indoDay = $indoDays[$rawDay] ?? $rawDay;
              $formattedRowDate = $indoDay . ', ' . date('d M Y', strtotime($act['shift_date']));
              $shiftTypeUpper = ucfirst($act['shift_type']);
              $techDisplay = $act['technician_name'] ?: ($act['tech_user_name'] ?: 'Teknisi');
              $actPhotos = $photosByAct[$act['id']] ?? [];
          ?>
            <tr>
              <td class="col-no"><?= $no++ ?></td>
              <td class="col-date"><?= htmlspecialchars($formattedRowDate) ?></td>
              <td class="col-shift">
                <span class="<?= ($act['shift_type'] === 'pagi') ? 'badge-shift-pagi' : 'badge-shift-malam' ?>">
                  <?= $shiftTypeUpper ?>
                </span>
              </td>
              <td class="col-tech"><?= htmlspecialchars($techDisplay) ?></td>
              <td class="col-desc">
                <div style="margin-bottom: 2px;">
                  <span class="cat-mini-pill"><?= htmlspecialchars($act['category']) ?></span>
                  <?php if (!empty($act['location'])): ?>
                    <span style="font-size: 7.5pt; font-weight: 700; color: #008080;">📍 <?= htmlspecialchars($act['location']) ?></span>
                  <?php endif; ?>
                </div>
                <div style="font-weight: 700; color: #0f172a; margin-bottom: 2px;">
                  <?= htmlspecialchars($act['title']) ?>
                </div>
                <div style="color: #475569; font-size: 8pt; white-space: pre-line;">
                  <?= nl2br(htmlspecialchars($act['description'])) ?>
                </div>
              </td>
              <td class="col-photo">
                <?php if (!empty($actPhotos)): ?>
                  <?php foreach ($actPhotos as $p): ?>
                    <div class="photo-thumb-wrap">
                      <img src="<?= htmlspecialchars($p['photo_path']) ?>" alt="Foto Dokumentasi">
                      <?php if (!empty($p['caption'])): ?>
                        <div class="photo-caption-text">
                          <strong style="color: #0f172a;"><?= htmlspecialchars($p['caption']) ?></strong>
                          <?php if (!empty($p['caption_2'])): ?>
                            <div style="font-size: 6.5pt; color: #475569; margin-top: 1px; line-height: 1.2;"><?= htmlspecialchars($p['caption_2']) ?></div>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                <?php else: ?>
                  <span style="color: #94a3b8; font-size: 7.5pt; font-style: italic;">(Tanpa foto)</span>
                <?php endif; ?>
              </td>
              <td class="col-ket">
                <span style="font-size: 7.5pt; font-weight: 700; color: #047857; background: #ecfdf5; padding: 2px 5px; border-radius: 3px; display: inline-block;">
                  Normal
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <!-- Signature Sign-Off Box -->
    <table class="sign-table">
      <tr>
        <td>
          <div>Deli Serdang, <?= date('d') . ' ' . $periodLabel ?></div>
          <div style="font-weight: 700; margin-top: 2px;">Perwakilan Tim NOC / EOS Duty</div>
          <div class="sign-space"></div>
          <div class="sign-name">Tim Pelaksana Shift NOC</div>
          <div class="sign-role">Bandara Internasional Kualanamu</div>
        </td>
        <td>
          <div>Mengetahui &amp; Menyetujui,</div>
          <div style="font-weight: 700; margin-top: 2px;"><?= htmlspecialchars($leaderRole) ?></div>
          <div class="sign-space"></div>
          <div class="sign-name"><?= htmlspecialchars($leaderName) ?></div>
          <div class="sign-role"><?= htmlspecialchars($unitName) ?></div>
        </td>
      </tr>
    </table>

  </div>

</body>
</html>
