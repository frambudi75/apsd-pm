<?php
/**
 * API: Jalankan Auto-Optimise & Pemeliharaan Sistem
 * PT Angkasa Pura Sarana Digital - Sistem PM
 *
 * Khusus Superadmin: Mengoptimalkan database (VACUUM / OPTIMIZE),
 * membersihkan temporary storage, dan menonaktifkan link share expired.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';

// Strict RBAC: Hanya Superadmin yang boleh menjalankan optimasi sistem
if (!is_logged_in() || !has_role('superadmin')) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Akses ditolak: Hanya Superadmin yang berwenang melakukan optimasi sistem.'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode request tidak diizinkan.']);
    exit;
}

$pdo = get_db_connection();
$steps = [];
$totalFreedBytes = 0;
$startTime = microtime(true);

try {
    // 0. DATABASE AUTO-REPAIR & HEALTH INTEGRITY SCAN
    $repairReport = auto_repair_database($pdo, true);
    $steps[] = [
        'name' => 'Auto-Repair & Pemindaian Integritas Basis Data',
        'status' => 'success',
        'detail' => $repairReport['message']
    ];

    // 1. DATABASE OPTIMIZATION
    $dbDriver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $dbSizeBefore = 0;
    $dbSizeAfter = 0;

    if ($dbDriver === 'sqlite') {
        $dbPath = __DIR__ . '/../database/apsd.sqlite';
        if (file_exists($dbPath)) {
            $dbSizeBefore = filesize($dbPath);
        }

        // Run WAL checkpoint truncate and VACUUM
        $pdo->exec("PRAGMA wal_checkpoint(TRUNCATE);");
        $pdo->exec("VACUUM;");
        $pdo->exec("PRAGMA optimize;");

        clearstatcache(true, $dbPath);
        if (file_exists($dbPath)) {
            $dbSizeAfter = filesize($dbPath);
        }

        $dbDiff = $dbSizeBefore - $dbSizeAfter;
        if ($dbDiff > 0) {
            $totalFreedBytes += $dbDiff;
        }

        $steps[] = [
            'name' => 'Defragmentasi Database (SQLite VACUUM & WAL Truncate)',
            'status' => 'success',
            'detail' => 'Ukuran DB sebelum: ' . format_bytes_opt($dbSizeBefore) . ', sesudah: ' . format_bytes_opt($dbSizeAfter) . ($dbDiff > 0 ? ' (hemat ' . format_bytes_opt($dbDiff) . ')' : ' (database sudah ringkas)')
        ];
    } else {
        // MySQL / MariaDB
        $tables = [
            'sites',
            'pm_reports',
            'pm_indoor_details',
            'pm_outdoor_details',
            'pm_photos',
            'users',
            'operators',
            'daily_shifts',
            'daily_activities',
            'daily_activity_photos',
            'system_settings',
            'user_documents',
            'user_document_shares',
            'user_document_external_shares',
            'audit_logs'
        ];

        $dbSizeBefore = 0;
        try {
            $dbSizeBefore = (int)$pdo->query("
                SELECT SUM(data_length + index_length) 
                FROM information_schema.TABLES 
                WHERE table_schema = DATABASE()
            ")->fetchColumn();
        } catch (Exception $e) {}

        $optimizedCount = 0;
        foreach ($tables as $tbl) {
            try {
                $stmt = $pdo->query("OPTIMIZE TABLE `{$tbl}`");
                if ($stmt) {
                    $stmt->fetchAll();
                    $stmt->closeCursor();
                }
                $optimizedCount++;
            } catch (Exception $e) {}
        }

        $dbSizeAfter = 0;
        try {
            $dbSizeAfter = (int)$pdo->query("
                SELECT SUM(data_length + index_length) 
                FROM information_schema.TABLES 
                WHERE table_schema = DATABASE()
            ")->fetchColumn();
        } catch (Exception $e) {}

        $dbDiff = $dbSizeBefore - $dbSizeAfter;
        if ($dbDiff > 0) {
            $totalFreedBytes += $dbDiff;
        }

        $steps[] = [
            'name' => 'Defragmentasi Tabel & Indeks (MySQL OPTIMIZE TABLE)',
            'status' => 'success',
            'detail' => "Berhasil mengoptimasi {$optimizedCount} tabel sistem utama. Kapasitas DB: " . format_bytes_opt($dbSizeAfter) . ($dbDiff > 0 ? ' (hemat ' . format_bytes_opt($dbDiff) . ')' : ' (struktur tabel & indeks optimal)')
        ];
    }

    // 2. TEMPORARY & ORPHANED FILES PURGE
    $tempDir = __DIR__ . '/../uploads/temp';
    $cleanedFilesCount = 0;
    $cleanedBytes = 0;

    if (is_dir($tempDir)) {
        $files = scandir($tempDir);
        $now = time();
        foreach ($files as $f) {
            if ($f === '.' || $f === '..' || $f === '.gitignore' || $f === 'web.config' || $f === '.htaccess') {
                continue;
            }
            $filePath = $tempDir . '/' . $f;
            if (is_file($filePath)) {
                // Hapus berkas sementara yang berumur lebih dari 24 jam (86400 detik)
                if (($now - filemtime($filePath)) > 86400) {
                    $sz = filesize($filePath);
                    if (@unlink($filePath)) {
                        $cleanedFilesCount++;
                        $cleanedBytes += $sz;
                    }
                }
            }
        }
    }
    $totalFreedBytes += $cleanedBytes;

    $steps[] = [
        'name' => 'Pembersihan Berkas Sementara (uploads/temp/)',
        'status' => 'success',
        'detail' => "Berhasil membersihkan {$cleanedFilesCount} berkas sementara kadaluwarsa (" . format_bytes_opt($cleanedBytes) . " dibebaskan)."
    ];

    // 3. STORAGE & PHOTO OPTIMIZATION (Optimasi Foto Berukuran > 700 KB)
    require_once __DIR__ . '/../includes/image_compressor.php';
    $optimizedPhotosCount = 0;
    $photoFreedBytes = 0;

    $scanAndOptimizeImages = function(string $dir) use (&$scanAndOptimizeImages, &$optimizedPhotosCount, &$photoFreedBytes): void {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === 'temp' || $item === '.gitignore' || $item === '.htaccess') continue;
            $fullPath = $dir . '/' . $item;
            if (is_dir($fullPath)) {
                $scanAndOptimizeImages($fullPath);
            } elseif (is_file($fullPath)) {
                $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $origSize = (int)filesize($fullPath);
                    // Jika ukuran file foto melebihi 700 KB, lakukan optimasi kompresi ulang
                    if ($origSize > 716800) {
                        $tempOptimized = $fullPath . '.opt.tmp';
                        if (compress_and_save_image($fullPath, $tempOptimized, 1600, 82)) {
                            $newSize = (int)filesize($tempOptimized);
                            if ($newSize > 0 && $newSize < $origSize) {
                                rename($tempOptimized, $fullPath);
                                $photoFreedBytes += ($origSize - $newSize);
                                $optimizedPhotosCount++;
                            } else {
                                @unlink($tempOptimized);
                            }
                        }
                    }
                }
            }
        }
    };

    $uploadsBase = realpath(__DIR__ . '/../uploads');
    if ($uploadsBase && is_dir($uploadsBase)) {
        $scanAndOptimizeImages($uploadsBase . '/reports');
        $scanAndOptimizeImages($uploadsBase . '/daily');
    }

    $totalFreedBytes += $photoFreedBytes;

    $steps[] = [
        'name' => 'Optimasi & Kompresi Foto Fisik (uploads/reports/ & daily/)',
        'status' => 'success',
        'detail' => ($optimizedPhotosCount > 0)
            ? "Berhasil mengompresi {$optimizedPhotosCount} foto berukuran besar (" . format_bytes_opt($photoFreedBytes) . " kapasitas dibebaskan)."
            : "Seluruh foto fisik telah berada pada rasio kompresi optimal (&le; 700 KB per foto)."
    ];

    // 4. EXPIRED EXTERNAL SHARES MAINTENANCE
    $expiredSharesCount = 0;
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmtExp = $pdo->prepare("
                UPDATE user_document_external_shares 
                SET is_active = 0, updated_at = datetime('now', 'localtime') 
                WHERE is_active = 1 AND expires_at IS NOT NULL AND expires_at < datetime('now', 'localtime')
            ");
        } else {
            $stmtExp = $pdo->prepare("
                UPDATE user_document_external_shares 
                SET is_active = 0, updated_at = NOW() 
                WHERE is_active = 1 AND expires_at IS NOT NULL AND expires_at < NOW()
            ");
        }
        $stmtExp->execute();
        $expiredSharesCount = $stmtExp->rowCount();
    } catch (Exception $e) {}

    $steps[] = [
        'name' => 'Pengarsipan Tautan Berbagi Eksternal Kadaluwarsa',
        'status' => 'success',
        'detail' => "{$expiredSharesCount} tautan eksternal yang melewati batas waktu berhasil dinonaktifkan otomatis."
    ];

    // 4. STORAGE SAVINGS ANALYSIS
    $rawTotal = 0;
    $compTotal = 0;
    $docCount = 0;
    try {
        $stmtDocs = $pdo->query("SELECT COUNT(*) as c, SUM(file_size_raw) as raw_sz, SUM(file_size_compressed) as comp_sz FROM user_documents");
        $docRow = $stmtDocs->fetch(PDO::FETCH_ASSOC);
        if ($docRow) {
            $docCount = (int)($docRow['c'] ?? 0);
            $rawTotal = (int)($docRow['raw_sz'] ?? 0);
            $compTotal = (int)($docRow['comp_sz'] ?? 0);
        }
    } catch (Exception $e) {}

    $savedVaultBytes = ($rawTotal > $compTotal) ? ($rawTotal - $compTotal) : 0;
    $savedVaultPct = ($rawTotal > 0) ? round(($savedVaultBytes / $rawTotal) * 100, 1) : 0;

    $steps[] = [
        'name' => 'Analisis Efisiensi Kompresi Brankas Dokumen',
        'status' => 'success',
        'detail' => "Total {$docCount} dokumen tersimpan. Kompresi transparan menghemat " . format_bytes_opt($savedVaultBytes) . " ({$savedVaultPct}% kapasitas disk dihemat)."
    ];

    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    // 5. LOG SYSTEM EVENT
    $summaryDesc = "Auto-optimise selesai dalam {$executionTime} ms. Pembersihan {$cleanedFilesCount} temp files, {$expiredSharesCount} tautan expired dinonaktifkan.";
    log_audit_event('SYSTEM', 'SYSTEM_OPTIMIZE', $summaryDesc, 'system', null);

    // Kirim notifikasi hasil pemeliharaan ke Discord Saluran Keamanan & Pemeliharaan
    try {
        require_once __DIR__ . '/../includes/discord.php';
        discord_notify_system_optimize([
            'cleaned_files' => $cleanedFilesCount,
            'expired_links' => $expiredSharesCount,
            'vault_savings' => format_bytes_opt($savedVaultBytes),
            'vault_savings_pct' => $savedVaultPct
        ], $executionTime);
    } catch (Throwable $eDisc) {
        error_log('Discord system optimize notification warning: ' . $eDisc->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Auto-Optimise sistem selesai dieksekusi dengan sukses!',
        'execution_time_ms' => $executionTime,
        'freed_bytes' => $totalFreedBytes,
        'freed_formatted' => format_bytes_opt($totalFreedBytes),
        'steps' => $steps,
        'stats' => [
            'cleaned_files' => $cleanedFilesCount,
            'expired_links' => $expiredSharesCount,
            'vault_savings' => format_bytes_opt($savedVaultBytes),
            'vault_savings_pct' => $savedVaultPct
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Gagal menjalankan optimasi sistem: ' . $e->getMessage()
    ]);
}

/**
 * Format bytes helper
 */
function format_bytes_opt(int $bytes): string {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = (int)floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}
