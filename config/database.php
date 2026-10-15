<?php
/**
 * Dual Database Configuration & Auto Migration (MySQL & SQLite)
 * PT Angkasa Pura Sarana Digital - Sistem PM
 */

function get_db_connection() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $connection = strtolower(getenv('DB_CONNECTION') ?: 'sqlite');

    if ($connection === 'mysql') {
        $pdo = connect_mysql();
    } else {
        $pdo = connect_sqlite();
    }

    return $pdo;
}

/**
 * Production MySQL / MariaDB Connection with Auto-Retry & Auto-Migration
 */
function connect_mysql(): PDO {
    $dbHost = getenv('DB_HOST') ?: '127.0.0.1';
    $dbPort = getenv('DB_PORT') ?: '3306';
    $dbName = getenv('DB_DATABASE') ?: 'apsd_pm';
    $dbUser = getenv('DB_USERNAME') ?: 'root';
    $dbPass = getenv('DB_PASSWORD') ?: '';

    $maxRetries = 10;
    $retryDelay = 2; // seconds
    $pdo = null;

    for ($i = 0; $i < $maxRetries; $i++) {
        try {
            $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5
            ]);
            break;
        } catch (PDOException $e) {
            // Error 1049: Unknown database - try creating it
            if ($e->getCode() == 1049) {
                try {
                    $rootPdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass);
                    $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $pdo = new PDO($dsn, $dbUser, $dbPass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                    ]);
                    break;
                } catch (Exception $ex) {
                    // Retry on next loop
                }
            }

            if ($i === $maxRetries - 1) {
                die("Koneksi MySQL gagal ({$dbHost}:{$dbPort}): " . htmlspecialchars($e->getMessage()));
            }
            sleep($retryDelay);
        }
    }

    init_mysql_schema($pdo);
    return $pdo;
}

/**
 * Local Development SQLite Connection with WAL mode
 */
function connect_sqlite(): PDO {
    $dbDir = __DIR__ . '/../database';
    if (!is_dir($dbDir)) {
        @mkdir($dbDir, 0777, true);
    }
    @chmod($dbDir, 0777);

    $dbPath = $dbDir . '/apsd.sqlite';
    $isNew = !file_exists($dbPath);
    if (file_exists($dbPath)) {
        @chmod($dbPath, 0666);
    }

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // WAL mode & busy timeout for robust concurrency
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA busy_timeout = 5000;');

        init_sqlite_schema($pdo, $isNew);
        return $pdo;
    } catch (PDOException $e) {
        die("Koneksi SQLite gagal: " . htmlspecialchars($e->getMessage()));
    }
}

/**
 * MySQL Schema Initialization
 */
