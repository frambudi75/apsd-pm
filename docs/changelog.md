# Catatan Perubahan (Changelog)

Seluruh riwayat pembaruan dan implementasi fitur sistem Preventive Maintenance (PM) PT Angkasa Pura Sarana Digital.

---

### [Versi 2.6.0] - 2026-10-17

#### Ditambahkan
* **Dashboard Terpisah Berdasarkan Role**:
  - Tampilan dashboard adaptif: teknisi/operator lapangan mendapatkan ringkasan giliran shift aktif, quick check-in logbook & daily activity, serta riwayat tugas mereka.
  - Supervisor dan Admin mendapatkan ringkasan metrik eksekutif, status equipment breakdown, serta log approval.
* **Sidebar Collapsible (Minimize Sidebar Desktop)**:
  - Tombol toggle untuk memperkecil sidebar dari 260px menjadi 72px mode icon-only dengan tooltip label cepat.
  - Status tersimpan otomatis di localStorage sehingga tidak berkedip saat refresh atau navigasi halaman.
* **Pembersihan Topbar Header**:
  - Menghapus tombol aksi dan avatar duplikat di sudut kanan atas header untuk tampilan kerja yang bersih dan fokus.
* **Manajemen Profil Mandiri (Self-Service Profile & Password)**:
  - Halaman `profile.php` bagi semua pengguna untuk mengubah nama profil, username, email, dan reset password mandiri.
* **Konfigurasi Identitas & Logo Sistem (Branding Management)**:
  - Pemisahan Logo Aplikasi (`app_logo_path`) dan Logo Kop Resmi Laporan PDF Kiri (`report_left_logo_path`) di `settings.php`.
  - Pengaturan Nama Aplikasi (`app_title`) dan Subtitle (`app_subtitle`) terpusat yang diterapkan ke navbar, title bar, footer, dan login.
* **Perbaikan Modul Kamera Daily Activity**:
  - Penambahan input native environment capture untuk memastikan pengambilan gambar kamera di perangkat mobile dan tablet berjalan tanpa kendala.

---

### [Versi 2.5.0] - 2026-10-16

#### Ditambahkan
* **Modul Master Operator & Dynamic Provider Sync**:
  - Pengelolaan data operator tenant seluler dan sinkronisasi dinamis ke formulir checklist dan laporan.
* **Modul Daily Activity & Shift Handover**:
  - Pencatatan aktivitas harian teknisi NOC dengan upload dokumentasi foto, kategori fleksibel, dan log handover shift.

---

### [Versi 2.1.0] - 2026-10-15

#### Ditambahkan
* **Kamera Langsung Terintegrasi (Live In-App Camera)**:
  - Viewfinder kamera live interaktif (`navigator.mediaDevices.getUserMedia`) langsung di dalam aplikasi PWA tanpa perlu berpindah ke aplikasi luar.
  - Dukungan tombol ganti kamera depan/belakang (*switch camera*) dan tombol shutter besar ramah sentuhan.
  - Tampilan HUD realtime pada video stream yang menampilkan jam berjalan (WIB), nama site, dan status GPS.
* **Timestamp & GPS Watermark Otomatis**:
  - Secara otomatis mencetak label inspeksi resmi pada foto: Nama Site & Lokasi, Waktu Terkini (WIB), Koordinat GPS (Latitude & Longitude), serta Kode & Judul Slot Foto.
  - Efek bayangan teks dan banner pita gradien semi-transparan untuk menjamin keterbacaan 100% pada kondisi foto gelap maupun terang benderang.
  - Fallback pintar koordinat GPS: menggunakan sensor lokasi perangkat (*geolocation*) dan otomatis beralih ke koordinat master site jika GPS tidak aktif.
* **Tombol Aksi Ganda pada Setiap Slot Foto**:
  - Tombol **Kamera Live** untuk memotret langsung dengan watermark instan.
  - Tombol **Galeri** untuk memilih foto dari penyimpanan file smartphone/komputer.
* **Master Controller Watermark**:
  - Opsi toggle aktif/nonaktif watermark otomatis jika teknisi ingin mengunggah foto dari aplikasi kamera eksternal tanpa risiko double-watermark.

---

### [Versi 2.0.1] - 2026-10-15

#### Diperbaiki
* **Batas Maksimal Upload File PHP (Docker & Server)**:
  - Mengonfigurasi `max_file_uploads = 100` pada `/usr/local/etc/php/conf.d/apsd-limits.ini` untuk mengakomodasi seluruh 24 slot foto PM Indoor dan 48 slot foto PM Outdoor tanpa memicu warning PHP.
  - Meningkatkan `post_max_size = 128M`, `upload_max_filesize = 64M`, `memory_limit = 512M`, dan `max_input_vars = 5000`.
* **Izin Tulis Database SQLite pada Volume Mount Docker**:
  - Menambahkan script `entrypoint.sh` yang dijalankan sebagai root pada saat container start untuk secara otomatis memberikan hak kepemilikan `www-data:www-data` dan hak akses `chmod -R 777` pada direktori mount `./database` dan `./uploads`.
  - Mengaktifkan mode SQLite Write-Ahead Logging (`PRAGMA journal_mode = WAL;`) dan `PRAGMA busy_timeout = 5000;` pada `config/database.php` untuk mencegah error database readonly dan kunci transaksi konkurensi.

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
