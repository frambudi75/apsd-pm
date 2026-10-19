<?php
/**
 * Backup & Disaster Recovery Helper Engine
 * PT Angkasa Pura Sarana Digital - Sistem PM
 *
 * Mendukung:
 * 1. Dump basis data mandiri (MySQL / MariaDB & SQLite) tanpa dependensi CLI external
 * 2. Pengemasan ZIP terkompresi untuk folder uploads & seluruh berkas sistem
 * 3. Pemecahan berkas otomatis (Chunking / Batching) untuk pengiriman Discord Webhook (< 25MB)
 * 4. Proteksi direktori cadangan dari akses web publik (.htaccess Deny)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/version.php';
require_once __DIR__ . '/discord.php';
require_once __DIR__ . '/audit.php';

const BACKUP_DIR = __DIR__ . '/../backups';
const DISCORD_CHUNK_SIZE = 20971520; // 20 MB per chunk (aman di bawah batas 25 MB Discord)

/**
 * Pastikan direktori cadangan ada dan terlindungi
 */
function ensure_backup_dir(): string {
    $dir = BACKUP_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @chmod($dir, 0777);

    // Amankan direktori dari akses web HTTP langsung
    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Order Allow,Deny\nDeny from all\n");
    }

    $tempDir = $dir . '/temp';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0777, true);
    }
    @chmod($tempDir, 0777);

    return $dir;
}

/**
 * Format ukuran byte menjadi teks ramah pengguna
 */
function format_backup_bytes(int $bytes): string {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = (int)floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

/**
 * Ekspor basis data aktif menjadi file SQL lengkap (Struktur + Data)
 */
function export_database_sql(string $outputPath): bool {
    $pdo = get_db_connection();
    $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $fp = fopen($outputPath, 'w');
    if (!$fp) {
        return false;
    }

    $now = date('Y-m-d H:i:s');
    $appName = get_system_setting('app_title', 'APSD PM NOC KNO');

    fwrite($fp, "-- =========================================================\n");
    fwrite($fp, "-- CADANGAN BASIS DATA RESMI: {$appName}\n");
    fwrite($fp, "-- Waktu Ekspor : {$now} WIB\n");
    fwrite($fp, "-- Driver Basis Data : " . strtoupper($driver) . "\n");
    fwrite($fp, "-- Versi Sistem : " . APP_VERSION . "\n");
    fwrite($fp, "-- =========================================================\n\n");

    if ($driver === 'sqlite') {
        fwrite($fp, "PRAGMA foreign_keys = OFF;\nBEGIN TRANSACTION;\n\n");

        $tablesQuery = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        while ($t = $tablesQuery->fetch(PDO::FETCH_ASSOC)) {
            $tbl = $t['name'];
            $createSql = $t['sql'];
            fwrite($fp, "-- ---------------------------------------------------------\n");
            fwrite($fp, "-- Struktur tabel: `{$tbl}`\n");
            fwrite($fp, "-- ---------------------------------------------------------\n");
            fwrite($fp, "DROP TABLE IF EXISTS `{$tbl}`;\n");
            fwrite($fp, $createSql . ";\n\n");

            $rowsQuery = $pdo->query("SELECT * FROM `{$tbl}`");
            $rows = $rowsQuery->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                fwrite($fp, "-- Data untuk tabel: `{$tbl}`\n");
                foreach ($rows as $row) {
                    $cols = array_map(function($c) { return "`" . str_replace("`", "``", $c) . "`"; }, array_keys($row));
                    $vals = array_map(function($v) use ($pdo) {
                        return $v === null ? "NULL" : $pdo->quote($v);
                    }, array_values($row));
                    fwrite($fp, "INSERT INTO `{$tbl}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n");
                }
                fwrite($fp, "\n");
            }
        }
        fwrite($fp, "COMMIT;\nPRAGMA foreign_keys = ON;\n");

    } else {
        // MySQL / MariaDB Dump
        fwrite($fp, "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n");
        fwrite($fp, "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n");
        fwrite($fp, "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n");
        fwrite($fp, "/*!40101 SET NAMES utf8mb4 */;\n");
        fwrite($fp, "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;\n");
        fwrite($fp, "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n");
        fwrite($fp, "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n");
        fwrite($fp, "/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;\n\n");

        $tables = [];
        $stmtTables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        while ($row = $stmtTables->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }

        foreach ($tables as $tbl) {
            fwrite($fp, "-- ---------------------------------------------------------\n");
            fwrite($fp, "-- Struktur tabel: `{$tbl}`\n");
            fwrite($fp, "-- ---------------------------------------------------------\n");
            fwrite($fp, "DROP TABLE IF EXISTS `{$tbl}`;\n");

            $createRow = $pdo->query("SHOW CREATE TABLE `{$tbl}`")->fetch(PDO::FETCH_NUM);
            fwrite($fp, $createRow[1] . ";\n\n");

            $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
            if ($count > 0) {
                fwrite($fp, "-- Dumping data untuk tabel: `{$tbl}` ({$count} baris)\n");
                fwrite($fp, "LOCK TABLES `{$tbl}` WRITE;\n");
                fwrite($fp, "/*!40000 ALTER TABLE `{$tbl}` DISABLE KEYS */;\n");

                $q = $pdo->query("SELECT * FROM `{$tbl}`");
                $batchSize = 200;
                $currentBatch = 0;
                $rowBuffer = [];

                while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                    $vals = [];
                    foreach ($row as $v) {
                        if ($v === null) {
                            $vals[] = 'NULL';
                        } elseif (is_numeric($v) && (string)(int)$v === (string)$v) {
                            $vals[] = $v;
                        } else {
                            $vals[] = $pdo->quote($v);
                        }
                    }
                    $rowBuffer[] = "(" . implode(', ', $vals) . ")";
                    $currentBatch++;

                    if ($currentBatch >= $batchSize) {
                        fwrite($fp, "INSERT INTO `{$tbl}` VALUES " . implode(",\n", $rowBuffer) . ";\n");
                        $rowBuffer = [];
                        $currentBatch = 0;
                    }
                }

                if (!empty($rowBuffer)) {
                    fwrite($fp, "INSERT INTO `{$tbl}` VALUES " . implode(",\n", $rowBuffer) . ";\n");
                }

                fwrite($fp, "/*!40000 ALTER TABLE `{$tbl}` ENABLE KEYS */;\n");
                fwrite($fp, "UNLOCK TABLES;\n\n");
            }
        }

        fwrite($fp, "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n");
        fwrite($fp, "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n");
        fwrite($fp, "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;\n");
        fwrite($fp, "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n");
        fwrite($fp, "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n");
        fwrite($fp, "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n");
        fwrite($fp, "/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;\n");
    }

    fclose($fp);
    return true;
}

