<?php
/**
 * Seeder to insert the 2 original sample reports
 * Matching the 2 source PDFs
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/checklist_data.php';
$pdo = get_db_connection();

// Check if reports already exist
$count = $pdo->query("SELECT COUNT(*) FROM pm_reports")->fetchColumn();
if ($count > 0) {
    echo "Reports already seeded.\n";
    exit;
}

$indoorDefs = get_indoor_checklist_definitions();
$indoorPhotos = get_indoor_photo_slots();
$outdoorPhotos = get_outdoor_photo_slots();

// ==========================================
// SEED REPORT 1: PM INDOOR (Telkomsel Server Domestik)
// ==========================================
$stmt = $pdo->prepare("
    INSERT INTO pm_reports (
        id, report_type, site_id, form_no, effective_date, period, 
        report_date, inspector_name, team_leader, om_vendor, operator, status
    ) VALUES (
        1, 'indoor', 1, '1', '01-Jul-25', 'Harian / Daily',
        '2025-07-25', 'Juli', '', 'PT. Naer Tunas Indonesia', 'TELKOMSEL', 'completed'
    )
");
$stmt->execute();

// Indoor site snapshot
$inSite = [
    'site_name' => 'IBC Bandara International KUALANAMU',
    'location_detail' => 'Server Domestik',
    'region' => 'Deli Serdang',
    'province' => 'Sumatra Utara',
    'city' => 'Medan',
    'address' => 'Jl. Bandara Kuala Namu, Ps. Enam Kuala Namu, Kec. Beringin, Kabupaten Deli Serdang, Sumatera Utara',
    'site_height' => '-',
    'tower_type' => 'Non Pole',
    'site_type' => 'Indoor'
];

$inChecklist = [];
foreach ($indoorDefs as $secKey => $sec) {
    foreach ($sec['items'] as $itemNo => $item) {
        $inChecklist["chk_{$secKey}_{$itemNo}"] = [
            'status' => 'OK',
            'name' => $item['name'],
            'ref' => $item['ref'],
            'note' => ''
        ];
    }
}

$stmtDet = $pdo->prepare("
    INSERT INTO pm_indoor_details (report_id, site_snapshot_json, checklist_json)
    VALUES (1, ?, ?)
");
$stmtDet->execute([json_encode($inSite), json_encode($inChecklist)]);

// Indoor photos
$stmtP = $pdo->prepare("
    INSERT INTO pm_photos (report_id, slot_code, title, image_path, measurement_value, is_na, page_group)
    VALUES (1, ?, ?, ?, ?, ?, ?)
");

foreach ($indoorPhotos as $grpKey => $grp) {
    foreach ($grp['slots'] as $slotCode => $slot) {
        $filePath = "uploads/reports/1/photo_{$slotCode}.jpg";
        $hasFile = file_exists(__DIR__ . '/../' . $filePath);
        $meas = $slot['default_label'] ?? null;
        $stmtP->execute([
            $slotCode,
            $slot['title'],
            $hasFile ? $filePath : null,
            $meas,
            $hasFile ? 0 : 1,
            $grpKey
        ]);
    }
}
echo "Sample Report 1 (Indoor) Seeded.\n";

// ==========================================
// SEED REPORT 2: PM OUTDOOR (Pole 2 Bandara Kualanamu)
// ==========================================
$stmt2 = $pdo->prepare("
    INSERT INTO pm_reports (
        id, report_type, site_id, form_no, effective_date, period, 
        report_date, inspection_time, timezone, inspector_name, inspector_phone,
        team_leader, om_vendor, operator, status
    ) VALUES (
        2, 'outdoor', 2, '1', '16-Jul-25', 'Jul-25',
        '2025-07-16', '10.25', 'WIB', 'Hamdani', '',
        '', 'PT. Naer Tunas Indonesia', 'APSD', 'completed'
    )
");
$stmt2->execute();

$outSite = [
    'site_id_str' => 'APSD-DSER-078-08',
    'site_name' => 'Bandara Kualanamu Pole 2',
    'region' => 'Sumbagut',
    'province' => 'Sumatra Utara',
    'city' => 'Medan',
    'address' => 'Jl. Bandara Kuala Namu, Ps. Enam Kuala Namu, Kec. Beringin, Kabupaten Deli Serdang, Sumatera Utara 20553',
    'latitude' => '3.618278',
    'longitude' => '98.861139',
    'site_height' => '18',
    'tower_type' => 'Pole PJU 18 m',
    'site_type' => 'Green Field',
    'anchor_tenant' => 'APSD',
    'total_tenant' => 1,
    'om_vendor' => 'PT. Naer Tunas Indonesia'
];

$outGrounding = [
    'measurement' => [
        'control_box' => '0.24',
        'tower_leg' => '0.26',
        'fence' => '0',
        'egb' => '0.20'
    ],
    'status' => [
        'lightening_rod' => ['status' => 'OK', 'remarks' => ''],
        'bar_grounding_tower' => ['status' => 'OK', 'remarks' => ''],
        'bar_grounding_control_box' => ['status' => 'OK', 'remarks' => ''],
        'down_conductor_cable' => ['status' => 'OK', 'remarks' => ''],
        'bolt_connection' => ['status' => 'OK', 'remarks' => '']
    ]
];

$outStructural = [
    'items' => [
        'members' => ['val' => 'Complete/lengkap', 'need_inv' => 'No'],
        'corrosion' => ['val' => 'No', 'need_inv' => 'No'],
        'alignment' => ['val' => 'Align and vertical/simetris & tegak lurus', 'need_inv' => 'No'],
        'pedestal' => ['val' => 'Good/bagus', 'need_inv' => 'No'],
        'soil' => ['val' => 'Normal', 'need_inv' => 'No'],
        'building' => ['val' => 'Other/lainya : Site GF', 'need_inv' => 'No']
    ],
    'inspection_company' => 'PT.NAER',
    'inspection_person' => 'HAMDHANI'
];

$stmtDet2 = $pdo->prepare("
    INSERT INTO pm_outdoor_details (report_id, site_snapshot_json, grounding_json, structural_json)
    VALUES (2, ?, ?, ?)
");
$stmtDet2->execute([
    json_encode($outSite),
    json_encode($outGrounding),
    json_encode($outStructural)
]);

// Outdoor photos
$stmtP2 = $pdo->prepare("
    INSERT INTO pm_photos (report_id, slot_code, title, image_path, measurement_value, is_na, page_group)
    VALUES (2, ?, ?, ?, ?, ?, ?)
");

foreach ($outdoorPhotos as $grpKey => $grp) {
    foreach ($grp['slots'] as $slotCode => $slot) {
        $filePath = "uploads/reports/2/photo_{$slotCode}.jpg";
        $hasFile = file_exists(__DIR__ . '/../' . $filePath);
        $meas = $slot['default_label'] ?? null;
        $stmtP2->execute([
            $slotCode,
            $slot['title'],
            $hasFile ? $filePath : null,
            $meas,
            $hasFile ? 0 : 1,
            $grpKey
        ]);
    }
}

echo "Sample Report 2 (Outdoor) Seeded.\n";
