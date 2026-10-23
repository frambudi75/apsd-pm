<?php
/**
 * API: Hapus Kegiatan Harian (Daily Activity)
 * IASS SBU ICT KNO
 */
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

header('Content-Type: application/json; charset=utf-8');

function send_json_response(array $data, int $statusCode = 200): void {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';

if (!is_logged_in()) {
    send_json_response(['success' => false, 'message' => 'Sesi login telah berakhir.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response(['success' => false, 'message' => 'Metode request tidak diizinkan.'], 405);
}

$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$pdo = get_db_connection();

try {
    $activityId = (int)($_POST['activity_id'] ?? 0);
    if ($activityId <= 0) {
        send_json_response(['success' => false, 'message' => 'ID Kegiatan tidak valid.']);
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
        send_json_response(['success' => false, 'message' => 'Data kegiatan tidak ditemukan.']);
    }

    if (!has_role(['superadmin', 'admin']) && (int)$act['user_id'] !== $userId) {
        send_json_response(['success' => false, 'message' => 'Akses ditolak: Anda hanya dapat menghapus kegiatan yang Anda buat sendiri.']);
    }

    if ($act['shift_status'] === 'completed' && !has_role(['superadmin', 'admin'])) {
        send_json_response(['success' => false, 'message' => 'Shift telah dikunci. Silakan hubungi Superadmin jika ada koreksi atau permintaan hapus kegiatan.']);
    }

    // Delete photos files from disk
    $stmtPhotos = $pdo->prepare("SELECT photo_path FROM daily_activity_photos WHERE activity_id = ?");
    $stmtPhotos->execute([$activityId]);
    $photos = $stmtPhotos->fetchAll();
    foreach ($photos as $p) {
        $filePath = __DIR__ . '/../' . $p['photo_path'];
        if (file_exists($filePath) && is_file($filePath)) {
            @unlink($filePath);
        }
    }

    // Delete database records
    $stmtDelPhotos = $pdo->prepare("DELETE FROM daily_activity_photos WHERE activity_id = ?");
    $stmtDelPhotos->execute([$activityId]);

    $stmtDel = $pdo->prepare("DELETE FROM daily_activities WHERE id = ?");
    $stmtDel->execute([$activityId]);

    $descSnippet = isset($act['description']) ? mb_substr(strip_tags($act['description']), 0, 60) : '';
    try {
        log_audit_event('DAILY_ACTIVITY', 'DAILY_ACTIVITY_DELETE', "Hapus kegiatan logbook ID {$activityId}: {$descSnippet}", 'daily_activity', $activityId);
    } catch (\Throwable $tLog) {}

    send_json_response([
        'success' => true,
        'message' => 'Kegiatan berhasil dihapus.'
    ]);

} catch (\Throwable $e) {
    error_log("delete_daily_activity error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    send_json_response([
        'success' => false,
        'message' => 'Gagal menghapus kegiatan: ' . $e->getMessage()
    ], 500);
}
