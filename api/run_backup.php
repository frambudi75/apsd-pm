<?php
/**
 * API: Jalankan Pembuatan & Pengiriman Cadangan Sistem
 * PT Angkasa Pura Sarana Digital - Sistem PM
 *
 * Khusus Superadmin: Mengontrol ekspor basis data, pengemasan berkas sistem,
 * pengiriman multi-batch ke Discord, dan pengunduhan berkas cadangan.
 */

// Naikkan limit eksekusi & memori untuk pencadangan berkas besar
@ini_set('memory_limit', '512M');
@set_time_limit(300);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/backup_helper.php';

// Strict RBAC: Hanya Superadmin yang berwenang
if (!is_logged_in() || !has_role('superadmin')) {
    if (isset($_GET['action']) && $_GET['action'] === 'download') {
        http_response_code(403);
        die("Akses ditolak: Hanya Superadmin yang berwenang mengunduh berkas cadangan.");
    }
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Akses ditolak: Hanya Superadmin yang berwenang mengakses modul cadangan.'
    ]);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 1. Aksi Unduh Berkas Cadangan (GET)
if ($action === 'download') {
    $rawName = $_GET['filename'] ?? '';
    $cleanName = basename($rawName);
    $filePath = BACKUP_DIR . '/' . $cleanName;

    if (empty($cleanName) || !file_exists($filePath) || !is_file($filePath)) {
        http_response_code(404);
        die("Berkas cadangan tidak ditemukan.");
    }

    $ext = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));
    $mime = 'application/octet-stream';
    if ($ext === 'zip') $mime = 'application/zip';
    elseif ($ext === 'gz') $mime = 'application/gzip';
    elseif ($ext === 'sql') $mime = 'application/sql';

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $cleanName . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($filePath));

    // Clear output buffer dan stream file
    if (ob_get_level()) {
        ob_end_clean();
    }
    readfile($filePath);
    exit;
}

// Untuk aksi API selain unduh, gunakan format JSON
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode request tidak diizinkan.']);
    exit;
}

try {
    // 2. Buat Cadangan Baru
    if ($action === 'create') {
        $type = $_POST['type'] ?? 'full';
        $res = create_system_backup($type);

        echo json_encode([
            'success' => true,
            'message' => "Cadangan sistem berhasil dibuat (" . $res['size_formatted'] . ")!",
            'data' => $res
        ]);
        exit;
    }

    // 3. Kirim Cadangan ke Saluran Discord
    elseif ($action === 'send_discord') {
        $filename = $_POST['filename'] ?? '';
        if (empty($filename)) {
            echo json_encode(['success' => false, 'message' => 'Nama berkas cadangan belum ditentukan.']);
            exit;
        }

        $res = send_backup_to_discord($filename);
        echo json_encode($res);
        exit;
    }

    // 4. Hapus Berkas Cadangan
    elseif ($action === 'delete') {
        $filename = $_POST['filename'] ?? '';
        if (empty($filename)) {
            echo json_encode(['success' => false, 'message' => 'Nama berkas cadangan tidak valid.']);
            exit;
        }

        $ok = delete_backup_file($filename);
        if ($ok) {
            echo json_encode(['success' => true, 'message' => "Berkas cadangan '{$filename}' berhasil dihapus."]);
        } else {
            echo json_encode(['success' => false, 'message' => "Gagal menghapus berkas '{$filename}'."]);
        }
        exit;
    }

    else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Aksi cadangan tidak dikenali.']);
        exit;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan pada sistem cadangan: ' . $e->getMessage()
    ]);
    exit;
}
