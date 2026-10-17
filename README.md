# Sistem Preventive Maintenance (PM) Checklist & Laporan PDF

Aplikasi web dan Progressive Web App (PWA) terintegrasi untuk input checklist pemeliharaan berkala (PM), pencatatan Daily Activity shift NOC, repositori dokumen teknis, dan generator laporan PDF resmi untuk PT Angkasa Pura Sarana Digital (APSD), PT IAS Support Indonesia, dan operator telekomunikasi seluler (Telkomsel, Indosat, XL, Smartfren).

---

### Fitur Utama Sistem

1. **Modul Laporan PM Indoor (7 Halaman)**
   - Format standar Daily Check List IBC Telkomsel (Ruang Server Domestik).
   - Checklist Bagian A sampai K (Power, Rectifier, Baterai, AC, Suhu, Transmisi, Alarm).
   - Reflow kartu responsif di mobile agar tombol status dan kolom catatan tampil lega tanpa terpotong.
   - Dokumentasi foto 24 slot (6 slot per halaman, otomatis N/A untuk slot kosong).

2. **Modul Laporan PM Outdoor / Pole (13 Halaman)**
   - Format standar PM Pole Bandara Kualanamu.
   - Checklist Mechanical Electrical: Grounding (< 1 Ohm), Tower Alignment, Korosi, Pedestal, Lighting, Pagar, Pondasi.
   - Reflow kartu responsif di mobile dengan form input catatan lebar penuh.
   - Dokumentasi foto 48 slot (Grup A sampai H, 8 halaman).

3. **Modul Daily Activity & Handover Shift NOC**
   - Pencatatan logbook kegiatan harian shift tim NOC (Pagi, Siang, Malam).
   - Kategorisasi kegiatan: Preventive Maintenance, Corrective Maintenance, Handling Complain, Monitoring, Project.
   - Upload bukti foto dokumentasi langsung dari kamera atau galeri.
   - Checklist serah terima shift (handover) terstruktur untuk akuntabilitas operasional.
   - Cetak laporan PDF per shift (A4) dan cetak logbook bulanan resmi (Monthly Logbook).

4. **Progressive Web App (PWA) & Mobile-First**
   - Dapat diinstal langsung ke Home Screen smartphone Android/iOS atau desktop (PWA installable).
   - Icon launcher 192x192 dan 512x512 dengan dukungan mode maskable dan any.
   - Offline Caching via Service Worker (`sw.js` v2.7.4) untuk akses di area bandara bersinyal minim.
   - Auto-Save Draf Lokal: pemulihan data draf otomatis jika browser tertutup tiba-tiba.
   - Navigasi bawah (Bottom Navigation Bar) yang nyaman untuk satu jempol teknisi di lapangan.
   - Quick-Jump Navigation: lompat cepat antar seksi formulir tanpa scrolling panjang.
   - Floating Collapsible Photo Tracker: indikator progress foto yang ringkas dan dapat diciutkan agar tidak menutupi layar.

5. **Kamera Langsung & Watermark Timestamp/GPS Otomatis**
   - **Live In-App Camera Viewfinder**: Ambil foto langsung dari kamera smartphone/laptop dengan preview HUD waktu nyata.
   - **Label Resmi Otomatis**: Mencetak nama site, waktu terkini (WIB), koordinat GPS (Lat/Long), dan judul foto langsung pada gambar.
   - **Pilihan Fleksibel**: Tombol **Kamera Live** untuk memotret instan, atau tombol **Galeri** untuk memilih foto dari penyimpanan file.
   - **Toggle Watermark**: Opsi untuk mengaktifkan atau menonaktifkan watermark otomatis sesuai kebutuhan teknisi.

6. **Kompresi Gambar 2 Tahap (Two-Tier Image Compression)**
   - Tahap 1 (Client Canvas): Foto dari kamera HP (5 s/d 15 MB) dikompresi langsung di browser menjadi ~300 KB sebelum dikirim ke server. Menghemat kuota dan mempercepat upload di lokasi sinyal minim.
   - Tahap 2 (Server GD Bicubic): Memastikan auto-rotasi EXIF kamera HP tegak, scaling proporsional maksimal 1600px, dan kualitas JPEG 82% sehingga teks meteran, barcode, dan detail fisik tetap tajam dan terbaca jelas di cetakan PDF.

7. **Kustomisasi Branding & Kop Logo Provider**
   - Pemilihan logo tenant/operator kop kanan (Telkomsel, IAS Support, Indosat Ooredoo, XL Axiata, atau Upload Logo Kustom).
   - Pengaturan logo kop kiri resmi dan logo aplikasi terpusat pada menu Pengaturan Sistem (`settings.php`).
   - Keterangan foto (caption) bebas diedit oleh teknisi.

