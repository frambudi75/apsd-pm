<?php
/**
 * Headless PDF Generation & Direct Download
 * Uses Microsoft Edge CLI (Built-in on Windows)
 * PT Angkasa Pura Sarana Digital
 */
require_once __DIR__ . '/config/database.php';
$pdo = get_db_connection();

$report_id = intval($_GET['id'] ?? 0);
if ($report_id <= 0) {
    die("ID Laporan tidak valid.");
}

$stmt = $pdo->prepare("SELECT r.*, s.site_name FROM pm_reports r LEFT JOIN sites s ON r.site_id = s.id WHERE r.id = ?");
$stmt->execute([$report_id]);
$report = $stmt->fetch();
if (!$report) {
    die("Laporan tidak ditemukan.");
}

$isIndoor = ($report['report_type'] === 'indoor');
$printFile = $isIndoor ? 'print_indoor.php' : 'print_outdoor.php';

// Check available Edge binary paths
$edgePaths = [
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
];

$browserBin = null;
foreach ($edgePaths as $p) {
    if (file_exists($p)) {
        $browserBin = $p;
        break;
    }
}

// Fallback if no browser binary: redirect to print view
if (!$browserBin) {
    header("Location: {$printFile}?id={$report_id}&autoprint=1");
    exit;
}

// Prepare temporary PDF output
$tempDir = __DIR__ . '/uploads/temp';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
}

$pdfFilename = ($isIndoor ? 'PM_Indoor_' : 'PM_Outdoor_') . preg_replace('/[^A-Za-z0-9_\-]/', '_', $report['site_name'] ?: 'Site') . '_' . date('Ymd_His', strtotime($report['report_date'])) . '.pdf';
$pdfPath = $tempDir . '/' . $pdfFilename;

// Construct local URL
$host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
$url = "http://{$host}/apsd/{$printFile}?id={$report_id}";

// Execute headless print-to-pdf command
$cmd = sprintf(
    '"%s" --headless --disable-gpu --no-pdf-header-footer --print-to-pdf="%s" "%s"',
    $browserBin,
    $pdfPath,
    $url
);

@exec($cmd, $output, $returnCode);

if (file_exists($pdfPath) && filesize($pdfPath) > 1000) {
    // Deliver PDF file for direct download
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $pdfFilename . '"');
    header('Content-Length: ' . filesize($pdfPath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    readfile($pdfPath);
    @unlink($pdfPath);
    exit;
} else {
    // If command timed out or failed, redirect directly to browser print view
    header("Location: {$printFile}?id={$report_id}");
    exit;
}
