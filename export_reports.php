<?php
/**
 * Export PM Reports to Excel-Compatible CSV
 * PT Angkasa Pura Sarana Digital - IASS SBU ICT KNO
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/checklist_data.php';

require_login();
$pdo = get_db_connection();

$typeFilter = trim($_GET['type'] ?? 'all');
$siteFilter = trim($_GET['site'] ?? 'all');
$monthFilter = trim($_GET['month'] ?? 'all');
$statusFilter = trim($_GET['status'] ?? 'all');

$sql = "
    SELECT r.*, s.site_name, s.location_detail, s.region, s.city,
           ind.checklist_json as indoor_checklist,
           outd.grounding_json, outd.tower_json, outd.lighting_fence_other_json, outd.structural_json,
           outd.tenant_ac_json, outd.tenant_electrical_json, outd.pln_function_json
    FROM pm_reports r
    LEFT JOIN sites s ON r.site_id = s.id
    LEFT JOIN pm_indoor_details ind ON ind.report_id = r.id
    LEFT JOIN pm_outdoor_details outd ON outd.report_id = r.id
    WHERE 1=1
";
$params = [];

if ($typeFilter === 'indoor' || $typeFilter === 'outdoor') {
    $sql .= " AND r.report_type = ?";
    $params[] = $typeFilter;
}

$sql .= " ORDER BY r.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll();

// Client-side / Advanced filtering in PHP (matching reports.php logic)
$filtered = [];
foreach ($reports as $r) {
    $siteName = $r['site_name'] ?: (($r['report_type'] === 'indoor') ? 'IBC Bandara International Kualanamu' : 'Bandara Kualanamu Pole ' . ($r['site_id'] ?: '1'));
    if ($siteFilter !== 'all' && $siteName !== $siteFilter) {
        continue;
    }

    $rMonth = date('Y-m', strtotime($r['report_date']));
    if ($monthFilter === 'current_month') {
        if ($rMonth !== date('Y-m')) continue;
    } elseif ($monthFilter !== 'all') {
        if ($rMonth !== $monthFilter) continue;
    }

    $findings = get_report_findings_summary($r);
    $r['findings'] = $findings;

    if ($statusFilter === 'ok' && $findings['has_nok']) {
        continue;
    }
    if ($statusFilter === 'nok' && !$findings['has_nok']) {
        continue;
    }

    $r['site_display_name'] = $siteName;
    $filtered[] = $r;
}

// Generate Excel-compatible CSV with UTF-8 BOM
$filename = 'Rekap_PM_KNO_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// UTF-8 BOM for Microsoft Excel compatibility
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// CSV Header
fputcsv($output, [
    'No',
    'ID Dokumen',
    'Tanggal PM',
    'Tipe Pemeliharaan',
    'Operator / Tenant',
    'Nama Site',
    'Lokasi Detail',
    'Kota / Wilayah',
    'Petugas Teknisi PIC',
    'Leader / Approver',
    'Status Temuan',
    'Rincian Temuan'
], ';');

$no = 1;
foreach ($filtered as $row) {
    $rType = ($row['report_type'] === 'indoor') ? 'PM Indoor IBC (7 Hal)' : 'PM Outdoor Pole/Tower (13 Hal)';
    $op = !empty($row['operator']) ? $row['operator'] : (!empty($row['operator_name']) ? $row['operator_name'] : 'Telkomsel');
    $statusText = $row['findings']['has_nok'] ? 'Ada Temuan (NOK)' : 'Normal (OK)';
    $nokItems = !empty($row['findings']['nok_items']) ? implode(', ', $row['findings']['nok_items']) : 'Tidak ada anomali';

    fputcsv($output, [
        $no++,
        'PM-' . str_pad($row['id'], 5, '0', STR_PAD_LEFT),
        date('d/m/Y', strtotime($row['report_date'])),
        $rType,
        $op,
        $row['site_display_name'],
        $row['location_detail'] ?: '-',
        ($row['city'] ? $row['city'] . ', ' : '') . ($row['region'] ?: 'Deli Serdang'),
        $row['inspector_name'] ?: '-',
        $row['approver_name'] ?: '-',
        $statusText,
        $nokItems
    ], ';');
}

fclose($output);
exit;
