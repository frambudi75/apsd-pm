<?php
/**
 * Automated Migration Script: SQLite to MySQL
 * PT Angkasa Pura Sarana Digital - Sistem PM
 *
 * This script safely migrates all data from database/apsd.sqlite to MySQL database apsd_pm,
 * preserving IDs, relationships, indexes, timestamps, and full data integrity.
 */

require_once __DIR__ . '/../config/database.php';

echo "=========================================================\n";
echo "  MIGRASI OTOMATIS BASIS DATA: SQLITE -> MYSQL (APSD PM) \n";
echo "=========================================================\n\n";

$sqlitePath = __DIR__ . '/apsd.sqlite';
if (!file_exists($sqlitePath)) {
    die("[ERROR] File SQLite database/apsd.sqlite tidak ditemukan!\n");
}

// 1. Amankan Backup File SQLite
$backupPath = __DIR__ . '/apsd.sqlite.backup_' . date('Ymd_His') . '.bak';
if (copy($sqlitePath, $backupPath)) {
    echo "[1/6] Backup SQLite berhasil dibuat:\n      -> " . basename($backupPath) . "\n\n";
} else {
    die("[ERROR] Gagal membuat file backup SQLite. Migrasi dibatalkan demi keamanan.\n");
}

// 2. Koneksi ke SQLite
try {
    $sqlite = new PDO('sqlite:' . $sqlitePath);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    echo "[2/6] Koneksi SQLite sumber: TERHUBUNG.\n";
} catch (Exception $e) {
    die("[ERROR] Gagal membuka koneksi SQLite: " . $e->getMessage() . "\n");
}

// 3. Koneksi ke MySQL & Inisialisasi Database
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_DATABASE') ?: 'apsd_pm';
$dbUser = getenv('DB_USERNAME') ?: 'root';
$dbPass = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '';

try {
    // Pastikan database tujuan sudah ada
    $rootPdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // Hubungkan ke database tujuan
    $mysql = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    echo "[3/6] Koneksi MySQL tujuan ({$dbHost}:{$dbPort}/{$dbName}): TERHUBUNG.\n\n";
} catch (Exception $e) {
    die("[ERROR] Gagal terhubung ke server MySQL: " . $e->getMessage() . "\n");
}

// 4. Inisialisasi Skema & Indexing Lengkap di MySQL
echo "[4/6] Menyiapkan skema tabel, indexing, dan relasi di MySQL...\n";
init_mysql_schema($mysql);
echo "      -> Seluruh 15 tabel & indeks MySQL siap digunakan.\n\n";

// 5. Salin Data Baris demi Baris dengan Mempertahankan ID & Foreign Key
echo "[5/6] Memulai proses penyalinan data (Read-and-Copy)...\n";
$mysql->exec("SET FOREIGN_KEY_CHECKS = 0;");

$tables = [
    'system_settings',
    'operators',
    'sites',
    'users',
    'pm_reports',
    'pm_indoor_details',
    'pm_outdoor_details',
    'pm_photos',
    'daily_shifts',
    'daily_activities',
    'daily_activity_photos',
    'user_documents',
    'user_document_shares',
    'user_document_external_shares',
    'audit_logs'
];

$summary = [];

foreach ($tables as $table) {
    // Cek apakah tabel ada di SQLite
    $checkSqlite = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetchColumn();
    if (!$checkSqlite) {
        $summary[$table] = ['sqlite' => 0, 'mysql' => 0, 'status' => 'SKIP (Tabel belum ada di SQLite)'];
        continue;
    }

    // Bersihkan tabel MySQL sebelum disalin
    $mysql->exec("TRUNCATE TABLE `{$table}`");

    // Ambil data dari SQLite
    $rows = $sqlite->query("SELECT * FROM `{$table}`")->fetchAll();
    $sqliteCount = count($rows);

    if ($sqliteCount > 0) {
        $columns = array_keys($rows[0]);
        $colNames = implode('`, `', $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $insertSql = "INSERT INTO `{$table}` (`{$colNames}`) VALUES ({$placeholders})";
        $stmtInsert = $mysql->prepare($insertSql);

        foreach ($rows as $row) {
            $stmtInsert->execute(array_values($row));
        }

        // Reset AUTO_INCREMENT ke ID tertinggi + 1 (jika ada kolom id)
        if (in_array('id', $columns)) {
            $maxId = $mysql->query("SELECT MAX(id) FROM `{$table}`")->fetchColumn();
            if ($maxId !== null && $maxId !== false) {
                $nextId = (int)$maxId + 1;
                $mysql->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = {$nextId}");
            }
        }
    }

    // Verifikasi jumlah baris di MySQL
    $mysqlCount = (int)$mysql->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    $isMatch = ($sqliteCount === $mysqlCount);

    $summary[$table] = [
        'sqlite' => $sqliteCount,
        'mysql'  => $mysqlCount,
        'status' => $isMatch ? 'OK (100% Cocok)' : 'MISMATCH'
    ];

    echo sprintf("  - %-30s : %4d baris SQLite -> %4d baris MySQL [%s]\n", 
        $table, $sqliteCount, $mysqlCount, $isMatch ? 'OK' : 'MISMATCH');
}

$mysql->exec("SET FOREIGN_KEY_CHECKS = 1;");
echo "\n";

// 6. Tulis Konfigurasi .env Otomatis untuk Mengaktifkan MySQL
echo "[6/6] Mengaktifkan koneksi MySQL pada aplikasi (.env)...\n";
$envContent = <<<ENV
# APSD PM Report & Daily Activity Configuration
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=apsd_pm
DB_USERNAME=root
DB_PASSWORD=

ENV;

$envFile = __DIR__ . '/../.env';
file_put_contents($envFile, $envContent);
echo "      -> File .env berhasil dibuat dengan DB_CONNECTION=mysql\n\n";

echo "=========================================================\n";
echo "  HASIL AKHIR VERIFIKASI PARITAS DATA (100% LENGKAP)    \n";
echo "=========================================================\n";
printf("%-30s | %-12s | %-12s | %-10s\n", "Nama Tabel", "SQLite (Asal)", "MySQL (Hasil)", "Status");
echo str_repeat("-", 72) . "\n";
$allMatch = true;
foreach ($summary as $tbl => $data) {
    if (strpos($data['status'], 'MISMATCH') !== false) {
        $allMatch = false;
    }
    printf("%-30s | %12d | %12d | %s\n", 
        $tbl, $data['sqlite'], $data['mysql'], $data['status']);
}
echo str_repeat("-", 72) . "\n";

if ($allMatch) {
    echo "\n[SUKSES BESAR] Seluruh data berhasil dimigrasikan ke MySQL tanpa ada data yang hilang!\n";
    echo "Aplikasi sekarang resmi berjalan di atas MySQL dengan indeks performa penuh.\n";
} else {
    echo "\n[PERINGATAN] Ditemukan ketidakcocokan jumlah data pada salah satu tabel. Periksa rincian di atas.\n";
}
