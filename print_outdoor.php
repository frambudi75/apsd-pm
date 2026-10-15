<?php
/**
 * Pixel-Perfect Print View: PM Outdoor / Pole (13 Halaman)
 * PT Angkasa Pura Sarana Digital & PT IAS Support Indonesia
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/checklist_data.php';
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

// Fetch outdoor details
$stmtDet = $pdo->prepare("SELECT * FROM pm_outdoor_details WHERE report_id = ?");
$stmtDet->execute([$report_id]);
$details = $stmtDet->fetch();

$site = json_decode($details['site_snapshot_json'] ?? '{}', true);
$grounding = json_decode($details['grounding_json'] ?? '{}', true);
$towerCond = json_decode($details['tower_json'] ?? '{}', true);
$structural = json_decode($details['structural_json'] ?? '{}', true);

// Fetch photos
$stmtPhotos = $pdo->prepare("SELECT * FROM pm_photos WHERE report_id = ? ORDER BY slot_code ASC");
$stmtPhotos->execute([$report_id]);
$photosRaw = $stmtPhotos->fetchAll();
$photos = [];
foreach ($photosRaw as $p) {
    $photos[$p['slot_code']] = $p;
}

$photoGroups = get_outdoor_photo_slots();

$formattedDate = date('d/m/Y', strtotime($report['report_date']));
$dateAbbr = date('d-M-y', strtotime($report['report_date']));

$rightLogoPath = $report['custom_logo_path'] ?? 'assets/img/logo_ias.png';
$showRightLogo = ($rightLogoPath !== 'none' && !empty($rightLogoPath) && file_exists(__DIR__ . '/' . $rightLogoPath));
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>PM_Outdoor_<?= preg_replace('/[^A-Za-z0-9_\-]/', '_', $site['site_name'] ?? 'Site') ?>_<?= date('Ymd', strtotime($report['report_date'])) ?></title>
  <link rel="stylesheet" href="assets/css/print.css?v=<?= filemtime(__DIR__ . '/assets/css/print.css') ?>">
  <style>
    .pole-meta-table td {
      padding: 3px 5px;
      font-size: 8pt;
    }
    .circle-wrap {
      border: 1.5px solid #000;
      border-radius: 12px;
      padding: 0 4px;
      display: inline-block;
      font-weight: bold;
    }
  </style>
</head>
<body class="preview-mode">

  <!-- Top Action Toolbar (Hidden when printing) -->
  <div class="no-print" style="width: 210mm; margin: 0 auto 15px auto; background: #ffffff; padding: 12px 18px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.25); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
    <div>
      <strong style="color: #0f172a; font-size: 15px;">Pratinjau Cetak: PM Outdoor (13 Halaman)</strong>
      <p style="font-size: 12px; color: #64748b; margin: 2px 0 0 0;">Format telah dipresisikan dengan ukuran A4 standar resmi PT IAS Support Indonesia.</p>
    </div>
    <div style="display: flex; gap: 8px;">
      <a href="index.php" style="display: inline-block; padding: 6px 14px; background: #e2e8f0; color: #1e293b; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 13px;">
        &larr; Dashboard
      </a>
      <button onclick="window.print()" style="display: inline-block; padding: 6px 16px; background: #008080; color: #ffffff; border: none; cursor: pointer; border-radius: 4px; font-weight: 600; font-size: 13px;">
        🖨️ Cetak / Simpan PDF
      </button>
      <a href="download_pdf.php?id=<?= $report['id'] ?>" style="display: inline-block; padding: 6px 14px; background: #0284c7; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 13px;">
        ⬇️ Download PDF
      </a>
    </div>
  </div>

  <div class="preview-container">

    <!-- ==========================================
         HALAMAN 1: SITE INFORMATION (COVER)
         ========================================== -->
    <div class="print-page">
      <!-- Kop Dokumen -->
      <table class="table-bordered" style="margin-bottom: 0;">
        <tr>
          <td style="width: 22%; text-align: center; vertical-align: middle; padding: 6px;">
            <img src="assets/img/logo_ias.png?v=2" alt="IAS Support" style="height: 38px; width: auto; max-width: 100%;">
          </td>
          <td style="width: 56%; text-align: center; vertical-align: middle; padding: 4px;">
            <div style="font-size: 8.5pt; font-weight: bold;">PT IAS SUPPORT INDONESIA</div>
            <div style="font-size: 8pt; font-weight: bold;">CHECK LIST PREVENTIVE MAINTANANCE</div>
            <div style="font-size: 8pt; font-weight: bold;">MECHANICAL ELECTRICAL</div>
            <div style="font-size: 8pt; font-weight: bold; background: #e2e8f0; padding: 1px;">SITE INFORMATION</div>
          </td>
          <td style="width: 22%; padding: 0;">
            <table style="width: 100%; border-collapse: collapse; font-size: 7.5pt; height: 100%;">
              <tr>
                <td style="border: none; border-bottom: 1px solid #000; padding: 3px 5px; font-weight: bold;">DATE</td>
                <td style="border: none; border-bottom: 1px solid #000; padding: 3px 5px;">: <?= $dateAbbr ?></td>
              </tr>
              <tr>
                <td colspan="2" style="border: none; height: 28px; text-align: center; vertical-align: middle; padding: 2px;">
                  <?php if ($showRightLogo): ?>
                    <img src="<?= htmlspecialchars($rightLogoPath) ?>?v=2" alt="Logo Tenant" style="max-height: 24px; max-width: 90%; object-fit: contain;">
                  <?php else: ?>
                    <span style="font-weight: bold; font-size: 7.5pt; color: #333;"><?= htmlspecialchars($report['operator'] ?: '') ?></span>
                  <?php endif; ?>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>

      <!-- Sub Header Report Title -->
      <table class="table-bordered" style="margin-top: -1px; margin-bottom: 0;">
        <tr>
          <td style="text-align: center; font-weight: bold; font-size: 9.5pt; background: #f2f2f2; padding: 4px;">
            Preventive Maintenance Report
          </td>
        </tr>
        <tr>
          <td style="text-align: center; font-weight: bold; font-size: 8.5pt; padding: 3px;">
            Periode <?= htmlspecialchars($report['period']) ?>
          </td>
        </tr>
      </table>

      <!-- Site Details Table -->
      <table class="table-bordered" style="margin-top: -1px; margin-bottom: 0; font-size: 8.5pt;">
        <tr>
          <td style="width: 25%; font-weight: bold;">APSD Site ID</td>
          <td style="width: 75%;">: <?= htmlspecialchars($site['site_id_str'] ?? '') ?></td>
        </tr>
        <tr>
          <td style="font-weight: bold;">APSD Site Name</td>
          <td>: <?= htmlspecialchars($site['site_name'] ?? '') ?></td>
        </tr>
        <tr>
          <td style="font-weight: bold;">Region</td>
          <td>: <?= htmlspecialchars($site['region'] ?? 'Sumbagut') ?></td>
        </tr>
        <tr>
          <td style="font-weight: bold;">Province</td>
          <td>: <?= htmlspecialchars($site['province'] ?? 'Sumatra Utara') ?></td>
        </tr>
        <tr>
          <td style="font-weight: bold;">City</td>
          <td>: <?= htmlspecialchars($site['city'] ?? 'Medan') ?></td>
        </tr>
        <tr>
          <td style="font-weight: bold;">Address</td>
          <td>: <?= htmlspecialchars($site['address'] ?? '') ?></td>
        </tr>
        <tr>
          <td style="font-weight: bold;">Longitude</td>
          <td>: <?= htmlspecialchars($site['longitude'] ?? '98.861139') ?></td>
        </tr>
        <tr>
          <td style="font-weight: bold;">Latitude</td>
          <td>: <?= htmlspecialchars($site['latitude'] ?? '3.618278') ?></td>
        </tr>
        <tr>
          <td style="font-weight: bold;">Site Height</td>
          <td>: <?= htmlspecialchars($site['site_height'] ?? '18') ?></td>
        </tr>
        <tr>
          <td style="font-weight: bold;">Tower Type</td>
          <td>: <?= htmlspecialchars($site['tower_type'] ?? 'Pole PJU 18 m') ?></td>
        </tr>
        <tr>
          <td style="font-weight: bold;">Site Type</td>
          <td>: <?= htmlspecialchars($site['site_type'] ?? 'Green Field') ?></td>
        </tr>
        <tr>
          <td style="font-weight: bold;">Anchor Tenant</td>
          <td>: <?= htmlspecialchars($site['anchor_tenant'] ?? 'APSD') ?></td>
        </tr>
        <tr>
          <td style="font-weight: bold;">Total Tenant</td>
          <td>: 1</td>
        </tr>
      </table>

      <!-- Tenant List Table -->
      <table class="table-bordered" style="margin-top: -1px; margin-bottom: 0; font-size: 8pt;">
        <thead>
          <tr>
            <th colspan="5" style="text-align: left; padding: 4px; background: #ffffff;">Tenant List</th>
          </tr>
          <tr>
            <th style="width: 8%;">No.</th>
            <th style="width: 25%;">Tenant</th>
            <th style="width: 25%;">Equipment Type</th>
            <th style="width: 20%;">Sisa Token</th>
            <th style="width: 22%;">Power Source</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td class="text-center">1</td>
            <td class="text-center">APSD</td>
            <td class="text-center">-</td>
            <td class="text-center">-</td>
            <td class="text-center">PLN</td>
          </tr>
          <tr>
            <td class="text-center">2</td>
            <td></td><td></td><td></td><td></td>
          </tr>
          <tr>
            <td class="text-center">3</td>
            <td></td><td></td><td></td><td></td>
          </tr>
          <tr>
            <td class="text-center">4</td>
            <td></td><td></td><td></td><td></td>
          </tr>
          <tr>
            <td class="text-center">dst</td>
            <td></td><td></td><td></td><td></td>
          </tr>
        </tbody>
      </table>

      <!-- OM Vendor Box -->
      <table class="table-bordered" style="margin-top: -1px; font-size: 8.5pt;">
        <tr>
          <td style="width: 25%; font-weight: bold;">OM Vendor Name</td>
          <td style="width: 75%;">: <?= htmlspecialchars($site['om_vendor'] ?? 'PT. Naer Tunas Indonesia') ?></td>
        </tr>
      </table>

    </div>

    <!-- ==========================================
         HALAMAN 2: SITE INFO CHECKLIST (GROUNDING, TOWER, LIGHTING, FENCE)
         ========================================== -->
    <div class="print-page">
      <table class="table-bordered" style="margin-bottom: 0;">
        <tr>
          <td style="width: 22%; text-align: center; vertical-align: middle; padding: 4px;">
            <img src="assets/img/logo_ias.png" alt="IAS Support" style="height: 32px; width: auto; max-width: 100%;">
          </td>
          <td style="width: 56%; text-align: center; vertical-align: middle; padding: 2px;">
            <div style="font-size: 8pt; font-weight: bold;">PT IAS SUPPORT INDONESIA</div>
            <div style="font-size: 8pt; font-weight: bold;">CHECK LIST PREVENTIVE MAINTANANCE</div>
            <div style="font-size: 8pt; font-weight: bold;">MECHANICAL ELECTRICAL</div>
            <div style="font-size: 7.5pt; font-weight: bold; background: #e2e8f0;">SITE INFORMATION</div>
          </td>
          <td style="width: 22%; font-size: 7.5pt; padding: 4px;">
            <strong>DATE :</strong> <?= $dateAbbr ?>
          </td>
        </tr>
      </table>

      <!-- Meta Bar -->
      <table class="table-bordered" style="margin-top: -1px; margin-bottom: 0; font-size: 7.5pt;">
        <tr>
          <td style="width: 20%; font-weight: bold;">APSD Site ID</td>
          <td style="width: 30%;">: <?= htmlspecialchars($site['site_id_str'] ?? '0') ?></td>
          <td style="width: 15%; font-weight: bold;">Date</td>
          <td style="width: 35%;">: <?= $formattedDate ?></td>
        </tr>
        <tr>
          <td style="font-weight: bold;">APSD Site Name</td>
          <td>: <?= htmlspecialchars($site['site_name'] ?? '') ?></td>
          <td style="font-weight: bold;">Time</td>
          <td>: <?= htmlspecialchars($report['inspection_time'] ?? '10.25') ?> &nbsp; <span class="circle-wrap"><?= htmlspecialchars($report['timezone'] ?? 'WIB') ?></span>/WITA/WIT</td>
        </tr>
        <tr>
          <td style="font-weight: bold;">PIC Field Name</td>
          <td>: <?= htmlspecialchars($report['inspector_name']) ?></td>
          <td style="font-weight: bold;">PIC Field Number</td>
          <td>: <?= htmlspecialchars($report['inspector_phone'] ?? '') ?></td>
        </tr>
      </table>

      <!-- GROUNDING -->
      <table class="table-bordered" style="margin-top: -1px; margin-bottom: 0; font-size: 7.5pt;">
        <tr style="background: #e2e8f0; font-weight: bold;"><td colspan="6" class="text-center">GROUNDING</td></tr>
        <tr>
          <td style="width: 35%;">Grounding Measurment</td>
          <td colspan="5">: Ohm (&lt; 1 Ohm)</td>
        </tr>
        <tr>
          <td style="padding-left: 15px;">Grounding Control Box</td>
          <td style="width: 15%;">: <?= htmlspecialchars($grounding['measurement']['control_box'] ?? '0.24') ?></td>
          <td colspan="4">Ohm (&lt; 1 Ohm)</td>
        </tr>
        <tr>
          <td style="padding-left: 15px;">Tower Leg</td>
          <td>: <?= htmlspecialchars($grounding['measurement']['tower_leg'] ?? '0.26') ?></td>
          <td colspan="4">Ohm (&lt; 1 Ohm)</td>
        </tr>
        <tr>
          <td style="padding-left: 15px;">Fence</td>
          <td>: <?= htmlspecialchars($grounding['measurement']['fence'] ?? '0') ?></td>
          <td colspan="4">Ohm (&lt; 1 Ohm)</td>
        </tr>
        <tr>
          <td style="padding-left: 15px;">Grounding EGB</td>
          <td>: <?= htmlspecialchars($grounding['measurement']['egb'] ?? '0.20') ?></td>
          <td colspan="4">Ohm (&lt; 1 Ohm)</td>
        </tr>
        <tr style="font-weight: bold;"><td colspan="6">Grounding Status :</td></tr>
        <?php 
          $gStatus = [
            'lightening_rod' => 'Lightening Rod',
            'bar_grounding_tower' => 'Bar Grounding Tower',
            'bar_grounding_control_box' => 'Bar Grounding Control Box',
            'down_conductor_cable' => 'Down Conductor Cable',
            'bolt_connection' => 'Bolt Connection'
          ];
          foreach ($gStatus as $gk => $glabel):
            $st = $grounding['status'][$gk]['status'] ?? 'OK';
            $rmk = $grounding['status'][$gk]['remarks'] ?? '';
        ?>
          <tr>
            <td style="padding-left: 15px;"><?= $glabel ?></td>
            <td colspan="5">
              : 
              <span class="<?= ($st==='OK')?'circle-wrap':'' ?>">OK</span> &nbsp;
              <span class="<?= ($st==='NOK')?'circle-wrap':'' ?>">NOK</span> &nbsp;
              <span class="<?= ($st==='NA')?'circle-wrap':'' ?>">N/A</span> &nbsp;&nbsp;&nbsp;&nbsp;
              Remaks : ( uncompleted condition / stolen / broken / worse connection ) <?= htmlspecialchars($rmk) ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>

      <!-- TOWER -->
      <table class="table-bordered" style="margin-top: -1px; margin-bottom: 0; font-size: 7.5pt;">
        <tr style="background: #e2e8f0; font-weight: bold;"><td colspan="2" class="text-center">TOWER</td></tr>
        <tr>
          <td style="width: 35%;">Tower Type</td>
          <td>: SST &nbsp; Monopole &nbsp; Triangle &nbsp; <span class="circle-wrap">Pole</span> &nbsp; Mini Tower</td>
        </tr>
        <tr>
          <td>Painting Condition</td>
          <td>: <span class="circle-wrap">OK</span> NOK N/A &nbsp;&nbsp;&nbsp;&nbsp; Remaks : ( tower loosen paint 70 % ), .............</td>
        </tr>
        <tr>
          <td>Contruction Condition</td>
          <td>: <span class="circle-wrap">OK</span> NOK &nbsp;&nbsp;&nbsp;&nbsp; Remaks : ( paving down / tower leg cracked), ........</td>
        </tr>
        <tr>
          <td>Tower Cleaning</td>
          <td>: <span class="circle-wrap">OK</span> NOK &nbsp;&nbsp;&nbsp;&nbsp; Remaks : ( cleaning from spider nest/bee/etc ), .............</td>
        </tr>
        <tr>
          <td>Tower Lamp/OBL</td>
          <td>: OK NOK <span class="circle-wrap">N/A</span> &nbsp;&nbsp;&nbsp;&nbsp; Remaks : ( broken, cracked, unshielded), ........</td>
        </tr>
        <tr>
          <td>Photo Sensor</td>
          <td>: OK NOK <span class="circle-wrap">N/A</span> &nbsp;&nbsp;&nbsp;&nbsp; Remaks : ( broken, cracked, unshielded), ........</td>
        </tr>
        <tr>
          <td>Tower Verticality</td>
          <td>: <span class="circle-wrap">OK</span> NOK N/A</td>
        </tr>
        <tr>
          <td>Pole Sticker</td>
          <td>: OK NOK <span class="circle-wrap">N/A</span></td>
        </tr>
        <tr>
          <td>Baseplate Leg Painting</td>
          <td>: OK NOK <span class="circle-wrap">N/A</span></td>
        </tr>
      </table>

      <!-- LIGHTING, FENCE & OTHER -->
      <table class="table-bordered" style="margin-top: -1px; font-size: 7.5pt;">
        <tr style="background: #e2e8f0; font-weight: bold;"><td colspan="2" class="text-center">LIGHTING</td></tr>
        <tr><td style="width: 35%;">Tower Lamp(OBL) Amount</td><td>: 0 Unit</td></tr>
        <tr><td>Yard Lamp Amount (Mercury/TL)</td><td>: 1 Unit</td></tr>
        <tr><td>Tower Lamp(OBL) Condition</td><td>: OK NOK <span class="circle-wrap">N/A</span> &nbsp;&nbsp;&nbsp;&nbsp; Remaks : ( broken, cracked, unshielded)</td></tr>
        <tr><td>Site Photo Sensor</td><td>: OK NOK <span class="circle-wrap">N/A</span> &nbsp;&nbsp;&nbsp;&nbsp; Remaks : ( sensor respon checking)</td></tr>
        <tr><td>Yard Lamp Shield</td><td>: <span class="circle-wrap">OK</span> NOK N/A &nbsp;&nbsp;&nbsp;&nbsp; Remaks : (lamp shield checking broken / no )</td></tr>

        <tr style="background: #e2e8f0; font-weight: bold;"><td colspan="2" class="text-center">FENCE</td></tr>
        <tr><td>Fence Condition</td><td>: OK NOK <span class="circle-wrap">N/A</span> &nbsp;&nbsp;&nbsp;&nbsp; Remaks : ( collapsed, broken )</td></tr>
        <tr><td>Site Cleaning</td><td>: <span class="circle-wrap">OK</span> NOK &nbsp;&nbsp;&nbsp;&nbsp; Remaks : ( cleaning from grass/garbage )</td></tr>
        <tr><td>Paving Block/Gravel</td><td>: <span class="circle-wrap">OK</span> NOK &nbsp;&nbsp;&nbsp;&nbsp; Remaks : ...................................</td></tr>
        <tr><td>Barbared Wire</td><td>: OK NOK <span class="circle-wrap">N/A</span></td></tr>
        <tr><td>Fance Door</td><td>: OK NOK <span class="circle-wrap">N/A</span></td></tr>

        <tr style="background: #e2e8f0; font-weight: bold;"><td colspan="2" class="text-center">OTHER</td></tr>
        <tr><td>Safety Sign Board</td><td>: OK NOK <span class="circle-wrap">N/A</span> &nbsp;&nbsp;&nbsp;&nbsp; Remaks : ( uninstalled, undetected )</td></tr>
        <tr><td>Warning Sign Board</td><td>: OK NOK <span class="circle-wrap">N/A</span> &nbsp;&nbsp;&nbsp;&nbsp; Remaks : ( uninstalled, undetected )</td></tr>
        <tr><td>Combination Lock/Padlock</td><td>: OK NOK <span class="circle-wrap">N/A</span> &nbsp;&nbsp;&nbsp;&nbsp; Remaks : ( uninstalled, undetected )</td></tr>
        <tr><td>Site Keeper Status</td><td>: OK NOK <span class="circle-wrap">N/A</span></td></tr>
        <tr><td>Notification Sticker</td><td>: OK NOK <span class="circle-wrap">N/A</span></td></tr>
      </table>
    </div>

    <!-- ==========================================
         HALAMAN 3: BASE SERVICE (STRUCTURAL INSPECTION & SIGNATURE)
         ========================================== -->
    <div class="print-page">
      <table class="table-bordered" style="margin-bottom: 0;">
        <tr>
          <td style="width: 22%; text-align: center; vertical-align: middle; padding: 4px;">
            <img src="assets/img/logo_ias.png" alt="IAS Support" style="height: 32px; width: auto; max-width: 100%;">
          </td>
          <td style="width: 56%; text-align: center; vertical-align: middle; padding: 2px;">
            <div style="font-size: 8pt; font-weight: bold;">PT IAS SUPPORT INDONESIA</div>
            <div style="font-size: 8pt; font-weight: bold;">CHECK LIST PREVENTIVE MAINTANANCE</div>
            <div style="font-size: 8pt; font-weight: bold;">MECHANICAL ELECTRICAL</div>
            <div style="font-size: 7.5pt; font-weight: bold; background: #e2e8f0;">BASE SERVICE</div>
          </td>
          <td style="width: 22%; font-size: 7.5pt; padding: 4px;">
            <strong>DATE :</strong> <?= $dateAbbr ?>
          </td>
        </tr>
      </table>

      <!-- Meta Bar -->
      <table class="table-bordered" style="margin-top: -1px; margin-bottom: 0; font-size: 7.5pt;">
        <tr>
          <td style="width: 20%; font-weight: bold;">APSD Site ID</td>
          <td style="width: 30%;">: <?= htmlspecialchars($site['site_id_str'] ?? '0') ?></td>
          <td style="width: 15%; font-weight: bold;">Date</td>
          <td style="width: 35%;">: <?= $formattedDate ?></td>
        </tr>
        <tr>
          <td style="font-weight: bold;">APSD Site Name</td>
          <td>: <?= htmlspecialchars($site['site_name'] ?? '') ?></td>
          <td style="font-weight: bold;">Time</td>
          <td>: <?= htmlspecialchars($report['inspection_time'] ?? '10.25') ?> &nbsp; <span class="circle-wrap"><?= htmlspecialchars($report['timezone'] ?? 'WIB') ?></span>/WITA/WIT</td>
        </tr>
        <tr>
          <td style="font-weight: bold;">PIC Field Name</td>
          <td>: <?= htmlspecialchars($report['inspector_name']) ?></td>
          <td style="font-weight: bold;">PIC Field Number</td>
          <td>: <?= htmlspecialchars($report['inspector_phone'] ?? '') ?></td>
        </tr>
      </table>

      <!-- Structural Details -->
      <table class="table-bordered" style="margin-top: -1px; font-size: 7.5pt;">
        <tr style="background: #e2e8f0; font-weight: bold;"><td colspan="2" class="text-center">OTHER</td></tr>
        
        <!-- Members Completeness -->
        <tr>
          <td style="width: 38%; font-weight: bold;">MEMBERS COMPLETENESS</td>
          <td>: <span class="circle-wrap">Complate/lengkap</span> &nbsp;&nbsp; Not complate/tidak lengkap &nbsp;&nbsp;&nbsp;&nbsp; At elevation/pada ketinggian ...... Meter</td>
        </tr>
        <tr>
          <td style="padding-left: 12px;">Need future investigation/butuh investigasi lebih lanjut ?</td>
          <td>: Yes &nbsp;&nbsp; <span class="circle-wrap">No</span> &nbsp;&nbsp;&nbsp;&nbsp; Remaks/Keterangan :</td>
        </tr>

        <!-- Tower Corrosion -->
        <tr>
          <td style="font-weight: bold;">TOWER CORROSION/KOROSI PADA TOWER</td>
          <td>: <span class="circle-wrap">No</span> &nbsp;&nbsp; Yes,minor &nbsp;&nbsp; Yes,major</td>
        </tr>
        <tr>
          <td style="padding-left: 12px;">Need future investigation/butuh investigasi lebih lanjut ?</td>
          <td>: Yes &nbsp;&nbsp; <span class="circle-wrap">No</span> &nbsp;&nbsp;&nbsp;&nbsp; Remaks/Keterangan :</td>
        </tr>

        <!-- Tower Alignment -->
        <tr>
          <td style="font-weight: bold;">TOWER ALIGMENT/KETEGAKAN TOWER</td>
          <td>: <span class="circle-wrap">Align and vertical/simetris & tegak lurus</span> &nbsp;&nbsp; Not Align & not vertical/tidak tegak lurus</td>
        </tr>
        <tr>
          <td style="padding-left: 12px;">Need future investigation/butuh investigasi lebih lanjut ?</td>
          <td>: Yes &nbsp;&nbsp; <span class="circle-wrap">No</span> &nbsp;&nbsp;&nbsp;&nbsp; Remaks/Keterangan :</td>
        </tr>

        <!-- Pedestal & Baseplate -->
        <tr>
          <td style="font-weight: bold;">PEDESTAL & BASEPLATE CONDITION/KONDISI PONDASI & BASEPLATE</td>
          <td>: <span class="circle-wrap">Good/bagus</span> &nbsp;&nbsp; Cracked/retak &nbsp;&nbsp; Corrosion/Baseplate berkarat</td>
        </tr>
        <tr>
          <td style="padding-left: 12px;">Need future investigation/butuh investigasi lebih lanjut ?</td>
          <td>: Yes &nbsp;&nbsp; <span class="circle-wrap">No</span> &nbsp;&nbsp;&nbsp;&nbsp; Remaks/Keterangan :</td>
        </tr>

        <!-- Soil & Gravel -->
        <tr>
          <td style="font-weight: bold;">SOIL & GRAVEL CONDITION INSIDE & AROUND SITE AREA/KONDISI TANAH DI DALAM & DI LUAR AREA</td>
          <td>: <span class="circle-wrap">Normal</span> &nbsp;&nbsp; Landslide/penurunan &nbsp;&nbsp; Flooded/tergenang &nbsp;&nbsp; Washed away/tergerus</td>
        </tr>
        <tr>
          <td style="padding-left: 12px;">Need future investigation/butuh investigasi lebih lanjut ?</td>
          <td>: Yes &nbsp;&nbsp; <span class="circle-wrap">No</span> &nbsp;&nbsp;&nbsp;&nbsp; Remaks/Keterangan :</td>
        </tr>

        <!-- Building Existing -->
        <tr>
          <td style="font-weight: bold;">BUILDING EXISTING CONDITION/KONDISI BANGUNAN EXISTING</td>
          <td>: Normal &nbsp;&nbsp; Cracked/retak &nbsp;&nbsp; Leakage/Bocor &nbsp;&nbsp; <span class="circle-wrap">Other/lainya : Site GF</span></td>
        </tr>
        <tr>
          <td style="padding-left: 12px;">Need future investigation/butuh investigasi lebih lanjut ?</td>
          <td>: Yes &nbsp;&nbsp; <span class="circle-wrap">No</span> &nbsp;&nbsp;&nbsp;&nbsp; Remaks/Keterangan :</td>
        </tr>

        <!-- Other Info -->
        <tr>
          <td style="font-weight: bold;">Other Information :</td>
          <td style="min-height: 40px;">-</td>
        </tr>
      </table>

      <!-- Signature Box -->
      <div style="margin-top: 40px; display: flex; justify-content: flex-end;">
        <table class="table-bordered" style="width: 220px; text-align: center; font-size: 8pt;">
          <tr><td style="font-weight: bold; background: #e2e8f0;"><?= htmlspecialchars($structural['inspection_company'] ?? 'PT.NAER') ?></td></tr>
          <tr><td style="font-weight: bold; font-size: 7.5pt;">INSPECTION BY</td></tr>
          <tr><td style="height: 55px; vertical-align: middle; font-style: italic; color: #555;">( Signed )</td></tr>
          <tr><td style="font-weight: bold;">(<?= htmlspecialchars($structural['inspection_person'] ?? 'HAMDHANI') ?>)</td></tr>
        </table>
      </div>

    </div>

    <!-- ==========================================
         HALAMAN 4: TENANT (AC, SHELTER, ALARM, GROUNDING, LISTRIK)
         ========================================== -->
    <div class="print-page">
      <table class="table-bordered" style="margin-bottom: 0;">
        <tr>
          <td style="width: 22%; text-align: center; vertical-align: middle; padding: 4px;">
            <img src="assets/img/logo_ias.png" alt="IAS Support" style="height: 32px; width: auto; max-width: 100%;">
          </td>
          <td style="width: 56%; text-align: center; vertical-align: middle; padding: 2px;">
            <div style="font-size: 8pt; font-weight: bold;">PT IAS SUPPORT INDONESIA</div>
            <div style="font-size: 8pt; font-weight: bold;">CHECK LIST PREVENTIVE MAINTANANCE</div>
            <div style="font-size: 8pt; font-weight: bold;">MECHANICAL ELECTRICAL</div>
            <div style="font-size: 7.5pt; font-weight: bold; background: #e2e8f0;">TENANT</div>
          </td>
          <td style="width: 22%; font-size: 7.5pt; padding: 4px;">
            <strong>DATE :</strong> <?= $dateAbbr ?>
          </td>
        </tr>
      </table>

      <table class="table-bordered" style="margin-top: -1px; margin-bottom: 0; font-size: 7pt;">
        <tr>
          <td style="width: 20%; font-weight: bold;">APSD Site ID</td>
          <td style="width: 30%;">: <?= htmlspecialchars($site['site_id_str'] ?? '0') ?></td>
          <td style="width: 15%; font-weight: bold;">Date</td>
          <td style="width: 35%;">: <?= $formattedDate ?></td>
        </tr>
        <tr>
          <td style="font-weight: bold;">APSD Site Name</td>
          <td>: <?= htmlspecialchars($site['site_name'] ?? '') ?></td>
          <td style="font-weight: bold;">Time</td>
          <td>: <?= htmlspecialchars($report['inspection_time'] ?? '10.25') ?> &nbsp; <span class="circle-wrap"><?= htmlspecialchars($report['timezone'] ?? 'WIB') ?></span>/WITA/WIT</td>
        </tr>
        <tr>
          <td colspan="2"></td>
          <td style="font-weight: bold;">Operator</td>
          <td>: APSD</td>
        </tr>
      </table>

      <!-- Air Conditioner -->
      <table class="table-bordered" style="margin-top: -1px; margin-bottom: 0; font-size: 7pt;">
        <tr style="background: #e2e8f0; font-weight: bold;"><td colspan="6" class="text-center">AIR CONDITIONER</td></tr>
        <tr>
          <td style="width: 25%;">Equipment type</td>
          <td colspan="5">: Indoor &nbsp; <span class="circle-wrap">Outdoor</span> &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; AC Amount : - Unit</td>
        </tr>
        <tr style="font-weight: bold; text-align: center; background: #fafafa;">
          <td>Measurment</td><td>Remaks</td><td>AC # 1</td><td>AC # 2</td><td>AC # 3</td><td>AC # 4</td>
        </tr>
        <tr><td>Broken AC</td><td>Ac Status</td><td>Yes &nbsp; <span class="circle-wrap">No</span></td><td>Yes &nbsp; <span class="circle-wrap">No</span></td><td>Yes &nbsp; <span class="circle-wrap">No</span></td><td>Yes &nbsp; <span class="circle-wrap">No</span></td></tr>
        <tr><td>AC merek</td><td>Brand name</td><td>-</td><td>-</td><td>-</td><td>-</td></tr>
        <tr><td>AC Type</td><td>Type</td><td>Inverter &nbsp; Non inverter</td><td>Inverter &nbsp; Non inverter</td><td>Inverter &nbsp; Non inverter</td><td>Inverter &nbsp; Non inverter</td></tr>
        <tr><td>AC Outdoor Condition</td><td>Leak/not leak</td><td>OK NOK <span class="circle-wrap">N/A</span></td><td>OK NOK <span class="circle-wrap">N/A</span></td><td>OK NOK <span class="circle-wrap">N/A</span></td><td>OK NOK <span class="circle-wrap">N/A</span></td></tr>
        <tr><td>AC Indoor Condition</td><td>Leak/not leak</td><td>OK NOK <span class="circle-wrap">N/A</span></td><td>OK NOK <span class="circle-wrap">N/A</span></td><td>OK NOK <span class="circle-wrap">N/A</span></td><td>OK NOK <span class="circle-wrap">N/A</span></td></tr>
        <tr><td>Pipe Condition</td><td>Leak/not leak</td><td>OK NOK <span class="circle-wrap">N/A</span></td><td>OK NOK <span class="circle-wrap">N/A</span></td><td>OK NOK <span class="circle-wrap">N/A</span></td><td>OK NOK <span class="circle-wrap">N/A</span></td></tr>
        <tr><td>Room Temperator</td><td>... C</td><td>OK NOK <span class="circle-wrap">N/A</span></td><td>OK NOK <span class="circle-wrap">N/A</span></td><td>OK NOK <span class="circle-wrap">N/A</span></td><td>OK NOK <span class="circle-wrap">N/A</span></td></tr>
      </table>

      <!-- Exhaust Fan & Shelter & Alarm -->
      <table class="table-bordered" style="margin-top: -1px; margin-bottom: 0; font-size: 7pt;">
        <tr style="background: #e2e8f0; font-weight: bold;"><td colspan="4" class="text-center">EXHAUST FAN & SHALTER</td></tr>
        <tr>
          <td style="width: 25%;">Exhaust fan condition</td>
          <td style="width: 25%;">: OK NOK <span class="circle-wrap">N/A</span></td>
          <td style="width: 25%;">Exhaust fan cleaning</td>
          <td style="width: 25%;">: OK NOK <span class="circle-wrap">N/A</span></td>
        </tr>
        <tr><td>Shalter roof</td><td>: OK NOK <span class="circle-wrap">N/A</span></td><td>Wall shalter</td><td>: OK NOK <span class="circle-wrap">N/A</span></td></tr>
        <tr><td>Fence condition</td><td>: OK NOK <span class="circle-wrap">N/A</span></td><td>Room cleaning</td><td>: <span class="circle-wrap">OK</span> NOK</td></tr>
        <tr><td>Roof cleaning</td><td>: <span class="circle-wrap">OK</span> NOK</td><td>Fire extinguisher</td><td>: OK NOK <span class="circle-wrap">N/A</span></td></tr>

        <tr style="background: #e2e8f0; font-weight: bold;"><td colspan="4" class="text-center">ALARM SYSTEM & GROUNDING</td></tr>
        <tr><td>Heat detector</td><td>: OK NOK <span class="circle-wrap">N/A</span></td><td>Smoke detector</td><td>: OK NOK <span class="circle-wrap">N/A</span></td></tr>
        <tr><td>Ring bell</td><td>: OK NOK <span class="circle-wrap">N/A</span></td><td>Grounding status</td><td>: <span class="circle-wrap">OK</span> NOK N/A</td></tr>
        <tr><td>Control box</td><td>: <span class="circle-wrap">OK</span> NOK N/A (&lt; 1 Ohm)</td><td>External grounding bar</td><td>: <span class="circle-wrap">OK</span> NOK N/A (&lt; 1 Ohm)</td></tr>
      </table>

      <!-- KWH & PLN -->
      <table class="table-bordered" style="margin-top: -1px; font-size: 7pt;">
        <tr style="background: #e2e8f0; font-weight: bold;"><td colspan="4" class="text-center">KWH ELECTRICAL MEASURMENT & PLN CHECK</td></tr>
        <tr><td>Phase voltage R-N (200 V - 240 V)</td><td>: 0 &nbsp; (220 V +/- 5% max 10 V)</td><td>Phase voltage R-T (200 V - 240 V)</td><td>: 0 &nbsp; (380 V +/- 5% max 10 V)</td></tr>
        <tr><td>Phase voltage S-N (200 V - 240 V)</td><td>: 0 &nbsp; (220 V +/- 5% max 10 V)</td><td>Phase voltage S-T (200 V - 240 V)</td><td>: 0 &nbsp; (380 V +/- 5% max 10 V)</td></tr>
        <tr><td>Phase voltage T-N (200 V - 240 V)</td><td>: 0 &nbsp; (220 V +/- 5% max 10 V)</td><td>Phase voltage N-G (&lt; 5V)</td><td>: 0</td></tr>
        <tr><td>Phase voltage R-S (200 V - 240 V)</td><td>: 0 &nbsp; (380 V +/- 5% max 10 V)</td><td>KWH Meter Record</td><td>: 0 kWh</td></tr>
        <tr><td>PLN power supplied</td><td>: kVA ....... Phase</td><td>MCB Condition</td><td>: <span class="circle-wrap">OK</span> NOK N/A</td></tr>
        <tr><td>MCB input 1 Phase</td><td>: A ....... Pcs</td><td>MCB input 3 Phase</td><td>: A ....... Pcs</td></tr>
      </table>
    </div>

    <!-- ==========================================
         HALAMAN 5: TENANT (PLN FUNCTION TEST & RE-FUELLING)
         ========================================== -->
    <div class="print-page">
      <table class="table-bordered" style="margin-bottom: 0;">
        <tr>
          <td style="width: 22%; text-align: center; vertical-align: middle; padding: 4px;">
            <img src="assets/img/logo_ias.png" alt="IAS Support" style="height: 32px; width: auto; max-width: 100%;">
          </td>
          <td style="width: 56%; text-align: center; vertical-align: middle; padding: 2px;">
            <div style="font-size: 8pt; font-weight: bold;">PT IAS SUPPORT INDONESIA</div>
            <div style="font-size: 8pt; font-weight: bold;">CHECK LIST PREVENTIVE MAINTANANCE</div>
            <div style="font-size: 8pt; font-weight: bold;">MECHANICAL ELECTRICAL</div>
            <div style="font-size: 7.5pt; font-weight: bold; background: #e2e8f0;">TENANT</div>
          </td>
          <td style="width: 22%; font-size: 7.5pt; padding: 4px;">
            <strong>DATE :</strong> <?= $dateAbbr ?>
          </td>
        </tr>
      </table>

      <table class="table-bordered" style="margin-top: -1px; margin-bottom: 0; font-size: 7.5pt;">
        <tr>
          <td style="width: 20%; font-weight: bold;">APSD Site ID</td>
          <td style="width: 30%;">: <?= htmlspecialchars($site['site_id_str'] ?? '0') ?></td>
          <td style="width: 15%; font-weight: bold;">Date</td>
          <td style="width: 35%;">: <?= $formattedDate ?></td>
        </tr>
        <tr>
          <td style="font-weight: bold;">APSD Site Name</td>
          <td>: <?= htmlspecialchars($site['site_name'] ?? '') ?></td>
          <td style="font-weight: bold;">Time</td>
          <td>: <?= htmlspecialchars($report['inspection_time'] ?? '10.25') ?> &nbsp; <span class="circle-wrap"><?= htmlspecialchars($report['timezone'] ?? 'WIB') ?></span>/WITA/WIT</td>
        </tr>
        <tr>
          <td colspan="2"></td>
          <td style="font-weight: bold;">Operator</td>
          <td>: APSD</td>
        </tr>
      </table>

      <table class="table-bordered" style="margin-top: -1px; font-size: 8pt;">
        <tr><td style="width: 35%;">PLN ID</td><td>: -</td></tr>
        <tr><td>Spill kit (sawdust)</td><td>: OK NOK <span class="circle-wrap">N/A</span></td></tr>
        <tr><td>Seal Locked genset</td><td>: OK NOK <span class="circle-wrap">N/A</span></td></tr>
        <tr><td>KWH record</td><td>: <span class="circle-wrap">OK</span> NOK N/A</td></tr>
        <tr><td>MSDS fuel genset and oil</td><td>: OK NOK <span class="circle-wrap">N/A</span></td></tr>
        <tr><td>Terminal/OKA</td><td>: <span class="circle-wrap">OK</span> NOK N/A</td></tr>
        <tr><td>MCB</td><td>: <span class="circle-wrap">OK</span> NOK N/A</td></tr>
        <tr><td>Generator anchored to foundation</td><td>: OK NOK <span class="circle-wrap">N/A</span></td></tr>
        <tr><td>Trafo</td><td>: <span class="circle-wrap">OK</span> NOK N/A &nbsp;&nbsp; (if trafo broken/has been shared with community/other operator)</td></tr>

        <tr style="background: #e2e8f0; font-weight: bold;"><td colspan="2" class="text-center">PLN FUNCTION TEST</td></tr>
        <tr style="font-weight: bold;"><td colspan="2">MCB in the KWH 'ON' Position</td></tr>
        <tr>
          <td style="padding-left: 15px;">A. Current flows to the MCCB for devices</td>
          <td>: <span class="circle-wrap">OK</span> NOK N/A &nbsp;&nbsp;&nbsp;&nbsp; ( Current measurement result = visual check )</td>
        </tr>
        <tr>
          <td style="padding-left: 15px;">B. R,S, & T indicator lamps are lit</td>
          <td>: OK NOK <span class="circle-wrap">N/A</span></td>
        </tr>
        <tr>
          <td>MCB for devices to ' ON Position'</td>
          <td>: <span class="circle-wrap">OK</span> NOK N/A &nbsp;&nbsp;&nbsp;&nbsp; ( MCB in position )</td>
        </tr>
        <tr>
          <td>KWH meter spin normally</td>
          <td>: <span class="circle-wrap">OK</span> NOK N/A &nbsp;&nbsp;&nbsp;&nbsp; ( Spin normally )</td>
        </tr>
      </table>
    </div>

    <!-- ==========================================
         HALAMAN 6 s/d 13: DOKUMENTASI FOTO (GRUP A s/d H)
         ========================================== -->
    <?php foreach ($photoGroups as $grpKey => $grp): ?>
      <div class="print-page">
        
        <!-- Header Foto Tiap Halaman -->
        <table class="photo-page-header">
          <tr>
            <td style="width: 20%; text-align: center; vertical-align: middle; padding: 4px;">
              <img src="assets/img/logo_ias.png" alt="IAS Support" style="height: 30px; width: auto; max-width: 100%;">
            </td>
            <td style="width: 60%; text-align: center; vertical-align: middle; padding: 2px;">
              <div style="font-size: 8pt; font-weight: bold;">PT IAS SUPPORT INDONESIA</div>
              <div style="font-size: 8.5pt; font-weight: bold;">CHECK LIST PREVENTIVE MAINTANANCE</div>
              <div style="font-size: 8pt; font-weight: bold;">MECHANICAL ELECTRICAL</div>
              <div style="font-size: 7.5pt; font-weight: bold; background: #e2e8f0;">BASE SERVICE</div>
            </td>
            <td style="width: 20%; text-align: center; vertical-align: middle; padding: 4px;">
              <?php if ($showRightLogo): ?>
                <img src="<?= htmlspecialchars($rightLogoPath) ?>?v=2" alt="Logo Tenant" style="max-height: 28px; width: auto; max-width: 100%; object-fit: contain;">
              <?php else: ?>
                <span style="font-weight: bold; font-size: 8pt;"><?= htmlspecialchars($report['operator'] ?: '') ?></span>
              <?php endif; ?>
            </td>
          </tr>
        </table>

        <!-- Metadata Sub Header -->
        <table class="table-bordered" style="margin-top: -1px; margin-bottom: 6px; font-size: 7.5pt;">
          <tr>
            <td style="width: 20%; font-weight: bold;">APSD Site ID</td>
            <td style="width: 30%;">: <?= htmlspecialchars($site['site_id_str'] ?? 'APSD-DSER-078-08') ?></td>
            <td style="width: 15%; font-weight: bold;">Date</td>
            <td style="width: 35%;">: <?= $dateAbbr ?></td>
          </tr>
          <tr>
            <td style="font-weight: bold;">APSD Site Name</td>
            <td>: <?= htmlspecialchars($site['site_name'] ?? 'Bandara Kualanamu Pole 2') ?></td>
            <td style="font-weight: bold;">Time</td>
            <td>: <?= htmlspecialchars($report['inspection_time'] ?? '10.25') ?> &nbsp; <span class="circle-wrap"><?= htmlspecialchars($report['timezone'] ?? 'WIB') ?></span>/WITA/WIT</td>
          </tr>
          <tr>
            <td colspan="2"></td>
            <td style="font-weight: bold;">Operator</td>
            <td>: <?= htmlspecialchars($report['operator'] ?: 'APSD') ?></td>
          </tr>
        </table>

        <!-- 2x3 Grid Foto (6 Slot per halaman) -->
        <div class="photo-grid-container">
          <?php foreach ($grp['slots'] as $slotCode => $slotDef): ?>
            <?php 
              $p = $photos[$slotCode] ?? null;
              $hasImage = ($p && !empty($p['image_path']) && file_exists(__DIR__ . '/' . $p['image_path']));
              $isNa = (!$p || $p['is_na'] || !$hasImage);
              $meas = $p['measurement_value'] ?? ($slotDef['default_label'] ?? '');
            ?>
            <div class="photo-cell">
              <div class="photo-cell-image-wrapper">
                <?php if ($hasImage): ?>
                  <img src="<?= htmlspecialchars($p['image_path']) ?>" alt="<?= htmlspecialchars($slotDef['title']) ?>">
                <?php else: ?>
                  <div class="photo-cell-na">N/A</div>
                <?php endif; ?>
              </div>
              <div class="photo-cell-footer">
                <span class="photo-cell-code">Foto <?= htmlspecialchars($slotCode) ?></span>
                <span class="photo-cell-desc">
                  <?= htmlspecialchars($slotDef['title']) ?>
                  <?php if (!empty($meas) && $slotDef['has_measurement']): ?>
                    (<?= htmlspecialchars($meas) ?>)
                  <?php endif; ?>
                </span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

      </div>
    <?php endforeach; ?>

  </div>

</body>
</html>
