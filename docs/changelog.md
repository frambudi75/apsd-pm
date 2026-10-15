# Catatan Perubahan (Changelog)

Seluruh riwayat pembaruan dan implementasi fitur sistem Preventive Maintenance (PM) PT Angkasa Pura Sarana Digital.

---

### [Versi 2.0.0] - 2026-10-15

#### Ditambahkan
* **Progressive Web App (PWA)**:
  - File Web App Manifest (`manifest.json`) dengan konfigurasi standalone, warna tema IAS Teal, dan icon 192px serta 512px.
  - Service Worker (`sw.js`) dengan strategi network-first dan offline fallback cache.
  - PWA Install Banner pada mobile dan desktop browser dengan event `beforeinstallprompt`.
  - Indikator status koneksi offline/online interaktif.
  - Pintasan aplikasi (app shortcuts) untuk form PM Indoor, PM Outdoor, dan Master Site.
* **Desain Responsif & Mobile-First**:
  - Navigasi bawah (Bottom Navigation Bar) tetap di layar smartphone dengan safe-area padding.
  - Tampilan kartu native untuk daftar laporan di perangkat seluler berlayar < 768px.
  - Bar navigasi cepat (Mobile Quick-Jump Chips) pada form PM Indoor dan Outdoor.
  - Ukuran target sentuh radio button status (OK / NOK / N/A) dioptimalkan >= 44px (memenuhi standar aksesibilitas WCAG).
  - Kolom standar referensi disembunyikan otomatis pada tampilan HP agar checklist tidak terhimpit.
* **Pipeline Kompresi Gambar 2 Tahap (Two-Tier Image Compression)**:
  - Kompresi sisi klien (Client-side Canvas) yang mereduksi foto kamera 5 s/d 15 MB menjadi ~300 KB sebelum diupload.
  - Kompresi sisi server (Server-side GD bicubic) dengan auto-rotasi EXIF dan kualitas JPEG 82% sehingga teks display dan meteran tetap tajam di cetakan PDF.
* **Pengindeksan Database SQLite**:
  - 8 indeks performa B-Tree pada tabel `pm_reports`, `pm_photos`, dan `sites`.
* **Integrasi Cache Redis**:
  - Modul pembantu `config/redis.php` (`AppRedis`) dengan non-blocking fallback jika Redis tidak aktif.
  - Caching data dashboard dan statistik dengan TTL 60 detik serta invalidasi otomatis saat transaksi simpan/hapus.
* **Setup Docker untuk Port 2022**:
  - File `Dockerfile` berbasis `php:8.2-apache` dengan ekstensi GD, SQLite, dan Redis.
  - File `docker-compose.yml` yang mengekspos aplikasi pada port host `2022:80` terhubung ke service Redis.
  - File `.dockerignore` untuk meminimalkan ukuran build context.

---

### [Versi 1.1.0] - 2026-10-15

#### Ditambahkan
* **Kustomisasi Logo Kop Kanan**:
  - Pilihan logo operator tenant (IAS Support, Telkomsel, Indosat Ooredoo, XL Axiata, atau Upload Custom Logo).
  - Pratinjau langsung logo yang dipilih di formulir input.
* **Fleksibilitas Keterangan Foto**:
  - Keterangan foto pada slot awal (Foto A-1) dikosongkan secara default agar teknisi bebas mengisi keterangan site condition.
  - Seluruh 24 slot (Indoor) dan 48 slot (Outdoor) mendukung kustomisasi judul foto.
* **Koreksi Logo Kop Kiri & Kanan**:
  - Memverifikasi dan memperbaiki pertukaran logo IAS Support dan Telkomsel.

---

### [Versi 1.0.0] - 2026-10-15

#### Ditambahkan
* Rilis awal sistem Generator Laporan Preventive Maintenance (PM).
* Modul PM Indoor (7 Halaman) sesuai template Daily Check List IBC Telkomsel Kualanamu.
* Modul PM Outdoor (13 Halaman) sesuai template PM Pole 2 Bandara Kualanamu.
* Tampilan cetak A4 presisi (`print_indoor.php` dan `print_outdoor.php`).
* Export PDF otomatis melalui headless browser (`download_pdf.php`).
* Database SQLite dengan skema relasional `sites`, `pm_reports`, `pm_indoor_details`, `pm_outdoor_details`, dan `pm_photos`.
