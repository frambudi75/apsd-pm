<?php
/**
 * API: Hapus Dokumen dari Brankas Dokumen
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

    if ($docId <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID dokumen tidak valid.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, user_id, title, stored_filename FROM user_documents WHERE id = ?");
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();

    if (!$doc) {
        echo json_encode(['success' => false, 'message' => 'Dokumen tidak ditemukan atau sudah dihapus.']);
        exit;
    }

    // Strict rule: Only the owner can delete their document
    if ((int)$doc['user_id'] !== $userId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Akses ditolak: Dokumen ini bersifat pribadi dan hanya dapat dihapus oleh pemiliknya.']);
        exit;
    }

    // Unlink physical file if exists
    $filePath = __DIR__ . '/../uploads/documents/' . $doc['stored_filename'];
    if (!empty($doc['stored_filename']) && file_exists($filePath)) {
        @unlink($filePath);
    }

    // Delete related child shares first to maintain data integrity
    $stmtShares = $pdo->prepare("DELETE FROM user_document_shares WHERE document_id = ?");
    $stmtShares->execute([$docId]);

    $stmtExtShares = $pdo->prepare("DELETE FROM user_document_external_shares WHERE document_id = ?");
    $stmtExtShares->execute([$docId]);

    // Delete database records
    $stmtDel = $pdo->prepare("DELETE FROM user_documents WHERE id = ?");
    $stmtDel->execute([$docId]);

    log_audit_event('DOCUMENT', 'DOCUMENT_DELETE', 'Hapus dokumen: ' . $doc['title'] . ' (ID: ' . $docId . ')', 'document', $docId);

    echo json_encode([
        'success' => true,
        'message' => 'Dokumen "' . $doc['title'] . '" berhasil dihapus dari sistem.'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
