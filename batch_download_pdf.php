<?php
/**
 * Batch Download PM Report PDFs as a Single ZIP Archive
 * PT Angkasa Pura Sarana Digital - IASS SBU ICT KNO
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();
$pdo = get_db_connection();

$idsParam = $_POST['ids'] ?? ($_GET['ids'] ?? '');
if (is_array($idsParam)) {
    $ids = array_map('intval', $idsParam);
} else {
    $ids = array_filter(array_map('intval', explode(',', (string)$idsParam)));
}

$ids = array_unique(array_filter($ids, fn($id) => $id > 0));

if (empty($ids)) {
    die("Pilih setidaknya satu laporan untuk diunduh.");
}

// Fetch report metadata
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("
    SELECT r.*, s.site_name
    FROM pm_reports r
    LEFT JOIN sites s ON r.site_id = s.id
    WHERE r.id IN ($placeholders)
    ORDER BY r.id DESC
");
$stmt->execute(array_values($ids));
$reports = $stmt->fetchAll();

if (empty($reports)) {
    die("Tidak ada laporan valid yang ditemukan.");
}

$tempDir = __DIR__ . '/uploads/temp';
if (!is_dir($tempDir)) {
    @mkdir($tempDir, 0777, true);
}

// Candidate headless browser binary paths (Chrome prioritized on Windows)
$browserCandidates = [
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    '/usr/bin/google-chrome',
    '/usr/bin/google-chrome-stable',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser'
];

$browserBin = null;
foreach ($browserCandidates as $p) {
    if (file_exists($p)) {
        $browserBin = $p;
        break;
    }
}

$internalPort = '80';
if (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] !== '443' && $_SERVER['SERVER_PORT'] !== '80') {
    $internalPort = $_SERVER['SERVER_PORT'];
}
$portSuffix = ($internalPort === '80') ? '' : ":{$internalPort}";
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
$basePath = ($scriptDir === '/' || $scriptDir === '\\') ? '' : rtrim(str_replace('\\', '/', $scriptDir), '/');
$profileDir = $tempDir . '/chrome_batch_profile';

$filesToZip = [];
$cleanupFiles = [];

foreach ($reports as $report) {
    $rId = (int)$report['id'];
    $isIndoor = ($report['report_type'] === 'indoor');
    $printFile = $isIndoor ? 'print_indoor.php' : 'print_outdoor.php';
    $siteSlug = preg_replace('/[^A-Za-z0-9_\-]/', '_', $report['site_name'] ?: 'Site');
    $pdfFilename = ($isIndoor ? 'PM_Indoor_' : 'PM_Outdoor_') . $siteSlug . '_ID' . $rId . '_' . date('Ymd', strtotime($report['report_date'])) . '.pdf';
    $pdfPath = $tempDir . '/' . $pdfFilename;

    if (!file_exists($pdfPath) || filesize($pdfPath) < 30000) {
        if ($browserBin) {
            $pdfToken = hash_hmac('sha256', (string)$rId, 'apsd_internal_secret_2026');
            $internalUrl = "http://127.0.0.1{$portSuffix}{$basePath}/{$printFile}?id={$rId}&pdf_token={$pdfToken}";

            $cmd = sprintf(
                '"%s" --headless=new --user-data-dir="%s" --no-sandbox --disable-dev-shm-usage --disable-gpu --ignore-certificate-errors --allow-insecure-localhost --no-pdf-header-footer --print-to-pdf="%s" "%s"',
                $browserBin,
                $profileDir,
                $pdfPath,
                $internalUrl
            );

            @exec($cmd);

            // Wait for file creation
            $maxWait = 4.0;
            $waited = 0.0;
            while ($waited < $maxWait) {
                clearstatcache(true, $pdfPath);
                if (file_exists($pdfPath) && filesize($pdfPath) > 30000) {
                    break;
                }
                usleep(250000);
                $waited += 0.25;
            }
        }
    }

    if (file_exists($pdfPath) && filesize($pdfPath) > 10000) {
        $filesToZip[$pdfPath] = $pdfFilename;
    }
}

if (empty($filesToZip)) {
    die("Gagal membuat berkas PDF. Pastikan browser headless atau printer PDF aktif pada server.");
}

// Generate ZIP Archive
$zipFilename = 'Laporan_PM_KNO_Batch_' . date('Ymd_His') . '.zip';
$zipPath = $tempDir . '/' . $zipFilename;

function build_zip_file($filesMap, $destinationZip) {
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($destinationZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($filesMap as $localPath => $zipName) {
                if (file_exists($localPath)) {
                    $zip->addFile($localPath, $zipName);
                }
            }
            $zip->close();
            return file_exists($destinationZip);
        }
    }

    // Fallback: PowerShell on Windows or zip command on Linux
    $tempFolder = dirname($destinationZip) . '/batch_' . uniqid();
    @mkdir($tempFolder, 0777, true);
    foreach ($filesMap as $localPath => $zipName) {
        if (file_exists($localPath)) {
            copy($localPath, $tempFolder . '/' . $zipName);
        }
    }

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $winTemp = str_replace('/', '\\', $tempFolder);
        $winDest = str_replace('/', '\\', $destinationZip);
        $cmd = "powershell -NoProfile -Command \"Compress-Archive -Path '{$winTemp}\\*' -DestinationPath '{$winDest}' -Force\"";
        @exec($cmd);
    } else {
        $cmd = "cd " . escapeshellarg($tempFolder) . " && zip -r " . escapeshellarg($destinationZip) . " .";
        @exec($cmd);
    }

    // Cleanup temp staging directory
    $staged = glob("{$tempFolder}/*");
    if ($staged) {
        foreach ($staged as $f) @unlink($f);
    }
    @rmdir($tempFolder);

    return file_exists($destinationZip) && filesize($destinationZip) > 1000;
}

$success = build_zip_file($filesToZip, $zipPath);

if ($success && file_exists($zipPath)) {
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    readfile($zipPath);
    @unlink($zipPath);
    exit;
} else {
    die("Gagal mengompres berkas PDF ke format ZIP.");
}
