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

### 2. Tabel `pm_reports` (Master Laporan PM)
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
* `checklist_json` TEXT (Array JSON berisi status OK/NOK/NA, referensi, dan keterangan untuk Bagian A s/d Bagian K)
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

### 5. Tabel `pm_photos` (Foto Dokumentasi PM)
Menyimpan file foto dan nilai pengukuran pendukung untuk setiap slot foto.
* `id` INTEGER PRIMARY KEY AUTOINCREMENT
* `report_id` INTEGER NOT NULL (Foreign Key ke `pm_reports.id`)
* `slot_code` VARCHAR(20) NOT NULL (Contoh: `A-1`, `A-2`, `B-1`, dst.)
* `title` VARCHAR(255) NOT NULL (Deskripsi foto, misal: `KWH Multi Panel`, `Pengukuran grounding - Pedestal`)
* `image_path` VARCHAR(255) NULL (Jika NULL maka dicetak sebagai `N/A`)
* `measurement_value` VARCHAR(100) NULL (Contoh: `0.26`, `402.6`, `230.8`)
* `is_na` INTEGER DEFAULT 0 (1 jika N/A, 0 jika ada foto)
* `uploaded_at` DATETIME DEFAULT CURRENT_TIMESTAMP

---

### 6. Tabel `users` (Manajemen Pengguna & Autentikasi)
Menyimpan kredensial dan hak akses akun aplikasi.
* `id` INTEGER PRIMARY KEY AUTOINCREMENT
* `username` VARCHAR(50) NOT NULL UNIQUE
* `password` VARCHAR(255) NOT NULL (Password hash bcrypt)
* `full_name` VARCHAR(100) NOT NULL
* `email` VARCHAR(100) NULL
* `role` VARCHAR(20) NOT NULL DEFAULT 'teknisi' (`admin`, `supervisor`, `teknisi`)
* `status` VARCHAR(20) NOT NULL DEFAULT 'active' (`active`, `inactive`)
* `last_login` DATETIME NULL
* `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
* `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP

---

### 7. Tabel `daily_activities` (Logbook Shift Harian NOC)
Mencatat informasi shift kerja harian dan serah terima tugas.
* `id` INTEGER PRIMARY KEY AUTOINCREMENT
* `activity_date` DATE NOT NULL
* `shift` VARCHAR(20) NOT NULL (`pagi`, `siang`, `malam`)
* `team_leader` VARCHAR(100) NOT NULL
* `technician_names` TEXT NOT NULL (Daftar nama personil shift)
* `notes` TEXT NULL (Catatan umum kegiatan shift)
* `handover_notes` TEXT NULL (Catatan khusus serah terima ke shift berikutnya)
* `handover_checklist_json` TEXT NULL (Status verifikasi inventaris dan perangkat serah terima)
* `created_by` INTEGER NULL (Foreign Key ke `users.id`)
* `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
* `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP

---

### 8. Tabel `daily_activity_items` (Rincian Item Kegiatan Shift)
Menyimpan rincian tiap pekerjaan yang dilakukan selama jam dinas shift.
* `id` INTEGER PRIMARY KEY AUTOINCREMENT
* `daily_activity_id` INTEGER NOT NULL (Foreign Key ke `daily_activities.id`)
* `time_start` VARCHAR(10) NOT NULL (Contoh: `08:00`)
* `time_end` VARCHAR(10) NULL (Contoh: `09:30`)
* `category` VARCHAR(50) NOT NULL (`preventive`, `corrective`, `handling_complain`, `monitoring`, `project`, `lainnya`)
* `description` TEXT NOT NULL
* `status` VARCHAR(20) NOT NULL DEFAULT 'done' (`done`, `pending`, `in_progress`)
* `photo_path` VARCHAR(255) NULL
* `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP

---

### 9. Tabel `operators` (Master Data Provider / Tenant)
Menyimpan referensi operator telekomunikasi seluler dan logo tenant.
* `id` INTEGER PRIMARY KEY AUTOINCREMENT
* `operator_code` VARCHAR(50) NOT NULL UNIQUE (Contoh: `telkomsel`, `indosat`, `xl`, `smartfren`)
* `operator_name` VARCHAR(100) NOT NULL (Contoh: `PT Telekomunikasi Selular`)
* `brand_name` VARCHAR(50) NOT NULL (Contoh: `Telkomsel`)
* `logo_path` VARCHAR(255) NOT NULL
* `is_active` INTEGER DEFAULT 1
* `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP

---

### 10. Tabel `technical_documents` (Repositori Dokumen Teknis)
Menyimpan dokumen referensi SOP, IK, dan SLA.
* `id` INTEGER PRIMARY KEY AUTOINCREMENT
* `doc_title` VARCHAR(255) NOT NULL
* `doc_category` VARCHAR(50) NOT NULL (`sop`, `ik`, `sla`, `drawing`, `manual`, `lainnya`)
* `doc_number` VARCHAR(100) NULL (Nomor dokumen resmi perusahaan)
* `file_path` VARCHAR(255) NOT NULL
* `file_size` INTEGER DEFAULT 0
* `uploaded_by` INTEGER NULL (Foreign Key ke `users.id`)
* `download_count` INTEGER DEFAULT 0
* `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP

---

### 11. Tabel `audit_logs` (Rekam Jejak Keamanan & Aktivitas)
Mencatat setiap aksi penting pengguna untuk kebutuhan audit kepatuhan.
* `id` INTEGER PRIMARY KEY AUTOINCREMENT
* `user_id` INTEGER NULL (Foreign Key ke `users.id`)
* `username` VARCHAR(50) NULL
* `action` VARCHAR(50) NOT NULL (Contoh: `login`, `create`, `update`, `delete`, `download`, `optimize`)
* `module` VARCHAR(50) NOT NULL (Contoh: `auth`, `pm_indoor`, `pm_outdoor`, `daily_activity`, `users`, `system`)
* `details` TEXT NULL
* `ip_address` VARCHAR(50) NULL
* `user_agent` TEXT NULL
* `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP

---

### 12. Tabel `app_settings` (Konfigurasi & Identitas Sistem)
Menyimpan preferensi konfigurasi aplikasi dan branding yang dapat diubah via UI.
* `setting_key` VARCHAR(100) PRIMARY KEY
* `setting_value` TEXT NULL
* `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP

---

### 13. Tabel `report_shares` (Token Akses Laporan Publik)
Menyimpan token unik dan validitas kedaluwarsa untuk berbagi laporan ke pihak luar.
* `id` INTEGER PRIMARY KEY AUTOINCREMENT
* `report_id` INTEGER NOT NULL (Foreign Key ke `pm_reports.id`)
* `report_type` VARCHAR(20) NOT NULL (`indoor`, `outdoor`)
* `share_token` VARCHAR(64) NOT NULL UNIQUE
* `expires_at` DATETIME NULL
* `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
