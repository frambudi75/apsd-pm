<?php
/**
 * API: Selesaikan dan Kunci Sesi Shift (Daily Activity)
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
    $shiftId = (int)($_POST['shift_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($shiftId <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID Shift tidak valid.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM daily_shifts WHERE id = ?");
    $stmt->execute([$shiftId]);
    $shift = $stmt->fetch();

    if (!$shift) {
        echo json_encode(['success' => false, 'message' => 'Sesi shift tidak ditemukan.']);
        exit;
    }

    if (!has_role(['superadmin', 'admin']) && (int)$shift['user_id'] !== $userId) {
        echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda hanya dapat menyelesaikan shift Anda sendiri.']);
        exit;
    }

    // Check count of activities
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM daily_activities WHERE shift_id = ?");
    $stmtCount->execute([$shiftId]);
    $actCount = (int)$stmtCount->fetchColumn();

    if ($actCount === 0) {
        echo json_encode(['success' => false, 'message' => 'Belum ada kegiatan yang dicatat pada shift ini. Minimal catat 1 kegiatan sebelum menyelesaikan shift.']);
        exit;
    }

    // Mark shift completed
    try {
        $stmtUp = $pdo->prepare("
            UPDATE daily_shifts 
            SET status = 'completed', notes = ?, completed_at = datetime('now', 'localtime') 
            WHERE id = ?
        ");
        $stmtUp->execute([$notes, $shiftId]);
    } catch (Exception $ex) {
        $stmtUp = $pdo->prepare("
            UPDATE daily_shifts 
            SET status = 'completed', notes = ?, completed_at = NOW() 
            WHERE id = ?
        ");
        $stmtUp->execute([$notes, $shiftId]);
    }

    log_audit_event('DAILY_ACTIVITY', 'DAILY_SHIFT_COMPLETE', "Kunci dan selesaikan shift {$shift['shift_type']} ({$shift['shift_date']}) ID: {$shiftId}", 'daily_shift', $shiftId);

    echo json_encode([
        'success' => true,
        'shift_id' => $shiftId,
        'message' => 'Shift berhasil diselesaikan dan dilaporkan ke pimpinan/admin. Logbook telah terkunci.'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Gagal menyelesaikan shift: ' . $e->getMessage()
    ]);
}
