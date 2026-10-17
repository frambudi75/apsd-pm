<?php
/**
 * Portal Akses Berbagi Dokumen Eksternal (Public Document Share)
 * IASS SBU ICT KNO
 * 
 * Aturan Keamanan:
 * 1. Tidak membutuhkan akun login internal APSD.
 * 2. Menggunakan token acak kriptografis 64 karakter (tidak dapat ditebak).
 * 3. Mendukung proteksi verifikasi PIN opsional (dengan rate-limiting anti brute-force).
 * 4. Mendukung validasi masa berlaku (expiration date) dan pembatalan tautan (revoke).
 * 5. Berkas fisik terenkripsi/terkompresi tetap aman di server, didekompresi secara on-the-fly.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/icons.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = get_db_connection();
$token = trim($_GET['token'] ?? '');
$action = trim($_GET['action'] ?? 'view');

// Dynamic branding from system settings
$sysAppTitle = get_system_setting('app_title', get_system_setting('company_name', 'IASS SBU ICT KNO'));
$sysAppSubtitle = get_system_setting('app_subtitle', 'Sistem PM & NOC');
$sysAppLogo = get_system_setting('app_logo_path', 'assets/img/logo_ias.png?v=3');

// Helper: Format byte sizes
function format_shared_doc_size(int $bytes): string {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . ' KB';
    }
    return $bytes . ' B';
}

// Helper: File badge extension
function get_shared_file_badge(string $ext): array {
    $ext = strtolower($ext);
    switch ($ext) {
        case 'pdf':
            return ['icon' => 'document', 'color' => '#dc2626', 'bg' => '#fee2e2', 'label' => 'PDF'];
        case 'doc':
        case 'docx':
        case 'rtf':
            return ['icon' => 'file-text', 'color' => '#2563eb', 'bg' => '#dbeafe', 'label' => strtoupper($ext)];
        case 'xls':
        case 'xlsx':
        case 'csv':
            return ['icon' => 'clipboard-list', 'color' => '#059669', 'bg' => '#d1fae5', 'label' => strtoupper($ext)];
        case 'ppt':
        case 'pptx':
            return ['icon' => 'play', 'color' => '#ea580c', 'bg' => '#ffedd5', 'label' => strtoupper($ext)];
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'webp':
        case 'gif':
            return ['icon' => 'camera', 'color' => '#7c3aed', 'bg' => '#ede9fe', 'label' => strtoupper($ext)];
        case 'zip':
        case 'rar':
        case '7z':
            return ['icon' => 'archive', 'color' => '#d97706', 'bg' => '#fef3c7', 'label' => strtoupper($ext)];
        default:
            return ['icon' => 'document', 'color' => '#008080', 'bg' => '#e6f5f5', 'label' => strtoupper($ext ?: 'FILE')];
    }
}

// Fungsi render template error
function render_share_error_page(string $title, string $message, string $iconType = 'alert-triangle') {
    global $sysAppTitle, $sysAppLogo;
    if (empty($sysAppTitle)) {
        $sysAppTitle = get_system_setting('app_title', get_system_setting('company_name', 'IASS SBU ICT KNO'));
    }
    if (empty($sysAppLogo)) {
        $sysAppLogo = get_system_setting('app_logo_path', 'assets/img/logo_ias.png?v=3');
    }
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?= htmlspecialchars($title) ?> - <?= htmlspecialchars($sysAppTitle) ?></title>
      <link rel="icon" type="image/png" href="<?= htmlspecialchars(get_app_favicon_url()) ?>">
      <link rel="shortcut icon" type="image/png" href="<?= htmlspecialchars(get_app_favicon_url()) ?>">
      <style>
        :root {
          --primary: #008080;
          --bg: #f8fafc;
          --card: #ffffff;
          --text: #0f172a;
          --muted: #64748b;
          --border: #e2e8f0;
        }
        body {
          margin: 0;
          padding: 24px;
          font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
          background: var(--bg);
          color: var(--text);
          display: flex;
          align-items: center;
          justify-content: center;
          min-height: 100vh;
          box-sizing: border-box;
        }
        .error-card {
          background: var(--card);
          border: 1px solid var(--border);
          border-radius: 16px;
          box-shadow: 0 10px 30px rgba(0, 0, 0, 0.06);
          max-width: 480px;
          width: 100%;
          padding: 40px 32px;
          text-align: center;
        }
        .error-icon {
          width: 68px;
          height: 68px;
          border-radius: 50%;
          background: #fee2e2;
          color: #dc2626;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          margin-bottom: 20px;
        }
        h2 {
          font-size: 1.35rem;
          font-weight: 800;
          margin: 0 0 10px;
          color: var(--text);
        }
        p {
          font-size: 0.92rem;
          color: var(--muted);
          line-height: 1.6;
          margin: 0 0 24px;
        }
        .brand-badge {
          display: inline-flex;
          align-items: center;
          gap: 6px;
          font-size: 0.78rem;
          font-weight: 700;
          color: var(--primary);
          background: rgba(0, 128, 128, 0.08);
          padding: 4px 12px;
          border-radius: 999px;
          margin-bottom: 16px;
        }
      </style>
    </head>
    <body>
      <div class="error-card">
        <div style="text-align: center; margin-bottom: 12px;">
          <img src="<?= htmlspecialchars($sysAppLogo) ?>" alt="<?= htmlspecialchars($sysAppTitle) ?>" style="max-height: 40px; max-width: 140px; object-fit: contain;">
        </div>
        <div class="brand-badge"><?= htmlspecialchars($sysAppTitle) ?></div>
        <div class="error-icon"><?= app_icon($iconType, 32) ?></div>
        <h2><?= htmlspecialchars($title) ?></h2>
        <p><?= nl2br(htmlspecialchars($message)) ?></p>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// 1. Validasi Token
if (empty($token) || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
    render_share_error_page(
        'Tautan Tidak Valid',
        'Tautan berbagi dokumen yang Anda buka tidak valid atau salah format. Pastikan Anda menyalin tautan secara lengkap.',
        'alert-triangle'
    );
}

// 2. Ambil data tautan & dokumen
$stmt = $pdo->prepare("
    SELECT s.*, 
           d.title, d.category, d.description, d.original_filename, d.stored_filename,
           d.file_size_raw, d.file_size_compressed, d.mime_type, d.file_ext, d.is_compressed,
           u.full_name AS owner_name, u.username AS owner_username
    FROM user_document_external_shares s
    JOIN user_documents d ON s.document_id = d.id
    JOIN users u ON d.user_id = u.id
    WHERE s.share_token = ?
    LIMIT 1
");
$stmt->execute([$token]);
$share = $stmt->fetch();

if (!$share || (int)$share['is_active'] !== 1) {
    render_share_error_page(
        'Tautan Tidak Ditemukan',
        'Tautan berbagi ini tidak ditemukan atau telah dicabut oleh pemilik dokumen.',
        'lock'
    );
}

// 3. Pemeriksaan Masa Berlaku (Expires)
if (!empty($share['expires_at'])) {
    $expTs = strtotime($share['expires_at']);
    if ($expTs < time()) {
        render_share_error_page(
            'Tautan Telah Kedaluwarsa',
            'Masa berlaku tautan berbagi ini telah habis pada ' . date('d F Y, H:i', $expTs) . ' WIB. Silakan hubungi pemilik dokumen untuk meminta tautan berbagi baru.',
            'clock'
        );
    }
}

// 4. Pemeriksaan Keamanan PIN (Jika diaktifkan)
$shareId = (int)$share['id'];
$isPinVerified = false;
$pinError = '';

if ((int)$share['has_pin'] === 1) {
    $sessionPinKey = 'verified_share_' . $shareId;
    $cooldownKey = 'pin_cooldown_' . $shareId;
    $attemptsKey = 'pin_attempts_' . $shareId;

    if (!empty($_SESSION[$sessionPinKey])) {
        $isPinVerified = true;
    }

    // Tangani proses input PIN
    if (!$isPinVerified && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_pin'])) {
        if (!empty($_SESSION[$cooldownKey]) && $_SESSION[$cooldownKey] > time()) {
            $wait = $_SESSION[$cooldownKey] - time();
            $pinError = "Terlalu banyak percobaan salah. Silakan tunggu {$wait} detik sebelum mencoba kembali.";
        } else {
            $inputPin = trim($_POST['pin'] ?? '');
            if (!empty($inputPin) && password_verify($inputPin, $share['pin_hash'])) {
                $_SESSION[$sessionPinKey] = true;
                unset($_SESSION[$attemptsKey], $_SESSION[$cooldownKey]);
                header('Location: share?token=' . urlencode($token));
                exit;
            } else {
                $attempts = ($_SESSION[$attemptsKey] ?? 0) + 1;
                $_SESSION[$attemptsKey] = $attempts;
                $rem = 5 - $attempts;
                if ($rem <= 0) {
                    $_SESSION[$cooldownKey] = time() + 300; // 5 menit cooldown
                    $_SESSION[$attemptsKey] = 0;
                    $pinError = 'PIN salah sebanyak 5 kali. Akses dikunci sementara selama 5 menit demi keamanan.';
                } else {
                    $pinError = "Kode PIN yang Anda masukkan tidak sesuai. Sisa kesempatan: {$rem} kali.";
                }
            }
        }
    }

    // Jika belum diverifikasi, tampilkan layar input PIN
    if (!$isPinVerified) {
        ?>
        <!DOCTYPE html>
        <html lang="id">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <title>Proteksi PIN Dokumen - <?= htmlspecialchars($sysAppTitle) ?></title>
          <link rel="icon" type="image/png" href="<?= htmlspecialchars(get_app_favicon_url()) ?>">
          <link rel="shortcut icon" type="image/png" href="<?= htmlspecialchars(get_app_favicon_url()) ?>">
          <style>
            :root {
              --primary: #008080;
              --primary-hover: #006666;
              --bg: #f8fafc;
              --card: #ffffff;
              --text: #0f172a;
              --muted: #64748b;
              --border: #e2e8f0;
            }
            body {
              margin: 0;
              padding: 24px;
              font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
              background: var(--bg);
              color: var(--text);
              display: flex;
              align-items: center;
              justify-content: center;
              min-height: 100vh;
              box-sizing: border-box;
            }
            .pin-card {
              background: var(--card);
              border: 1px solid var(--border);
              border-radius: 18px;
              box-shadow: 0 16px 40px rgba(0, 0, 0, 0.08);
              max-width: 440px;
              width: 100%;
              padding: 40px 32px;
              box-sizing: border-box;
            }
            .pin-header {
              text-align: center;
              margin-bottom: 24px;
            }
            .pin-icon {
              width: 64px;
              height: 64px;
              border-radius: 50%;
              background: rgba(0, 128, 128, 0.1);
              color: var(--primary);
              display: inline-flex;
              align-items: center;
              justify-content: center;
              margin-bottom: 16px;
            }
            h2 {
              font-size: 1.3rem;
              font-weight: 800;
              margin: 0 0 6px;
              color: var(--text);
            }
            .pin-sub {
              font-size: 0.85rem;
              color: var(--muted);
              line-height: 1.5;
            }
            .doc-preview-pill {
              background: var(--bg);
              border: 1px solid var(--border);
              border-radius: 10px;
              padding: 12px 14px;
              margin: 20px 0;
              display: flex;
              align-items: center;
              gap: 12px;
            }
            .doc-pill-info {
              flex: 1;
              min-width: 0;
            }
            .doc-pill-title {
              font-size: 0.88rem;
              font-weight: 700;
              color: var(--text);
              white-space: nowrap;
              overflow: hidden;
              text-overflow: ellipsis;
            }
            .doc-pill-owner {
              font-size: 0.76rem;
              color: var(--muted);
              margin-top: 2px;
            }
            .form-group {
              margin-bottom: 20px;
            }
            .form-label {
              display: block;
              font-size: 0.82rem;
              font-weight: 700;
              color: var(--text);
              margin-bottom: 8px;
            }
            .pin-input {
              width: 100%;
              padding: 12px 16px;
              font-size: 1.15rem;
              letter-spacing: 4px;
              text-align: center;
              font-weight: 800;
              border: 2px solid var(--border);
              border-radius: 10px;
              box-sizing: border-box;
              outline: none;
              transition: border-color 0.15s ease;
            }
            .pin-input:focus {
              border-color: var(--primary);
            }
            .btn-submit {
              width: 100%;
              padding: 13px;
              font-size: 0.92rem;
              font-weight: 700;
              background: var(--primary);
              color: #ffffff;
              border: none;
              border-radius: 10px;
              cursor: pointer;
              transition: background 0.15s ease;
              display: flex;
              align-items: center;
              justify-content: center;
              gap: 8px;
            }
            .btn-submit:hover {
              background: var(--primary-hover);
            }
            .alert-error {
              background: #fef2f2;
              border: 1px solid #fecaca;
              color: #b91c1c;
              padding: 10px 14px;
              border-radius: 8px;
              font-size: 0.82rem;
              font-weight: 600;
              margin-bottom: 18px;
              line-height: 1.45;
            }
          </style>
        </head>
        <body>
          <div class="pin-card">
            <div style="text-align: center; margin-bottom: 16px;">
              <img src="<?= htmlspecialchars($sysAppLogo) ?>" alt="<?= htmlspecialchars($sysAppTitle) ?>" style="max-height: 42px; max-width: 150px; object-fit: contain;">
            </div>
            <div class="pin-header">
              <div class="pin-icon">
                <?= app_icon('lock', 30) ?>
              </div>
              <h2>Dokumen Terproteksi PIN</h2>
              <div class="pin-sub">Tautan ini dilindungi kode PIN oleh pengirim. Silakan masukkan PIN untuk membuka dokumen.</div>
            </div>

            <div class="doc-preview-pill">
              <div style="color: var(--primary);"><?= app_icon('document', 24) ?></div>
              <div class="doc-pill-info">
                <div class="doc-pill-title"><?= htmlspecialchars($share['title']) ?></div>
                <div class="doc-pill-owner">Dibagikan oleh: <?= htmlspecialchars($share['owner_name'] ?: $share['owner_username']) ?></div>
              </div>
            </div>

            <?php if (!empty($pinError)): ?>
              <div class="alert-error"><?= htmlspecialchars($pinError) ?></div>
            <?php endif; ?>

            <form method="POST">
              <input type="hidden" name="verify_pin" value="1">
              <div class="form-group">
                <label class="form-label" for="pin_field">Masukkan Kode PIN Keamanan:</label>
                <input type="password" id="pin_field" name="pin" class="pin-input" placeholder="••••" required autofocus autocomplete="off" maxlength="20">
              </div>
              <button type="submit" class="btn-submit">
                <?= app_icon('check-circle', 16) ?>
                <span>Buka Akses Dokumen</span>
              </button>
            </form>
          </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// 5. Pencatatan Statistik Akses (hanya dicatat sekali per sesi penelusuran)
$countKey = 'counted_share_' . $shareId;
if (empty($_SESSION[$countKey])) {
    try {
        $stmtCount = $pdo->prepare("
            UPDATE user_document_external_shares 
            SET access_count = access_count + 1, last_accessed_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $stmtCount->execute([$shareId]);
        $_SESSION[$countKey] = true;
    } catch (Exception $e) {}
}

// 6. Tangani Pengunduhan / Streaming Berkas
$filePath = __DIR__ . '/uploads/documents/' . $share['stored_filename'];

if ($action === 'download' || $action === 'preview') {
    if (!file_exists($filePath) || !is_readable($filePath)) {
        render_share_error_page(
            'Berkas Tidak Ditemukan',
            'File fisik dokumen tidak ditemukan di penyimpanan server. Silakan hubungi pemilik berkas.',
            'alert-triangle'
        );
    }

    $rawContent = file_get_contents($filePath);
    if ($rawContent === false) {
        render_share_error_page('Gagal Membaca File', 'Terjadi gangguan saat membaca berkas dari penyimpanan.', 'alert-triangle');
    }

    $outputData = $rawContent;
    if (!empty($share['is_compressed'])) {
        $decomp = @gzdecode($rawContent);
        if ($decomp !== false) {
            $outputData = $decomp;
        }
    }

    $mime = $share['mime_type'] ?: 'application/octet-stream';
    $origName = $share['original_filename'] ?: 'document';

    // Bersihkan output buffer
    while (ob_get_level()) {
        ob_end_clean();
    }

    if ($action === 'download') {
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . rawurlencode($origName) . '"; filename*=UTF-8\'\'' . rawurlencode($origName));
        header('Content-Length: ' . strlen($outputData));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        echo $outputData;
        exit;
    } else {
        // Preview inline
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . rawurlencode($origName) . '"');
        header('Content-Length: ' . strlen($outputData));
        header('Cache-Control: private, max-age=3600');
        echo $outputData;
        exit;
    }
}

// 7. Render Halaman Antarmuka Publik Berbagi Dokumen
$badge = get_shared_file_badge($share['file_ext']);
$fileExt = strtolower($share['file_ext']);
$canPreview = in_array($fileExt, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif'], true);
$isImage = in_array($fileExt, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
$isPdf = ($fileExt === 'pdf');

$expiryText = 'Tanpa batas waktu (selamanya)';
if (!empty($share['expires_at'])) {
    $expiryText = date('d F Y, H:i', strtotime($share['expires_at'])) . ' WIB';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($share['title']) ?> - <?= htmlspecialchars($sysAppTitle) ?></title>
  <link rel="icon" type="image/png" href="<?= htmlspecialchars(get_app_favicon_url()) ?>">
  <link rel="shortcut icon" type="image/png" href="<?= htmlspecialchars(get_app_favicon_url()) ?>">
  <style>
    :root {
      --primary: #008080;
      --primary-hover: #006666;
      --primary-subtle: rgba(0, 128, 128, 0.08);
      --bg: #f8fafc;
      --card: #ffffff;
      --text-main: #0f172a;
      --text-muted: #475569;
      --text-subtle: #64748b;
      --border: #e2e8f0;
      --radius: 14px;
    }
    * {
      box-sizing: border-box;
    }
    body {
      margin: 0;
      padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      background: var(--bg);
      color: var(--text-main);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    .share-navbar {
      background: #ffffff;
      border-bottom: 1px solid var(--border);
      padding: 14px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      position: sticky;
      top: 0;
      z-index: 50;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
    }
    .share-brand {
      display: flex;
      align-items: center;
      gap: 12px;
      text-decoration: none;
      color: var(--text-main);
    }
    .share-brand img {
      height: 34px;
      width: auto;
      object-fit: contain;
    }
    .share-brand-text {
      display: flex;
      flex-direction: column;
    }
    .brand-title {
      font-size: 0.95rem;
      font-weight: 800;
      letter-spacing: -0.01em;
      color: var(--text-main);
    }
    .brand-sub {
      font-size: 0.72rem;
      color: var(--text-subtle);
      font-weight: 600;
    }
    .share-status-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 0.74rem;
      font-weight: 700;
      background: #ecfdf5;
      color: #059669;
      border: 1px solid #a7f3d0;
    }
    .share-container {
      max-width: 960px;
      width: 100%;
      margin: 28px auto;
      padding: 0 20px;
      flex: 1;
    }
    .doc-share-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.05);
      padding: 28px;
      margin-bottom: 24px;
    }
    .doc-main-row {
      display: flex;
      align-items: flex-start;
      gap: 20px;
      flex-wrap: wrap;
    }
    .doc-big-badge {
      width: 64px;
      height: 64px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 900;
      font-size: 0.9rem;
      letter-spacing: 0.5px;
      flex-shrink: 0;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
    }
    .doc-info-col {
      flex: 1;
      min-width: 260px;
    }
    .doc-title {
      font-size: 1.35rem;
      font-weight: 800;
      margin: 0 0 6px;
      color: var(--text-main);
      line-height: 1.3;
    }
    .doc-filename {
      font-size: 0.85rem;
      color: var(--text-subtle);
      margin-bottom: 12px;
      word-break: break-all;
    }
    .doc-tags-row {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      margin-bottom: 14px;
    }
    .tag-pill {
      font-size: 0.74rem;
      font-weight: 700;
      padding: 3px 9px;
      border-radius: 6px;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .tag-category {
      background: var(--primary-subtle);
      color: var(--primary);
      border: 1px solid rgba(0, 128, 128, 0.2);
    }
    .tag-size {
      background: #f1f5f9;
      color: #334155;
      border: 1px solid #e2e8f0;
    }
    .tag-pin {
      background: #eff6ff;
      color: #2563eb;
      border: 1px solid #bfdbfe;
    }
    .doc-desc {
      font-size: 0.88rem;
      color: var(--text-muted);
      line-height: 1.6;
      background: var(--bg);
      padding: 12px 16px;
      border-radius: 8px;
      margin-bottom: 20px;
      border-left: 3px solid var(--primary);
      white-space: pre-line;
    }
    .doc-meta-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 12px;
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 14px 18px;
      margin-bottom: 22px;
    }
    .meta-item {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }
    .meta-label {
      font-size: 0.72rem;
      font-weight: 700;
      color: var(--text-subtle);
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }
    .meta-val {
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--text-main);
    }
    .doc-actions-row {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
      padding-top: 14px;
      border-top: 1px solid var(--border);
    }
    .btn-action {
      padding: 11px 22px;
      border-radius: 9px;
      font-size: 0.88rem;
      font-weight: 700;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      cursor: pointer;
      border: 1px solid transparent;
      transition: all 0.15s ease;
    }
    .btn-download {
      background: var(--primary);
      color: #ffffff;
      box-shadow: 0 4px 12px rgba(0, 128, 128, 0.25);
    }
    .btn-download:hover {
      background: var(--primary-hover);
    }
    .btn-preview {
      background: #ffffff;
      color: var(--text-main);
      border-color: var(--border);
    }
    .btn-preview:hover {
      background: var(--bg);
      border-color: var(--text-subtle);
    }
    .preview-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.05);
      overflow: hidden;
      margin-bottom: 24px;
    }
    .preview-header {
      padding: 14px 20px;
      border-bottom: 1px solid var(--border);
      background: var(--bg);
      display: flex;
      align-items: center;
      justify-content: space-between;
      font-weight: 700;
      font-size: 0.88rem;
    }
    .preview-body {
      padding: 16px;
      text-align: center;
      background: #1e293b;
      min-height: 480px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .preview-img {
      max-width: 100%;
      max-height: 75vh;
      border-radius: 6px;
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.4);
      object-fit: contain;
    }
    .preview-iframe {
      width: 100%;
      height: 75vh;
      border: none;
      background: #ffffff;
    }
    .share-footer {
      text-align: center;
      padding: 24px 16px;
      font-size: 0.78rem;
      color: var(--text-subtle);
      border-top: 1px solid var(--border);
      background: #ffffff;
      margin-top: auto;
    }
    @media (max-width: 640px) {
      .share-navbar {
        padding: 10px 14px;
      }
      .brand-title {
        font-size: 0.88rem;
      }
      .share-status-pill {
        font-size: 0.68rem;
        padding: 3px 8px;
      }
      .share-container {
        padding: 0 12px;
        margin: 14px auto;
      }
      .doc-share-card {
        padding: 18px 14px;
      }
      .doc-main-row {
        gap: 12px;
      }
      .doc-big-badge {
        width: 48px;
        height: 48px;
        font-size: 0.76rem;
      }
      .doc-title {
        font-size: 1.15rem;
      }
      .doc-meta-grid {
        grid-template-columns: 1fr;
        padding: 10px 12px;
        gap: 10px;
      }
      .btn-action {
        width: 100%;
        justify-content: center;
      }
      .preview-body {
        min-height: 320px;
        padding: 8px;
      }
      .preview-iframe {
        height: 60vh;
      }
    }
  </style>
</head>
<body>

  <!-- Top Navigation -->
  <header class="share-navbar">
    <a href="./" class="share-brand">
      <img src="<?= htmlspecialchars($sysAppLogo) ?>" alt="<?= htmlspecialchars($sysAppTitle) ?>">
      <div class="share-brand-text">
        <span class="brand-title"><?= htmlspecialchars($sysAppTitle) ?></span>
        <span class="brand-sub">Portal Berbagi Dokumen Resmi</span>
      </div>
    </a>
    <div class="share-status-pill">
      <?= app_icon('check-circle', 13) ?>
      <span>Tautan Resmi Aktif</span>
    </div>
  </header>

  <!-- Content Container -->
  <main class="share-container">

    <div class="doc-share-card">
      <div class="doc-main-row">
        <!-- File Extension Badge -->
        <div class="doc-big-badge" style="background: <?= $badge['bg'] ?>; color: <?= $badge['color'] ?>;">
          <?= $badge['label'] ?>
        </div>

        <!-- Document Details -->
        <div class="doc-info-col">
          <h1 class="doc-title"><?= htmlspecialchars($share['title']) ?></h1>
          <div class="doc-filename"><?= htmlspecialchars($share['original_filename']) ?></div>

          <div class="doc-tags-row">
            <span class="tag-pill tag-category">
              <?= htmlspecialchars($share['category']) ?>
            </span>
            <span class="tag-pill tag-size">
              <?= format_shared_doc_size((int)$share['file_size_raw']) ?>
            </span>
            <?php if ((int)$share['has_pin'] === 1): ?>
              <span class="tag-pill tag-pin">
                <?= app_icon('lock', 11) ?> Terproteksi PIN
              </span>
            <?php endif; ?>
          </div>

          <?php if (!empty($share['description'])): ?>
            <div class="doc-desc">
              <?= htmlspecialchars($share['description']) ?>
            </div>
          <?php endif; ?>

          <div class="doc-meta-grid">
            <div class="meta-item">
              <span class="meta-label">Dibagikan Oleh</span>
              <span class="meta-val"><?= htmlspecialchars($share['owner_name'] ?: $share['owner_username']) ?></span>
            </div>
            <div class="meta-item">
              <span class="meta-label">Masa Berlaku Tautan</span>
              <span class="meta-val"><?= htmlspecialchars($expiryText) ?></span>
            </div>
            <div class="meta-item">
              <span class="meta-label">Format Dokumen</span>
              <span class="meta-val"><?= strtoupper($share['file_ext']) ?> (<?= htmlspecialchars($share['mime_type']) ?>)</span>
            </div>
          </div>

          <div class="doc-actions-row">
            <a href="share?token=<?= urlencode($token) ?>&action=download" class="btn-action btn-download">
              <?= app_icon('download', 16) ?>
              <span>Unduh Dokumen Asli</span>
            </a>
            <?php if ($canPreview): ?>
              <a href="#preview_section" class="btn-action btn-preview" onclick="document.getElementById('preview_section').scrollIntoView({behavior: 'smooth'})">
                <?= app_icon('eye', 16) ?>
                <span>Lihat Pratinjau Dokumen</span>
              </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Inline Preview Section (PDF / Images) -->
    <?php if ($canPreview): ?>
      <div class="preview-card" id="preview_section">
        <div class="preview-header">
          <span style="display: flex; align-items: center; gap: 6px;">
            <?= app_icon('eye', 14, 'var(--primary)') ?>
            <span>Pratinjau Dokumen</span>
          </span>
          <a href="share?token=<?= urlencode($token) ?>&action=preview" target="_blank" style="font-size: 0.78rem; color: var(--primary); text-decoration: none; font-weight: 700;">
            Buka Layar Penuh &nearr;
          </a>
        </div>
        <div class="preview-body">
          <?php if ($isPdf): ?>
            <iframe class="preview-iframe" src="share?token=<?= urlencode($token) ?>&action=preview" title="Pratinjau PDF"></iframe>
          <?php elseif ($isImage): ?>
            <img class="preview-img" src="share?token=<?= urlencode($token) ?>&action=preview" alt="Pratinjau Gambar" loading="lazy">
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

  </main>

  <footer class="share-footer">
    <div>Dokumen resmi ini dibagikan melalui Sistem Brankas Dokumen Terproteksi <strong><?= htmlspecialchars($sysAppTitle) ?></strong>.</div>
    <div style="margin-top: 4px; color: #94a3b8;">Dilarang membagikan atau menyalahgunakan berkas tanpa izin pemilik dokumen.</div>
  </footer>

</body>
</html>
