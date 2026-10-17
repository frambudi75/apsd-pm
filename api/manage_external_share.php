<?php
/**
 * API: Kelola Tautan Berbagi Dokumen ke Eksternal (Public Link Sharing)
 * IASS SBU ICT KNO
 * 
 * Aksi yang didukung:
 * - get: Mengambil informasi link berbagi aktif dokumen
 * - save: Membuat atau memperbarui link berbagi (PIN, Expiry)
 * - revoke: Mencabut / menonaktifkan link berbagi
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi login telah berakhir.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode request tidak diizinkan.']);
    exit;
}

$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$isSuperAdmin = has_role('superadmin');
$pdo = get_db_connection();

$action = trim($_POST['action'] ?? 'get');
$documentId = (int)($_POST['document_id'] ?? 0);

if ($documentId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID Dokumen tidak valid.']);
    exit;
}

// Validasi kepemilikan dokumen (hanya pemilik dokumen atau superadmin yang berhak mengelola tautan publik)
$stmtDoc = $pdo->prepare("SELECT id, user_id, title, original_filename FROM user_documents WHERE id = ?");
$stmtDoc->execute([$documentId]);
$doc = $stmtDoc->fetch();

if (!$doc) {
    echo json_encode(['success' => false, 'message' => 'Dokumen tidak ditemukan.']);
    exit;
}

if ((int)$doc['user_id'] !== $userId && !$isSuperAdmin) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak: Hanya pemilik dokumen yang berhak mengelola tautan berbagi eksternal.']);
    exit;
}

/**
 * Helper: Bangun URL lengkap untuk tautan share
 */
function build_external_share_url(string $token): string {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;
    $protocol = $isSecure ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUrl = get_app_base_url();
    return $protocol . $host . $baseUrl . 'share?token=' . urlencode($token);
}

/**
 * Helper: Format sisa waktu kedaluwarsa ke bahasa manusia
 */
function format_share_expiry(?string $expiresAt): array {
    if (empty($expiresAt)) {
        return [
            'is_expired' => false,
            'label' => 'Tanpa Batas Waktu (Selamanya)',
            'badge_class' => 'valid'
        ];
    }
    $expTime = strtotime($expiresAt);
    $diff = $expTime - time();

    if ($diff <= 0) {
        return [
            'is_expired' => true,
            'label' => 'Sudah Kedaluwarsa (' . date('d M Y H:i', $expTime) . ' WIB)',
            'badge_class' => 'expired'
        ];
    }

    $days = floor($diff / 86400);
    $hours = floor(($diff % 86400) / 3600);
    $minutes = floor(($diff % 3600) / 60);

    if ($days > 0) {
        $remainingText = $days . ' hari ' . ($hours > 0 ? $hours . ' jam' : '') . ' lagi';
    } elseif ($hours > 0) {
        $remainingText = $hours . ' jam ' . ($minutes > 0 ? $minutes . ' menit' : '') . ' lagi';
    } else {
        $remainingText = max(1, $minutes) . ' menit lagi';
    }

    return [
        'is_expired' => false,
        'label' => 'Berlaku hingga ' . date('d M Y H:i', $expTime) . ' WIB (' . $remainingText . ')',
        'badge_class' => ($diff < 86400) ? 'warning' : 'valid'
    ];
}

