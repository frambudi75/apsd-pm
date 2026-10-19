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
        if (!has_role(['superadmin', 'admin'])) {
            echo json_encode([
                'success' => false, 
                'message' => 'Sesi shift ini kosong tanpa catatan kegiatan. Sesuai SOP, Anda tidak dapat menutup shift kosong secara mandiri. Silakan hubungi Superadmin / Team Leader NOC.'
            ]);
            exit;
        } else {
            // Superadmin / Admin force-completing an empty shift
            if (empty($notes)) {
                $notes = 'Shift ditutup paksa oleh Superadmin/Admin (Tidak ada catatan kegiatan dari teknisi).';
            }
        }
    }

    // Mark shift completed
    $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'sqlite') {
        $stmtUp = $pdo->prepare("
            UPDATE daily_shifts 
            SET status = 'completed', notes = ?, completed_at = datetime('now', 'localtime') 
            WHERE id = ?
        ");
    } else {
        $stmtUp = $pdo->prepare("
            UPDATE daily_shifts 
            SET status = 'completed', notes = ?, completed_at = NOW() 
            WHERE id = ?
        ");
    }
    $stmtUp->execute([$notes, $shiftId]);

    log_audit_event('DAILY_ACTIVITY', 'DAILY_SHIFT_COMPLETE', "Kunci dan selesaikan shift {$shift['shift_type']} ({$shift['shift_date']}) ID: {$shiftId}", 'daily_shift', $shiftId);

    // Kirim notifikasi ringkasan shift ke Discord Saluran Operasional NOC
    $discordSent = false;
    try {
        require_once __DIR__ . '/../includes/discord.php';
        $stmtActs = $pdo->prepare("SELECT id, activity_time, category, location, title, description FROM daily_activities WHERE shift_id = ? ORDER BY activity_time ASC");
        $stmtActs->execute([$shiftId]);
        $activities = $stmtActs->fetchAll(PDO::FETCH_ASSOC);

        $stmtPhotos = $pdo->prepare("
            SELECT COUNT(*) FROM daily_activity_photos p 
            JOIN daily_activities a ON p.activity_id = a.id 
            WHERE a.shift_id = ?
        ");
        $stmtPhotos->execute([$shiftId]);
        $photoCount = (int)$stmtPhotos->fetchColumn();

        $shift['notes'] = $notes;
        $discordSent = discord_notify_shift_complete($shift, $activities, $photoCount);
    } catch (Throwable $eDisc) {
        error_log('Discord shift complete notification warning: ' . $eDisc->getMessage());
    }

    $msg = 'Shift berhasil diselesaikan dan dilaporkan ke pimpinan/admin. Logbook telah terkunci.';
    if ($discordSent) {
        $msg .= ' Notifikasi ringkasan resmi telah terkirim ke Discord NOC.';
    }

    echo json_encode([
        'success' => true,
        'shift_id' => $shiftId,
        'discord_sent' => $discordSent,
        'message' => $msg
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Gagal menyelesaikan shift: ' . $e->getMessage()
    ]);
}
