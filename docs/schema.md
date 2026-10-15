# Database Schema Documentation

Sistem ini mendukung SQLite (default, self-contained di `database/apsd.sqlite`) maupun MySQL (melalui PDO di `config/database.php`).

---

### 1. Tabel `sites` (Master Data Site)
Menyimpan referensi data lokasi site agar tidak perlu diinput berulang kali.
* `id` INTEGER PRIMARY KEY AUTOINCREMENT
* `site_id_str` VARCHAR(100) (Contoh: `APSD-DSER-078-08` atau nomor registrasi)
* `site_name` VARCHAR(255) (Contoh: `Bandara Kualanamu Pole 2`, `IBC Bandara International KUALANAMU`)
* `location_detail` VARCHAR(255) (Contoh: `Server Domestik`, `Area Parkir B`)
* `region` VARCHAR(100) (Contoh: `Sumbagut`, `Deli Serdang`)
* `province` VARCHAR(100) (Contoh: `Sumatra Utara`)
* `city` VARCHAR(100) (Contoh: `Medan`)
* `address` TEXT
* `latitude` VARCHAR(50) (Contoh: `3.618278`)
* `longitude` VARCHAR(50) (Contoh: `98.861139`)
* `site_height` VARCHAR(50) (Contoh: `18`)
* `tower_type` VARCHAR(100) (Contoh: `Pole PJU 18 m`, `Non Pole`, `SST`)
* `site_type` VARCHAR(100) (Contoh: `Indoor`, `Green Field`, `Rooftop`)
* `anchor_tenant` VARCHAR(100) (Default: `APSD` / `Telkomsel`)
* `total_tenant` INTEGER DEFAULT 1
* `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP

---

### 2. Tabel `pm_reports` (Master Laporan)
Mencatat metadata setiap inspeksi yang dibuat.
* `id` INTEGER PRIMARY KEY AUTOINCREMENT
* `report_type` VARCHAR(20) NOT NULL (`indoor` atau `outdoor`)
* `site_id` INTEGER NULL (Relasi opsional ke tabel `sites`)
* `form_no` VARCHAR(50) DEFAULT '1'
* `report_date` DATE NOT NULL
* `inspection_time` VARCHAR(20) NULL
* `timezone` VARCHAR(10) DEFAULT 'WIB'
* `inspector_name` VARCHAR(100) NOT NULL (Nama Petugas / PIC Field)
* `inspector_phone` VARCHAR(50) NULL
* `team_leader` VARCHAR(100) NULL
* `om_vendor` VARCHAR(150) DEFAULT 'PT. Naer Tunas Indonesia'
* `notes` TEXT NULL
* `status` VARCHAR(20) DEFAULT 'completed' (`draft`, `completed`)
* `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
* `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP

---

### 3. Tabel `pm_indoor_details` (Detail Data PM Indoor)
Menyimpan seluruh parameter dan item checklist laporan Indoor (Halaman 1 s/d 3).
* `id` INTEGER PRIMARY KEY AUTOINCREMENT
* `report_id` INTEGER NOT NULL (Foreign Key ke `pm_reports.id`)
* `site_info_json` TEXT (Snapshot data site saat inspeksi)
* `checklist_json` TEXT (Array JSON berisi status OK/NOK, referensi, dan keterangan untuk Bagian A s/d Bagian K)
* `measurements_json` TEXT (Nilai suhu AC, suhu ruangan, tegangan phase jika diinput dalam teks)

---

### 4. Tabel `pm_outdoor_details` (Detail Data PM Outdoor / Pole)
Menyimpan seluruh parameter laporan Outdoor 13 halaman.
* `id` INTEGER PRIMARY KEY AUTOINCREMENT
* `report_id` INTEGER NOT NULL (Foreign Key ke `pm_reports.id`)
* `site_info_json` TEXT (Snapshot informasi site dan tenant list)
* `grounding_json` TEXT (Nilai ohm control box, tower leg, fence, EGB, status splitzen/rod)
* `tower_json` TEXT (Tipe tower, painting, construction, cleaning, OBL, sensor, verticality)
* `lighting_fence_other_json` TEXT (Lampu, pagar, cleaning, rambu K3, gembok)
* `structural_json` TEXT (Members completeness, korosi, alignment, pedestal, kondisi tanah, bangunan)
* `tenant_ac_json` TEXT (Spesifikasi dan checklist AC #1 s/d #4, exhaust fan, shelter)
* `tenant_electrical_json` TEXT (Pengukuran tegangan phase, PLN visual, genset, PLN function test)

---

### 5. Tabel `pm_photos` (Foto Dokumentasi)
Menyimpan file foto dan nilai pengukuran pendukung untuk setiap slot foto.
* `id` INTEGER PRIMARY KEY AUTOINCREMENT
* `report_id` INTEGER NOT NULL (Foreign Key ke `pm_reports.id`)
* `slot_code` VARCHAR(20) NOT NULL (Contoh: `A-1`, `A-2`, `B-1`, dst.)
* `title` VARCHAR(255) NOT NULL (Deskripsi foto, misal: `KWH Multi Panel`, `Pengukuran grounding - Pedestal`)
* `image_path` VARCHAR(255) NULL (Jika NULL maka dicetak sebagai `N/A`)
* `measurement_value` VARCHAR(100) NULL (Contoh: `0.26`, `402.6`, `230.8`)
* `is_na` INTEGER DEFAULT 0 (1 jika N/A, 0 jika ada foto)
* `uploaded_at` DATETIME DEFAULT CURRENT_TIMESTAMP
