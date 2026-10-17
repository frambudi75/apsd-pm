<?php
/**
 * Kompilasi Cetak & Ekspor PDF Logbook Shift NOC (Daily Activity)
 * PT Angkasa Pura Sarana Digital & IASS SBU ICT KNO
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = get_db_connection();
$shiftId = (int)($_GET['shift_id'] ?? $_GET['id'] ?? 0);

if ($shiftId <= 0) {
    die("ID Shift tidak valid.");
}

// Fetch shift record
$stmt = $pdo->prepare("
    SELECT s.*, u.full_name AS user_full_name, u.username, u.role AS user_role 
    FROM daily_shifts s
    LEFT JOIN users u ON s.user_id = u.id
    WHERE s.id = ?
");
$stmt->execute([$shiftId]);
$shift = $stmt->fetch();

if (!$shift) {
    die("Data shift tidak ditemukan.");
}

// Fetch all activities in this shift
$stmtAct = $pdo->prepare("
    SELECT * FROM daily_activities 
    WHERE shift_id = ? 
    ORDER BY activity_time ASC, id ASC
");
$stmtAct->execute([$shiftId]);
$activities = $stmtAct->fetchAll();

// Fetch photos for each activity
$actIds = array_column($activities, 'id');
$photosByAct = [];
if (!empty($actIds)) {
    $placeholders = implode(',', array_fill(0, count($actIds), '?'));
    $stmtPhotos = $pdo->prepare("
        SELECT * FROM daily_activity_photos 
        WHERE activity_id IN ($placeholders) 
        ORDER BY id ASC
    ");
    $stmtPhotos->execute($actIds);
    $allPhotos = $stmtPhotos->fetchAll();
    foreach ($allPhotos as $p) {
        $photosByAct[$p['activity_id']][] = $p;
    }
}

$techName = $shift['technician_name'] ?: ($shift['user_full_name'] ?: $shift['username']);
$shiftInfo = get_shift_info($shift['shift_type']);
$shiftTypeLabel = $shiftInfo['name'] . ' (' . $shiftInfo['time'] . ')';
$formattedDate = date('d F Y', strtotime($shift['shift_date']));
$pdfFilename = 'Logbook_NOC_' . ucfirst($shift['shift_type']) . '_' . $shift['shift_date'] . '.pdf';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Laporan Shift NOC - <?= htmlspecialchars($techName) ?> (<?= htmlspecialchars($formattedDate) ?>)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/png" href="assets/img/logo_ias.png?v=3">
  
  <style>
    @page {
      size: A4 portrait;
      margin: 12mm 15mm 15mm 15mm;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: Arial, "Helvetica Neue", Helvetica, sans-serif;
      font-size: 11pt;
      line-height: 1.4;
      color: #1e293b;
      background: #f8fafc;
      padding: 20px 0;
    }

    /* Screen Action Bar (hidden on print) */
    .print-actions-bar {
      position: sticky;
      top: 0;
      z-index: 1000;
      background: #ffffff;
      border-bottom: 2px solid #008080;
      padding: 10px 20px;
      margin-bottom: 25px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: 0 4px 12px rgba(0,0,0,0.08);
      max-width: 900px;
      margin-left: auto;
      margin-right: auto;
      border-radius: 8px;
    }

    .btn-action {
      padding: 8px 16px;
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

    .btn-print {
      background: #008080;
      color: #ffffff;
    }
    .btn-print:hover {
      background: #006666;
    }

    .btn-pdf {
      background: #0284c7;
      color: #ffffff;
    }
    .btn-pdf:hover {
      background: #0369a1;
    }

    .btn-back {
      background: #f1f5f9;
      color: #475569;
      border-color: #cbd5e1;
    }
    .btn-back:hover {
      background: #e2e8f0;
      color: #0f172a;
    }

    /* A4 Document Container */
    .a4-sheet {
      width: 210mm;
      min-height: 297mm;
      padding: 12mm 15mm;
      margin: 0 auto;
      background: #ffffff;
      box-shadow: 0 4px 20px rgba(0,0,0,0.08);
      border-radius: 2px;
    }

    /* Official Letterhead */
    .doc-header-table {
      width: 100%;
      border-bottom: 3px double #008080;
      padding-bottom: 10px;
      margin-bottom: 15px;
      border-collapse: collapse;
    }

    .doc-header-table td {
      vertical-align: middle;
    }

    .doc-title-main {
      font-size: 14pt;
      font-weight: 800;
      color: #008080;
      text-transform: uppercase;
      letter-spacing: 0.02em;
    }

    .doc-title-sub {
      font-size: 12pt;
      font-weight: 700;
      color: #0f172a;
      margin-top: 2px;
      letter-spacing: 0.01em;
    }

    .doc-title-location {
      font-size: 8.5pt;
      color: #64748b;
      margin-top: 3px;
    }

    /* Meta Info Grid Box */
    .meta-box {
      border: 1px solid #cbd5e1;
      background: #f8fafc;
      border-radius: 6px;
      padding: 10px 14px;
      margin-bottom: 18px;
    }

    .meta-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 9.5pt;
    }

    .meta-table td {
      padding: 3px 6px;
      vertical-align: top;
    }

    .meta-label {
      width: 140px;
      color: #64748b;
      font-weight: 600;
    }

    .meta-val {
      color: #0f172a;
      font-weight: 700;
    }

    /* Section Headings */
    .section-title-bar {
      background: #e6f3f3;
      border-left: 4px solid #008080;
      padding: 6px 10px;
      font-size: 10.5pt;
      font-weight: 800;
      color: #008080;
      text-transform: uppercase;
      margin-top: 15px;
      margin-bottom: 10px;
      letter-spacing: 0.03em;
    }

    /* Activities Table */
    .act-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 9pt;
      margin-bottom: 18px;
    }

    .act-table th {
      background: #f1f5f9;
      color: #334155;
      font-weight: 700;
      padding: 7px 8px;
      border: 1px solid #cbd5e1;
      text-align: left;
      font-size: 8.5pt;
      text-transform: uppercase;
    }

    .act-table td {
      padding: 8px 8px;
      border: 1px solid #cbd5e1;
      vertical-align: top;
      line-height: 1.45;
    }

    .cat-tag {
      display: inline-block;
      font-size: 7.5pt;
      font-weight: 700;
      padding: 2px 6px;
      border-radius: 3px;
      text-transform: uppercase;
    }

    .cat-tag-monitoring { background: #e0f2fe; color: #0369a1; }
    .cat-tag-patroli { background: #ecfdf5; color: #047857; }
    .cat-tag-troubleshooting { background: #fee2e2; color: #b91c1c; }
    .cat-tag-maintenance { background: #fef3c7; color: #b45309; }
    .cat-tag-administrasi { background: #f3e8ff; color: #6b21a8; }
    .cat-tag-lainnya { background: #f1f5f9; color: #475569; }

    /* Reference Photo Badges in Chronological Table */
    .ref-photo-badge {
      display: inline-block;
      font-size: 7.5pt;
      font-weight: 700;
      color: #0284c7;
      background: #e0f2fe;
      padding: 2px 6px;
      border-radius: 3px;
      border: 1px solid #bae6fd;
      white-space: nowrap;
    }

    .status-ok-badge {
      display: inline-block;
      font-size: 7.5pt;
      font-weight: 700;
      color: #047857;
      background: #ecfdf5;
      padding: 2px 6px;
      border-radius: 3px;
      border: 1px solid #a7f3d0;
    }

    /* Activity Photo Group in Section II (Like PM Format) */
    .photo-act-group {
      margin-bottom: 14px;
      page-break-inside: avoid;
      border: 1px solid #cbd5e1;
      border-radius: 6px;
      overflow: hidden;
      background: #ffffff;
      box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    }

    .photo-act-header {
      background: #f8fafc;
      border-bottom: 1px solid #e2e8f0;
      padding: 6px 10px;
    }

    .photo-act-badge {
      display: inline-block;
      background: #008080;
      color: #ffffff;
      font-size: 7.5pt;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 3px;
      letter-spacing: 0.02em;
    }

    .photo-doc-grid {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      padding: 10px;
      background: #ffffff;
    }

    .photo-doc-card {
      flex: 1 1 calc(33.333% - 10px);
      max-width: calc(33.333% - 7px);
      border: 1px solid #cbd5e1;
      border-radius: 4px;
      overflow: hidden;
      background: #ffffff;
      box-shadow: 0 1px 2px rgba(0,0,0,0.03);
    }

    .photo-doc-grid-2 .photo-doc-card {
      flex: 1 1 calc(50% - 10px);
      max-width: calc(50% - 5px);
    }

    .photo-doc-tag {
      background: #f1f5f9;
      color: #1e293b;
      font-size: 7.5pt;
      font-weight: 700;
      padding: 4px 8px;
      border-bottom: 1px solid #e2e8f0;
    }

    .photo-doc-img-wrap {
      width: 100%;
      height: 145px;
      background: #f8fafc;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
    }

    .photo-doc-img-wrap img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .photo-doc-caption {
      font-size: 7.5pt;
      color: #475569;
      padding: 5px 8px;
      line-height: 1.3;
      border-top: 1px solid #f1f5f9;
      background: #fafafa;
      min-height: 26px;
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
      font-size: 9.5pt;
    }

    .sign-space {
      height: 65px;
    }

    .sign-name {
      font-weight: 800;
      text-decoration: underline;
      color: #0f172a;
    }

    .sign-role {
      font-size: 8.5pt;
      color: #64748b;
      margin-top: 2px;
    }

    /* Print Styles */
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
    }
  </style>
</head>
<body>

  <!-- Screen Actions Bar -->
  <div class="print-actions-bar">
    <div style="display: flex; align-items: center; gap: 8px;">
      <a href="daily_activity?shift_id=<?= (int)$shift['id'] ?>" class="btn-action btn-back">
        &larr; Kembali ke Logbook
      </a>
      <span style="font-size: 13px; font-weight: 700; color: #008080;">
        Logbook Shift <?= ucfirst($shift['shift_type']) ?> &bull; <?= htmlspecialchars($formattedDate) ?>
      </span>
    </div>

    <div style="display: flex; align-items: center; gap: 8px;">
      <a href="download_pdf?shift_id=<?= (int)$shift['id'] ?>" class="btn-action btn-pdf" id="btn_export_pdf" title="Unduh langsung file PDF resmi berkualitas tinggi">
        📥 Unduh File PDF
      </a>
      <button type="button" class="btn-action btn-print" onclick="window.print()" title="Cetak dokumen atau Simpan sebagai PDF via browser">
        🖨️ Cetak Dokumen
      </button>
    </div>
  </div>

  <!-- A4 Printable Sheet -->
  <div class="a4-sheet" id="printable_content">
    
    <!-- Letterhead -->
    <table class="doc-header-table">
      <tr>
        <td style="width: 80px;">
          <img src="<?= htmlspecialchars(get_effective_report_logo()) ?>" alt="Logo Kop" style="height: 48px; object-fit: contain;">
        </td>
        <td style="padding-left: 12px;">
          <div class="doc-title-main"><?= htmlspecialchars(get_system_setting('company_name', 'IASS SBU ICT KNO')) ?></div>
          <div class="doc-title-sub"><?= htmlspecialchars(get_system_setting('unit_name', 'IASS SBU ICT KNO')) ?> &bull; LOGBOOK HARIAN SHIFT NOC</div>
          <div class="doc-title-location">Bandara Internasional Kualanamu, Deli Serdang, Sumatera Utara</div>
        </td>
        <td style="width: 120px; text-align: right;">
          <div style="font-size: 8pt; font-weight: 700; color: #64748b; text-transform: uppercase;">ID Shift: #<?= (int)$shift['id'] ?></div>
          <div style="font-size: 8pt; color: #008080; font-weight: 800; margin-top: 2px;">
            <?= ($shift['status'] === 'completed') ? 'RESMI &bull; TERKUNCI' : 'AKTIF &bull; BERJALAN' ?>
          </div>
        </td>
      </tr>
    </table>

    <!-- Shift Meta Information Box -->
    <div class="meta-box">
      <table class="meta-table">
        <tr>
          <td class="meta-label">Tanggal Shift:</td>
          <td class="meta-val"><?= htmlspecialchars($formattedDate) ?></td>
          <td class="meta-label">Teknisi Jaga:</td>
          <td class="meta-val"><?= htmlspecialchars($techName) ?> (<?= htmlspecialchars($shift['user_role'] ?? 'Teknisi') ?>)</td>
        </tr>
        <tr>
          <td class="meta-label">Jenis Shift:</td>
          <td class="meta-val"><?= htmlspecialchars($shiftTypeLabel) ?></td>
          <td class="meta-label">Status Shift:</td>
          <td class="meta-val">
            <?= ($shift['status'] === 'completed') ? 'Selesai & Diserahkan' : 'Sedang Berjalan' ?>
            <?php if (!empty($shift['completed_at'])): ?>
              (Pukul <?= date('H:i', strtotime($shift['completed_at'])) ?> WIB)
            <?php endif; ?>
          </td>
        </tr>
        <tr>
          <td class="meta-label">Total Kegiatan:</td>
          <td class="meta-val"><?= count($activities) ?> Catatan Kegiatan</td>
          <td class="meta-label">Total Lampiran Foto:</td>
          <td class="meta-val">
            <?php 
              $totalDocPhotos = 0;
              foreach ($activities as $a) { $totalDocPhotos += count($photosByAct[$a['id']] ?? []); }
              echo $totalDocPhotos . ' Foto Dokumentasi';
            ?>
          </td>
        </tr>
      </table>
    </div>

    <!-- Section 1: Tabel Kronologis Kegiatan -->
    <div class="section-title-bar">I. Tabel Kronologis Kegiatan Harian Shift</div>
    
    <?php if (empty($activities)): ?>
      <p style="font-size: 9pt; color: #64748b; font-style: italic; padding: 10px 0;">
        Tidak ada catatan kegiatan yang dicatat pada shift ini.
      </p>
    <?php else: ?>
      <table class="act-table">
        <thead>
          <tr>
            <th style="width: 25px; text-align: center;">No</th>
            <th style="width: 70px;">Waktu</th>
            <th style="width: 85px;">Kategori</th>
            <th style="width: 110px;">Lokasi / Site</th>
            <th>Pekerjaan &amp; Uraian Penanganan</th>
            <th style="width: 105px; text-align: center;">Dokumentasi</th>
            <th style="width: 65px; text-align: center;">Keterangan</th>
          </tr>
        </thead>
        <tbody>
          <?php $no = 1; foreach ($activities as $act): 
            $curNo = $no++;
            $cat = strtolower($act['category']);
            $tagClass = 'cat-tag-' . $cat;
            if (!in_array($cat, ['monitoring', 'patroli', 'troubleshooting', 'maintenance', 'administrasi'])) {
                $tagClass = 'cat-tag-lainnya';
            }
            $actPhotos = $photosByAct[$act['id']] ?? [];
          ?>
            <tr style="page-break-inside: avoid;">
              <td style="text-align: center; font-weight: 700;"><?= $curNo ?></td>
              <td style="font-weight: 700; color: #008080; white-space: nowrap;"><?= htmlspecialchars($act['activity_time']) ?></td>
              <td><span class="cat-tag <?= $tagClass ?>"><?= htmlspecialchars($act['category']) ?></span></td>
              <td style="font-weight: 600; color: #334155; font-size: 8.5pt;">
                <?= htmlspecialchars($act['location'] ?: '-') ?>
              </td>
              <td>
                <div style="font-weight: 700; color: #0f172a; margin-bottom: 3px; font-size: 9pt;">
                  <?= htmlspecialchars($act['title']) ?>
                </div>
                <div style="color: #475569; font-size: 8.5pt; line-height: 1.45;">
                  <?= nl2br(htmlspecialchars($act['description'])) ?>
                </div>
              </td>
              <td style="text-align: center; vertical-align: middle;">
                <?php if (!empty($actPhotos)): ?>
                  <?php if (count($actPhotos) === 1): ?>
                    <span class="ref-photo-badge">📷 Foto <?= $curNo ?>.1</span>
                  <?php else: ?>
                    <span class="ref-photo-badge">📷 <?= count($actPhotos) ?> Foto (<?= $curNo ?>.1 - <?= $curNo ?>.<?= count($actPhotos) ?>)</span>
                  <?php endif; ?>
                <?php else: ?>
                  <span style="color: #94a3b8; font-size: 7.5pt; font-style: italic;">(Tanpa foto)</span>
                <?php endif; ?>
              </td>
              <td style="text-align: center; vertical-align: middle;">
                <span class="status-ok-badge">Normal</span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <!-- Section II: Lampiran Dokumentasi Foto Kegiatan (Format PM Terstruktur) -->
    <?php if ($totalDocPhotos > 0): ?>
      <div class="section-title-bar" style="margin-top: 20px;">II. Lampiran Dokumentasi Foto Kegiatan</div>
      <p style="font-size: 8pt; color: #64748b; margin-top: -5px; margin-bottom: 12px;">
        Dokumentasi visual hasil pekerjaan dan patroli shift sesuai nomor urut kegiatan pada Tabel Kronologis di atas.
      </p>

      <?php $actNum = 1; foreach ($activities as $act): 
        $curActNum = $actNum++;
        $actPhotos = $photosByAct[$act['id']] ?? [];
        if (empty($actPhotos)) {
            continue;
        }
        $cat = strtolower($act['category']);
        $tagClass = 'cat-tag-' . $cat;
        if (!in_array($cat, ['monitoring', 'patroli', 'troubleshooting', 'maintenance', 'administrasi'])) {
            $tagClass = 'cat-tag-lainnya';
        }
      ?>
        <div class="photo-act-group">
          <div class="photo-act-header">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px;">
              <div style="display: flex; align-items: center; gap: 8px;">
                <span class="photo-act-badge">Kegiatan #<?= $curActNum ?></span>
                <span style="font-size: 8pt; font-weight: 700; color: #008080;">⏰ <?= htmlspecialchars($act['activity_time']) ?></span>
                <span class="cat-tag <?= $tagClass ?>"><?= htmlspecialchars($act['category']) ?></span>
                <strong style="color: #0f172a; font-size: 8.5pt;"><?= htmlspecialchars($act['title']) ?></strong>
              </div>
              <span style="font-size: 7.5pt; font-weight: 700; color: #0284c7; background: #e0f2fe; padding: 2px 7px; border-radius: 3px;">
                <?= count($actPhotos) ?> Foto Dokumentasi
              </span>
            </div>
            <?php if (!empty($act['location'])): ?>
              <div style="font-size: 7.5pt; color: #64748b; margin-top: 3px;">
                📍 Lokasi: <strong><?= htmlspecialchars($act['location']) ?></strong>
              </div>
            <?php endif; ?>
          </div>

          <div class="photo-doc-grid <?= (count($actPhotos) <= 2) ? 'photo-doc-grid-2' : '' ?>">
            <?php $pNum = 1; foreach ($actPhotos as $p): 
              $curPNum = $pNum++;
            ?>
              <div class="photo-doc-card">
                <div class="photo-doc-tag">
                  <span>📷 Dokumentasi <?= $curActNum ?>.<?= $curPNum ?></span>
                  <?php if (!empty($p['created_at'])): ?>
                    <span style="float: right; color: #64748b; font-weight: 400;"><?= date('H:i', strtotime($p['created_at'])) ?> WIB</span>
                  <?php endif; ?>
                </div>
                <div class="photo-doc-img-wrap">
                  <img src="<?= htmlspecialchars($p['photo_path']) ?>" alt="Foto Dokumentasi <?= $curActNum ?>.<?= $curPNum ?>">
                </div>
                <div class="photo-doc-caption">
                  <?= htmlspecialchars(!empty($p['caption']) ? $p['caption'] : $act['title']) ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <!-- Section III: Catatan Khusus & Serah Terima Shift -->
    <?php if (!empty($shift['notes'])): ?>
      <div class="section-title-bar"><?= ($totalDocPhotos > 0) ? 'III' : 'II' ?>. Catatan Khusus &amp; Serah Terima Shift</div>
      <div style="border: 1px solid #cbd5e1; background: #f8fafc; border-radius: 4px; padding: 10px; font-size: 9pt; color: #334155; margin-bottom: 18px; line-height: 1.5;">
        <?= nl2br(htmlspecialchars($shift['notes'])) ?>
      </div>
    <?php endif; ?>

    <!-- Sign-off Verification Section -->
    <table class="sign-table">
      <tr>
        <td>
          <div>Deli Serdang, <?= htmlspecialchars($formattedDate) ?></div>
          <div style="font-weight: 700; margin-top: 2px;">Teknisi Jaga Pelaksana</div>
          <div class="sign-space"></div>
          <div class="sign-name"><?= htmlspecialchars($techName) ?></div>
          <div class="sign-role">NOC Support KNO</div>
        </td>
        <td>
          <div>Mengetahui &amp; Menyetujui,</div>
          <div style="font-weight: 700; margin-top: 2px;"><?= htmlspecialchars(get_system_setting('default_leader_role', 'Supervisor / Team Leader NOC KNO')) ?></div>
          <div class="sign-space"></div>
          <div class="sign-name"><?= htmlspecialchars(get_system_setting('default_team_leader', 'Eko Sulistyo')) ?></div>
          <div class="sign-role"><?= htmlspecialchars(get_system_setting('unit_name', 'IASS SBU ICT KNO')) ?></div>
        </td>
      </tr>
    </table>

  </div>

  <script src="assets/js/html2pdf.bundle.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      var btnPdf = document.getElementById('btn_export_pdf');
      if (btnPdf) {
        btnPdf.addEventListener('click', function() {
          btnPdf.disabled = true;
          btnPdf.textContent = 'Membuat PDF...';

          var element = document.getElementById('printable_content');
          var opt = {
            margin:       [10, 10, 10, 10],
            filename:     '<?= $pdfFilename ?>',
            image:        { type: 'jpeg', quality: 0.95 },
            html2canvas:  { scale: 2, useCORS: true, logging: false },
            jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
          };

          html2pdf().set(opt).from(element).save().then(function() {
            btnPdf.disabled = false;
            btnPdf.textContent = '📥 Unduh File PDF';
          }).catch(function(err) {
            console.error(err);
            btnPdf.disabled = false;
            btnPdf.textContent = '📥 Unduh File PDF';
            alert('Gagal membuat PDF otomatis. Anda dapat menggunakan tombol "Cetak Dokumen" lalu pilih "Save as PDF".');
          });
        });
      }
    });
  </script>
</body>
</html>
