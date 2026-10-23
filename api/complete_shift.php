<?php
/**
 * API: Selesaikan dan Kunci Sesi Shift (Daily Activity)
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
    $shiftId = (int)($_POST['shift_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($shiftId <= 0) {
        send_json_response(['success' => false, 'message' => 'ID Shift tidak valid.']);
    }

    $stmt = $pdo->prepare("SELECT * FROM daily_shifts WHERE id = ?");
    $stmt->execute([$shiftId]);
    $shift = $stmt->fetch();

    if (!$shift) {
        send_json_response(['success' => false, 'message' => 'Sesi shift tidak ditemukan.']);
    }

    if (!has_role(['superadmin', 'admin']) && (int)$shift['user_id'] !== $userId) {
        send_json_response(['success' => false, 'message' => 'Akses ditolak: Anda hanya dapat menyelesaikan shift Anda sendiri.']);
    }

    // Check count of activities
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM daily_activities WHERE shift_id = ?");
    $stmtCount->execute([$shiftId]);
    $actCount = (int)$stmtCount->fetchColumn();

    if ($actCount === 0) {
        if (!has_role(['superadmin', 'admin'])) {
            send_json_response([
                'success' => false, 
                'message' => 'Sesi shift ini kosong tanpa catatan kegiatan. Sesuai SOP, Anda tidak dapat menutup shift kosong secara mandiri. Silakan hubungi Superadmin / Team Leader NOC.'
            ]);
        } else {
            // Superadmin / Admin force-completing an empty shift
            if (empty($notes)) {
                $notes = 'Shift ditutup paksa oleh Superadmin/Admin (Tidak ada catatan kegiatan dari teknisi).';
            }
        }
    }

    // Tangani tanda tangan digital pelaksana shift
    require_once __DIR__ . '/../includes/signature_helper.php';
    $sigPath = null;
    $useProfileSig = (int)($_POST['use_profile_sig'] ?? 0);
    $saveToProfile = (int)($_POST['save_to_profile'] ?? 0);

    if (!empty($_POST['signature_canvas'])) {
        $sigPath = save_signature_from_base64($_POST['signature_canvas'], 'sig_shift_' . $shiftId);
    } elseif (isset($_FILES['signature_file']) && $_FILES['signature_file']['error'] === UPLOAD_ERR_OK) {
        $sigPath = save_signature_from_file($_FILES['signature_file'], 'sig_shift_' . $shiftId);
    } elseif ($useProfileSig === 1) {
        $stmtU = $pdo->prepare("SELECT signature_path FROM users WHERE id = ?");
        $stmtU->execute([$shift['user_id']]);
        $sigPath = $stmtU->fetchColumn() ?: null;
    }

    // Fallback otomatis ke profil teknisi jika shift belum memiliki TTD spesifik
    if (empty($sigPath)) {
        $stmtU = $pdo->prepare("SELECT signature_path FROM users WHERE id = ?");
        $stmtU->execute([$shift['user_id']]);
        $sigPath = $stmtU->fetchColumn() ?: null;
    }

    // Simpan juga ke profil akun pengguna jika diminta atau jika profil belum memiliki TTD
    if ($sigPath && ($saveToProfile === 1 || empty($currentUser['signature_path']))) {
        try {
            $stmtProf = $pdo->prepare("UPDATE users SET signature_path = ? WHERE id = ?");
            $stmtProf->execute([$sigPath, $shift['user_id']]);
        } catch (\Throwable $e) {}
    }

    // Mark shift completed with signature
    $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'sqlite') {
        $stmtUp = $pdo->prepare("
            UPDATE daily_shifts 
            SET status = 'completed', notes = ?, signature_path = ?, completed_at = datetime('now', 'localtime') 
            WHERE id = ?
        ");
    } else {
        $stmtUp = $pdo->prepare("
            UPDATE daily_shifts 
            SET status = 'completed', notes = ?, signature_path = ?, completed_at = NOW() 
            WHERE id = ?
        ");
    }
    $stmtUp->execute([$notes, $sigPath, $shiftId]);

    try {
        log_audit_event('DAILY_ACTIVITY', 'DAILY_SHIFT_COMPLETE', "Kunci dan selesaikan shift {$shift['shift_type']} ({$shift['shift_date']}) ID: {$shiftId}", 'daily_shift', $shiftId);
    } catch (\Throwable $tLog) {}

    // Kirim notifikasi ringkasan shift ke Discord Saluran Operasional NOC (Fail-safe, non-blocking)
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
    } catch (\Throwable $eDisc) {
        error_log('Discord shift complete notification warning: ' . $eDisc->getMessage());
    }

    $msg = 'Shift berhasil diselesaikan dan dilaporkan ke pimpinan/admin. Logbook telah terkunci.';
    if ($discordSent) {
        $msg .= ' Notifikasi ringkasan resmi telah terkirim ke Discord NOC.';
    }

    send_json_response([
        'success' => true,
        'shift_id' => $shiftId,
        'discord_sent' => $discordSent,
        'message' => $msg
    ]);

} catch (\Throwable $e) {
    error_log("complete_shift error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    send_json_response([
        'success' => false,
        'message' => 'Gagal menyelesaikan shift: ' . $e->getMessage()
    ], 500);
}