function init_mysql_schema(PDO $pdo) {
    // 1. Table sites
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sites (
            id INT AUTO_INCREMENT PRIMARY KEY,
            site_id_str VARCHAR(100) NULL,
            site_name VARCHAR(255) NOT NULL,
            location_detail VARCHAR(255) NULL,
            region VARCHAR(100) NULL,
            province VARCHAR(100) NULL,
            city VARCHAR(100) NULL,
            address TEXT NULL,
            latitude VARCHAR(50) NULL,
            longitude VARCHAR(50) NULL,
            site_height VARCHAR(50) NULL,
            tower_type VARCHAR(100) NULL,
            site_type VARCHAR(100) NULL,
            anchor_tenant VARCHAR(100) DEFAULT 'APSD',
            total_tenant INT DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sites_name (site_name),
            INDEX idx_sites_code (site_id_str)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 2. Table pm_reports
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_type VARCHAR(20) NOT NULL,
            site_id INT NULL,
            form_no VARCHAR(50) DEFAULT '1',
            effective_date VARCHAR(50) DEFAULT '01-Jul-25',
            period VARCHAR(50) DEFAULT 'Jul-25',
            report_date DATE NOT NULL,
            inspection_time VARCHAR(20) NULL,
            timezone VARCHAR(10) DEFAULT 'WIB',
            inspector_name VARCHAR(100) NOT NULL,
            inspector_phone VARCHAR(50) NULL,
            team_leader VARCHAR(100) NULL,
            om_vendor VARCHAR(150) DEFAULT 'PT. Naer Tunas Indonesia',
            operator VARCHAR(100) DEFAULT 'TELKOMSEL',
            custom_logo_path VARCHAR(255) NULL,
            notes TEXT NULL,
            status VARCHAR(20) DEFAULT 'completed',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_rep_type (report_type),
            INDEX idx_rep_date (report_date),
            INDEX idx_rep_site (site_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 3. Table pm_indoor_details
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_indoor_details (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_id INT NOT NULL UNIQUE,
            site_snapshot_json LONGTEXT NULL,
            checklist_json LONGTEXT NULL,
            FOREIGN KEY (report_id) REFERENCES pm_reports(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 4. Table pm_outdoor_details
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_outdoor_details (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_id INT NOT NULL UNIQUE,
            site_snapshot_json LONGTEXT NULL,
            tenant_list_json LONGTEXT NULL,
            grounding_json LONGTEXT NULL,
            tower_json LONGTEXT NULL,
            lighting_fence_other_json LONGTEXT NULL,
            structural_json LONGTEXT NULL,
            tenant_ac_json LONGTEXT NULL,
            tenant_electrical_json LONGTEXT NULL,
            pln_function_json LONGTEXT NULL,
            FOREIGN KEY (report_id) REFERENCES pm_reports(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 5. Table pm_photos
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_photos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_id INT NOT NULL,
            slot_code VARCHAR(20) NOT NULL,
            title VARCHAR(255) NOT NULL,
            image_path VARCHAR(255) NULL,
            measurement_value VARCHAR(100) NULL,
            is_na TINYINT DEFAULT 0,
            page_group VARCHAR(10) NULL,
            uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_photo_rep (report_id),
            INDEX idx_photo_slot (report_id, slot_code),
            INDEX idx_photo_grp (report_id, page_group),
            FOREIGN KEY (report_id) REFERENCES pm_reports(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 6. Table users (Authentication & RBAC)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'user',
            is_active INT NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_login DATETIME NULL,
            INDEX idx_users_username (username),
            INDEX idx_users_role (role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    seed_default_sites_if_empty($pdo);
    seed_default_users_if_empty($pdo);
}

/**
 * SQLite Schema Initialization
 */
function init_sqlite_schema(PDO $pdo, bool $isNew) {
    // 1. Table sites
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id_str VARCHAR(100) NULL,
            site_name VARCHAR(255) NOT NULL,
            location_detail VARCHAR(255) NULL,
            region VARCHAR(100) NULL,
            province VARCHAR(100) NULL,
            city VARCHAR(100) NULL,
            address TEXT NULL,
            latitude VARCHAR(50) NULL,
            longitude VARCHAR(50) NULL,
            site_height VARCHAR(50) NULL,
            tower_type VARCHAR(100) NULL,
            site_type VARCHAR(100) NULL,
            anchor_tenant VARCHAR(100) DEFAULT 'APSD',
            total_tenant INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // 2. Table pm_reports
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_type VARCHAR(20) NOT NULL,
            site_id INTEGER NULL,
            form_no VARCHAR(50) DEFAULT '1',
            effective_date VARCHAR(50) DEFAULT '01-Jul-25',
            period VARCHAR(50) DEFAULT 'Jul-25',
            report_date DATE NOT NULL,
            inspection_time VARCHAR(20) NULL,
            timezone VARCHAR(10) DEFAULT 'WIB',
            inspector_name VARCHAR(100) NOT NULL,
            inspector_phone VARCHAR(50) NULL,
            team_leader VARCHAR(100) NULL,
            om_vendor VARCHAR(150) DEFAULT 'PT. Naer Tunas Indonesia',
            operator VARCHAR(100) DEFAULT 'TELKOMSEL',
            custom_logo_path VARCHAR(255) NULL,
            notes TEXT NULL,
            status VARCHAR(20) DEFAULT 'completed',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    try {
        $pdo->exec("ALTER TABLE pm_reports ADD COLUMN custom_logo_path VARCHAR(255) NULL");
    } catch (Exception $e) {}

    // 3. Table pm_indoor_details
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_indoor_details (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id INTEGER NOT NULL UNIQUE,
            site_snapshot_json TEXT NULL,
            checklist_json TEXT NULL,
            FOREIGN KEY (report_id) REFERENCES pm_reports(id) ON DELETE CASCADE
        )
    ");

    // 4. Table pm_outdoor_details
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_outdoor_details (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id INTEGER NOT NULL UNIQUE,
            site_snapshot_json TEXT NULL,
            tenant_list_json TEXT NULL,
            grounding_json TEXT NULL,
            tower_json TEXT NULL,
            lighting_fence_other_json TEXT NULL,
            structural_json TEXT NULL,
            tenant_ac_json TEXT NULL,
            tenant_electrical_json TEXT NULL,
            pln_function_json TEXT NULL,
            FOREIGN KEY (report_id) REFERENCES pm_reports(id) ON DELETE CASCADE
        )
    ");

    // 5. Table pm_photos
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_photos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id INTEGER NOT NULL,
            slot_code VARCHAR(20) NOT NULL,
            title VARCHAR(255) NOT NULL,
            image_path VARCHAR(255) NULL,
            measurement_value VARCHAR(100) NULL,
            is_na INTEGER DEFAULT 0,
            page_group VARCHAR(10) NULL,
            uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (report_id) REFERENCES pm_reports(id) ON DELETE CASCADE
        )
    ");

    // 6. Performance Indexing
    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_pm_reports_type ON pm_reports(report_type);
        CREATE INDEX IF NOT EXISTS idx_pm_reports_date ON pm_reports(report_date);
        CREATE INDEX IF NOT EXISTS idx_pm_reports_site_id ON pm_reports(site_id);
        CREATE INDEX IF NOT EXISTS idx_pm_photos_report_id ON pm_photos(report_id);
        CREATE INDEX IF NOT EXISTS idx_pm_photos_report_slot ON pm_photos(report_id, slot_code);
        CREATE INDEX IF NOT EXISTS idx_pm_photos_page_group ON pm_photos(report_id, page_group);
        CREATE INDEX IF NOT EXISTS idx_sites_site_name ON sites(site_name);
        CREATE INDEX IF NOT EXISTS idx_sites_site_id_str ON sites(site_id_str);
    ");

    // 7. Table users (Authentication & RBAC)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'user',
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_login DATETIME NULL
        );
        CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);
        CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);
    ");

    seed_default_sites_if_empty($pdo);
    seed_default_users_if_empty($pdo);
}

/**
 * Seed Default Master Sites if table has zero rows
 */
function seed_default_sites_if_empty(PDO $pdo) {
    $count = $pdo->query("SELECT COUNT(*) FROM sites")->fetchColumn();
    if ($count == 0) {
        $stmt = $pdo->prepare("
            INSERT INTO sites (
                site_id_str, site_name, location_detail, region, province, city, 
                address, latitude, longitude, site_height, tower_type, site_type, anchor_tenant, total_tenant
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        // Seed 1: Indoor Kualanamu
        $stmt->execute([
            '',
            'IBC Bandara International KUALANAMU',
            'Server Domestik',
            'Deli Serdang',
            'Sumatra Utara',
            'Medan',
            'Jl. Bandara Kuala Namu, Ps. Enam Kuala Namu, Kec. Beringin, Kabupaten Deli Serdang, Sumatera Utara',
            '3.618278',
            '98.861139',
            '-',
            'Non Pole',
            'Indoor',
            'TELKOMSEL',
            1
        ]);

        // Seed 2: Outdoor Pole 2 Kualanamu
        $stmt->execute([
            'APSD-DSER-078-08',
            'Bandara Kualanamu Pole 2',
            'Area Pole 2',
            'Sumbagut',
            'Sumatra Utara',
            'Medan',
            'Jl. Bandara Kuala Namu, Ps. Enam Kuala Namu, Kec. Beringin, Kabupaten Deli Serdang, Sumatera Utara 20553',
            '3.618278',
            '98.861139',
            '18',
            'Pole PJU 18 m',
            'Green Field',
            'APSD',
            1
        ]);
    }
}

/**
 * Seed Default Users if table has zero rows
 */
function seed_default_users_if_empty(PDO $pdo) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($count == 0) {
            $stmt = $pdo->prepare("
                INSERT INTO users (username, password_hash, full_name, role, is_active)
                VALUES (?, ?, ?, ?, ?)
            ");

            // 1. Superadmin (Super Administrator KNO)
            $stmt->execute([
                'superadmin',
                password_hash('admin123', PASSWORD_BCRYPT),
                'Super Administrator KNO',
                'superadmin',
                1
            ]);

            // 2. Admin (Administrator SBU ICT)
            $stmt->execute([
                'admin',
                password_hash('admin123', PASSWORD_BCRYPT),
                'Administrator SBU ICT',
                'admin',
                1
            ]);

            // 3. User biasa (Teknisi Lapangan KNO)
            $stmt->execute([
                'teknisi',
                password_hash('admin123', PASSWORD_BCRYPT),
                'Teknisi Lapangan KNO',
                'user',
                1
            ]);
        }
    } catch (Exception $e) {
        // Ignored if table already exists or initializing
    }
}

