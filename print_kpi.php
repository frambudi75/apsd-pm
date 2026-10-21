<?php
/**
 * Lembar Cetak Dokumen Resmi KPI & Evaluasi Kinerja Tahunan Tim NOC
 * PT Angkasa Pura Sarana Digital & IASS SBU ICT KNO
 * 
 * Mendukung 2 Mode:
 * 1. Rekapitulasi Tahunan Seluruh Tim (print_kpi?year=2026)
 * 2. Lembar Rapor Evaluasi Kinerja Tahunan Individu (print_kpi?year=2026&user_id=3)
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/signature_helper.php';
require_login();

$pdo = get_db_connection();
$currentUser = current_user();
$isAdmin = has_role(['superadmin', 'admin']);

$filterYear = (int)($_GET['year'] ?? date('Y'));
if ($filterYear < 2020 || $filterYear > 2035) {
    $filterYear = (int)date('Y');
}

$targetUserId = (int)($_GET['user_id'] ?? 0);

// Pembatasan Akses:
// - Teknisi biasa hanya boleh mencetak rapor tahunan miliknya sendiri yang sudah berstatus published.
// - Admin & Superadmin boleh mencetak rekap tim maupun rapor siapa pun.
if (!$isAdmin) {
    if ($targetUserId !== (int)$currentUser['id']) {
        $targetUserId = (int)$currentUser['id'];
    }
}

// Function helper kalkulasi sistem tahunan jika belum tersimpan
if (!function_exists('calculate_user_annual_kpi')) {
    function calculate_user_annual_kpi(PDO $pdo, int $userId, int $year): array {
        $yearStr = (string)$year;

        // 1. Shift tahunan
        $stmtShift = $pdo->prepare("
            SELECT COUNT(id) AS total_shifts,
                   SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_shifts
            FROM daily_shifts
            WHERE user_id = ? AND shift_date LIKE ?
        ");
        $stmtShift->execute([$userId, $yearStr . '%']);
        $shiftData = $stmtShift->fetch() ?: ['total_shifts' => 0, 'completed_shifts' => 0];
        $totShifts = (int)$shiftData['total_shifts'];
        $compShifts = (int)$shiftData['completed_shifts'];
        $shiftScore = ($totShifts > 0) ? min(15.0, round(($compShifts / $totShifts) * 15.0, 2)) : 0.0;

        // 2. Logbook tahunan (Target tahunan misal 150 kegiatan untuk 20 poin penuh)
        $stmtAct = $pdo->prepare("
            SELECT COUNT(a.id) 
            FROM daily_activities a
            INNER JOIN daily_shifts s ON a.shift_id = s.id
            WHERE s.user_id = ? AND s.shift_date LIKE ?
        ");
        $stmtAct->execute([$userId, $yearStr . '%']);
        $actsCount = (int)$stmtAct->fetchColumn();
        $activityScore = min(20.0, round(($actsCount / 120.0) * 20.0, 2));

        // 3. Foto bukti fisik
        $stmtPhotos = $pdo->prepare("
            SELECT COUNT(p.id)
            FROM daily_activity_photos p
            INNER JOIN daily_activities a ON p.activity_id = a.id
            INNER JOIN daily_shifts s ON a.shift_id = s.id
            WHERE s.user_id = ? AND s.shift_date LIKE ?
        ");
        $stmtPhotos->execute([$userId, $yearStr . '%']);
        $photosCount = (int)$stmtPhotos->fetchColumn();
        $photoRatio = ($actsCount > 0) ? min(1.0, ($photosCount / $actsCount)) : 0.0;
        $photoScore = round($photoRatio * 15.0, 2);

        // 4. Partisipasi PM tahunan (Target tahunan 4 site untuk 10 poin)
        $stmtUser = $pdo->prepare("SELECT username, full_name FROM users WHERE id = ?");
        $stmtUser->execute([$userId]);
        $uData = $stmtUser->fetch();
        $pmCount = 0;
        if ($uData) {
            $uName = $uData['username'];
            $fName = $uData['full_name'];
            $stmtPm = $pdo->prepare("
                SELECT COUNT(id) FROM pm_reports
                WHERE report_date LIKE ? AND (inspector_name LIKE ? OR inspector_name LIKE ?)
            ");
            $stmtPm->execute([$yearStr . '%', '%' . $uName . '%', '%' . $fName . '%']);
            $pmCount = (int)$stmtPm->fetchColumn();
        }
        $pmScore = min(10.0, round($pmCount * 2.5, 2));

        $sysScore = round($shiftScore + $activityScore + $photoScore + $pmScore, 2);

        return [
            'shifts_count' => $totShifts,
            'completed_shifts' => $compShifts,
            'shift_score' => $shiftScore,
            'activities_count' => $actsCount,
            'activity_score' => $activityScore,
            'photos_count' => $photosCount,
            'photo_score' => $photoScore,
            'pm_count' => $pmCount,
            'pm_score' => $pmScore,
            'system_score' => $sysScore
        ];
    }
}

if (!function_exists('calculate_user_system_kpi')) {
    /**
     * Backward-compatible alias for calculate_user_annual_kpi
     */
    function calculate_user_system_kpi(PDO $pdo, int $userId, $period = null): array {
        $year = is_numeric($period) ? (int)$period : (int)substr((string)($period ?: date('Y')), 0, 4);
        return calculate_user_annual_kpi($pdo, $userId, $year);
    }
}

