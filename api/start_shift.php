<?php
/**
 * API: Buka / Mulai Sesi Shift Harian NOC
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
$currentUserId = (int)($currentUser['id'] ?? 0);
$isAdmin = has_role(['superadmin', 'admin']);

$pdo = get_db_connection();

try {
    $shiftDate = trim($_POST['shift_date'] ?? date('Y-m-d'));
    $shiftType = strtolower(trim($_POST['shift_type'] ?? 'pagi'));
    if (!in_array($shiftType, ['oh', 'pagi', 'malam'])) {
        $shiftType = 'pagi';
    }

    $targetUserId = $currentUserId;
    if ($isAdmin && !empty($_POST['user_id'])) {
        $targetUserId = (int)$_POST['user_id'];
    }

    // Get technician name
    $stmtUser = $pdo->prepare("SELECT full_name, username FROM users WHERE id = ?");
    $stmtUser->execute([$targetUserId]);
    $uRow = $stmtUser->fetch();
    $techName = $uRow['full_name'] ?: ($uRow['username'] ?? 'Teknisi NOC');

    // Check if shift already exists
    $stmtCheck = $pdo->prepare("
        SELECT * FROM daily_shifts 
        WHERE user_id = ? AND shift_date = ? AND shift_type = ? 
        ORDER BY id DESC LIMIT 1
    ");
    $stmtCheck->execute([$targetUserId, $shiftDate, $shiftType]);
    $existing = $stmtCheck->fetch();

    if ($existing) {
        echo json_encode([
            'success' => true,
            'shift_id' => (int)$existing['id'],
            'message' => 'Sesi shift sudah ditemukan dan telah aktif.',
            'status' => $existing['status']
        ]);
        exit;
    }

    // Create new active shift
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $stmtInsert = $pdo->prepare("
            INSERT INTO daily_shifts (user_id, technician_name, shift_date, shift_type, status, notes, created_at)
            VALUES (?, ?, ?, ?, 'active', '', datetime('now', 'localtime'))
        ");
    } else {
        $stmtInsert = $pdo->prepare("
            INSERT INTO daily_shifts (user_id, technician_name, shift_date, shift_type, status, notes, created_at)
            VALUES (?, ?, ?, ?, 'active', '', NOW())
        ");
    }

    $stmtInsert->execute([$targetUserId, $techName, $shiftDate, $shiftType]);
    $newShiftId = (int)$pdo->lastInsertId();

    $shiftInfo = get_shift_info($shiftType);

    echo json_encode([
        'success' => true,
        'shift_id' => $newShiftId,
        'message' => 'Sesi ' . $shiftInfo['name'] . ' (' . $shiftDate . ') berhasil dibuka! Anda sekarang dapat mencatat kegiatan.',
        'status' => 'active'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
