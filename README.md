# Sistem Preventive Maintenance (PM) Checklist & Laporan PDF

Aplikasi web dan Progressive Web App (PWA) untuk input checklist pemeliharaan berkala (PM) dan pembuatan laporan PDF resmi untuk PT Angkasa Pura Sarana Digital (APSD), PT IAS Support Indonesia, dan operator seluler (Telkomsel, Indosat, XL, dll).

---

### Fitur Utama Sistem

1. **Modul Laporan PM Indoor (7 Halaman)**
   - Format standar Daily Check List IBC Telkomsel (Ruang Server Domestik).
   - Checklist Bagian A sampai K (Power, Rectifier, Baterai, AC, Suhu, Transmisi, Alarm).
   - Dokumentasi foto 24 slot (6 slot per halaman, otomatis N/A untuk slot kosong).

2. **Modul Laporan PM Outdoor / Pole (13 Halaman)**
   - Format standar PM Pole Bandara Kualanamu.
   - Checklist Mechanical Electrical: Grounding (< 1 Ohm), Tower Alignment, Korosi, Pedestal, Lighting, Pagar, Pondasi.
   - Dokumentasi foto 48 slot (Grup A sampai H, 8 halaman).

3. **Progressive Web App (PWA) & Mobile-First**
   - Dapat diinstal langsung ke Home Screen smartphone Android/iOS atau desktop.
   - Offline Caching via Service Worker (`sw.js`) untuk akses di area bandara dengan sinyal terbatas.
   - Navigasi bawah (Bottom Navigation Bar) yang nyaman untuk satu jempol teknisi di lapangan.
   - Quick-Jump Navigation: lompat cepat antar seksi form tanpa scrolling panjang.
   - Kartu Laporan Responsif: otomatis beralih dari tabel desktop ke kartu mobile yang mudah ditap.
   - Touch targets memenuhi standar WCAG (>= 44px) dengan radio button status berdimensi besar.

4. **Kamera Langsung & Watermark Timestamp/GPS Otomatis**
   - **Live In-App Camera Viewfinder**: Ambil foto langsung dari kamera smartphone/laptop dengan preview HUD waktu nyata.
   - **Label Resmi Otomatis**: Mencetak nama site, waktu terkini (WIB), koordinat GPS (Lat/Long), dan judul foto langsung pada gambar.
   - **Pilihan Fleksibel**: Tombol **Kamera Live** untuk memotret instan, atau tombol **Galeri** untuk memilih foto dari penyimpanan file.
   - **Toggle Watermark**: Opsi untuk mengaktifkan atau menonaktifkan watermark otomatis sesuai kebutuhan teknisi.

5. **Kompresi Gambar 2 Tahap (Two-Tier Image Compression)**
   - Tahap 1 (Client Canvas): Foto dari kamera HP (5 s/d 15 MB) dikompresi langsung di browser menjadi ~300 KB sebelum dikirim ke server. Menghemat kuota dan mempercepat upload di lokasi sinyal minim.
   - Tahap 2 (Server GD Bicubic): Memastikan auto-rotasi EXIF kamera HP tegak, scaling proporsional maksimal 1600px, dan kualitas JPEG 82% sehingga teks meteran, barcode, dan detail fisik tetap tajam dan terbaca jelas di cetakan PDF.

5. **Custom Kop Logo Provider**
   - Pemilihan logo tenant/operator kop kanan (Telkomsel, IAS Support, Indosat Ooredoo, XL Axiata, atau Upload Logo Kustom).
   - Keterangan foto (caption) bebas diedit oleh teknisi.

6. **Database Indexing & Redis Caching**
   - Indexing database SQLite pada kolom kunci pencarian (`report_type`, `report_date`, `site_id`, `slot_code`).
   - Redis caching dengan koneksi cepat (non-blocking fallback jika Redis tidak aktif).
   - Auto-invalidation cache saat laporan ditambah atau dihapus.

7. **Docker Containerization (Port 2022)**
   - Konfigurasi lengkap `Dockerfile` dan `docker-compose.yml`.
   - Web application berjalan di port `2022` (`http://localhost:2022/`).
   - Layanan Redis cache terintegrasi.

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

---

### Format Cetak & Export PDF

1. **Cetak PDF Melalui Browser (Print Preview)**
   - Klik tombol **Cetak PDF** pada baris laporan.
   - Browser akan membuka halaman cetak dengan stylesheet `@media print` presisi A4.
   - Tekan tombol **Cetak / Simpan PDF** atau `Ctrl + P` untuk menyimpan sebagai file PDF.

2. **Download PDF Otomatis (Direct Download)**
   - Klik tombol **Download** pada baris laporan.
   - Menggunakan binary browser headless untuk menghasilkan file PDF resmi langsung ke penyimpanan komputer Anda.
