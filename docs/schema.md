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

### 6. Tabel `users` (Manajemen Pengguna, Jabatan & Autentikasi)
Menyimpan kredensial akun, jabatan resmi kedinasan, dan tanda tangan digital.
* `id` INTEGER PRIMARY KEY AUTOINCREMENT
* `username` VARCHAR(50) NOT NULL UNIQUE
* `password_hash` VARCHAR(255) NOT NULL (Password hash bcrypt)
* `full_name` VARCHAR(100) NOT NULL
* `jabatan` VARCHAR(100) NULL DEFAULT 'Teknisi Jaga NOC' (Jabatan resmi dinas, misal: *Supervisor NOC KNO*, *Teknisi Jaga NOC*, *Administrator SBU ICT*)
* `email` VARCHAR(100) NULL
* `phone` VARCHAR(50) NULL (Nomor HP / WhatsApp koordinasi lapangan)
* `role` VARCHAR(20) NOT NULL DEFAULT 'user' (`superadmin`, `admin`, `user`)
* `is_active` INTEGER DEFAULT 1 (1 = Aktif, 0 = Nonaktif)
* `signature_path` VARCHAR(255) NULL (Path relatif file tanda tangan digital e-signature)
* `last_login` DATETIME NULL
* `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
* `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP

---

### 7. Tabel `daily_shifts` (Sesi Shift & Verifikasi Handover NOC)
Mencatat sesi shift operasional 24 jam dan tanda tangan penyelesaian tugas.
* `id` INTEGER PRIMARY KEY AUTOINCREMENT
* `user_id` INTEGER NOT NULL (Relasi ke `users.id`)
* `shift_date` DATE NOT NULL
* `shift_type` VARCHAR(20) NOT NULL (`pagi`, `malam`, `oh`)
* `technician_name` VARCHAR(100) NOT NULL
* `status` VARCHAR(20) DEFAULT 'open' (`open`, `completed`)
* `start_time` DATETIME DEFAULT CURRENT_TIMESTAMP
* `end_time` DATETIME NULL
* `notes` TEXT NULL (Catatan serah terima shift & inventaris)
* `signature_path` VARCHAR(255) NULL (Tanda tangan digital saat penutupan shift)
* `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
* `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP

---

### 8. Tabel `daily_activities` (Kronologis Kegiatan Shift NOC)
Menyimpan rincian setiap aktivitas dinas selama rentang shift.
* `id` INTEGER PRIMARY KEY AUTOINCREMENT
* `shift_id` INTEGER NOT NULL (Relasi ke `daily_shifts.id`)
* `user_id` INTEGER NOT NULL (Relasi ke `users.id`)
* `activity_time` VARCHAR(10) NOT NULL (Waktu WIB, contoh: `09:30`)
* `category` VARCHAR(50) NOT NULL (`Monitoring`, `Patroli`, `Troubleshooting`, `Maintenance`, `Administrasi`, `Lainnya`)
* `title` VARCHAR(255) NOT NULL
* `description` TEXT NULL
* `location` VARCHAR(100) NULL
* `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
* `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP

---

### 9. Tabel `daily_activity_photos` (Foto Bukti Pekerjaan Shift)
Menyimpan dokumentasi visual foto lapangan pendukung setiap item kegiatan.
* `id` INTEGER PRIMARY KEY AUTOINCREMENT
* `activity_id` INTEGER NOT NULL (Relasi ke `daily_activities.id`)
* `photo_path` VARCHAR(255) NOT NULL
* `caption` VARCHAR(255) NULL (Keterangan utama foto)
* `caption_2` VARCHAR(255) NULL (Keterangan teknis tambahan / rincian hasil kerja)
* `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP

---

### 10. Tabel `operators` (Master Data Provider / Tenant)
Menyimpan referensi operator telekomunikasi seluler dan logo tenant.
* `id` INTEGER PRIMARY KEY AUTOINCREMENT
* `operator_code` VARCHAR(50) NOT NULL UNIQUE (Contoh: `telkomsel`, `indosat`, `xl`, `smartfren`)
* `operator_name` VARCHAR(100) NOT NULL (Contoh: `PT Telekomunikasi Selular`)
* `brand_name` VARCHAR(50) NOT NULL (Contoh: `Telkomsel`)
* `logo_path` VARCHAR(255) NOT NULL
* `is_active` INTEGER DEFAULT 1
* `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP

---