try {
    if ($action === 'get') {
        // Ambil data tautan aktif
        $stmtShare = $pdo->prepare("
            SELECT * FROM user_document_external_shares 
            WHERE document_id = ? AND is_active = 1 
            ORDER BY id DESC LIMIT 1
        ");
        $stmtShare->execute([$documentId]);
        $share = $stmtShare->fetch();

        if (!$share) {
            echo json_encode([
                'success' => true,
                'has_active_share' => false
            ]);
            exit;
        }

        $expInfo = format_share_expiry($share['expires_at']);
        $shareUrl = build_external_share_url($share['share_token']);

        echo json_encode([
            'success' => true,
            'has_active_share' => true,
            'share' => [
                'id' => (int)$share['id'],
                'token' => $share['share_token'],
                'share_url' => $shareUrl,
                'has_pin' => (int)$share['has_pin'] === 1,
                'expires_at' => $share['expires_at'],
                'expiry_info' => $expInfo,
                'access_count' => (int)$share['access_count'],
                'last_accessed_at' => $share['last_accessed_at'] ? date('d M Y H:i', strtotime($share['last_accessed_at'])) . ' WIB' : 'Belum pernah diakses',
                'created_at' => date('d M Y H:i', strtotime($share['created_at'])) . ' WIB'
            ]
        ]);
        exit;

    } elseif ($action === 'save') {
        $usePin = (int)($_POST['use_pin'] ?? 0) === 1;
        $pin = trim($_POST['pin'] ?? '');
        $expiryPreset = trim($_POST['expiry_preset'] ?? '7d');
        $customExpiry = trim($_POST['expiry_custom'] ?? '');

        // Hitung nilai expires_at
        $expiresAt = null;
        switch ($expiryPreset) {
            case '1h':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
                break;
            case '24h':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
                break;
            case '3d':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+3 days'));
                break;
            case '7d':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
                break;
            case '30d':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
                break;
            case 'custom':
                if (!empty($customExpiry)) {
                    $customTs = strtotime($customExpiry);
                    if ($customTs && $customTs > time()) {
                        $expiresAt = date('Y-m-d H:i:s', $customTs);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Tanggal dan jam kedaluwarsa kustom harus di masa depan.']);
                        exit;
                    }
                }
                break;
            case 'never':
            default:
                $expiresAt = null;
                break;
        }

        // Cek tautan aktif yang sudah ada untuk dokumen ini
        $stmtExisting = $pdo->prepare("
            SELECT * FROM user_document_external_shares 
            WHERE document_id = ? AND is_active = 1 
            ORDER BY id DESC LIMIT 1
        ");
        $stmtExisting->execute([$documentId]);
        $existing = $stmtExisting->fetch();

        $pinHash = null;
        if ($usePin) {
            if (!empty($pin)) {
                if (strlen($pin) < 4 || strlen($pin) > 20) {
                    echo json_encode(['success' => false, 'message' => 'PIN keamanan harus terdiri dari 4 hingga 20 karakter.']);
                    exit;
                }
                $pinHash = password_hash($pin, PASSWORD_DEFAULT);
            } elseif ($existing && (int)$existing['has_pin'] === 1 && !empty($existing['pin_hash'])) {
                // Pertahankan PIN lama jika tidak mengisi PIN baru
                $pinHash = $existing['pin_hash'];
            } else {
                echo json_encode(['success' => false, 'message' => 'Silakan tentukan kode PIN keamanan untuk tautan ini.']);
                exit;
            }
        }

        if ($existing) {
            // Perbarui link aktif yang sudah ada
            $stmtUpdate = $pdo->prepare("
                UPDATE user_document_external_shares 
                SET has_pin = ?, 
                    pin_hash = ?, 
                    expires_at = ?, 
                    updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmtUpdate->execute([
                $usePin ? 1 : 0,
                $pinHash,
                $expiresAt,
                $existing['id']
            ]);

            $shareToken = $existing['share_token'];
            $msg = 'Pengaturan tautan berbagi eksternal berhasil diperbarui.';
        } else {
            // Buat tautan baru dengan token acak 64 karakter
            $shareToken = bin2hex(random_bytes(32));
            $stmtInsert = $pdo->prepare("
                INSERT INTO user_document_external_shares 
                (document_id, created_by_user_id, share_token, has_pin, pin_hash, expires_at, is_active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $stmtInsert->execute([
                $documentId,
                $userId,
                $shareToken,
                $usePin ? 1 : 0,
                $pinHash,
                $expiresAt
            ]);
            $msg = 'Tautan berbagi eksternal berhasil dibuat.';
        }

        $shareUrl = build_external_share_url($shareToken);
        $expInfo = format_share_expiry($expiresAt);

        log_audit_event('EXTERNAL_SHARE', 'SHARE_EXTERNAL_SAVE', 'Konfigurasi tautan eksternal dokumen: ' . $doc['title'] . ($usePin ? ' (dengan PIN)' : ' (tanpa PIN)'), 'document', $documentId);

        echo json_encode([
            'success' => true,
            'message' => $msg,
            'share_url' => $shareUrl,
            'token' => $shareToken,
            'has_pin' => $usePin,
            'expiry_info' => $expInfo
        ]);
        exit;

    } elseif ($action === 'revoke') {
        // Cabut / nonaktifkan tautan aktif
        $stmtRevoke = $pdo->prepare("
            UPDATE user_document_external_shares 
            SET is_active = 0, updated_at = CURRENT_TIMESTAMP 
            WHERE document_id = ? AND is_active = 1
        ");
        $stmtRevoke->execute([$documentId]);

        log_audit_event('EXTERNAL_SHARE', 'SHARE_EXTERNAL_REVOKE', 'Cabut tautan eksternal dokumen: ' . $doc['title'], 'document', $documentId);

        echo json_encode([
            'success' => true,
            'message' => 'Tautan berbagi eksternal telah dicabut dan dinonaktifkan.'
        ]);
        exit;

    } else {
        echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali.']);
        exit;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
