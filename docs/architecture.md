# Arsitektur Sistem & Alur Kerja (Workflow)

Dokumen ini menjelaskan struktur arsitektur teknis sistem Preventive Maintenance (PM) Checklist & Laporan PDF PT Angkasa Pura Sarana Digital.

---

### 1. Diagram Komponen Arsitektur

```
[ Client Browser / PWA ]
       │
       ├─ Offline Cache (Service Worker 'sw.js' & 'manifest.json')
       ├─ Client-Side Image Compressor (HTML5 Canvas Bicubic)
       │
       ▼ (HTTP / HTTPS Port 80 atau Port 2022 di Docker)
[ Apache Web Server / PHP 8.2 ]
       │
       ├─ Routing & Views (index.php, form_indoor.php, form_outdoor.php, sites.php)
       ├─ Print Engine (print_indoor.php, print_outdoor.php, download_pdf.php)
       │
       ├── Cache Layer (Redis via AppRedis config/redis.php)
       │     └─ Cache report list, dashboard statistics (TTL 60s)
       │     └─ Non-blocking fallback jika Redis offline
       │
       ├── Server Compressor (includes/image_compressor.php)
       │     └─ EXIF auto-rotation, downscale max 1600px, JPEG 82%
       │
       ├── Database Layer (SQLite PDO database/apsd.sqlite)
       │     └─ 8 B-Tree Performance Indexes
       │
       └─ File Storage (uploads/reports/{report_id}/)
             └─ Foto dokumentasi slot terkompresi
```

---

### 2. Arsitektur Progressive Web App (PWA)

* **Web App Manifest (`manifest.json`)**:
  - Konfigurasi nama aplikasi, ikon beresolusi 192x192 dan 512x512, tema warna brand IAS Teal (`#008080`), mode display `standalone`, dan orientasi responsif.
  - Menyediakan app shortcuts untuk akses instan ke formulir PM Indoor, PM Outdoor, dan Master Site langsung dari launcher Android atau desktop.
* **Service Worker (`sw.js`)**:
  - Mengimplementasikan strategi Network-First dengan fallback dinamis ke cache lokal.
  - Memungkinkan teknisi tetap membuka formulir dan melihat data arsip saat berada di basement, shelter terpencil, atau area apron tanpa koneksi internet aktif.
* **PWA Install Banner & Offline Monitor**:
  - Menangkap event `beforeinstallprompt` untuk memberikan tombol pasang aplikasi satu sentuhan.
  - Mendeteksi status online/offline browser dan menampilkan notifikasi status koneksi.

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

Database SQLite (`database/apsd.sqlite`) memiliki 8 indeks B-Tree aktif untuk mempercepat waktu query:

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
  - Volume mounting untuk data SQLite dan upload foto agar data tetap aman saat container di-restart.
