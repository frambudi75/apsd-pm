<?php
/**
 * Helper Tanda Tangan Digital (Upload & Canvas Touch / Gores Layar)
 * PT Angkasa Pura Sarana Digital & IASS SBU ICT KNO
 */

if (!defined('SIGNATURE_UPLOAD_DIR')) {
    define('SIGNATURE_UPLOAD_DIR', __DIR__ . '/../uploads/signatures');
}

/**
 * Pastikan direktori tanda tangan siap digunakan
 */
function ensure_signature_dir_exists(): string {
    $dir = SIGNATURE_UPLOAD_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Simpan tanda tangan dari base64 canvas data URL (PNG)
 * 
 * @param string $dataUrl Format: "data:image/png;base64,..."
 * @param string $prefix Prefix nama file (misal: "sig_user_1", "sig_spv", "sig_shift_12")
 * @return string|null Path relatif file gambar (misal: "uploads/signatures/sig_user_1_172951234.png") atau null jika gagal
 */
function save_signature_from_base64(string $dataUrl, string $prefix = 'sig'): ?string {
    $dataUrl = trim($dataUrl);
    if (empty($dataUrl)) {
        return null;
    }

    if (!preg_match('/^data:image\/(png|jpeg|webp);base64,(.+)$/i', $dataUrl, $matches)) {
        return null;
    }

    $ext = strtolower($matches[1]);
    $encodedData = $matches[2];
    $decodedData = base64_decode($encodedData);

    if ($decodedData === false || strlen($decodedData) < 20) {
        return null;
    }

    $dir = ensure_signature_dir_exists();
    $safePrefix = preg_replace('/[^a-zA-Z0-9_-]/', '_', $prefix);
    $filename = $safePrefix . '_' . time() . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
    $filePath = $dir . '/' . $filename;

    if (@file_put_contents($filePath, $decodedData) !== false) {
        return 'uploads/signatures/' . $filename;
    }

    return null;
}

/**
 * Simpan tanda tangan dari form file upload ($_FILES)
 * 
 * @param array $fileArray Elemen $_FILES (misal: $_FILES['signature_file'])
 * @param string $prefix Prefix nama file
 * @return string|null Path relatif file gambar atau null jika gagal
 */
function save_signature_from_file(array $fileArray, string $prefix = 'sig'): ?string {
    if (!isset($fileArray['tmp_name']) || empty($fileArray['tmp_name']) || $fileArray['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $maxBytes = 5 * 1024 * 1024; // 5 MB
    if ($fileArray['size'] > $maxBytes) {
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $fileArray['tmp_name']);
    finfo_close($finfo);

    $allowedMimes = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp'
    ];

    if (!isset($allowedMimes[$mime])) {
        return null;
    }

    $ext = $allowedMimes[$mime];
    $dir = ensure_signature_dir_exists();
    $safePrefix = preg_replace('/[^a-zA-Z0-9_-]/', '_', $prefix);
    $filename = $safePrefix . '_' . time() . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
    $targetPath = $dir . '/' . $filename;

    if (move_uploaded_file($fileArray['tmp_name'], $targetPath)) {
        return 'uploads/signatures/' . $filename;
    }

    return null;
}

/**
 * Hapus file tanda tangan lama secara aman (hanya dalam folder uploads/signatures)
 */
function delete_signature_file(?string $relPath): bool {
    if (empty($relPath)) {
        return false;
    }

    // Bersihkan dari path traversal
    $cleanPath = str_replace(['..', '\\'], ['', '/'], $relPath);
    if (!str_starts_with($cleanPath, 'uploads/signatures/')) {
        return false;
    }

    $fullPath = __DIR__ . '/../' . $cleanPath;
    if (file_exists($fullPath) && is_file($fullPath)) {
        return @unlink($fullPath);
    }

    return false;
}

/**
 * Render tag <img> tanda tangan atau kembalikan string kosong
 */
function render_signature_img(?string $relPath, string $alt = 'Tanda Tangan', string $extraStyles = ''): string {
    if (empty($relPath)) {
        return '';
    }

    $fullPath = __DIR__ . '/../' . ltrim($relPath, '/');
    if (!file_exists($fullPath)) {
        return '';
    }

    $escapedPath = htmlspecialchars($relPath, ENT_QUOTES, 'UTF-8');
    $escapedAlt = htmlspecialchars($alt, ENT_QUOTES, 'UTF-8');
    $style = 'max-height: 52px; max-width: 170px; width: auto; height: auto; object-fit: contain; display: block; margin: 0 auto;' . $extraStyles;

    return '<img src="' . $escapedPath . '" alt="' . $escapedAlt . '" style="' . $style . '" class="doc-signature-img">';
}

/**
 * Dapatkan path tanda tangan tersimpan untuk user ID tertentu
 */
if (!function_exists('get_user_signature_path')) {
    function get_user_signature_path($userId): ?string {
        if (empty($userId) || !is_numeric($userId)) {
            return null;
        }
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare("SELECT signature_path FROM users WHERE id = ?");
            $stmt->execute([(int)$userId]);
            $path = $stmt->fetchColumn();
            return (!empty($path) && is_string($path)) ? $path : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