// Data Pengesahan & Kop
$companyName = get_system_setting('company_name', 'IASS SBU ICT KNO');
$unitName = get_system_setting('unit_name', 'KNO');
$leaderName = get_system_setting('default_team_leader', 'Wahyu Wandira');
$leaderRole = get_system_setting('default_leader_role', 'Supervisor / Team Leader NOC KNO');

$adminName = get_system_setting('default_admin_name', '');
if (empty($adminName)) {
    $adminName = $currentUser['full_name'] ?: ($currentUser['username'] ?: 'Administrator NOC');
}
$adminRole = get_system_setting('default_admin_role', '');
if (empty($adminRole)) {
    $adminRole = (($currentUser['user_role'] ?? $currentUser['role'] ?? '') === 'superadmin') ? 'Super Administrator' : 'Administrator Operasional';
}
$leaderSignature = get_system_setting('default_leader_signature', '');
if (empty($leaderSignature)) {
    $stmtSpv = $pdo->prepare("SELECT signature_path FROM users WHERE full_name = ? OR username = ? LIMIT 1");
    $stmtSpv->execute([$leaderName, $leaderName]);
    $leaderSignature = $stmtSpv->fetchColumn() ?: '';
}

$stmtAdmSig = $pdo->prepare("SELECT signature_path FROM users WHERE id = ?");
$stmtAdmSig->execute([(int)$currentUser['id']]);
$adminSignature = $stmtAdmSig->fetchColumn() ?: '';

$isSingleUserMode = ($targetUserId > 0);

