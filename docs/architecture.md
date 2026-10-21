# Arsitektur Sistem & Alur Kerja (Workflow)

Dokumen ini menjelaskan struktur arsitektur teknis, alur data, dan komponen modul sistem Preventive Maintenance (PM) Checklist & Laporan PDF PT Angkasa Pura Sarana Digital.

---

### 1. Diagram Komponen Arsitektur

```
[ Client Browser / PWA (Android, iOS, Desktop) ]
       │
       ├─ Offline Cache (Service Worker 'sw.js' v2.7.4 & 'manifest.json')
       ├─ LocalStorage Draft Auto-Save Engine ('assets/js/draft_manager.js')
       ├─ Client-Side Image Pre-Compressor (HTML5 Canvas 1600px)
       ├─ Mobile Card Reflow Layout Engine (Responsive Media Queries)
       │
       ▼ (HTTP / HTTPS Port 80 atau Port 2022 di Docker)
[ Apache Web Server / PHP 8.2 ]
       │
       ├── Authentication & RBAC Layer (Superadmin, Administrator, Teknisi)
       │     └─ Session tracking, password hash bcrypt, profile self-service, struktur jabatan
       │
       ├── Core Operational Modules:
       │     ├─ PM Generator (form_indoor.php, form_outdoor.php, reports.php)
       │     ├─ Daily Activity NOC (daily_activity.php, daily_reports.php)
       │     ├─ Performance Analytics & KPI (statistics.php, print_statistics.php, kpi.php, print_kpi.php)
       │     ├─ Technical Document Repository (documents.php, download_document.php)
       │     ├─ Master Data (sites.php, operators.php, users.php)
       │     ├─ Disaster Recovery & Backup (backup.php, includes/backup_helper.php)
       │     └─ System Diagnostics & Auto-Repair (system_optimize.php, audit_log.php, config/database.php)
       │
       ├── PDF & Verification Engine:
       │     ├─ Browser Media Print (@media print presisi A4)
       │     ├─ Smart Verification Engine (Anti duplicate signature & dynamic jabatan)
       │     ├─ E-Signature Engine (Touchscreen canvas & file upload via includes/signature_helper.php)
       │     ├─ Headless Chromium/Edge Direct Engine (download_pdf.php)
       │     ├─ Batch Exporter ZIP (batch_download_pdf.php)
       │     └─ Public Share Token Engine (share.php)
       │
       ├── Image Processing Engine (includes/image_compressor.php):
       │     └─ EXIF auto-rotation, Bicubic GD downscaling, JPEG 82%
       │
       ├── Notification & Discord Multi-Channel Engine (includes/discord.php):
       │     ├─ Channel 1: Operasional NOC (Shift completion & logbook PDF)
       │     ├─ Channel 2: Keamanan & Audit (Brute-force lockout & system events)
       │     └─ Channel 3: Backup Vault (Disaster recovery chunks & hash SHA-256)
       │
       ├── Cache Layer (Redis via AppRedis config/redis.php):
       │     ├─ Caching report list, daily activity, dashboard stats (TTL 60s)
       │     └─ Non-blocking fallback jika Redis offline
       │
       ├── Database Layer (MySQL/MariaDB & SQLite Dual Driver via config/database.php):
       │     ├─ 16 Relational Tables with Self-Healing Auto-Repair
       │     └─ B-Tree Performance Indexes
       │
       └── File Storage Engine:
             ├─ uploads/reports/{report_id}/ (Foto dokumentasi slot PM)
             ├─ uploads/daily/{activity_id}/ (Foto dokumentasi shift)
             ├─ uploads/documents/ (File SOP, IK, dan sertifikat)
             ├─ uploads/signatures/ (Tanda tangan digital transparan PNG/JPG)
             ├─ uploads/branding/ (Logo resmi kop dan icon PWA)
             └─ backups/ (Arsip pencadangan lokal berkala)
```

---

### 2. Arsitektur Progressive Web App (PWA) & Mobile-First

* **Web App Manifest (`manifest.json`)**:
  - Konfigurasi nama aplikasi, tema warna IAS Teal (`#008080`), mode display `standalone`, dan background `#0f172a`.
  - Icon launcher 192x192 dan 512x512 dengan pemisahan purpose `any` dan `maskable` untuk kompatibilitas penuh Android 13+.
  - App shortcuts untuk formulir PM Indoor, PM Outdoor, Daily Activity, dan Master Site.
