<?php
/**
 * Database Configuration & Auto Migration
 * PT Angkasa Pura Sarana Digital - Sistem PM
 */

function get_db_connection() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dbDir = __DIR__ . '/../database';
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0777, true);
    }

    $dbPath = $dbDir . '/apsd.sqlite';
    $isNew = !file_exists($dbPath);

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Auto migrate schema if tables don't exist
        init_db_schema($pdo, $isNew);

        return $pdo;
    } catch (PDOException $e) {
        die("Koneksi database gagal: " . htmlspecialchars($e->getMessage()));
    }
}

function init_db_schema(PDO $pdo, bool $isNew) {
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

    // Migration: add custom_logo_path if not existing
    try {
        $pdo->exec("ALTER TABLE pm_reports ADD COLUMN custom_logo_path VARCHAR(255) NULL");
    } catch (Exception $e) {
        // Column already exists
    }

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

    // Seed default sample sites if empty
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
            '',
            '',
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