### 11. Tabel `system_settings` (Konfigurasi, Identitas & Pengesahan Sistem)
Menyimpan preferensi konfigurasi aplikasi, identitas instansi, dan parameter tanda tangan pengesahan.
* `setting_key` VARCHAR(100) PRIMARY KEY
* `setting_value` TEXT NULL
* `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP

*Kunci Pengaturan Utama:*
- `default_team_leader`: Nama resmi Team Leader / Supervisor NOC (default: `Wahyu Wandira`).
- `default_leader_role`: Jabatan Leader di lembar pengesahan (default: `Supervisor / Team Leader NOC KNO`).
- `default_leader_signature`: Berkas tanda tangan digital resmi Supervisor / Team Leader.
- `default_manager_name`: Nama Manager Unit untuk eskalasi pengesahan tingkat lanjut (opsional).
- `default_manager_role`: Jabatan Manager pada lembar cetak (default: `Manager SBU ICT KNO`).
- `default_manager_signature`: Berkas tanda tangan digital resmi Manager Unit.
- `company_name`: Nama perusahaan / entitas (default: `IASS SBU ICT KNO`).
- `unit_name`: Kode unit bandara (default: `KNO`).
- `app_title` & `app_subtitle`: Nama dan tagline pada navigasi sidebar.
- `shift_oh_time`, `shift_pagi_time`, `shift_malam_time`: Jam dinas shift NOC.
- `discord_webhook_noc`, `discord_webhook_security`, `discord_webhook_backup`: Integrasi notifikasi multi-saluran Discord.

---

### 12. Tabel `user_documents` (Brankas Dokumen Teknis & Sertifikasi)
Menyimpan repositori dokumen teknik, sertifikasi personel, manual perangkat, dan kontrak kerja.
* `id` INTEGER PRIMARY KEY AUTOINCREMENT
* `user_id` INTEGER NOT NULL (Relasi ke `users.id`)
* `title` VARCHAR(255) NOT NULL
* `category` VARCHAR(100) NOT NULL (`Sertifikat & Lisensi`, `Pass Bandara & Identitas`, `Surat Tugas & SK`, `Manual & Dokumen Teknis`, `Kontrak & Legalitas`, `Laporan & Notulen`, `Lainnya`)
* `document_number` VARCHAR(100) NULL
* `expiry_date` DATE NULL
* `file_path` VARCHAR(255) NOT NULL
* `file_name` VARCHAR(255) NOT NULL
* `file_size` INTEGER DEFAULT 0
* `file_type` VARCHAR(50) NULL
* `description` TEXT NULL
* `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
* `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP

---

### 13. Tabel `audit_logs` (Rekam Jejak Keamanan & Aktivitas)
Mencatat seluruh aksi penting pengguna untuk audit kepatuhan ISO dan keamanan sistem.
* `id` INTEGER PRIMARY KEY AUTOINCREMENT
* `user_id` INTEGER NULL (Relasi ke `users.id`)
* `category` VARCHAR(50) NOT NULL (`AUTH`, `PM`, `SHIFT`, `DOC`, `SETTINGS`, `BACKUP`, `SYSTEM`)
* `event_type` VARCHAR(100) NOT NULL
* `details` TEXT NULL
* `target_type` VARCHAR(50) NULL
* `target_id` INTEGER NULL
* `ip_address` VARCHAR(50) NULL
* `user_agent` TEXT NULL
* `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP

---

### 14. Tabel `kpi_evaluations` (Evaluasi Kinerja & Penilaian KPI Personil)
Menyimpan akumulasi skor KPI tahunan berbasis sistem otomatis dan review supervisor.
* `id` INTEGER PRIMARY KEY AUTOINCREMENT
* `user_id` INTEGER NOT NULL (Relasi ke `users.id`)
* `period_year` VARCHAR(10) NOT NULL (Contoh: `2026`)
* `shift_score` DECIMAL(5,2) DEFAULT 0.00 (Skor disiplin sesi shift, bobot 15.0)
* `activity_score` DECIMAL(5,2) DEFAULT 0.00 (Skor intensitas kegiatan, bobot 20.0)
* `photo_score` DECIMAL(5,2) DEFAULT 0.00 (Skor kelengkapan dokumentasi foto, bobot 10.0)
* `pm_score` DECIMAL(5,2) DEFAULT 0.00 (Skor kontribusi inspeksi PM, bobot 15.0)
* `system_score` DECIMAL(5,2) DEFAULT 0.00 (Total skor otomatis sistem, maks 60.0)
* `review_score` DECIMAL(5,2) DEFAULT 0.00 (Skor evaluasi subjektif supervisor, maks 40.0)
* `total_score` DECIMAL(5,2) DEFAULT 0.00 (Total akumulasi skor, maks 100.0)
* `grade` VARCHAR(5) DEFAULT 'C' (Predikat huruf: `A`, `B`, `C`, `D`)
* `notes` TEXT NULL (Catatan evaluasi dan rekomendasi supervisor)
* `status` VARCHAR(20) DEFAULT 'draft' (`draft`, `published`)
* `evaluator_id` INTEGER NULL (Relasi ke `users.id` evaluator/leader)
* `evaluated_at` DATETIME NULL
* `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
* `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
