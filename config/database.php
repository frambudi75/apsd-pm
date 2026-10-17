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
            email VARCHAR(100) NULL,
            phone VARCHAR(50) NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'user',
            is_active INT NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_login DATETIME NULL,
            INDEX idx_users_username (username),
            INDEX idx_users_role (role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    try { $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(100) NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(50) NULL"); } catch (Exception $e) {}

    // 7. Table operators (Master Operator / Tenant)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS operators (
            id INT AUTO_INCREMENT PRIMARY KEY,
            operator_code VARCHAR(50) NOT NULL UNIQUE,
            operator_name VARCHAR(100) NOT NULL,
            brand_name VARCHAR(50) NOT NULL,
            logo_path VARCHAR(255) NULL,
            is_active INT NOT NULL DEFAULT 1,
            display_order INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_op_code (operator_code),
            INDEX idx_op_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 8. Table daily_shifts (NOC Shift Logbook Sessions)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS daily_shifts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            technician_name VARCHAR(100) NOT NULL,
            shift_date DATE NOT NULL,
            shift_type VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            INDEX idx_shift_user (user_id),
            INDEX idx_shift_date (shift_date),
            INDEX idx_shift_type (shift_type),
            INDEX idx_shift_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 9. Table daily_activities (Individual activities within a shift)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS daily_activities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            shift_id INT NOT NULL,
            user_id INT NOT NULL,
            activity_time VARCHAR(20) NOT NULL,
            category VARCHAR(50) NOT NULL,
            location VARCHAR(150) NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_act_shift (shift_id),
            INDEX idx_act_user (user_id),
            INDEX idx_act_cat (category),
            FOREIGN KEY (shift_id) REFERENCES daily_shifts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 10. Table daily_activity_photos (Dynamic photo attachments per activity)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS daily_activity_photos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            activity_id INT NOT NULL,
            photo_path VARCHAR(255) NOT NULL,
            caption VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_actphoto_act (activity_id),
            FOREIGN KEY (activity_id) REFERENCES daily_activities(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 11. Table system_settings (System Preferences & Default Leader)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(50) PRIMARY KEY,
            setting_value TEXT NULL,
            setting_label VARCHAR(100) NULL,
            setting_group VARCHAR(50) DEFAULT 'general',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 12. Table user_documents (Personal & Shared Document Vault)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            category VARCHAR(100) NOT NULL DEFAULT 'Lainnya',
            description TEXT NULL,
            original_filename VARCHAR(255) NOT NULL,
            stored_filename VARCHAR(255) NOT NULL,
            file_size_raw BIGINT NOT NULL,
            file_size_compressed BIGINT NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_ext VARCHAR(20) NOT NULL,
            is_compressed TINYINT NOT NULL DEFAULT 1,
            privacy_type VARCHAR(20) NOT NULL DEFAULT 'private',
            expiry_date DATE NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_doc_user (user_id),
            INDEX idx_doc_cat (category),
            INDEX idx_doc_privacy (privacy_type),
            INDEX idx_doc_expiry (expiry_date),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 13. Table user_document_shares (Document Sharing Permissions)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_document_shares (
            id INT AUTO_INCREMENT PRIMARY KEY,
            document_id INT NOT NULL,
            shared_with_user_id INT NOT NULL,
            shared_by_user_id INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_share_doc (document_id),
            INDEX idx_share_target (shared_with_user_id),
            INDEX idx_share_owner (shared_by_user_id),
            UNIQUE KEY uq_doc_shared_user (document_id, shared_with_user_id),
            FOREIGN KEY (document_id) REFERENCES user_documents(id) ON DELETE CASCADE,
            FOREIGN KEY (shared_with_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 14. Table user_document_external_shares (Public / External Share Links)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_document_external_shares (
            id INT AUTO_INCREMENT PRIMARY KEY,
            document_id INT NOT NULL,
            created_by_user_id INT NOT NULL,
            share_token VARCHAR(64) NOT NULL UNIQUE,
            has_pin TINYINT NOT NULL DEFAULT 0,
            pin_hash VARCHAR(255) NULL,
            expires_at DATETIME NULL,
            access_count INT NOT NULL DEFAULT 0,
            last_accessed_at DATETIME NULL,
            is_active TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_extshare_token (share_token),
            INDEX idx_extshare_doc (document_id),
            INDEX idx_extshare_active (is_active),
            INDEX idx_extshare_expires (expires_at),
            FOREIGN KEY (document_id) REFERENCES user_documents(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 15. Table audit_logs (System Security & Activity Trail)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            username VARCHAR(100) NULL,
            user_role VARCHAR(50) NULL,
            action_type VARCHAR(50) NOT NULL,
            action_name VARCHAR(100) NOT NULL,
            target_type VARCHAR(50) NULL,
            target_id VARCHAR(100) NULL,
            description TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_created (created_at),
            INDEX idx_audit_action_type (action_type),
            INDEX idx_audit_action_name (action_name),
            INDEX idx_audit_user (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    seed_default_sites_if_empty($pdo);
    seed_default_users_if_empty($pdo);
    seed_default_operators_if_empty($pdo);
    seed_default_settings_if_empty($pdo);
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
            email VARCHAR(100) NULL,
            phone VARCHAR(50) NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'user',
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_login DATETIME NULL
        );
        CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);
        CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);
    ");
    try { $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(100) NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(50) NULL"); } catch (Exception $e) {}

    // 8. Table operators (Master Operator / Tenant)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS operators (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            operator_code VARCHAR(50) NOT NULL UNIQUE,
            operator_name VARCHAR(100) NOT NULL,
            brand_name VARCHAR(50) NOT NULL,
            logo_path VARCHAR(255) NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            display_order INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_op_code ON operators(operator_code);
        CREATE INDEX IF NOT EXISTS idx_op_active ON operators(is_active);
    ");

    // 9. Table daily_shifts (NOC Shift Logbook Sessions)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS daily_shifts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            technician_name VARCHAR(100) NOT NULL,
            shift_date DATE NOT NULL,
            shift_type VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL
        );
        CREATE INDEX IF NOT EXISTS idx_shift_user ON daily_shifts(user_id);
        CREATE INDEX IF NOT EXISTS idx_shift_date ON daily_shifts(shift_date);
        CREATE INDEX IF NOT EXISTS idx_shift_type ON daily_shifts(shift_type);
        CREATE INDEX IF NOT EXISTS idx_shift_status ON daily_shifts(status);
    ");

    // 10. Table daily_activities (Individual activities within a shift)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS daily_activities (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            shift_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            activity_time VARCHAR(20) NOT NULL,
            category VARCHAR(50) NOT NULL,
            location VARCHAR(150) NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (shift_id) REFERENCES daily_shifts(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_act_shift ON daily_activities(shift_id);
        CREATE INDEX IF NOT EXISTS idx_act_user ON daily_activities(user_id);
        CREATE INDEX IF NOT EXISTS idx_act_cat ON daily_activities(category);
    ");

    // 11. Table daily_activity_photos (Dynamic photo attachments per activity)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS daily_activity_photos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            activity_id INTEGER NOT NULL,
            photo_path VARCHAR(255) NOT NULL,
            caption VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (activity_id) REFERENCES daily_activities(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_actphoto_act ON daily_activity_photos(activity_id);
    ");

    // 12. Table system_settings (System Preferences & Default Leader)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(50) PRIMARY KEY,
            setting_value TEXT NULL,
            setting_label VARCHAR(100) NULL,
            setting_group VARCHAR(50) DEFAULT 'general',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // 13. Table user_documents (Personal & Shared Document Vault)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_documents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title VARCHAR(255) NOT NULL,
            category VARCHAR(100) NOT NULL DEFAULT 'Lainnya',
            description TEXT NULL,
            original_filename VARCHAR(255) NOT NULL,
            stored_filename VARCHAR(255) NOT NULL,
            file_size_raw INTEGER NOT NULL,
            file_size_compressed INTEGER NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_ext VARCHAR(20) NOT NULL,
            is_compressed INTEGER NOT NULL DEFAULT 1,
            privacy_type VARCHAR(20) NOT NULL DEFAULT 'private',
            expiry_date DATE NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_doc_user ON user_documents(user_id);
        CREATE INDEX IF NOT EXISTS idx_doc_cat ON user_documents(category);
        CREATE INDEX IF NOT EXISTS idx_doc_privacy ON user_documents(privacy_type);
        CREATE INDEX IF NOT EXISTS idx_doc_expiry ON user_documents(expiry_date);
    ");

    // 14. Table user_document_shares (Document Sharing Permissions)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_document_shares (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            document_id INTEGER NOT NULL,
            shared_with_user_id INTEGER NOT NULL,
            shared_by_user_id INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (document_id) REFERENCES user_documents(id) ON DELETE CASCADE,
            FOREIGN KEY (shared_with_user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_share_doc ON user_document_shares(document_id);
        CREATE INDEX IF NOT EXISTS idx_share_target ON user_document_shares(shared_with_user_id);
        CREATE INDEX IF NOT EXISTS idx_share_owner ON user_document_shares(shared_by_user_id);
        CREATE UNIQUE INDEX IF NOT EXISTS uq_doc_shared_user ON user_document_shares(document_id, shared_with_user_id);
    ");

    // 15. Table user_document_external_shares (Public / External Share Links)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_document_external_shares (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            document_id INTEGER NOT NULL,
            created_by_user_id INTEGER NOT NULL,
            share_token VARCHAR(64) NOT NULL UNIQUE,
            has_pin INTEGER NOT NULL DEFAULT 0,
            pin_hash VARCHAR(255) NULL,
            expires_at DATETIME NULL,
            access_count INTEGER NOT NULL DEFAULT 0,
            last_accessed_at DATETIME NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (document_id) REFERENCES user_documents(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_extshare_token ON user_document_external_shares(share_token);
        CREATE INDEX IF NOT EXISTS idx_extshare_doc ON user_document_external_shares(document_id);
        CREATE INDEX IF NOT EXISTS idx_extshare_active ON user_document_external_shares(is_active);
        CREATE INDEX IF NOT EXISTS idx_extshare_expires ON user_document_external_shares(expires_at);
    ");

    // 16. Table audit_logs (System Security & Activity Trail)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL,
            username VARCHAR(100) NULL,
            user_role VARCHAR(50) NULL,
            action_type VARCHAR(50) NOT NULL,
            action_name VARCHAR(100) NOT NULL,
            target_type VARCHAR(50) NULL,
            target_id VARCHAR(100) NULL,
            description TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        );
        CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at);
        CREATE INDEX IF NOT EXISTS idx_audit_action_type ON audit_logs(action_type);
        CREATE INDEX IF NOT EXISTS idx_audit_action_name ON audit_logs(action_name);
        CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_logs(user_id);
    ");

    seed_default_sites_if_empty($pdo);
    seed_default_users_if_empty($pdo);
    seed_default_operators_if_empty($pdo);
    seed_default_settings_if_empty($pdo);
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

/**
 * Seed Default Operators if table has zero rows
 */
function seed_default_operators_if_empty(PDO $pdo) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM operators")->fetchColumn();
        if ($count == 0) {
            $stmt = $pdo->prepare("
                INSERT INTO operators (operator_code, operator_name, brand_name, logo_path, is_active, display_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $defaults = [
                ['telkomsel', 'TELKOMSEL', 'Telkomsel', 'assets/img/logo_telkomsel.png?v=2', 1, 1],
                ['ias', 'IASS SBU ICT KNO', 'IASS', 'assets/img/logo_ias.png?v=2', 1, 2],
                ['indosat', 'INDOSAT OOREDOO', 'Indosat', 'assets/img/logo_indosat.svg', 1, 3],
                ['xl', 'XL AXIATA', 'XL', 'assets/img/logo_xl.svg', 1, 4],
                ['smartfren', 'SMARTFREN TELECOM', 'Smartfren', 'assets/img/logo_smartfren.svg', 1, 5]
            ];

            foreach ($defaults as $row) {
                $stmt->execute($row);
            }
        }
    } catch (Exception $e) {
        // Ignored if table already exists or initializing
    }
}

/**
 * Get all active operators for form dropdowns & synchronization
 */
function get_all_active_operators(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SELECT * FROM operators WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($results)) {
            return $results;
        }
    } catch (Exception $e) {
        // Fallback below
    }

    return [
        ['operator_code' => 'telkomsel', 'operator_name' => 'TELKOMSEL', 'brand_name' => 'Telkomsel', 'logo_path' => 'assets/img/logo_telkomsel.png?v=2'],
        ['operator_code' => 'ias', 'operator_name' => 'IASS SBU ICT KNO', 'brand_name' => 'IASS', 'logo_path' => 'assets/img/logo_ias.png?v=2'],
        ['operator_code' => 'indosat', 'operator_name' => 'INDOSAT OOREDOO', 'brand_name' => 'Indosat', 'logo_path' => 'assets/img/logo_indosat.svg'],
        ['operator_code' => 'xl', 'operator_name' => 'XL AXIATA', 'brand_name' => 'XL', 'logo_path' => 'assets/img/logo_xl.svg'],
        ['operator_code' => 'smartfren', 'operator_name' => 'SMARTFREN TELECOM', 'brand_name' => 'Smartfren', 'logo_path' => 'assets/img/logo_smartfren.svg'],
    ];
}

/**
 * Seed Default System Settings (Default Team Leader, WhatsApp, O&M Vendor)
 */
function seed_default_settings_if_empty(PDO $pdo) {
    try {
        $defaults = [
            ['default_team_leader', 'Eko Sulistyo', 'Nama Default Team Leader / Supervisor', 'leader'],
            ['default_leader_role', 'Supervisor / Team Leader NOC KNO', 'Jabatan / Posisi Leader', 'leader'],
            ['default_om_vendor', 'PT. Naer Tunas Indonesia', 'Default Vendor O&M', 'general'],
            ['whatsapp_target_phone', '', 'Nomor WhatsApp Tujuan Laporan (opsional)', 'notification'],
            ['company_name', 'IASS SBU ICT KNO', 'Nama Perusahaan', 'general'],
            ['unit_name', 'IASS SBU ICT KNO', 'Unit Kerja / Wilayah', 'general'],
            ['shift_oh_time', '08:00 - 17:00 WIB', 'Jam Kerja Office Hour (OH)', 'shift'],
            ['shift_pagi_time', '08:00 - 20:00 WIB', 'Jam Kerja Operasional Shift Pagi', 'shift'],
            ['shift_malam_time', '20:00 - 08:00 WIB', 'Jam Kerja Operasional Shift Malam', 'shift'],
            ['activity_categories', 'Monitoring, Patroli, Troubleshooting, Maintenance, Administrasi, Lainnya', 'Daftar Kategori Kegiatan Harian NOC', 'shift'],
            ['app_title', 'IASS SBU ICT KNO', 'Nama / Judul Aplikasi (Sidebar & Navigasi)', 'branding'],
            ['app_subtitle', 'Sistem PM & NOC', 'Sub-judul / Tagline Aplikasi', 'branding'],
            ['app_logo_path', 'assets/img/logo_ias.png?v=3', 'File Logo Aplikasi (Sidebar & Header)', 'branding'],
            ['report_left_logo_path', 'assets/img/logo_ias.png', 'File Logo PDF Laporan (Kop Sebelah Kiri)', 'branding'],
            ['discord_webhook_noc', '', 'Webhook URL Saluran NOC', 'notification'],
            ['discord_webhook_security', '', 'Webhook URL Saluran Keamanan', 'notification'],
            ['discord_notify_shift_complete', '1', 'Notif Discord Shift Selesai', 'notification'],
            ['discord_notify_doc_upload', '1', 'Notif Discord Upload Dokumen', 'notification'],
            ['discord_notify_system_optimize', '1', 'Notif Discord Auto Optimise', 'notification'],
            ['discord_notify_login_failed', '1', 'Notif Discord Login Gagal', 'notification'],
        ];

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("INSERT OR IGNORE INTO system_settings (setting_key, setting_value, setting_label, setting_group) VALUES (?, ?, ?, ?)");
        } else {
            $stmt = $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value, setting_label, setting_group) VALUES (?, ?, ?, ?)");
        }

        foreach ($defaults as $row) {
            $stmt->execute($row);
        }
    } catch (Exception $e) {
        // Table not ready or initialized
    }
}

require_once __DIR__ . '/../includes/icons.php';

/**
 * Get shift metadata helper
 */
function get_shift_info(string $type): array {
    $ohTime = get_system_setting('shift_oh_time', '08:00 - 17:00 WIB');
    $pagiTime = get_system_setting('shift_pagi_time', '08:00 - 20:00 WIB');
    $malamTime = get_system_setting('shift_malam_time', '20:00 - 08:00 WIB');

    switch (strtolower($type)) {
        case 'oh':
        case 'office_hour':
            return [
                'code' => 'oh',
                'name' => 'Office Hour (OH)',
                'time' => $ohTime,
                'icon' => '🏢',
                'svg_icon' => app_icon('building', 14),
                'badge_class' => 'shift-badge-oh',
                'category' => 'Office Hour'
            ];
        case 'malam':
            return [
                'code' => 'malam',
                'name' => 'Operasional Shift Malam',
                'time' => $malamTime,
                'icon' => '🌙',
                'svg_icon' => app_icon('moon', 14),
                'badge_class' => 'shift-badge-malam',
                'category' => 'Operasional'
            ];
        case 'pagi':
        default:
            return [
                'code' => 'pagi',
                'name' => 'Operasional Shift Pagi',
                'time' => $pagiTime,
                'icon' => '☀️',
                'svg_icon' => app_icon('sun', 14),
                'badge_class' => 'shift-badge-pagi',
                'category' => 'Operasional'
            ];
    }
}

/**
 * Global memory cache for system settings
 */
$GLOBALS['_SYSTEM_SETTINGS_CACHE'] = [];

/**
 * Clear or invalidate system settings cache
 */
function clear_system_setting_cache(?string $key = null): void {
    if ($key === null) {
        $GLOBALS['_SYSTEM_SETTINGS_CACHE'] = [];
    } else {
        unset($GLOBALS['_SYSTEM_SETTINGS_CACHE'][$key]);
    }
}

/**
 * Get system setting value by key with optional fallback
 */
function get_system_setting(string $key, string $default = '', bool $forceRefresh = false): string {
    if (!$forceRefresh && isset($GLOBALS['_SYSTEM_SETTINGS_CACHE'][$key])) {
        return $GLOBALS['_SYSTEM_SETTINGS_CACHE'][$key];
    }
    try {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null && trim((string)$val) !== '') {
            $GLOBALS['_SYSTEM_SETTINGS_CACHE'][$key] = (string)$val;
            return $GLOBALS['_SYSTEM_SETTINGS_CACHE'][$key];
        }
    } catch (Exception $e) {}
    $GLOBALS['_SYSTEM_SETTINGS_CACHE'][$key] = $default;
    return $default;
}

/**
 * Update or insert system setting with instant cache update
 */
function set_system_setting(string $key, string $value): bool {
    // Invalidate and update global cache immediately
    $GLOBALS['_SYSTEM_SETTINGS_CACHE'][$key] = $value;
    try {
        $pdo = get_db_connection();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value, updated_at) 
                VALUES (?, ?, datetime('now', 'localtime'))
                ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = datetime('now', 'localtime')
            ");
            return $stmt->execute([$key, $value]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
            ");
            return $stmt->execute([$key, $value]);
        }
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get kop surat logo for official printable reports & PDFs
 * Reads strictly from the right setting in settings.php (report_left_logo_path)
 */
function get_report_kop_logo(): string {
    $reportLogo = trim(get_system_setting('report_left_logo_path', 'assets/img/logo_ias.png'));
    $cleanPath = preg_replace('/\?.*$/', '', $reportLogo);
    $fullPath = __DIR__ . '/../' . ltrim($cleanPath, '/\\');
    if (!empty($cleanPath) && file_exists($fullPath)) {
        return $cleanPath;
    }
    return 'assets/img/logo_ias.png';
}

/**
 * Backward compatibility alias for get_report_kop_logo
 */
function get_effective_report_logo(): string {
    return get_report_kop_logo();
}

/**
 * Get application logo URL for browser tab bar / taskbar favicon
 * Reads strictly from the left setting in settings.php (app_logo_path)
 * Automatically adds filemtime query string to prevent stale browser tab icon caching
 */
function get_app_favicon_url(): string {
    $appLogo = trim(get_system_setting('app_logo_path', 'assets/img/logo_ias.png?v=3'));
    $cleanPath = preg_replace('/\?.*$/', '', $appLogo);
    $fullPath = __DIR__ . '/../' . ltrim($cleanPath, '/\\');
    if (!empty($cleanPath) && file_exists($fullPath)) {
        return $cleanPath . '?v=' . filemtime($fullPath);
    }
    return $appLogo;
}



