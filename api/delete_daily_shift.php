<?php
/**
 * API: Hapus Laporan Sesi Shift (Daily Activity Shift)
 * IASS SBU ICT KNO
 * Khusus Superadmin: menghapus sesi shift beserta seluruh kegiatan dan foto di dalamnya
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

if (!has_role('superadmin')) {
    send_json_response(['success' => false, 'message' => 'Akses ditolak: Hanya Superadmin yang berwenang menghapus laporan shift.'], 403);
}

$pdo = get_db_connection();

try {
    $shiftId = (int)($_POST['shift_id'] ?? 0);
    if ($shiftId <= 0) {
        send_json_response(['success' => false, 'message' => 'ID Shift tidak valid.']);
    }

    $stmt = $pdo->prepare("SELECT * FROM daily_shifts WHERE id = ?");
    $stmt->execute([$shiftId]);
    $shift = $stmt->fetch();

    if (!$shift) {
        send_json_response(['success' => false, 'message' => 'Laporan shift tidak ditemukan atau sudah dihapus.']);
    }

    // Ambil seluruh ID kegiatan dalam shift ini
    $stmtAct = $pdo->prepare("SELECT id FROM daily_activities WHERE shift_id = ?");
    $stmtAct->execute([$shiftId]);
    $actIds = $stmtAct->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($actIds)) {
        $placeholders = implode(',', array_fill(0, count($actIds), '?'));
        
        // Ambil file foto dokumentasi untuk dihapus dari storage fisik
        $stmtPhotos = $pdo->prepare("SELECT photo_path FROM daily_activity_photos WHERE activity_id IN ($placeholders)");
        $stmtPhotos->execute($actIds);
        $photos = $stmtPhotos->fetchAll();
        foreach ($photos as $p) {
            $filePath = __DIR__ . '/../' . $p['photo_path'];
            if (file_exists($filePath) && is_file($filePath)) {
                @unlink($filePath);
            }
        }

        // Hapus record foto dari basis data
        $stmtDelPhotos = $pdo->prepare("DELETE FROM daily_activity_photos WHERE activity_id IN ($placeholders)");
        $stmtDelPhotos->execute($actIds);

        // Hapus seluruh kegiatan dalam shift ini
        $stmtDelActs = $pdo->prepare("DELETE FROM daily_activities WHERE shift_id = ?");
        $stmtDelActs->execute([$shiftId]);
    }

    // Bersihkan folder direktori foto shift jika kosong
    $shiftDir = __DIR__ . '/../uploads/daily/' . $shiftId;
    if (is_dir($shiftDir)) {
        @rmdir($shiftDir);
    }

    // Hapus sesi shift
    $stmtDelShift = $pdo->prepare("DELETE FROM daily_shifts WHERE id = ?");
    $stmtDelShift->execute([$shiftId]);

    $shiftDateFormatted = date('d/m/Y', strtotime($shift['shift_date']));
    $shiftTypeName = strtoupper($shift['shift_type']);

    try {
        log_audit_event('DAILY_ACTIVITY', 'DAILY_SHIFT_DELETE', "Hapus laporan shift {$shiftTypeName} tanggal {$shiftDateFormatted} (ID: {$shiftId})", 'daily_shift', $shiftId);
    } catch (\Throwable $tLog) {}

    send_json_response([
        'success' => true,
        'message' => "Laporan shift {$shiftTypeName} tanggal {$shiftDateFormatted} berhasil dihapus permanen beserta seluruh kegiatan dan dokumentasinya."
    ]);

} catch (\Throwable $e) {
    error_log("delete_daily_shift error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    send_json_response([
        'success' => false,
        'message' => 'Gagal menghapus laporan shift: ' . $e->getMessage()
    ], 500);
}
