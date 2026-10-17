<?php
/**
 * API: Hapus Laporan Sesi Shift (Daily Activity Shift)
 * IASS SBU ICT KNO
 * Khusus Superadmin: menghapus sesi shift beserta seluruh kegiatan dan foto di dalamnya
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

if (!has_role('superadmin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak: Hanya Superadmin yang berwenang menghapus laporan shift.']);
    exit;
}

$pdo = get_db_connection();

try {
    $shiftId = (int)($_POST['shift_id'] ?? 0);
    if ($shiftId <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID Shift tidak valid.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM daily_shifts WHERE id = ?");
    $stmt->execute([$shiftId]);
    $shift = $stmt->fetch();

    if (!$shift) {
        echo json_encode(['success' => false, 'message' => 'Laporan shift tidak ditemukan atau sudah dihapus.']);
        exit;
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
            if (!empty($p['photo_path']) && file_exists($filePath)) {
                @unlink($filePath);
            }
        }

        // Hapus relasi foto di database
        $stmtDelPhotos = $pdo->prepare("DELETE FROM daily_activity_photos WHERE activity_id IN ($placeholders)");
        $stmtDelPhotos->execute($actIds);

        // Hapus relasi kegiatan di database
        $stmtDelAct = $pdo->prepare("DELETE FROM daily_activities WHERE shift_id = ?");
        $stmtDelAct->execute([$shiftId]);
    }

    // Hapus sesi shift
    $stmtDelShift = $pdo->prepare("DELETE FROM daily_shifts WHERE id = ?");
    $stmtDelShift->execute([$shiftId]);

    $shiftDateFormatted = date('d/m/Y', strtotime($shift['shift_date']));
    $shiftTypeName = strtoupper($shift['shift_type']);

    log_audit_event('DAILY_ACTIVITY', 'DAILY_SHIFT_DELETE', "Hapus laporan shift {$shiftTypeName} tanggal {$shiftDateFormatted} (ID: {$shiftId})", 'daily_shift', $shiftId);

    echo json_encode([
        'success' => true,
        'message' => "Laporan shift {$shiftTypeName} tanggal {$shiftDateFormatted} berhasil dihapus permanen beserta seluruh kegiatan dan dokumentasinya."
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Gagal menghapus laporan shift: ' . $e->getMessage()
    ]);
}
