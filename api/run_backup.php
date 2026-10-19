<?php
/**
 * API: Jalankan Pembuatan & Pengiriman Cadangan Sistem
 * PT Angkasa Pura Sarana Digital - Sistem PM
 *
 * Khusus Superadmin: Mengontrol ekspor basis data, pengemasan berkas sistem,
 * pengiriman multi-batch ke Discord, dan pengunduhan berkas cadangan.
 */

// Tangani output buffering dari baris pertama agar tidak ada HTML/whitespace yang mengotori JSON
ob_start();

// Matikan display_errors langsung ke output (catat ke log server agar respons JSON tetap bersih)
@ini_set('display_errors', '0');
error_reporting(E_ALL);

// Naikkan limit eksekusi & memori untuk pencadangan berkas besar
@ini_set('memory_limit', '512M');
@set_time_limit(300);

// Helper pengirim respons JSON yang bersih tanpa polusi output buffer
function send_backup_json_response(array $data, int $statusCode = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

try {
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/backup_helper.php';

    // Strict RBAC: Hanya Superadmin yang berwenang
    if (!is_logged_in() || !has_role('superadmin')) {
        if (isset($_GET['action']) && $_GET['action'] === 'download') {
            while (ob_get_level() > 0) ob_end_clean();
            http_response_code(403);
            die("Akses ditolak: Hanya Superadmin yang berwenang mengunduh berkas cadangan.");
        }
        send_backup_json_response([
            'success' => false,
            'message' => 'Akses ditolak: Hanya Superadmin yang berwenang mengakses modul cadangan.'
        ], 403);
    }

    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    // 1. Aksi Unduh Berkas Cadangan (GET)
    if ($action === 'download') {
        $rawName = $_GET['filename'] ?? '';
        $cleanName = basename($rawName);
        $filePath = BACKUP_DIR . '/' . $cleanName;

        if (empty($cleanName) || !file_exists($filePath) || !is_file($filePath)) {
            while (ob_get_level() > 0) ob_end_clean();
            http_response_code(404);
            die("Berkas cadangan tidak ditemukan.");
        }

        $ext = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));
        $mime = 'application/octet-stream';
        if ($ext === 'zip') $mime = 'application/zip';
        elseif ($ext === 'gz') $mime = 'application/gzip';
        elseif ($ext === 'sql') $mime = 'application/sql';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $cleanName . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));

        readfile($filePath);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        send_backup_json_response(['success' => false, 'message' => 'Metode request tidak diizinkan.'], 405);
    }

    // 2. Buat Cadangan Baru
    if ($action === 'create') {
        $type = $_POST['type'] ?? 'full';
        $res = create_system_backup($type);

        send_backup_json_response([
            'success' => true,
            'message' => "Cadangan sistem berhasil dibuat (" . $res['size_formatted'] . ")!",
            'data' => $res
        ]);
    }

    // 3. Kirim Cadangan ke Saluran Discord
    elseif ($action === 'send_discord') {
        $filename = $_POST['filename'] ?? '';
        if (empty($filename)) {
            send_backup_json_response(['success' => false, 'message' => 'Nama berkas cadangan belum ditentukan.'], 400);
        }

        $res = send_backup_to_discord($filename);
        send_backup_json_response($res, ($res['success'] ? 200 : 400));
    }

    // 4. Hapus Berkas Cadangan
    elseif ($action === 'delete') {
        $filename = $_POST['filename'] ?? '';
        if (empty($filename)) {
            send_backup_json_response(['success' => false, 'message' => 'Nama berkas cadangan tidak valid.'], 400);
        }

        $ok = delete_backup_file($filename);
        if ($ok) {
            send_backup_json_response(['success' => true, 'message' => "Berkas cadangan '{$filename}' berhasil dihapus."]);
        } else {
            send_backup_json_response(['success' => false, 'message' => "Gagal menghapus berkas '{$filename}'."], 500);
        }
    }

    else {
        send_backup_json_response(['success' => false, 'message' => 'Aksi cadangan tidak dikenali.'], 400);
    }

} catch (Throwable $e) {
    send_backup_json_response([
        'success' => false,
        'message' => 'Terjadi kesalahan pada sistem cadangan: ' . $e->getMessage()
    ], 500);
}
