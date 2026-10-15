<?php
/**
 * API Save PM Outdoor / Pole Report
 * IASS SBU ICT KNO
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/image_compressor.php';
$pdo = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Extract General Form Data
    $site_id = !empty($_POST['site_id']) ? intval($_POST['site_id']) : null;
    $site_id_str = trim($_POST['site_id_str'] ?? '0');
    $site_name = trim($_POST['site_name'] ?? '');
    $location_detail = trim($_POST['location_detail'] ?? $_POST['address'] ?? $_POST['city'] ?? '');
    $report_date = $_POST['report_date'] ?? date('Y-m-d');
    $inspection_time = trim($_POST['inspection_time'] ?? '10.25');
    $timezone = trim($_POST['timezone'] ?? 'WIB');
    $inspector_name = trim($_POST['inspector_name'] ?? 'Hamdani');
    $inspector_phone = trim($_POST['inspector_phone'] ?? '');
    $period = trim($_POST['period'] ?? 'Jul-25');
    $region = trim($_POST['region'] ?? 'Sumbagut');
    $province = trim($_POST['province'] ?? 'Sumatra Utara');
    $city = trim($_POST['city'] ?? 'Medan');
    $address = trim($_POST['address'] ?? '');
    $latitude = trim($_POST['latitude'] ?? '3.618278');
    $longitude = trim($_POST['longitude'] ?? '98.861139');
    $site_height = trim($_POST['site_height'] ?? '18');
    $tower_type = trim($_POST['tower_type'] ?? 'Pole PJU 18 m');
    $site_type = trim($_POST['site_type'] ?? 'Green Field');
    $anchor_tenant = trim($_POST['anchor_tenant'] ?? 'IASS SBU ICT KNO');
    $om_vendor = trim($_POST['om_vendor'] ?? 'PT. Naer Tunas Indonesia');
    $operator = trim($_POST['operator'] ?? 'IASS SBU ICT KNO');
    $preset_logo = trim($_POST['preset_logo'] ?? 'ias');

    // 2. Insert into pm_reports
    $stmt = $pdo->prepare("
        INSERT INTO pm_reports (
            report_type, site_id, form_no, effective_date, period, 
            report_date, inspection_time, timezone, inspector_name, inspector_phone,
            team_leader, om_vendor, operator, status
        ) VALUES (
            'outdoor', ?, '1', '16-Jul-25', ?, 
            ?, ?, ?, ?, ?,
            '', ?, ?, 'completed'
        )
    ");
    $stmt->execute([
        $site_id, $period,
        $report_date, $inspection_time, $timezone, $inspector_name, $inspector_phone,
        $om_vendor, $operator
    ]);
    $report_id = $pdo->lastInsertId();

    // Determine custom logo path
    $custom_logo_path = 'assets/img/logo_ias.png';
    if ($preset_logo === 'telkomsel') {
        $custom_logo_path = 'assets/img/logo_telkomsel.png';
    } elseif ($preset_logo === 'indosat') {
        $custom_logo_path = 'assets/img/logo_indosat.svg';
    } elseif ($preset_logo === 'xl') {
        $custom_logo_path = 'assets/img/logo_xl.svg';
    } elseif ($preset_logo === 'none') {
        $custom_logo_path = 'none';
    }

    if (isset($_FILES['custom_logo_file']) && $_FILES['custom_logo_file']['error'] === UPLOAD_ERR_OK) {
        $logoDir = __DIR__ . "/../uploads/reports/{$report_id}";
        if (!is_dir($logoDir)) mkdir($logoDir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['custom_logo_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg'])) {
            $dest = "{$logoDir}/provider_logo.{$ext}";
            if (move_uploaded_file($_FILES['custom_logo_file']['tmp_name'], $dest)) {
                $custom_logo_path = "uploads/reports/{$report_id}/provider_logo.{$ext}";
            }
        }
    }

    $pdo->prepare("UPDATE pm_reports SET custom_logo_path = ? WHERE id = ?")->execute([$custom_logo_path, $report_id]);

    // 3. Snapshot Site Data
    $siteSnapshot = [
        'site_id_str' => $site_id_str,
        'site_name' => $site_name,
        'region' => $region,
        'province' => $province,
        'city' => $city,
        'address' => $address,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'site_height' => $site_height,
        'tower_type' => $tower_type,
        'site_type' => $site_type,
        'anchor_tenant' => $anchor_tenant,
        'total_tenant' => 1,
        'om_vendor' => $om_vendor
    ];

    // Checklist & measurement groups
    $groundingMeas = $_POST['grounding_meas'] ?? [];
    $groundingStatus = $_POST['grounding_status'] ?? [];
    $groundingData = [
        'measurement' => $groundingMeas,
        'status' => $groundingStatus
    ];

    $siteCondition = $_POST['site_condition'] ?? [];
    $structural = $_POST['structural'] ?? [];
    $structuralData = [
        'items' => $structural,
        'inspection_company' => trim($_POST['inspection_company'] ?? 'PT.NAER'),
        'inspection_person' => trim($_POST['inspection_person'] ?? 'HAMDHANI')
    ];

    $stmtDetails = $pdo->prepare("
        INSERT INTO pm_outdoor_details (
            report_id, site_snapshot_json, grounding_json, tower_json, structural_json
        ) VALUES (?, ?, ?, ?, ?)
    ");
    $stmtDetails->execute([
        $report_id,
        json_encode($siteSnapshot, JSON_UNESCAPED_UNICODE),
        json_encode($groundingData, JSON_UNESCAPED_UNICODE),
        json_encode($siteCondition, JSON_UNESCAPED_UNICODE),
        json_encode($structuralData, JSON_UNESCAPED_UNICODE)
    ]);

    // 4. Handle Photos
    $uploadDir = __DIR__ . "/../uploads/reports/{$report_id}";
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }
    @chmod($uploadDir, 0777);

    $photosMeta = $_POST['photos'] ?? [];
    $stmtPhoto = $pdo->prepare("
        INSERT INTO pm_photos (report_id, slot_code, title, image_path, measurement_value, is_na, page_group)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($photosMeta as $slotCode => $meta) {
        $title = isset($meta['title']) ? trim($meta['title']) : '';
        $measurement = $meta['measurement'] ?? null;
        $isNa = isset($meta['is_na']) ? 1 : 0;
        $imagePath = null;
        $pageGroup = substr($slotCode, 0, 1);

        $targetFile = "{$uploadDir}/photo_{$slotCode}.jpg";
        $watermarkInfo = [
            'date' => date('d/m/Y H:i:s', strtotime($report_date . ' ' . date('H:i:s'))) . ' WIB',
            'site' => $site_name ?: 'Site KNO',
            'location' => $location_detail,
            'gps' => (!empty($_POST['latitude']) && !empty($_POST['longitude'])) 
                ? "Lat: {$_POST['latitude']}, Long: {$_POST['longitude']}" 
                : 'GPS: Terdeteksi',
            'slot' => $slotCode,
            'title' => $title
        ];

        // 1. Check if client sent base64 image (from client-side canvas watermark)
        $base64Data = $_POST['photo_base64'][$slotCode] ?? '';
        $isClientWatermarked = ($_POST['photo_watermarked'][$slotCode] ?? '0') === '1';

        if (!$isNa && $isClientWatermarked && !empty($base64Data) && strpos($base64Data, 'data:image') === 0) {
            $parts = explode(',', $base64Data);
            $decoded = base64_decode($parts[1] ?? '');
            if (!empty($decoded)) {
                file_put_contents($targetFile, $decoded);
                $imagePath = "uploads/reports/{$report_id}/photo_{$slotCode}.jpg";
            }
        }

        // 2. Check file upload (from gallery input or native camera input) with server-side watermark
        $hasPhoto = isset($_FILES['photo_files']['name'][$slotCode]) && $_FILES['photo_files']['error'][$slotCode] === UPLOAD_ERR_OK;
        $hasCamera = isset($_FILES['camera_files']['name'][$slotCode]) && $_FILES['camera_files']['error'][$slotCode] === UPLOAD_ERR_OK;

        if (!$imagePath && !$isNa && ($hasPhoto || $hasCamera)) {
            $fileSource = $hasPhoto ? $_FILES['photo_files'] : $_FILES['camera_files'];
            $tmpName = $fileSource['tmp_name'][$slotCode];
            $ext = strtolower(pathinfo($fileSource['name'][$slotCode], PATHINFO_EXTENSION));
            $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']) || (@getimagesize($tmpName) !== false);
            if ($isImg) {
                if (compress_and_save_image($tmpName, $targetFile, 1600, 85, $watermarkInfo)) {
                    $imagePath = "uploads/reports/{$report_id}/photo_{$slotCode}.jpg";
                } elseif (move_uploaded_file($tmpName, $targetFile)) {
                    compress_and_save_image($targetFile, $targetFile, 1600, 85, $watermarkInfo);
                    $imagePath = "uploads/reports/{$report_id}/photo_{$slotCode}.jpg";
                }
            }
        } elseif (!$imagePath && !$isNa && !empty($base64Data) && strpos($base64Data, 'data:image') === 0) {
            // Client sent unwatermarked base64 fallback: burn watermark on server
            $parts = explode(',', $base64Data);
            $decoded = base64_decode($parts[1] ?? '');
            if (!empty($decoded)) {
                file_put_contents($targetFile, $decoded);
                compress_and_save_image($targetFile, $targetFile, 1600, 85, $watermarkInfo);
                $imagePath = "uploads/reports/{$report_id}/photo_{$slotCode}.jpg";
            }
        }

        // If no file was uploaded and not explicitly marked N/A, mark as N/A
        if (empty($imagePath)) {
            $isNa = 1;
        }

        $stmtPhoto->execute([
            $report_id,
            $slotCode,
            $title,
            $imagePath,
            $measurement,
            $isNa,
            $pageGroup
        ]);
    }

    $pdo->commit();

    // Invalidate Redis cache
    require_once __DIR__ . '/../config/redis.php';
    AppRedis::flushPrefix('dashboard:');
    AppRedis::del('reports:all');

    // Redirect to the outdoor print/preview page
    $compactParam = (!empty($_POST['compact_pdf'])) ? '&compact=1' : '';
    header("Location: ../print_outdoor.php?id={$report_id}&new=1{$compactParam}");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("Gagal menyimpan laporan Outdoor: " . htmlspecialchars($e->getMessage()));
}