// Load data sesuai mode cetak
if ($isSingleUserMode) {
    // Mode Rapor Individu
    $stmtU = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmtU->execute([$targetUserId]);
    $targetUser = $stmtU->fetch();
    if (!$targetUser) {
        die("Personel tidak ditemukan.");
    }

    $stmtKpi = $pdo->prepare("SELECT * FROM kpi_evaluations WHERE user_id = ? AND period_year = ?");
    $stmtKpi->execute([$targetUserId, (string)$filterYear]);
    $kpiData = $stmtKpi->fetch();

    $sysAuto = calculate_user_annual_kpi($pdo, $targetUserId, $filterYear);

    $shiftScore = $kpiData ? (float)$kpiData['shift_score'] : $sysAuto['shift_score'];
    $actScore = $kpiData ? (float)$kpiData['activity_score'] : $sysAuto['activity_score'];
    $photoScore = $kpiData ? (float)$kpiData['photo_score'] : $sysAuto['photo_score'];
    $pmScore = $kpiData ? (float)$kpiData['pm_score'] : $sysAuto['pm_score'];
    $sysScore = $kpiData ? (float)$kpiData['system_score'] : $sysAuto['system_score'];

    $sopScore = $kpiData ? (float)$kpiData['sop_score'] : 10.0;
    $troubleScore = $kpiData ? (float)$kpiData['troubleshooting_score'] : 10.0;
    $responseScore = $kpiData ? (float)$kpiData['response_score'] : 10.0;
    $initiativeScore = $kpiData ? (float)$kpiData['initiative_score'] : 10.0;
    $revScore = $kpiData ? (float)$kpiData['review_score'] : 40.0;

    $totalScore = $kpiData ? (float)$kpiData['total_score'] : round($sysScore + $revScore, 2);
    $grade = $kpiData ? $kpiData['grade'] : ($totalScore >= 90 ? 'A' : ($totalScore >= 80 ? 'B' : ($totalScore >= 70 ? 'C' : 'D')));
    $notes = $kpiData ? ($kpiData['feedback_notes'] ?: 'Kinerja tahunan telah memenuhi standar operasional NOC dan regulasi telekomunikasi bandara.') : 'Kinerja tahunan telah memenuhi standar operasional NOC dan regulasi telekomunikasi bandara.';

    $techSignature = !empty($targetUser['signature_path']) ? $targetUser['signature_path'] : get_user_signature_path($targetUserId);
    $page_title = 'Rapor Evaluasi KPI ' . $filterYear . ' - ' . ($targetUser['full_name'] ?: $targetUser['username']);
} else {
    // Mode Rekapitulasi Tim
    $stmtAll = $pdo->query("SELECT id, username, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name ASC");
    $allUsers = $stmtAll->fetchAll();

    $stmtSaved = $pdo->prepare("SELECT * FROM kpi_evaluations WHERE period_year = ?");
    $stmtSaved->execute([(string)$filterYear]);
    $savedEvals = [];
    while ($r = $stmtSaved->fetch()) {
        $savedEvals[(int)$r['user_id']] = $r;
    }

    $teamList = [];
    foreach ($allUsers as $u) {
        $uId = (int)$u['id'];
        $saved = $savedEvals[$uId] ?? null;
        $sys = calculate_user_annual_kpi($pdo, $uId, $filterYear);

        $shiftScore = $saved ? (float)$saved['shift_score'] : $sys['shift_score'];
        $actScore = $saved ? (float)$saved['activity_score'] : $sys['activity_score'];
        $photoScore = $saved ? (float)$saved['photo_score'] : $sys['photo_score'];
        $pmScore = $saved ? (float)$saved['pm_score'] : $sys['pm_score'];
        $sysScore = $saved ? (float)$saved['system_score'] : $sys['system_score'];

        $revScore = $saved ? (float)$saved['review_score'] : 40.0;
        $totalScore = $saved ? (float)$saved['total_score'] : round($sysScore + $revScore, 2);
        $grade = $saved ? $saved['grade'] : ($totalScore >= 90 ? 'A' : ($totalScore >= 80 ? 'B' : ($totalScore >= 70 ? 'C' : 'D')));
        $status = $saved ? ($saved['status'] ?: 'draft') : 'draft';

        $teamList[] = [
            'user' => $u,
            'system_score' => $sysScore,
            'review_score' => $revScore,
            'total_score' => $totalScore,
            'grade' => $grade,
            'status' => $status
        ];
    }

    usort($teamList, function($a, $b) {
        return $b['total_score'] <=> $a['total_score'];
    });

    $page_title = 'Rekapitulasi Penilaian KPI Tahunan ' . $filterYear;
}
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

    .formal-table tr:nth-child(even) td { background: #f8fafc; }

    .sign-table {
      width: 100%;
      margin-top: 10px;
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
      .print-actions-bar { display: none !important; }
      .a4-sheet {
        box-shadow: none !important;
        padding: 0 !important;
        margin: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        min-height: auto !important;
      }
      thead { display: table-header-group; }
      tr, .section-title, .sign-table {
        page-break-inside: avoid;
        break-inside: avoid;
      }
    }
  </style>
</head>
<body>

  <!-- Screen Actions Bar -->
  <div class="print-actions-bar">
    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
      <a href="kpi?year=<?= $filterYear ?>" class="btn-action btn-back">
        &larr; Kembali ke Penilaian KPI
      </a>
      <span style="font-size: 13px; font-weight: 700; color: #008080;">
        <?= $isSingleUserMode ? 'Slip Rapor Evaluasi Kinerja Personel' : 'Rekapitulasi KPI Tahunan Seluruh Tim' ?> &bull; Tahun <?= $filterYear ?>
      </span>
    </div>

    <div style="display: flex; align-items: center; gap: 8px;">
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
          <div class="doc-title-main">
            <?= $isSingleUserMode ? 'Lembar Evaluasi &amp; Rapor KPI Tahunan' : 'Rekapitulasi Nilai &amp; Peringkat KPI Tahunan' ?>
          </div>
          <div class="doc-title-sub">
            Key Performance Indicator (KPI) Operasional NOC &bull; Tahun Anggaran <?= $filterYear ?>
          </div>
          <div class="doc-title-location">
            <?= htmlspecialchars($companyName) ?> &bull; Unit <?= htmlspecialchars($unitName) ?> &bull; Bandara Internasional Kualanamu
          </div>
        </td>
        <td style="width: 140px; text-align: right; vertical-align: top;">
          <div style="font-size: 7.5pt; color: #64748b; font-weight: 600;">Tanggal Cetak:</div>
          <div style="font-size: 8pt; color: #0f172a; font-weight: 700; margin-top: 1px;">
            <?= date('d/m/Y H:i') ?> WIB
          </div>
          <div style="font-size: 7pt; color: #008080; font-weight: 700; margin-top: 2px;">
            Periode: <?= $filterYear ?>
          </div>
        </td>
      </tr>
    </table>

    <!-- ============================================== -->
    <!-- TAMPILAN 1: RAPOR EVALUASI INDIVIDU (SLIP KPI) -->
    <!-- ============================================== -->
    <?php if ($isSingleUserMode): ?>
      
      <!-- Identitas Personel & Hero Score Card -->
      <table style="width: 100%; border: 1.5px solid #008080; border-collapse: collapse; margin-bottom: 8px; background: #f8fafc;">
        <tr>
          <td style="padding: 6px 10px; width: 68%; vertical-align: middle;">
            <div style="font-size: 7pt; font-weight: 700; color: #008080; text-transform: uppercase;">Identitas Anggota / Teknisi:</div>
            <div style="font-size: 11pt; font-weight: 800; color: #0f172a;">
              <?= htmlspecialchars($targetUser['full_name'] ?: $targetUser['username']) ?>
            </div>
            <div style="font-size: 7.5pt; color: #475569; margin-top: 2px;">
              Username: <strong>@<?= htmlspecialchars($targetUser['username']) ?></strong> &bull; 
              Role: <strong><?= ($targetUser['role'] === 'superadmin' ? 'Super Administrator' : ($targetUser['role'] === 'admin' ? 'Administrator' : 'Teknisi Jaga NOC')) ?></strong> &bull; 
              Penempatan: <strong>Unit NOC Kualanamu</strong>
            </div>
          </td>
          <td style="padding: 6px 10px; width: 32%; text-align: center; border-left: 1.5px solid #008080; vertical-align: middle; background: #ffffff;">
            <div style="font-size: 6.8pt; font-weight: 700; color: #64748b; text-transform: uppercase;">Total Capaian Skor KPI:</div>
            <div style="font-size: 18pt; font-weight: 800; color: #008080; line-height: 1.1; margin: 2px 0;">
              <?= $totalScore ?> <span style="font-size: 8pt; color: #64748b;">/ 100</span>
            </div>
            <div style="display: inline-block; background: #008080; color: #ffffff; font-weight: 800; font-size: 7.5pt; padding: 1px 8px; border-radius: 3px;">
              PREDIKAT: GRADE <?= $grade ?>
            </div>
          </td>
        </tr>
      </table>

      <!-- Tabel Rincian Capaian Indikator Kinerja -->
      <div class="section-title">
        <span>1. Rincian Capaian Indikator Kinerja Objektif &amp; Kualitatif</span>
        <span style="font-size: 7.5pt; color: #64748b; font-weight: 600;">Metode: Hybrid 60% Sistem : 40% Review</span>
      </div>

      <table class="formal-table">
        <thead>
          <tr>
            <th style="width: 30px; text-align: center;">No</th>
            <th>Pilar Indikator Penilaian</th>
            <th style="width: 65px; text-align: center;">Bobot</th>
            <th>Capaian Riil Operasional di Lapangan</th>
            <th style="width: 75px; text-align: center;">Skor Perolehan</th>
            <th style="width: 70px; text-align: center;">Evaluasi</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td style="text-align: center; font-weight: 700;">1</td>
            <td style="font-weight: 700; color: #0f172a;">
              Disiplin Kehadiran Shift
              <div style="font-size: 6.8pt; font-weight: 400; color: #64748b;">Sumber: Logbook Absensi Shift NOC</div>
            </td>
            <td style="text-align: center; font-weight: 700;">15.0%</td>
            <td style="color: #475569; font-size: 7.2pt;">
              Menyelesaikan <?= (int)($sysAuto['completed_shifts']) ?> shift tepat waktu dari total <?= (int)($sysAuto['shifts_count']) ?> jadwal shift.
            </td>
            <td style="text-align: center; font-weight: 800; color: #008080;"><?= $shiftScore ?> / 15.0</td>
            <td style="text-align: center; font-weight: 700; color: #059669;">Tercapai</td>
          </tr>
          <tr>
            <td style="text-align: center; font-weight: 700;">2</td>
            <td style="font-weight: 700; color: #0f172a;">
              Volume &amp; Keaktifan Logbook
              <div style="font-size: 6.8pt; font-weight: 400; color: #64748b;">Sumber: Log Aktivitas Harian NOC</div>
            </td>
            <td style="text-align: center; font-weight: 700;">20.0%</td>
            <td style="color: #475569; font-size: 7.2pt;">
              Mencatat &amp; menuntaskan <?= (int)($sysAuto['activities_count']) ?> kegiatan teknis dan operasional bandara.
            </td>
            <td style="text-align: center; font-weight: 800; color: #008080;"><?= $actScore ?> / 20.0</td>
            <td style="text-align: center; font-weight: 700; color: #059669;">Tercapai</td>
          </tr>
          <tr>
            <td style="text-align: center; font-weight: 700;">3</td>
            <td style="font-weight: 700; color: #0f172a;">
              Dokumentasi Bukti Fisik Lapangan
              <div style="font-size: 6.8pt; font-weight: 400; color: #64748b;">Sumber: Lampiran Foto Bukti Pekerjaan</div>
            </td>
            <td style="text-align: center; font-weight: 700;">15.0%</td>
            <td style="color: #475569; font-size: 7.2pt;">
              Melampirkan <?= (int)($sysAuto['photos_count']) ?> dokumentasi foto visual pendukung kegiatan SOP.
            </td>
            <td style="text-align: center; font-weight: 800; color: #008080;"><?= $photoScore ?> / 15.0</td>
            <td style="text-align: center; font-weight: 700; color: #059669;">Tercapai</td>
          </tr>
          <tr>
            <td style="text-align: center; font-weight: 700;">4</td>
            <td style="font-weight: 700; color: #0f172a;">
              Partisipasi Inspeksi PM Bulanan
              <div style="font-size: 6.8pt; font-weight: 400; color: #64748b;">Sumber: Laporan PM Indoor &amp; Outdoor</div>
            </td>
            <td style="text-align: center; font-weight: 700;">10.0%</td>
            <td style="color: #475569; font-size: 7.2pt;">
              Berpartisipasi dalam <?= (int)($sysAuto['pm_count']) ?> inspeksi Preventive Maintenance perangkat &amp; tower.
            </td>
            <td style="text-align: center; font-weight: 800; color: #008080;"><?= $pmScore ?> / 10.0</td>
            <td style="text-align: center; font-weight: 700; color: #059669;">Tercapai</td>
          </tr>
          <tr>
            <td style="text-align: center; font-weight: 700;">5</td>
            <td style="font-weight: 700; color: #0f172a;">
              Evaluasi Kualitatif Supervisor
              <div style="font-size: 6.8pt; font-weight: 400; color: #64748b;">Kompetensi Teknis, SOP &amp; Kerjasama</div>
            </td>
            <td style="text-align: center; font-weight: 700;">40.0%</td>
            <td style="color: #475569; font-size: 7.2pt;">
              SOP/K3: <strong><?= $sopScore ?>/10</strong> &bull; Troubleshooting: <strong><?= $troubleScore ?>/10</strong> &bull; Respon: <strong><?= $responseScore ?>/10</strong> &bull; Inisiatif: <strong><?= $initiativeScore ?>/10</strong>
            </td>
            <td style="text-align: center; font-weight: 800; color: #6366f1;"><?= $revScore ?> / 40.0</td>
            <td style="text-align: center; font-weight: 700; color: #059669;">Baik</td>
          </tr>
          <tr style="background: #f1f5f9; font-weight: 800;">
            <td colspan="4" style="text-align: right; padding-right: 12px; font-size: 8pt; color: #0f172a;">
              TOTAL NILAI AKUMULASI KPI TAHUN <?= $filterYear ?>:
            </td>
            <td style="text-align: center; font-size: 9pt; color: #008080;">
              <?= $totalScore ?>
            </td>
            <td style="text-align: center; font-size: 8pt; color: #008080;">
              GRADE <?= $grade ?>
            </td>
          </tr>
        </tbody>
      </table>

      <!-- Catatan / Umpan Balik Supervisor -->
      <div class="section-title">
        <span>2. Catatan Evaluasi &amp; Rekomendasi Pengembangan dari Supervisor</span>
      </div>
      <div style="border: 1px solid #94a3b8; background: #f8fafc; padding: 6px 10px; border-radius: 2px; font-size: 7.5pt; color: #1e293b; line-height: 1.4; min-height: 38px;">
        <em>&ldquo;<?= htmlspecialchars($notes) ?>&rdquo;</em>
      </div>

      <!-- Tanda Tangan Pengesahan Rapor Individu -->
      <table class="sign-table">
        <tr>
          <td>
            <div>Deli Serdang, <?= date('d/m/Y') ?></div>
            <div style="font-weight: 700; margin-top: 2px;">Pegawai / Teknisi yang Dinilai,</div>
            <div class="sign-space">
              <?php if (!empty($techSignature)): ?>
                <?= render_signature_img($techSignature, 'TTD Teknisi') ?>
              <?php endif; ?>
            </div>
            <div class="sign-name"><?= htmlspecialchars($targetUser['full_name'] ?: $targetUser['username']) ?></div>
            <div class="sign-role">Teknisi Jaga NOC KNO</div>
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

    <!-- ============================================== -->
    <!-- TAMPILAN 2: REKAPITULASI KPI SELURUH TIM (A4)   -->
    <!-- ============================================== -->
    <?php else: ?>

      <div class="section-title">
        <span>Daftar Peringkat &amp; Hasil Evaluasi Kinerja Tim NOC &bull; Tahun <?= $filterYear ?></span>
        <span style="font-size: 7.5pt; color: #64748b; font-weight: 600;">Total: <?= count($teamList) ?> Personel Aktif</span>
      </div>

      <table class="formal-table">
        <thead>
          <tr>
            <th style="width: 32px; text-align: center;">Rank</th>
            <th>Nama Personel / Teknisi Jaga</th>
            <th style="width: 90px;">Role / Jabatan</th>
            <th style="width: 100px; text-align: center;">Metrik Sistem (60%)</th>
            <th style="width: 100px; text-align: center;">Review Leader (40%)</th>
            <th style="width: 80px; text-align: center;">Total Skor</th>
            <th style="width: 65px; text-align: center;">Predikat</th>
            <th style="width: 85px; text-align: center;">Status Rapor</th>
          </tr>
        </thead>
        <tbody>
          <?php 
          $no = 1;
          foreach ($teamList as $t): 
            $u = $t['user'];
            $roleLabel = ($u['role'] === 'superadmin' ? 'Super Admin' : ($u['role'] === 'admin' ? 'Admin' : 'Teknisi'));
          ?>
            <tr>
              <td style="text-align: center; font-weight: 700; color: #64748b;"><?= $no++ ?></td>
              <td style="font-weight: 700; color: #0f172a;">
                <?= htmlspecialchars($u['full_name'] ?: $u['username']) ?>
                <span style="font-size: 7pt; color: #64748b; font-weight: 400;">(@<?= htmlspecialchars($u['username']) ?>)</span>
              </td>
              <td style="font-size: 7.2pt; color: #475569; text-transform: uppercase; font-weight: 700;">
                <?= htmlspecialchars($roleLabel) ?>
              </td>
              <td style="text-align: center; font-weight: 700; color: #008080;">
                <?= $t['system_score'] ?> / 60
              </td>
              <td style="text-align: center; font-weight: 700; color: #6366f1;">
                <?= $t['review_score'] ?> / 40
              </td>
              <td style="text-align: center; font-weight: 800; font-size: 8.5pt; color: #0f172a;">
                <?= $t['total_score'] ?>
              </td>
              <td style="text-align: center; font-weight: 800; color: <?= ($t['grade'] === 'A' ? '#059669' : ($t['grade'] === 'B' ? '#0284c7' : '#d97706')) ?>;">
                GRADE <?= $t['grade'] ?>
              </td>
              <td style="text-align: center; font-size: 7pt; font-weight: 700; color: <?= ($t['status'] === 'published' ? '#059669' : '#64748b') ?>;">
                <?= ($t['status'] === 'published' ? 'TERKIRIM' : 'DRAFT') ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <!-- Tanda Tangan Pengesahan Rekap Tim -->
      <table class="sign-table">
        <tr>
          <td>
            <div>Deli Serdang, <?= date('d/m/Y') ?></div>
            <div style="font-weight: 700; margin-top: 2px;">Dibuat / Disusun oleh,</div>
            <div class="sign-space">
              <?php if (!empty($adminSignature)): ?>
                <?= render_signature_img($adminSignature, 'TTD Admin') ?>
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

</body>
</html>
