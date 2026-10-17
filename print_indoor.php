<?php
/**
 * Pixel-Perfect Print View: PM Indoor (7 Halaman)
 * PT Angkasa Pura Sarana Digital & Telkomsel
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/checklist_data.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
$pdo = get_db_connection();

$report_id = intval($_GET['id'] ?? 0);
if ($report_id <= 0) {
    die("ID Laporan tidak valid.");
}

// Fetch report master
$stmt = $pdo->prepare("SELECT * FROM pm_reports WHERE id = ?");
$stmt->execute([$report_id]);
$report = $stmt->fetch();
if (!$report) {
    die("Laporan tidak ditemukan.");
}

// Fetch indoor details
$stmtDet = $pdo->prepare("SELECT * FROM pm_indoor_details WHERE report_id = ?");
$stmtDet->execute([$report_id]);
$details = $stmtDet->fetch();

$site = json_decode($details['site_snapshot_json'] ?? '{}', true);
$checklist = json_decode($details['checklist_json'] ?? '{}', true);

// Fetch photos
$stmtPhotos = $pdo->prepare("SELECT * FROM pm_photos WHERE report_id = ? ORDER BY slot_code ASC");
$stmtPhotos->execute([$report_id]);
$photosRaw = $stmtPhotos->fetchAll();
$photos = [];
foreach ($photosRaw as $p) {
    $photos[$p['slot_code']] = $p;
}

$definitions = get_indoor_checklist_definitions();
$photoGroups = get_indoor_photo_slots($report['operator'] ?? 'Telkomsel');

$formattedDate = date('d/m/Y', strtotime($report['report_date']));
$dateMonthYear = date('d F Y', strtotime($report['report_date']));

$rightLogoPath = $report['custom_logo_path'] ?? 'assets/img/logo_telkomsel.png';
$showRightLogo = ($rightLogoPath !== 'none' && !empty($rightLogoPath) && file_exists(__DIR__ . '/' . $rightLogoPath));

$leftLogoPath = get_effective_report_logo();

// Compact Mode: Only print filled photos, skipping empty N/A slots
$isCompact = (isset($_GET['compact']) && $_GET['compact'] == '1');

$allSlots = [];
foreach ($photoGroups as $grpKey => $grp) {
    foreach ($grp['slots'] as $slotCode => $slotDef) {
        $p = $photos[$slotCode] ?? null;
        $hasImage = ($p && !empty($p['image_path']) && file_exists(__DIR__ . '/' . $p['image_path']));
        $allSlots[] = [
            'code' => $slotCode,
            'def' => $slotDef,
            'photo' => $p,
            'has_image' => $hasImage,
            'group' => $grpKey,
            'group_title' => $grp['title'] ?? ''
        ];
    }
}

$filledSlots = array_values(array_filter($allSlots, fn($s) => $s['has_image']));
$filledCount = count($filledSlots);

if ($isCompact) {
    if (empty($filledSlots)) {
        $photoPages = [[]];
    } else {
        $photoPages = array_chunk($filledSlots, 6);
    }
} else {
    $photoPages = [];
    foreach ($photoGroups as $grpKey => $grp) {
        $pageSlots = [];
        foreach ($grp['slots'] as $slotCode => $slotDef) {
            $p = $photos[$slotCode] ?? null;
            $hasImage = ($p && !empty($p['image_path']) && file_exists(__DIR__ . '/' . $p['image_path']));
            $pageSlots[] = [
                'code' => $slotCode,
                'def' => $slotDef,
                'photo' => $p,
                'has_image' => $hasImage,
                'group' => $grpKey,
                'group_title' => $grp['title'] ?? ''
            ];
        }
        $photoPages[] = $pageSlots;
    }
}

$totalPhotoPages = count($photoPages);
$totalPages = 3 + $totalPhotoPages;
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>PM_Indoor_<?= preg_replace('/[^A-Za-z0-9_\-]/', '_', $site['site_name'] ?? 'Site') ?>_<?= date('Ymd', strtotime($report['report_date'])) ?></title>
  <link rel="stylesheet" href="assets/css/print.css?v=<?= filemtime(__DIR__ . '/assets/css/print.css') ?>">
  <style>
    /* Specific styling for Indoor Checklist form */
    .indoor-meta-table td {
      padding: 3px 6px;
      font-size: 8pt;
    }
    .check-box-symbol {
      display: inline-block;
      width: 11px;
      height: 11px;
      border: 1px solid #000;
      margin-right: 4px;
      vertical-align: middle;
      text-align: center;
      line-height: 10px;
      font-size: 9pt;
      font-weight: bold;
    }
  </style>
  <script>
    (function() {
      document.addEventListener('contextmenu', function(e) {
        if (e.target && e.target.closest('input, textarea, [contenteditable="true"]')) return true;
        e.preventDefault();
        return false;
      }, false);

      document.addEventListener('keydown', function(e) {
        if (e.key === 'F12' || e.keyCode === 123) {
          e.preventDefault();
          return false;
        }
        if ((e.ctrlKey || e.metaKey) && e.shiftKey && ['I', 'i', 'J', 'j', 'C', 'c'].indexOf(e.key) !== -1) {
          e.preventDefault();
          return false;
        }
        if ((e.ctrlKey || e.metaKey) && (e.key === 'U' || e.key === 'u')) {
          e.preventDefault();
          return false;
        }
      }, false);
    })();
  </script>
