<?php
/**
 * Headless PDF Generation & Direct Download
 * Supports Linux / Docker Chromium and Windows Edge/Chrome
 * Includes seamless client-side html2pdf fallback (no preview loops)
 * PT Angkasa Pura Sarana Digital
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
$pdo = get_db_connection();

$shift_id = intval($_GET['shift_id'] ?? 0);
$month = trim($_GET['month'] ?? '');
$report_id = intval($_GET['id'] ?? 0);

if ($shift_id > 0) {
    $stmtShift = $pdo->prepare("SELECT * FROM daily_shifts WHERE id = ?");
    $stmtShift->execute([$shift_id]);
    $shiftData = $stmtShift->fetch();
    if (!$shiftData) {
        die("Shift tidak ditemukan.");
    }
    $printFile = 'print_shift.php';
    $pdfFilename = 'Logbook_NOC_' . ucfirst($shiftData['shift_type']) . '_' . $shiftData['shift_date'] . '.pdf';
    $idParam = "shift_id={$shift_id}";
    $targetId = (string)$shift_id;
    $targetPrintUrl = "print_shift.php?shift_id={$shift_id}";
} elseif (!empty($month) && preg_match('/^\d{4}-\d{2}$/', $month)) {
    $printFile = 'print_monthly_logbook.php';
    $pdfFilename = 'Rekap_Logbook_NOC_' . $month . '.pdf';
    $idParam = "month=" . urlencode($month);
    $targetId = $month;
    $targetPrintUrl = "print_monthly_logbook.php?month=" . urlencode($month);
} elseif ($report_id > 0) {
    $stmt = $pdo->prepare("SELECT r.*, s.site_name FROM pm_reports r LEFT JOIN sites s ON r.site_id = s.id WHERE r.id = ?");
    $stmt->execute([$report_id]);
    $report = $stmt->fetch();
    if (!$report) {
        die("Laporan tidak ditemukan.");
    }
    $isIndoor = ($report['report_type'] === 'indoor');
    $printFile = $isIndoor ? 'print_indoor.php' : 'print_outdoor.php';
    $isCompact = (isset($_GET['compact']) && $_GET['compact'] == '1');
    $pdfFilename = ($isIndoor ? 'PM_Indoor_' : 'PM_Outdoor_') . preg_replace('/[^A-Za-z0-9_\-]/', '_', $report['site_name'] ?: 'Site') . '_' . date('Ymd_His', strtotime($report['report_date'])) . '.pdf';
    $idParam = "id={$report_id}" . ($isCompact ? '&compact=1' : '');
    $targetId = (string)$report_id;
    $targetPrintUrl = "{$printFile}?id={$report_id}" . ($isCompact ? '&compact=1' : '');
} else {
    die("Parameter laporan tidak valid.");
}

// Candidate headless browser binary paths (Chrome prioritized on Windows)
$browserCandidates = [
    // Windows 64-bit Google Chrome (verified high performance)
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    // Linux / Debian / Docker
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

// If headless binary exists, attempt server-side PDF generation
if ($browserBin) {
    $tempDir = __DIR__ . '/uploads/temp';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0777, true);
    }
    $pdfPath = $tempDir . '/' . $pdfFilename;

    // Detect internal base URL with secure signed token
    $localPort = $_SERVER['SERVER_PORT'] ?? '80';
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $basePath = ($scriptDir === '/' || $scriptDir === '\\') ? '' : rtrim(str_replace('\\', '/', $scriptDir), '/');
    $pdfToken = hash_hmac('sha256', $targetId, 'apsd_internal_secret_2026');
    $internalUrl = "http://127.0.0.1:{$localPort}{$basePath}/{$printFile}?{$idParam}&pdf_token={$pdfToken}";

    $profileDir = $tempDir . '/chrome_headless_profile';
    $cmd = sprintf(
        '"%s" --headless=new --user-data-dir="%s" --no-sandbox --disable-dev-shm-usage --disable-gpu --no-pdf-header-footer --print-to-pdf="%s" "%s"',
        $browserBin,
        $profileDir,
        $pdfPath,
        $internalUrl
    );

    @exec($cmd, $output, $returnCode);

    // Wait up to 6 seconds for headless browser to finish writing the PDF file (> 5KB)
    $maxWait = 6.0;
    $waited = 0.0;
    while ($waited < $maxWait) {
        clearstatcache(true, $pdfPath);
        if (file_exists($pdfPath) && filesize($pdfPath) > 5000) {
            break;
        }
        usleep(300000); // 300ms
        $waited += 0.3;
    }

    if (file_exists($pdfPath) && filesize($pdfPath) > 5000) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $pdfFilename . '"');
        header('Content-Length: ' . filesize($pdfPath));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        readfile($pdfPath);
        @unlink($pdfPath);
        exit;
    }
}

// Standalone automated client-side downloader page (guarantees direct file download on phones & PCs without preview loops)
$targetPrintUrl = "{$printFile}?id={$report_id}" . ($isCompact ? '&compact=1' : '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mengunduh PDF - <?= htmlspecialchars($pdfFilename) ?></title>
  <style>
    * { box-sizing: border-box; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background: #0f172a;
      color: #f8fafc;
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      margin: 0;
      padding: 1rem;
    }
    .card {
      background: #1e293b;
      border: 1px solid #334155;
      border-radius: 14px;
      padding: 2.25rem 1.75rem;
      max-width: 440px;
      width: 100%;
      text-align: center;
      box-shadow: 0 12px 30px rgba(0,0,0,0.4);
    }
    .spinner {
      border: 4px solid rgba(255,255,255,0.1);
      border-left-color: #38bdf8;
      border-radius: 50%;
      width: 46px;
      height: 46px;
      animation: spin 1s linear infinite;
      margin: 0 auto 1.5rem auto;
    }
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    .btn {
      display: inline-block;
      padding: 0.75rem 1.5rem;
      background: #0284c7;
      color: #ffffff;
      text-decoration: none;
      border-radius: 8px;
      font-weight: 600;
      font-size: 0.95rem;
      border: none;
      cursor: pointer;
      min-height: 44px;
    }
    .btn-secondary {
      background: #334155;
      color: #e2e8f0;
    }
  </style>
  <script src="assets/js/html2pdf.bundle.min.js"></script>
</head>
<body>

  <div class="card">
    <div id="loader_spinner" class="spinner"></div>
    <h3 id="status_title" style="margin: 0 0 0.5rem 0; font-size: 1.25rem; font-weight: 700;">Menyiapkan File PDF...</h3>
    <p id="status_desc" style="color: #94a3b8; font-size: 0.9rem; margin: 0; line-height: 1.5;">
      Dokumen sedang diproses dan akan terunduh otomatis ke perangkat Anda.
    </p>
    <div id="action_container" style="display: none; margin-top: 1.5rem; gap: 0.5rem; justify-content: center; flex-wrap: wrap;">
      <a href="reports" class="btn">Kembali ke Riwayat</a>
      <a href="<?= htmlspecialchars($targetPrintUrl) ?>" class="btn btn-secondary">Buka Pratinjau Cetak</a>
    </div>
  </div>

  <iframe id="print_frame" src="<?= htmlspecialchars($targetPrintUrl) ?>" style="position: absolute; left: -9999px; top: -9999px; width: 210mm; height: 297mm; border: none;"></iframe>

  <script>
    const iframe = document.getElementById('print_frame');
    let hasDownloaded = false;

    iframe.onload = function () {
      if (hasDownloaded) return;
      hasDownloaded = true;

      setTimeout(function () {
        try {
          const frameDoc = iframe.contentDocument || iframe.contentWindow.document;
          const container = frameDoc.querySelector('.preview-container') || frameDoc.querySelector('#printable_content') || frameDoc.querySelector('.a4-sheet');
          
          if (!container || !window.html2pdf) {
            throw new Error('Elemen pratinjau tidak ditemukan');
          }

          // Enable clean export mode (zero gap, exact A4, no shadow)
          if (frameDoc.body) {
            frameDoc.body.classList.add('exporting-pdf');
          }

          const opt = {
            margin: 0,
            filename: '<?= $pdfFilename ?>',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true, logging: false },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
            pagebreak: { mode: ['avoid-all', 'css', 'legacy'], before: '.print-page' }
          };

          window.html2pdf().set(opt).from(container).save().then(function () {
            document.getElementById('loader_spinner').style.display = 'none';
            document.getElementById('status_title').innerText = '✅ Unduhan Berhasil!';
            document.getElementById('status_desc').innerText = 'File <?= htmlspecialchars($pdfFilename) ?> telah otomatis tersimpan di folder unduhan Anda.';
            document.getElementById('action_container').style.display = 'flex';
          }).catch(function (err) {
            console.error('html2pdf error:', err);
            window.location.href = '<?= $targetPrintUrl ?>';
          });
        } catch (e) {
          console.error(e);
          window.location.href = '<?= $targetPrintUrl ?>';
        }
      }, 500);
    };
  </script>

</body>
</html>