/**
 * Buat cadangan arsip sesuai tipe: 'db', 'files', atau 'full'
 */
function create_system_backup(string $type = 'full'): array {
    ensure_backup_dir();
    $timestamp = date('Ymd_His');
    $type = in_array($type, ['db', 'files', 'full']) ? $type : 'full';

    $tempDir = BACKUP_DIR . '/temp';
    $finalFilename = '';
    $finalPath = '';

    if ($type === 'db') {
        // Database dump only (dikompres gz jika ekstensi zlib aktif)
        $rawSql = $tempDir . "/temp_db_{$timestamp}.sql";
        export_database_sql($rawSql);

        if (function_exists('gzencode')) {
            $finalFilename = "backup_apsd_db_{$timestamp}.sql.gz";
            $finalPath = BACKUP_DIR . '/' . $finalFilename;
            $data = file_get_contents($rawSql);
            file_put_contents($finalPath, gzencode($data, 9));
            @unlink($rawSql);
        } else {
            $finalFilename = "backup_apsd_db_{$timestamp}.sql";
            $finalPath = BACKUP_DIR . '/' . $finalFilename;
            rename($rawSql, $finalPath);
        }

    } elseif ($type === 'files') {
        // Files only (folder uploads)
        $finalFilename = "backup_apsd_files_{$timestamp}.zip";
        $finalPath = BACKUP_DIR . '/' . $finalFilename;

        $zip = new ZipArchive();
        if ($zip->open($finalPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Gagal membuat arsip ZIP berkas unggahan.");
        }

        $uploadsDir = realpath(__DIR__ . '/../uploads');
        if ($uploadsDir && is_dir($uploadsDir)) {
            add_dir_to_zip($zip, $uploadsDir, 'uploads');
        }
        $zip->close();

    } else {
        // Full System Backup: Database + Uploads + Manifest
        $finalFilename = "backup_apsd_full_{$timestamp}.zip";
        $finalPath = BACKUP_DIR . '/' . $finalFilename;

        $tempSql = $tempDir . "/database_{$timestamp}.sql";
        export_database_sql($tempSql);

        $zip = new ZipArchive();
        if ($zip->open($finalPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tempSql);
            throw new Exception("Gagal membuat arsip ZIP cadangan penuh sistem.");
        }

        // 1. Masukkan file SQL database
        $zip->addFile($tempSql, 'database/database.sql');

        // 2. Masukkan seluruh file uploads
        $uploadsDir = realpath(__DIR__ . '/../uploads');
        if ($uploadsDir && is_dir($uploadsDir)) {
            add_dir_to_zip($zip, $uploadsDir, 'uploads');
        }

        // 3. Masukkan manifest info
        $manifest = [
            'app_title' => get_system_setting('app_title', 'APSD PM NOC KNO'),
            'app_version' => APP_VERSION,
            'backup_type' => 'full',
            'created_at' => date('Y-m-d H:i:s'),
            'db_driver' => get_db_connection()->getAttribute(PDO::ATTR_DRIVER_NAME),
            'system_info' => PHP_OS . ' | PHP ' . PHP_VERSION
        ];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        $zip->close();

        @unlink($tempSql);
    }

    if (!file_exists($finalPath) || filesize($finalPath) === 0) {
        throw new Exception("Berkas cadangan kosong atau gagal dibuat.");
    }

    $size = filesize($finalPath);
    $sha256 = hash_file('sha256', $finalPath);

    log_audit_event('SYSTEM', 'BACKUP_CREATE', "Membuat cadangan sistem tipe [{$type}]: {$finalFilename} (" . format_backup_bytes($size) . ")", 'system', null);

    return [
        'success' => true,
        'filename' => $finalFilename,
        'filepath' => $finalPath,
        'type' => $type,
        'size' => $size,
        'size_formatted' => format_backup_bytes($size),
        'sha256' => $sha256,
        'created_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * Helper rekursif untuk menambahkan folder ke ZIP
 */
function add_dir_to_zip(ZipArchive $zip, string $dirPath, string $zipPath): void {
    $files = scandir($dirPath);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..' || $file === '.gitignore' || $file === '.htaccess') {
            continue;
        }
        $fullPath = $dirPath . '/' . $file;
        $inZipPath = $zipPath . '/' . $file;

        if (is_dir($fullPath)) {
            // Hindari memasukkan temp uploads yang sudah expired
            if ($file === 'temp') continue;
            $zip->addEmptyDir($inZipPath);
            add_dir_to_zip($zip, $fullPath, $inZipPath);
        } elseif (is_file($fullPath)) {
            $zip->addFile($fullPath, $inZipPath);
        }
    }
}

/**
 * Potong berkas menjadi beberapa batch/part jika melebihi batas upload Discord (20 MB per part)
 */
function split_file_for_discord(string $filePath, int $maxChunkBytes = DISCORD_CHUNK_SIZE): array {
    if (!file_exists($filePath)) {
        return [];
    }

    $totalSize = filesize($filePath);
    $baseName = basename($filePath);

    // Jika ukuran <= 20 MB, kirim langsung sebagai 1 part tunggal
    if ($totalSize <= $maxChunkBytes) {
        return [
            'is_split' => false,
            'total_parts' => 1,
            'chunks' => [
                [
                    'part_num' => 1,
                    'file_path' => $filePath,
                    'file_name' => $baseName,
                    'size' => $totalSize,
                    'size_formatted' => format_backup_bytes($totalSize)
                ]
            ]
        ];
    }

    // Pemecahan berkas menjadi potongan batch
    $tempDir = BACKUP_DIR . '/temp/' . uniqid('split_');
    @mkdir($tempDir, 0777, true);

    $handle = fopen($filePath, 'rb');
    if (!$handle) {
        return [];
    }

    $chunks = [];
    $partNum = 1;
    $bufferSize = 65536; // 64 KB read buffer
    $totalParts = (int)ceil($totalSize / $maxChunkBytes);

    while (!feof($handle)) {
        $partFileName = sprintf("%s.part%02d_of_%02d", $baseName, $partNum, $totalParts);
        $partPath = $tempDir . '/' . $partFileName;
        $partHandle = fopen($partPath, 'wb');
        $bytesWritten = 0;

        while (!feof($handle) && $bytesWritten < $maxChunkBytes) {
            $readLength = min($bufferSize, $maxChunkBytes - $bytesWritten);
            $buffer = fread($handle, $readLength);
            fwrite($partHandle, $buffer);
            $bytesWritten += strlen($buffer);
        }
        fclose($partHandle);

        $chunks[] = [
            'part_num' => $partNum,
            'file_path' => $partPath,
            'file_name' => $partFileName,
            'size' => $bytesWritten,
            'size_formatted' => format_backup_bytes($bytesWritten)
        ];
        $partNum++;
    }
    fclose($handle);

    return [
        'is_split' => true,
        'total_parts' => count($chunks),
        'temp_dir' => $tempDir,
        'chunks' => $chunks
    ];
}

/**
 * Kirim berkas cadangan ke Saluran Discord via Webhook (Mendukung Multi-batch)
 */
function send_backup_to_discord(string $backupFileName, ?string $customWebhookUrl = null): array {
    $dir = BACKUP_DIR;
    $cleanName = basename($backupFileName);
    $filePath = $dir . '/' . $cleanName;

    if (!file_exists($filePath)) {
        return ['success' => false, 'message' => "Berkas cadangan '{$cleanName}' tidak ditemukan di server."];
    }

    $webhookUrl = trim((string)$customWebhookUrl);
    if (empty($webhookUrl)) {
        // Ambil dari webhook saluran Keamanan & Pemeliharaan sistem
        $webhookUrl = get_system_setting('discord_webhook_security', '');
    }

    if (empty($webhookUrl)) {
        return [
            'success' => false,
            'message' => 'URL Webhook Discord belum dikonfigurasi. Silakan isi Webhook Keamanan di Pengaturan Sistem terlebih dahulu.'
        ];
    }

    $fileSize = filesize($filePath);
    $sha256 = hash_file('sha256', $filePath);
    $appTitle = get_system_setting('app_title', 'APSD PM NOC KNO');
    $now = date('d/m/Y H:i:s');

    // 1. Pecah berkas jika ukuran > 20 MB
    $splitInfo = split_file_for_discord($filePath, DISCORD_CHUNK_SIZE);
    if (empty($splitInfo) || empty($splitInfo['chunks'])) {
        return ['success' => false, 'message' => 'Gagal memproses berkas cadangan untuk pengiriman.'];
    }

    $totalParts = $splitInfo['total_parts'];
    $isSplit = $splitInfo['is_split'];

    // 2. Kirim pesan notifikasi pembuka (Header Embed)
    $introEmbed = [
        'title' => '📦 CADANGAN SISTEM BARU (' . ($isSplit ? "MULTI-BATCH: {$totalParts} BAGIAN" : "TUNGGAL") . ')',
        'description' => "Proses pencadangan data sistem telah berhasil dibuat dan siap diarsipkan ke Discord.\n\n" .
                         "**Nama Berkas:** `{$cleanName}`\n" .
                         "**Kapasitas Total:** `" . format_backup_bytes($fileSize) . "`\n" .
                         "**Waktu Dibuat:** `{$now} WIB`\n" .
                         "**Total Paket:** `{$totalParts} file`\n" .
                         "**Integritas SHA-256:**\n```\n{$sha256}\n```",
        'color' => 0x0284c7, // Biru cerah
        'footer' => [
            'text' => $appTitle . ' • Disaster Recovery Vault'
        ],
        'timestamp' => date('c')
    ];

    send_discord_payload($webhookUrl, [
        'username' => $appTitle . ' [Backup Bot]',
        'embeds' => [$introEmbed]
    ], null, '', 20);

    // 3. Kirim setiap batch / part berkas secara sekuensial
    $successCount = 0;
    foreach ($splitInfo['chunks'] as $idx => $chunk) {
        $partNumber = $chunk['part_num'];
        $partPayload = [
            'username' => $appTitle . ' [Backup Bot]',
            'content' => "📥 **[Batch {$partNumber}/{$totalParts}]** `{$chunk['file_name']}` (" . $chunk['size_formatted'] . ")"
        ];

        // Berikan batas waktu upload cURL hingga 120 detik untuk file besar
        $res = send_discord_payload(
            $webhookUrl,
            $partPayload,
            $chunk['file_path'],
            $chunk['file_name'],
            120
        );

        if ($res['success']) {
            $successCount++;
        } else {
            // Bersihkan temp split dir jika gagal
            if (!empty($splitInfo['temp_dir']) && is_dir($splitInfo['temp_dir'])) {
                clean_temp_dir($splitInfo['temp_dir']);
            }
            return [
                'success' => false,
                'message' => "Gagal mengunggah Batch {$partNumber}/{$totalParts} ke Discord: " . $res['message']
            ];
        }

        // Jeda 1.2 detik antar batch untuk mencegah rate limiting Discord
        if ($partNumber < $totalParts) {
            usleep(1200000);
        }
    }

    // 4. Jika berkas dipecah, kirim pesan instruksi cara menggabungkan kembali
    if ($isSplit) {
        $combineCmdWin = 'copy /b "' . $cleanName . '.part*" "' . $cleanName . '"';
        $combineCmdLinux = 'cat "' . $cleanName . '".part* > "' . $cleanName . '"';

        $summaryEmbed = [
            'title' => '✅ PENCADANGAN MULTI-BATCH SELESAI',
            'description' => "Seluruh {$totalParts} bagian berkas cadangan telah sukses diunggah ke saluran Discord.\n\n" .
                             "**Cara Menggabungkan Kembali Saat Pemulihan (*Restore*):**\n" .
                             "Unduh semua file `.part*` ke dalam 1 folder, lalu jalankan di terminal:\n\n" .
                             "**Di Windows (Command Prompt):**\n```bat\n{$combineCmdWin}\n```\n" .
                             "**Di Linux / Server (Terminal):**\n```bash\n{$combineCmdLinux}\n```\n" .
                             "Setelah digabung, ekstrak berkas `{$cleanName}` seperti biasa.",
            'color' => 0x10b981, // Hijau emerald
            'footer' => [
                'text' => 'Integritas Berkas Terverifikasi: SHA-256 Cocok'
            ]
        ];

        send_discord_payload($webhookUrl, [
            'username' => $appTitle . ' [Backup Bot]',
            'embeds' => [$summaryEmbed]
        ], null, '', 20);
    }

    // Bersihkan temporary split directory
    if (!empty($splitInfo['temp_dir']) && is_dir($splitInfo['temp_dir'])) {
        clean_temp_dir($splitInfo['temp_dir']);
    }

    log_audit_event('SYSTEM', 'BACKUP_DISCORD_SEND', "Mengirim cadangan '{$cleanName}' ke Discord ({$totalParts} batch, " . format_backup_bytes($fileSize) . ")", 'system', null);

    return [
        'success' => true,
        'message' => "Cadangan '{$cleanName}' berhasil dikirim ke Discord dalam {$totalParts} batch!",
        'total_parts' => $totalParts,
        'file_name' => $cleanName,
        'size_formatted' => format_backup_bytes($fileSize)
    ];
}

/**
 * Dapatkan riwayat berkas cadangan yang tersimpan di server
 */
function get_backup_history(): array {
    ensure_backup_dir();
    $dir = BACKUP_DIR;
    $files = scandir($dir);
    $history = [];

    foreach ($files as $f) {
        if ($f === '.' || $f === '..' || $f === '.htaccess' || $f === '.gitkeep' || $f === 'temp') {
            continue;
        }
        $fullPath = $dir . '/' . $f;
        if (is_file($fullPath)) {
            $sz = filesize($fullPath);
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            $type = 'Lainnya';
            if (strpos($f, '_db_') !== false || $ext === 'sql' || $ext === 'gz') {
                $type = 'Basis Data (SQL)';
            } elseif (strpos($f, '_files_') !== false) {
                $type = 'Berkas Unggahan (ZIP)';
            } elseif (strpos($f, '_full_') !== false) {
                $type = 'Cadangan Penuh (Full System)';
            }

            $history[] = [
                'filename' => $f,
                'filepath' => $fullPath,
                'size' => $sz,
                'size_formatted' => format_backup_bytes($sz),
                'type' => $type,
                'created_at' => date('Y-m-d H:i:s', filemtime($fullPath)),
                'timestamp' => filemtime($fullPath)
            ];
        }
    }

    // Urutkan dari yang paling baru
    usort($history, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });

    return $history;
}

/**
 * Hapus berkas cadangan secara aman
 */
function delete_backup_file(string $fileName): bool {
    ensure_backup_dir();
    $clean = basename($fileName);
    $p = BACKUP_DIR . '/' . $clean;
    if (is_file($p) && file_exists($p)) {
        $sz = filesize($p);
        if (@unlink($p)) {
            log_audit_event('SYSTEM', 'BACKUP_DELETE', "Menghapus berkas cadangan: {$clean} (" . format_backup_bytes($sz) . ")", 'system', null);
            return true;
        }
    }
    return false;
}

/**
 * Helper hapus folder sementara secara bersih
 */
function clean_temp_dir(string $dir): void {
    if (!is_dir($dir)) return;
    $files = scandir($dir);
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p)) {
            clean_temp_dir($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}
