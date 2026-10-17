<?php
/**
 * API: Atur Hak Akses Berbagi Dokumen (Share Permissions)
 * IASS SBU ICT KNO
 */
header('Content-Type: application/json; charset=utf-8');
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
$pdo = get_db_connection();

try {
    $docId = (int)($_POST['document_id'] ?? 0);
    $privacyType = trim($_POST['privacy_type'] ?? 'private');

    if (!in_array($privacyType, ['private', 'shared_users', 'team'], true)) {
        $privacyType = 'private';
    }

    $rawShareUsers = $_POST['share_user_ids'] ?? [];
    if (!is_array($rawShareUsers)) {
        $rawShareUsers = !empty($rawShareUsers) ? explode(',', (string)$rawShareUsers) : [];
    }
    $shareUserIds = array_filter(array_map('intval', $rawShareUsers), function($id) use ($userId) {
        return $id > 0 && $id !== $userId;
    });

    if ($docId <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID dokumen tidak valid.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, user_id, title FROM user_documents WHERE id = ?");
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();

    if (!$doc) {
        echo json_encode(['success' => false, 'message' => 'Dokumen tidak ditemukan.']);
        exit;
    }

    // Strict rule: Only the owner can manage sharing permissions (even superadmin cannot share someone else's document)
    if ((int)$doc['user_id'] !== $userId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Akses ditolak: Hanya pemilik dokumen yang berhak mengubah hak akses berbagi berkas ini.']);
        exit;
    }

    // Update privacy_type in user_documents
    $stmtUpdate = $pdo->prepare("UPDATE user_documents SET privacy_type = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmtUpdate->execute([$privacyType, $docId]);

    // Clear old shares
    $stmtDel = $pdo->prepare("DELETE FROM user_document_shares WHERE document_id = ?");
    $stmtDel->execute([$docId]);

    // Insert new shares if shared_users
    if ($privacyType === 'shared_users' && !empty($shareUserIds)) {
        $stmtInsert = $pdo->prepare("
            INSERT INTO user_document_shares (document_id, shared_with_user_id, shared_by_user_id, created_at)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP)
        ");
        foreach ($shareUserIds as $targetId) {
            $stmtInsert->execute([$docId, $targetId, $userId]);
        }
    }

    $msg = 'Hak akses dokumen berhasil diperbarui.';
    if ($privacyType === 'private') {
        $msg = 'Dokumen sekarang berstatus Pribadi (hanya Anda yang dapat melihat).';
    } elseif ($privacyType === 'team') {
        $msg = 'Dokumen sekarang dibagikan ke Seluruh Tim.';
    } elseif ($privacyType === 'shared_users') {
        $msg = 'Dokumen berhasil dibagikan ke ' . count($shareUserIds) . ' rekan kerja yang dipilih.';
    }

    echo json_encode([
        'success' => true,
        'message' => $msg,
        'privacy_type' => $privacyType,
        'total_shared' => count($shareUserIds)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