</head>
<body class="preview-mode">

  <!-- Top Action Toolbar (Hidden when printing) -->
  <div class="no-print" style="width: 210mm; margin: 0 auto 15px auto; background: #ffffff; padding: 12px 18px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.25); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
    <div>
      <strong style="color: #0f172a; font-size: 15px;">Pratinjau Cetak: PM Indoor (<?= $totalPages ?> Halaman)</strong>
      <p style="font-size: 12px; color: #64748b; margin: 2px 0 0 0;">
        <?= $isCompact ? "Mode Ringkas Aktif: {$filledCount} foto terlampir ({$totalPhotoPages} hal foto)" : "Mode Standar: Semua 24 slot tercetak ({$totalPages} halaman)" ?>
      </p>
    </div>
    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
      <div style="display: flex; align-items: center; gap: 6px; background: #f1f5f9; padding: 6px 12px; border-radius: 6px;">
        <input type="checkbox" id="toggle_compact" <?= $isCompact ? 'checked' : '' ?> onchange="window.location.search = updateQueryParam('compact', this.checked ? '1' : '0')" style="width: 16px; height: 16px; cursor: pointer;">
        <label for="toggle_compact" style="font-size: 12px; font-weight: 600; color: #334155; cursor: pointer; margin: 0;">
          Hanya Foto Terisi (Skip N/A)
        </label>
      </div>
      <a href="reports" style="display: inline-block; padding: 6px 14px; background: #e2e8f0; color: #1e293b; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 13px;">
        &larr; Riwayat
      </a>
      <button onclick="window.print()" style="display: inline-block; padding: 6px 16px; background: #008080; color: #ffffff; border: none; cursor: pointer; border-radius: 4px; font-weight: 600; font-size: 13px;">
        🖨️ Cetak / Simpan PDF
      </button>
      <button type="button" id="btn_download_pdf" onclick="triggerClientDownload()" style="display: inline-block; padding: 6px 14px; background: #0284c7; color: #ffffff; border: none; cursor: pointer; border-radius: 4px; font-weight: 600; font-size: 13px;">
        ⬇️ Download PDF
      </button>
    </div>
  </div>

  <script src="assets/js/html2pdf.bundle.min.js"></script>
  <script>
    function updateQueryParam(key, val) {
      const u = new URL(window.location.href);
      u.searchParams.set(key, val);
      return u.search;
    }

    function triggerClientDownload() {
      const btn = document.getElementById('btn_download_pdf');
      if (btn) {
        btn.innerText = '⏳ Mengunduh PDF...';
        btn.disabled = true;
      }
      window.location.href = 'download_pdf?id=<?= $report['id'] ?><?= $isCompact ? '&compact=1' : '' ?>';
      setTimeout(function() {
        if (btn) {
          btn.innerText = '⬇️ Download PDF';
          btn.disabled = false;
        }
      }, 4000);
    }

    <?php if (isset($_GET['autoprint']) && $_GET['autoprint'] == '1'): ?>
      window.addEventListener('load', function() {
        setTimeout(function() { window.print(); }, 600);
      });
    <?php endif; ?>
  </script>

  <div class="preview-container">

    <!-- ==========================================
         HALAMAN 1: IDENTITAS & CHECKLIST BAGIAN A, B, C
         ========================================== -->
    <div class="print-page">
      
      <!-- Top Form Header -->
      <table class="table-bordered" style="margin-bottom: 0;">
        <tr>
          <td style="width: 28%; text-align: center; vertical-align: middle; padding: 6px;">
            <img src="<?= htmlspecialchars($leftLogoPath) ?>?v=<?= filemtime(__DIR__ . '/' . $leftLogoPath) ?>" alt="Kop Logo" style="height: 38px; width: auto; max-width: 100%;">
          </td>
          <td style="width: 44%; text-align: center; vertical-align: middle;">
            <div style="font-size: 11pt; font-weight: bold;">Daily Check List</div>
            <div style="font-size: 11pt; font-weight: bold;">IBC <?= htmlspecialchars($report['operator'] ?: 'Telkomsel') ?></div>
          </td>
          <td style="width: 28%; padding: 0;">
            <table style="width: 100%; border-collapse: collapse; font-size: 7.5pt;">
              <tr>
                <td style="border: none; border-bottom: 1px solid #000; border-right: 1px solid #000; padding: 2px 4px; width: 55%;">Form No.</td>
                <td style="border: none; border-bottom: 1px solid #000; padding: 2px 4px; text-align: center; font-weight: bold;"><?= htmlspecialchars($report['form_no']) ?></td>
              </tr>
              <tr>
                <td style="border: none; border-bottom: 1px solid #000; border-right: 1px solid #000; padding: 2px 4px;">Tanggal Efektif</td>
                <td style="border: none; border-bottom: 1px solid #000; padding: 2px 4px; text-align: center;"><?= htmlspecialchars($report['effective_date']) ?></td>
              </tr>
              <tr>
                <td style="border: none; border-bottom: 1px solid #000; border-right: 1px solid #000; padding: 2px 4px;">Revisi</td>
                <td style="border: none; border-bottom: 1px solid #000; padding: 2px 4px; text-align: center;">-</td>
              </tr>
              <tr>
                <td style="border: none; border-right: 1px solid #000; padding: 2px 4px;">Halaman</td>
                <td style="border: none; padding: 2px 4px; text-align: center;">1 dari <?= $totalPages ?></td>
              </tr>
            </table>
          </td>
        </tr>
      </table>

      <!-- Meta Inspeksi Bar -->
      <table class="table-bordered" style="margin-top: -1px; margin-bottom: 6px; font-size: 8pt;">
        <tr>
          <td style="width: 15%; font-weight: bold;">Lokasi</td>
          <td style="width: 25%;">: <?= htmlspecialchars($site['location_detail'] ?? 'Server Domestik') ?></td>
          <td style="width: 12%; font-weight: bold;">Petugas</td>
          <td style="width: 18%;">: <?= htmlspecialchars($report['inspector_name']) ?></td>
          <td style="width: 10%; font-weight: bold;">Leader</td>
          <td style="width: 20%;">: <?= htmlspecialchars($report['team_leader'] ?? '') ?></td>
        </tr>
        <tr>
          <td style="font-weight: bold;">Tanggal</td>
          <td>: <?= $formattedDate ?></td>
          <td style="font-weight: bold;">Paraf</td>
          <td>: <span style="font-size: 8pt; color: #555;">[ Terlampir ]</span></td>
          <td style="font-weight: bold;">Paraf</td>
          <td>:</td>
        </tr>
        <tr>
          <td style="font-weight: bold;">Periode Pemeriksaan</td>
          <td colspan="5">: <?= htmlspecialchars($report['period']) ?></td>
        </tr>
      </table>

      <!-- Preventive Maintenance Report Metadata Box -->
      <table class="table-bordered" style="margin-bottom: 6px; font-size: 8pt;">
        <thead>
          <tr>
            <th colspan="4" style="background: #e2e8f0; font-size: 8.5pt; text-align: center; padding: 3px;">
              Preventive Maintenance Report
            </th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td style="width: 18%;">Site ID</td>
            <td colspan="3">: <?= htmlspecialchars($site['site_id_str'] ?? '') ?></td>
          </tr>
          <tr>
            <td>Nama Site</td>
            <td colspan="3">: <?= htmlspecialchars($site['site_name'] ?? '') ?></td>
          </tr>
          <tr>
            <td>Region</td>
            <td colspan="3">: <?= htmlspecialchars($site['region'] ?? '') ?></td>
          </tr>
          <tr>
            <td>Provinsi</td>
            <td colspan="3">: <?= htmlspecialchars($site['province'] ?? '') ?></td>
          </tr>
          <tr>
            <td>Kota</td>
            <td colspan="3">: <?= htmlspecialchars($site['city'] ?? '') ?></td>
          </tr>
          <tr>
            <td>Alamat</td>
            <td colspan="3">: <?= htmlspecialchars($site['address'] ?? '') ?></td>
          </tr>
          <tr>
            <td>Latitude</td>
            <td colspan="3">: <?= htmlspecialchars($site['latitude'] ?? '') ?></td>
          </tr>
          <tr>
            <td>Longitude</td>
            <td colspan="3">: <?= htmlspecialchars($site['longitude'] ?? '') ?></td>
          </tr>
          <tr>
            <td>Tinggi Site</td>
            <td colspan="3">: <?= htmlspecialchars($site['site_height'] ?? '-') ?></td>
          </tr>
          <tr>
            <td>Tipe Tower</td>
            <td colspan="3">
              : 
              <span class="check-box-symbol"><?= (($site['tower_type'] ?? '') === 'Pole') ? '✓' : '' ?></span> Pole &nbsp;&nbsp;&nbsp;
              <span class="check-box-symbol"><?= (($site['tower_type'] ?? '') !== 'Pole') ? '✓' : '' ?></span> Non Pole
            </td>
          </tr>
          <tr>
            <td>Tipe Site</td>
            <td colspan="3">
              : 
              <span class="check-box-symbol"><?= (($site['site_type'] ?? '') === 'Indoor') ? '✓' : '' ?></span> Indoor &nbsp;&nbsp;&nbsp;
              <span class="check-box-symbol"><?= (($site['site_type'] ?? '') !== 'Indoor') ? '✓' : '' ?></span> Outdoor
            </td>
          </tr>
        </tbody>
      </table>

      <!-- Checklist Bagian A, B, C -->
      <table class="table-bordered" style="margin-bottom: 0;">
        <thead>
          <tr>
            <th rowspan="2" colspan="2" style="width: 38%; padding: 4px;">PEMERIKSAAN</th>
            <th colspan="2" style="width: 14%; padding: 4px;">HASIL</th>
            <th rowspan="2" style="width: 32%; padding: 4px;">REFERENSI</th>
            <th rowspan="2" style="width: 16%; padding: 4px;">KETERANGAN</th>
          </tr>
          <tr>
            <th style="width: 7%; padding: 2px;">OK</th>
            <th style="width: 7%; padding: 2px;">NOK</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (['A', 'B', 'C'] as $secKey): ?>
            <?php $sec = $definitions[$secKey]; ?>
            <tr style="background: #ffffff;">
              <td style="font-weight: bold; width: 4%; text-align: center;"><?= $secKey ?></td>
              <td colspan="5" style="font-weight: bold; background: #ffffff;"><?= htmlspecialchars($sec['name']) ?></td>
            </tr>
            <?php foreach ($sec['items'] as $itemNo => $item): ?>
              <?php 
                $fieldKey = "chk_{$secKey}_{$itemNo}";
                $itemStatus = $checklist[$fieldKey]['status'] ?? 'OK';
                $itemNote = $checklist[$fieldKey]['note'] ?? '';
              ?>
              <tr>
                <td style="text-align: center; font-size: 8pt; width: 4%;"><?= $itemNo ?></td>
                <td><?= htmlspecialchars($item['name']) ?></td>
                <td class="text-center" style="font-size: 11pt; font-weight: bold;">
                  <?= ($itemStatus === 'OK') ? '✓' : '' ?>
                </td>
                <td class="text-center" style="font-size: 11pt; font-weight: bold;">
                  <?= ($itemStatus === 'NOK') ? '✓' : '' ?>
                </td>
                <td style="font-size: 7.5pt; color: #111;"><?= htmlspecialchars($item['ref']) ?></td>
                <td style="font-size: 7.5pt;"><?= htmlspecialchars($itemNote) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </tbody>
      </table>

    </div>

    <!-- ==========================================
         HALAMAN 2: CHECKLIST BAGIAN D, E, F, G, H
         ========================================== -->
    <div class="print-page">
      <table class="table-bordered" style="margin-bottom: 0;">
        <thead>
          <tr>
            <th rowspan="2" colspan="2" style="width: 38%; padding: 4px;">PEMERIKSAAN</th>
            <th colspan="2" style="width: 14%; padding: 4px;">HASIL</th>
            <th rowspan="2" style="width: 32%; padding: 4px;">REFERENSI</th>
            <th rowspan="2" style="width: 16%; padding: 4px;">KETERANGAN</th>
          </tr>
          <tr>
            <th style="width: 7%; padding: 2px;">OK</th>
            <th style="width: 7%; padding: 2px;">NOK</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (['D', 'E', 'F', 'G', 'H'] as $secKey): ?>
            <?php $sec = $definitions[$secKey]; ?>
            <tr style="background: #ffffff;">
              <td style="font-weight: bold; width: 4%; text-align: center;"><?= $secKey ?></td>
              <td colspan="5" style="font-weight: bold; background: #ffffff;"><?= htmlspecialchars($sec['name']) ?></td>
            </tr>
            <?php foreach ($sec['items'] as $itemNo => $item): ?>
              <?php 
                $fieldKey = "chk_{$secKey}_{$itemNo}";
                $itemStatus = $checklist[$fieldKey]['status'] ?? 'OK';
                $itemNote = $checklist[$fieldKey]['note'] ?? '';
              ?>
              <tr>
                <td style="text-align: center; font-size: 8pt; width: 4%;"><?= $itemNo ?></td>
                <td><?= htmlspecialchars($item['name']) ?></td>
                <td class="text-center" style="font-size: 11pt; font-weight: bold;">
                  <?= ($itemStatus === 'OK') ? '✓' : '' ?>
                </td>
                <td class="text-center" style="font-size: 11pt; font-weight: bold;">
                  <?= ($itemStatus === 'NOK') ? '✓' : '' ?>
                </td>
                <td style="font-size: 7.5pt; color: #111;"><?= htmlspecialchars($item['ref']) ?></td>
                <td style="font-size: 7.5pt;"><?= htmlspecialchars($itemNote) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- ==========================================
         HALAMAN 3: CHECKLIST BAGIAN I, J, K & CATATAN
         ========================================== -->
    <div class="print-page">
      <table class="table-bordered" style="margin-bottom: 12px;">
        <thead>
          <tr>
            <th rowspan="2" colspan="2" style="width: 38%; padding: 4px;">PEMERIKSAAN</th>
            <th colspan="2" style="width: 14%; padding: 4px;">HASIL</th>
            <th rowspan="2" style="width: 32%; padding: 4px;">REFERENSI</th>
            <th rowspan="2" style="width: 16%; padding: 4px;">KETERANGAN</th>
          </tr>
          <tr>
            <th style="width: 7%; padding: 2px;">OK</th>
            <th style="width: 7%; padding: 2px;">NOK</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (['I', 'J', 'K'] as $secKey): ?>
            <?php $sec = $definitions[$secKey]; ?>
            <tr style="background: #ffffff;">
              <td style="font-weight: bold; width: 4%; text-align: center;"><?= $secKey ?></td>
              <td colspan="5" style="font-weight: bold; background: #ffffff;"><?= htmlspecialchars($sec['name']) ?></td>
            </tr>
            <?php foreach ($sec['items'] as $itemNo => $item): ?>
              <?php 
                $fieldKey = "chk_{$secKey}_{$itemNo}";
                $itemStatus = $checklist[$fieldKey]['status'] ?? 'OK';
                $itemNote = $checklist[$fieldKey]['note'] ?? '';
              ?>
              <tr>
                <td style="text-align: center; font-size: 8pt; width: 4%;"><?= $itemNo ?></td>
                <td><?= htmlspecialchars($item['name']) ?></td>
                <td class="text-center" style="font-size: 11pt; font-weight: bold;">
                  <?= ($itemStatus === 'OK') ? '✓' : '' ?>
                </td>
                <td class="text-center" style="font-size: 11pt; font-weight: bold;">
                  <?= ($itemStatus === 'NOK') ? '✓' : '' ?>
                </td>
                <td style="font-size: 7.5pt; color: #111;"><?= htmlspecialchars($item['ref']) ?></td>
                <td style="font-size: 7.5pt;"><?= htmlspecialchars($itemNote) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </tbody>
      </table>

      <!-- Catatan Box -->
      <div style="border: 1px solid #000; padding: 6px 8px; min-height: 140px;">
        <strong style="font-size: 8.5pt;">Catatan :</strong>
        <div style="font-size: 8pt; margin-top: 6px; white-space: pre-wrap; line-height: 1.4;">
          <?= htmlspecialchars($report['notes'] ?: '-') ?>
        </div>
      </div>
    </div>

    <!-- ==========================================
         HALAMAN 4 s/d <?= $totalPages ?>: DOKUMENTASI FOTO
         ========================================== -->
    <?php foreach ($photoPages as $pageIdx => $pageSlots): ?>
      <div class="print-page">
        
        <!-- Header Foto -->
        <table class="photo-page-header">
          <tr>
            <td style="width: 22%; text-align: center; vertical-align: middle; padding: 4px;">
              <img src="<?= htmlspecialchars($leftLogoPath) ?>?v=<?= filemtime(__DIR__ . '/' . $leftLogoPath) ?>" alt="Kop Logo" style="height: 32px; width: auto; max-width: 100%;">
            </td>
            <td style="width: 56%; text-align: center; vertical-align: middle; padding: 2px;">
              <div style="font-size: 8pt; font-weight: bold;">IASS SBU ICT KNO</div>
              <div style="font-size: 8.5pt; font-weight: bold;">CHECK LIST PREVENTIVE MAINTANANCE INDOOR</div>
              <div style="font-size: 8pt; font-weight: bold;">MECHANICAL ELECTRICAL</div>
              <div style="font-size: 7.5pt; font-weight: bold; color: #333;">BASE SERVICE (HAL <?= 3 + $pageIdx + 1 ?> DARI <?= $totalPages ?>)</div>
            </td>
            <td style="width: 22%; text-align: center; vertical-align: middle; padding: 4px;">
              <?php if ($showRightLogo): ?>
                <img src="<?= htmlspecialchars($rightLogoPath) ?>?v=2" alt="Logo Provider" style="max-height: 28px; width: auto; max-width: 100%; object-fit: contain;">
              <?php else: ?>
                <span style="font-weight: bold; font-size: 8.5pt;"><?= htmlspecialchars($report['operator'] ?: '') ?></span>
              <?php endif; ?>
            </td>
          </tr>
        </table>

        <!-- Metadata Sub Header -->
        <table class="table-bordered" style="margin-top: -1px; margin-bottom: 6px; font-size: 7.5pt;">
          <tr>
            <td style="width: 18%; font-weight: bold;">IASS Site ID</td>
            <td style="width: 32%;">: <?= htmlspecialchars($site['site_id_str'] ?? '') ?></td>
            <td style="width: 15%; font-weight: bold;">Date</td>
            <td style="width: 35%;">: <?= $dateMonthYear ?></td>
          </tr>
          <tr>
            <td style="font-weight: bold;">IASS Site Name</td>
            <td>: <?= htmlspecialchars($site['site_name'] ?? 'Bandara Kualanamu Server Domestik') ?></td>
            <td style="font-weight: bold;">Operator</td>
            <td>: <?= htmlspecialchars($report['operator'] ?: 'TELKOMSEL') ?></td>
          </tr>
        </table>

        <!-- Grid Foto (6 Slot standar 2x3 atau 8 Slot 2x4) -->
        <div class="photo-grid-container <?= (count($pageSlots) > 6) ? 'grid-8-slots' : '' ?>">
          <?php if (empty($pageSlots)): ?>
            <div style="grid-column: 1 / -1; height: 180mm; display: flex; align-items: center; justify-content: center; border: 1px solid #000; font-size: 11pt; color: #64748b; font-weight: bold;">
              Tidak ada dokumentasi foto yang diunggah untuk site ini.
            </div>
          <?php else: ?>
            <?php foreach ($pageSlots as $slotItem): ?>
              <?php 
                $slotCode = $slotItem['code'];
                $slotDef = $slotItem['def'];
                $p = $slotItem['photo'];
                $hasImage = $slotItem['has_image'];
                $meas = $p['measurement_value'] ?? ($slotDef['default_label'] ?? '');
              ?>
              <div class="photo-cell">
                <div class="photo-cell-image-wrapper">
                  <?php if ($hasImage): ?>
                    <img src="<?= htmlspecialchars($p['image_path']) ?>" alt="<?= htmlspecialchars($slotDef['title']) ?>" style="max-width: 100%; max-height: 100%; width: auto; height: auto; object-fit: contain; display: block; margin: auto;">
                  <?php else: ?>
                    <div class="photo-cell-na">N/A</div>
                  <?php endif; ?>
                </div>
                <div class="photo-cell-footer">
                  <span class="photo-cell-code">Foto <?= htmlspecialchars($slotCode) ?></span>
                  <span class="photo-cell-desc">
                    <?php 
                      $displayTitle = !empty($p['title']) ? $p['title'] : $slotDef['title'];
                      if (!empty($report['operator']) && strcasecmp($report['operator'], 'TELKOMSEL') !== 0 && stripos($displayTitle, 'Telkomsel') !== false) {
                          $opBrand = (stripos($report['operator'], 'XL') !== false) ? 'XL' :
                                     ((stripos($report['operator'], 'INDOSAT') !== false) ? 'Indosat' :
                                     ((stripos($report['operator'], 'IAS') !== false) ? 'IASS' : $report['operator']));
                          $displayTitle = str_ireplace('Telkomsel', $opBrand, $displayTitle);
                      }
                    ?>
                    <?= htmlspecialchars($displayTitle) ?>
                    <?php if (!empty($meas) && !empty($slotDef['has_measurement'])): ?>
                      (<?= htmlspecialchars($meas) ?>)
                    <?php endif; ?>
                  </span>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

      </div>
    <?php endforeach; ?>

  </div>

</body>
</html>