8. **Repositori Dokumen Teknis (SOP, IK, SLA)**
   - Manajemen dokumen referensi kerja: Standar Operasional Prosedur (SOP), Instruksi Kerja (IK), Service Level Agreement (SLA), Drawing/Topologi, dan Manual Panduan.
   - Fitur upload file PDF/dokumen, pencarian, filter kategori, preview dokumen interaktif, dan tracking unduhan.

9. **Manajemen Pengguna & Role-Based Access Control (RBAC)**
   - Tiga tingkatan peran: Admin, Supervisor, dan Teknisi.
   - Dashboard adaptif berdasarkan role: metrik eksekutif untuk pimpinan, giliran tugas untuk teknisi.
   - Tampilan User Manager responsif dengan kartu data pengguna di perangkat seluler (`users.php`).
   - Manajemen profil mandiri dan ubah password mandiri (`profile.php`).

10. **Audit Log & Pemantauan Keamanan**
    - Pencatatan seluruh aktivitas penting (login, logout, pembuatan laporan, perubahan data, download dokumen, optimasi sistem).
    - Mencatat user ID, IP address, user agent, modul, dan rincian timestamp.
    - Filter berdasarkan aksi, modul, dan rentang tanggal untuk audit kepatuhan.

11. **Optimasi Sistem & Pemeliharaan Database**
    - Fitur maintenance berkala via UI (`system_optimize.php`) dan script CLI/Cron (`cron_optimize.php`).
    - Operasi SQLite VACUUM, pembersihan cache sementara, rotasi log lawas, dan verifikasi integritas database.

12. **Berbagi Laporan Publik & Batch Download**
    - Pembuatan tautan publik aman (`share.php`) dengan token unik dan masa kedaluwarsa fleksibel untuk akses pihak eksternal/klien tanpa login.
    - Download batch seluruh arsip laporan PDF bulanan dalam format ZIP terkompresi (`batch_download_pdf.php`).

13. **Database Indexing & Redis Caching**
    - Indexing database SQLite pada kolom kunci pencarian (`report_type`, `report_date`, `site_id`, `slot_code`).
    - Redis caching dengan koneksi cepat (non-blocking fallback jika Redis tidak aktif).
    - Auto-invalidation cache saat laporan ditambah atau dihapus.

14. **Docker Containerization (Port 2022)**
    - Konfigurasi lengkap `Dockerfile` dan `docker-compose.yml`.
    - Web application berjalan di port `2022` (`http://localhost:2022/`).
    - Layanan Redis cache terintegrasi dengan restart policy `always`.

---

### Cara Menjalankan Aplikasi

#### Opsi 1: Menjalankan via Docker (Direkomendasikan untuk Server)
1. Clone repositori ke server Anda:
   ```bash
   git clone https://github.com/frambudi75/apsd-pm.git
   cd apsd-pm
   ```
2. Pastikan service Docker di Linux aktif otomatis saat server booting / restart:
   ```bash
   sudo systemctl enable docker
   sudo systemctl enable containerd
   ```
3. Jalankan docker compose:
   ```bash
   docker compose up -d --build
   ```
   *(Seluruh service `web`, `db`, dan `redis` telah dilengkapi kebijakan `restart: always`, sehingga otomatis berjalan kembali saat mesin Linux menyala setelah restart/reboot).*
4. Akses aplikasi melalui browser pada port **2022**:
   `http://<ip-server-anda>:2022/`

#### Opsi 2: Menjalankan via XAMPP / Web Server PHP Lokal
1. Letakkan folder project di direktori web root (contoh: `c:\xampp\htdocs\apsd`).
2. Pastikan web server Apache dan PHP (dengan ekstensi PDO SQLite dan GD) aktif.
3. Buka browser dan akses:
   `http://localhost/apsd/`
4. Database SQLite akan siap secara otomatis pada `database/apsd.sqlite`.
5. Login default: username `admin`, password `admin123`.

---

### Format Cetak & Export PDF

1. **Cetak PDF Melalui Browser (Print Preview)**
   - Klik tombol **Cetak PDF** pada baris laporan atau tombol cetak di formulir.
   - Browser akan membuka halaman cetak dengan stylesheet `@media print` presisi A4.
   - Tekan tombol **Cetak / Simpan PDF** atau `Ctrl + P` untuk menyimpan sebagai file PDF.

2. **Download PDF Otomatis (Direct Download)**
   - Klik tombol **Download** pada baris laporan.
   - Menggunakan binary browser headless untuk menghasilkan file PDF resmi langsung ke penyimpanan komputer Anda.
