<?php
/**
 * API: Buka / Mulai Sesi Shift Harian NOC
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
        send_json_response([
            'success' => true,
            'shift_id' => (int)$existing['id'],
            'message' => 'Sesi shift sudah ditemukan dan telah aktif.',
            'status' => $existing['status']
        ]);
    }

    // Validasi Disiplin SOP: Cek apakah teknisi masih memiliki sesi shift sebelumnya yang belum ditutup
    if (!$isAdmin) {
        $stmtPending = $pdo->prepare("
            SELECT s.*, 
                   (SELECT COUNT(*) FROM daily_activities a WHERE a.shift_id = s.id) AS total_activities
            FROM daily_shifts s
            WHERE s.user_id = ? 
              AND s.status = 'active'
              AND (s.shift_date < ? OR (s.shift_date = ? AND s.shift_type != ?))
            ORDER BY s.shift_date DESC, s.id DESC
            LIMIT 1
        ");
        $stmtPending->execute([$targetUserId, $shiftDate, $shiftDate, $shiftType]);
        $pendingShift = $stmtPending->fetch();

        if ($pendingShift) {
            $shiftInfoPending = get_shift_info($pendingShift['shift_type']);
            $pDate = date('d F Y', strtotime($pendingShift['shift_date']));
            $actCount = (int)$pendingShift['total_activities'];

            if ($actCount === 0) {
                send_json_response([
                    'success' => false,
                    'pending_unclosed' => true,
                    'is_empty' => true,
                    'pending_shift_id' => (int)$pendingShift['id'],
                    'message' => "AKSES DITAHAN SISTEM (PELANGGARAN SOP): Anda masih memiliki sesi {$shiftInfoPending['name']} ({$pDate}) yang belum ditutup dan TIDAK MEMILIKI CATATAN KEGIATAN. Sesuai SOP, silakan hubungi Superadmin / Team Leader NOC untuk evaluasi dan penutupan sesi shift tersebut."
                ], 403);
            } else {
                send_json_response([
                    'success' => false,
                    'pending_unclosed' => true,
                    'is_empty' => false,
                    'pending_shift_id' => (int)$pendingShift['id'],
                    'message' => "AKSES DITOLAK: Anda masih memiliki sesi {$shiftInfoPending['name']} ({$pDate}) yang belum diselesaikan ({$actCount} kegiatan tercatat). Selesaikan dan serahkan shift sebelumnya terlebih dahulu sebelum membuka shift baru."
                ], 403);
            }
        }
    }

    // Create new active shift
    $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
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

    try {
        log_audit_event('DAILY_ACTIVITY', 'DAILY_SHIFT_START', "Mulai sesi shift {$shiftInfo['name']} ({$shiftDate}) untuk {$techName}", 'daily_shift', $newShiftId);
    } catch (\Throwable $tLog) {}

    send_json_response([
        'success' => true,
        'shift_id' => $newShiftId,
        'message' => 'Sesi ' . $shiftInfo['name'] . ' (' . $shiftDate . ') berhasil dibuka! Anda sekarang dapat mencatat kegiatan.',
        'status' => 'active'
    ]);

} catch (\Throwable $e) {
    error_log("start_shift error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    send_json_response([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ], 500);
}
