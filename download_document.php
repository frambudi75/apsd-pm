<?php
/**
 * Unduh / Pratinjau Dokumen Pribadi & Berbagi (Document Vault Download)
 * IASS SBU ICT KNO
 * 
 * Aturan Keamanan & Privasi:
 * 1. Setiap pengunduh wajib terautentikasi (login aktif).
 * 2. Hanya pemilik berkas, pengguna yang dibagikan secara eksplisit, atau anggota tim
 *    (jika dokumen disetel ke 'team') yang berhak mengunduh.
 * 3. Super Admin TIDAK berhak melihat atau mengunduh berkas orang lain kecuali
 *    pemilik berkas telah membagikannya kepadanya.
 * 4. Berkas terkompresi secara otomatis didekompresi transparan ke format aslinya.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

start_secure_session();

if (!is_logged_in()) {
    $baseUrl = get_app_base_url();
    header('Location: ' . $baseUrl . 'login?expired=1');
    exit;
}

$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$docId = (int)($_GET['id'] ?? 0);
$isInlineView = (isset($_GET['view']) && in_array(strtolower((string)$_GET['view']), ['1', 'true', 'inline'], true));

if ($docId <= 0) {
    http_response_code(400);
    die('ID dokumen tidak valid.');
}

$pdo = get_db_connection();

// 1. Fetch document metadata
$stmt = $pdo->prepare("
    SELECT d.*, u.full_name as owner_name, u.username as owner_username
    FROM user_documents d
    JOIN users u ON d.user_id = u.id
    WHERE d.id = ?
");
$stmt->execute([$docId]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    die('Dokumen tidak ditemukan di sistem.');
}

// 2. Strict Access Control (Owner, Team, or Explicitly Shared User)
// IMPORTANT: Superadmin is NOT granted access by default if not shared!
$hasAccess = false;
$isOwner = ((int)$doc['user_id'] === $userId);

if ($isOwner) {
    $hasAccess = true;
} elseif ($doc['privacy_type'] === 'team') {
    $hasAccess = true;
} else {
    // Check if explicitly shared with this user in user_document_shares
    $stmtShare = $pdo->prepare("
        SELECT COUNT(*) FROM user_document_shares 
        WHERE document_id = ? AND shared_with_user_id = ?
    ");
    $stmtShare->execute([$docId, $userId]);
    if ($stmtShare->fetchColumn() > 0) {
        $hasAccess = true;
    }
}

if (!$hasAccess) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Akses Ditolak - Brankas Dokumen</title>
      <style>
        body {
          margin: 0;
          padding: 30px;
          font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
          background: #f1f5f9;
          color: #0f172a;
          display: flex;
          align-items: center;
          justify-content: center;
          min-height: 80vh;
        }
        .card {
          background: #ffffff;
          max-width: 460px;
          width: 100%;
          padding: 36px 30px;
          border-radius: 14px;
          box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
          text-align: center;
          border: 1px solid #e2e8f0;
        }
        .icon {
          width: 64px;
          height: 64px;
          border-radius: 50%;
          background: #fee2e2;
          color: #ef4444;
          display: flex;
          align-items: center;
          justify-content: center;
          margin: 0 auto 20px;
          font-size: 30px;
        }
        h2 {
          margin: 0 0 12px;
          font-size: 1.35rem;
          color: #991b1b;
        }
        p {
          margin: 0 0 24px;
          font-size: 0.92rem;
          line-height: 1.55;
          color: #475569;
        }
        .btn {
          display: inline-block;
          padding: 10px 22px;
          background: #008080;
          color: #ffffff;
          text-decoration: none;
          font-weight: 600;
          font-size: 0.9rem;
          border-radius: 8px;
          transition: background 0.15s ease;
        }
        .btn:hover {
          background: #006666;
        }
      </style>
    </head>
    <body>
      <div class="card">
        <div class="icon">🔒</div>
        <h2>Akses Dokumen Dibatasi</h2>
        <p>
          Dokumen ini bersifat pribadi milik <strong><?= htmlspecialchars($doc['owner_name'] ?: $doc['owner_username']) ?></strong>.<br>
          Anda belum memiliki izin akses sampai pemilik berkas membagikannya kepada Anda.
        </p>
        <a href="documents" class="btn">Kembali ke Brankas Dokumen</a>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// 3. Locate physical file on server
$filePath = __DIR__ . '/uploads/documents/' . $doc['stored_filename'];

if (!file_exists($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    die('Berkas dokumen tidak ditemukan di penyimpanan server.');
}

// 4. Read and Decompress
$fileRaw = file_get_contents($filePath);
if ($fileRaw === false) {
    http_response_code(500);
    die('Gagal membaca berkas dari penyimpanan.');
}

$outputBytes = $fileRaw;
if (!empty($doc['is_compressed'])) {
    $decompressed = @gzdecode($fileRaw);
    if ($decompressed !== false) {
        $outputBytes = $decompressed;
    }
}

// 5. Determine inline preview or download attachment
$ext = strtolower($doc['file_ext'] ?? pathinfo($doc['original_filename'], PATHINFO_EXTENSION));
$previewableExts = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'txt'];

$disposition = 'attachment';
if ($isInlineView && in_array($ext, $previewableExts, true)) {
    $disposition = 'inline';
}

$origFilename = $doc['original_filename'];
$cleanFilename = preg_replace('/[^\w\.\-\(\)\s]/u', '_', $origFilename);
$mimeType = $doc['mime_type'] ?: 'application/octet-stream';

// If inline SVG, ensure standard SVG header
if ($ext === 'svg') {
    $mimeType = 'image/svg+xml';
} elseif ($ext === 'pdf') {
    $mimeType = 'application/pdf';
}

// Clean output buffer to prevent corrupted binary downloads
if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: ' . $mimeType);
header('Content-Disposition: ' . $disposition . '; filename="' . $cleanFilename . '"; filename*=UTF-8\'\'' . rawurlencode($origFilename));
header('Content-Length: ' . strlen($outputBytes));
header('Content-Transfer-Encoding: binary');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('Expires: 0');

echo $outputBytes;
exit;