* **Service Worker (`sw.js` v2.7.4)**:
  - Mengimplementasikan strategi Network-First dengan dynamic caching untuk aset statis dan offline fallback.
  - Membantu teknisi tetap dapat mengakses formulir dan mereview data riwayat saat berada di area basement, shelter terpencil, atau garbarata tanpa koneksi internet.
* **Mobile Card Reflow Engine**:
  - Pada layar smartphone (< 768px), struktur tabel desktop otomatis bertransformasi menjadi kartu inspeksi independen.
  - Setiap kartu menyediakan ruang 100% untuk input catatan teknisi, kontrol tombol status radio berukuran sentuh jari 42px, dan indikator standar referensi yang terintegrasi.
* **Floating Collapsible Photo Tracker**:
  - Pelacak progres kelengkapan foto PM ditampilkan sebagai badge pill mengambang non-intrusif di sudut kanan bawah layar, dilengkapi kontrol ciutkan dan buka.

---

### 3. Pipeline Kompresi Gambar Dua Tahap (Two-Tier Compression)

1. **Tahap 1: Client-Side Pre-Compression (`assets/js/app.js`)**
   - Saat pengguna memilih file atau memotret via kamera smartphone, gambar dibaca oleh `FileReader` ke elemen `Image`.
   - HTML5 Canvas mereduksi dimensi gambar secara proporsional ke batas maksimal 1600 piksel dengan kualitas JPEG 85%.
   - File input digantikan dengan objek Blob terkompresi menggunakan API `DataTransfer`. Ukuran payload yang dikirim berkurang dari 10 MB menjadi ~300 KB.
2. **Tahap 2: Server-Side Processing & EXIF Fix (`includes/image_compressor.php`)**
   - Backend PHP memeriksa orientasi EXIF (`exif_read_data`) dan memutar orientasi gambar jika foto diambil dalam posisi vertikal (portrait).
   - Gambar diproses ulang dengan fungsi GD `imagecopyresampled` (algoritma interpolasi bicubic) dengan rasio kompresi 82% untuk menjaga ketajaman meteran listrik, teks display, dan label perangkat.

---

### 4. Skema Database & Pengindeksan Performa

Database SQLite (`database/apsd.sqlite`) dikonfigurasi dengan Write-Ahead Logging (`PRAGMA journal_mode = WAL;`) dan memiliki 8 indeks B-Tree aktif untuk mempercepat waktu query:

1. `idx_pm_reports_type` pada kolom `pm_reports(report_type)`
2. `idx_pm_reports_date` pada kolom `pm_reports(report_date)`
3. `idx_pm_reports_site_id` pada kolom `pm_reports(site_id)`
4. `idx_pm_photos_report_id` pada kolom `pm_photos(report_id)`
5. `idx_pm_photos_report_slot` pada kolom `pm_photos(report_id, slot_code)`
6. `idx_pm_photos_page_group` pada kolom `pm_photos(page_group)`
7. `idx_sites_site_name` pada kolom `sites(site_name)`
8. `idx_sites_site_id_str` pada kolom `sites(site_id_str)`

---

### 5. Strategi Caching Redis (`config/redis.php`)

* Kelas `AppRedis` menyediakan metode statis: `isAvailable()`, `get()`, `set()`, `del()`, dan `flushPrefix()`.
* Timeout koneksi disetel ke 0.5 detik sehingga jika layanan Redis tidak menyala, sistem langsung mengalihkan query ke SQLite tanpa jeda (non-blocking fallback).
* Dashboard meng-cache daftar laporan pada key `apsd:dashboard:reports` dan statistik pada `apsd:dashboard:stats` dengan TTL 60 detik.
* Setiap aksi simpan atau hapus laporan memanggil `AppRedis::flushPrefix('dashboard:')` untuk memastikan data selalu konsisten.

---

### 6. Kontainerisasi Docker (Port 2022)

* **File `Dockerfile`**:
  - Menggunakan image `php:8.2-apache`.
  - Menginstal dependensi `libsqlite3-dev`, `libpng-dev`, `libjpeg-dev`, `libfreetype6-dev`, dan modul PECL Redis.
  - Mengaktifkan Apache `mod_rewrite` dan mengatur hak akses direktori `database` dan `uploads`.
* **File `docker-compose.yml`**:
  - Service `web`: Port mapping `"2022:80"`.
  - Service `redis`: Image `redis:7-alpine` pada port `6379`.
  - Restart policy `always` untuk seluruh container sehingga otomatis aktif kembali setelah server reboot.
  - Volume mounting untuk data SQLite dan upload foto agar data tetap aman saat container di-restart.
