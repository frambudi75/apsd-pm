<?php
/**
 * API: Hapus Kegiatan Harian (Daily Activity)
 * IASS SBU ICT KNO
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
$pdo = get_db_connection();

try {
    $activityId = (int)($_POST['activity_id'] ?? 0);
    if ($activityId <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID Kegiatan tidak valid.']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT a.*, s.status AS shift_status 
        FROM daily_activities a
        JOIN daily_shifts s ON a.shift_id = s.id
        WHERE a.id = ?
    ");
    $stmt->execute([$activityId]);
    $act = $stmt->fetch();

    if (!$act) {
        echo json_encode(['success' => false, 'message' => 'Data kegiatan tidak ditemukan.']);
        exit;
    }

    if (!has_role(['superadmin', 'admin']) && (int)$act['user_id'] !== $userId) {
        echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda hanya dapat menghapus kegiatan yang Anda buat sendiri.']);
        exit;
    }

    if ($act['shift_status'] === 'completed' && !has_role(['superadmin', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'Shift telah dikunci. Silakan hubungi Superadmin jika ada koreksi atau permintaan hapus kegiatan.']);
        exit;
    }

    // Delete photos files from disk
    $stmtPhotos = $pdo->prepare("SELECT photo_path FROM daily_activity_photos WHERE activity_id = ?");
    $stmtPhotos->execute([$activityId]);
    $photos = $stmtPhotos->fetchAll();
    foreach ($photos as $p) {
        $filePath = __DIR__ . '/../' . $p['photo_path'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }

    // Delete database records
    $stmtDelPhotos = $pdo->prepare("DELETE FROM daily_activity_photos WHERE activity_id = ?");
    $stmtDelPhotos->execute([$activityId]);

    $stmtDel = $pdo->prepare("DELETE FROM daily_activities WHERE id = ?");
    $stmtDel->execute([$activityId]);

    echo json_encode([
        'success' => true,
        'message' => 'Kegiatan berhasil dihapus.'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Gagal menghapus kegiatan: ' . $e->getMessage()
    ]);
}
