<?php
/**
 * CLI Background Cron Script: Auto-Optimise & Pemeliharaan Sistem
 * PT Angkasa Pura Sarana Digital - Sistem PM
 *
 * Cara Penggunaan di Windows Task Scheduler / Linux Cron:
 * Windows: php.exe c:\xampp\htdocs\apsd\cron_optimize.php
 * Linux:   php /var/www/html/cron_optimize.php
 *
 * Disarankan dijalankan berkala (misal: setiap hari Minggu pukul 02:00 WIB).
 */

if (php_sapi_name() !== 'cli' && empty($_GET['cron_key'])) {
    http_response_code(403);
    die("Script ini hanya dapat dijalankan melalui CLI / Task Scheduler background.\n");
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/audit.php';

echo "[" . date('Y-m-d H:i:s') . "] Memulai proses auto-optimise APSD...\n";

$startTime = microtime(true);
$pdo = get_db_connection();
$freedBytes = 0;
$cleanedFiles = 0;
$expiredLinks = 0;

try {
    // 1. Optimize Database
    $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'sqlite') {
        $dbPath = __DIR__ . '/database/apsd.sqlite';
        $szBefore = file_exists($dbPath) ? filesize($dbPath) : 0;

        $pdo->exec("PRAGMA wal_checkpoint(TRUNCATE);");
        $pdo->exec("VACUUM;");
        $pdo->exec("PRAGMA optimize;");

        clearstatcache(true, $dbPath);
        $szAfter = file_exists($dbPath) ? filesize($dbPath) : 0;
        $diff = max(0, $szBefore - $szAfter);
        $freedBytes += $diff;
        echo "- SQLite VACUUM & WAL checkpoint selesai (hemat: {$diff} bytes).\n";
    } else {
        $tables = ['sites', 'users', 'operators', 'settings', 'daily_shifts', 'daily_activities', 'user_documents', 'audit_logs'];
        foreach ($tables as $t) {
            @$pdo->exec("OPTIMIZE TABLE `{$t}`");
        }
        echo "- MySQL OPTIMIZE TABLE selesai.\n";
    }

    // 2. Clean Temp Files (> 24 hours)
    $tempDir = __DIR__ . '/uploads/temp';
    if (is_dir($tempDir)) {
        $files = scandir($tempDir);
        $now = time();
        foreach ($files as $f) {
            if ($f === '.' || $f === '..' || $f === '.gitignore' || $f === '.htaccess') continue;
            $p = $tempDir . '/' . $f;
            if (is_file($p) && ($now - filemtime($p)) > 86400) {
                $sz = filesize($p);
                if (@unlink($p)) {
                    $cleanedFiles++;
                    $freedBytes += $sz;
                }
            }
        }
    }
    echo "- Pembersihan temp files selesai ({$cleanedFiles} files dihapus).\n";

    // 3. Deactivate Expired Shares
    try {
        $stmtExp = $pdo->prepare("
            UPDATE user_document_external_shares 
            SET is_active = 0, updated_at = datetime('now', 'localtime') 
            WHERE is_active = 1 AND expires_at IS NOT NULL AND expires_at < datetime('now', 'localtime')
        ");
        $stmtExp->execute();
        $expiredLinks = $stmtExp->rowCount();
    } catch (Exception $e) {}
    echo "- Pengarsipan link expired selesai ({$expiredLinks} links dinonaktifkan).\n";

    $elapsed = round((microtime(true) - $startTime) * 1000, 2);

    // 4. Log to Audit Trail
    log_audit_event(
        'SYSTEM',
        'CRON_OPTIMIZE',
        "Optimasi terjadwal (Cron/CLI) selesai dalam {$elapsed} ms. {$cleanedFiles} temp files dibersihkan, {$expiredLinks} link dinonaktifkan.",
        'system',
        null,
        null,
        'Cron/Scheduler',
        'system'
    );

    echo "[" . date('Y-m-d H:i:s') . "] Sukses! Waktu eksekusi: {$elapsed} ms. Selesai.\n";
    exit(0);

} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Error: " . $e->getMessage() . "\n";
    exit(1);
}
