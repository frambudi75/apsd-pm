<?php
/**
 * CLI Background Cron Script: Cadangan Otomatis Sistem
 * PT Angkasa Pura Sarana Digital - Sistem PM
 *
 * Cara Penggunaan di Windows Task Scheduler / Linux Cron:
 * Windows: php.exe c:\xampp\htdocs\icthub\cron_backup.php --type=full --discord=1
 * Linux:   docker compose exec -T web php cron_backup.php --type=full --discord=1
 */

if (php_sapi_name() !== 'cli' && empty($_GET['cron_key'])) {
    http_response_code(403);
    die("Script ini hanya dapat dijalankan melalui CLI / Task Scheduler background.\n");
}

@ini_set('memory_limit', '512M');
@set_time_limit(600);

require_once __DIR__ . '/includes/backup_helper.php';

// Parse arguments
$type = 'full';
$sendDiscord = true;

foreach ($argv as $arg) {
    if (strpos($arg, '--type=') === 0) {
        $type = substr($arg, 7);
    }
    if ($arg === '--no-discord' || $arg === '--discord=0') {
        $sendDiscord = false;
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Memulai proses pencadangan otomatis APSD (Tipe: {$type})...\n";

$startTime = microtime(true);

try {
    // 1. Buat Berkas Cadangan
    $res = create_system_backup($type);
    echo "- Berkas cadangan berhasil dibuat: {$res['filename']} ({$res['size_formatted']})\n";
    echo "- Hash SHA-256: {$res['sha256']}\n";

    // 2. Kirim ke Saluran Discord jika diaktifkan
    if ($sendDiscord) {
        echo "- Mengirim cadangan ke saluran Discord...\n";
        $discRes = send_backup_to_discord($res['filename']);
        if ($discRes['success']) {
            echo "- Berhasil dikirim ke Discord dalam {$discRes['total_parts']} batch.\n";
        } else {
            echo "- Peringatan Discord: {$discRes['message']}\n";
        }
    }

    $elapsed = round((microtime(true) - $startTime) * 1000, 2);
    echo "[" . date('Y-m-d H:i:s') . "] Sukses! Waktu eksekusi: {$elapsed} ms. Selesai.\n";
    exit(0);

} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Error: " . $e->getMessage() . "\n";
    exit(1);
}
