# Catatan Perubahan (Changelog)

Seluruh riwayat pembaruan dan implementasi fitur sistem Preventive Maintenance (PM) PT Angkasa Pura Sarana Digital.

---

### [Versi 2.7.6] - 2026-10-18

#### Ditambahkan
* **Pencatatan Audit Trail Penghapusan Laporan PM (`api/delete_report.php`)**:
  - Menyimpan ringkasan metadata laporan yang dihapus (jenis laporan, nama site, dan tanggal) ke tabel `audit_logs` secara otomatis saat proses single delete maupun batch delete.
* **Deteksi Otomatis URL & Dukungan Reverse Proxy / Cloudflare (`includes/auth.php`)**:
  - Menghadirkan fungsi `get_full_app_url()` dan `is_ssl_request()` untuk membangun URL absolut sistem secara dinamis di seluruh lingkungan (domain produksi root, reverse proxy HTTPS, dan lokal XAMPP).
* **Aturan Recovery Redirect URL Subfolder Lama (`.htaccess`)**:
  - Mengarahkan tautan dokumen yang terlanjur beredar dengan awalan `/apsd/` di grup WhatsApp ke rute URL root yang benar tanpa error 404.

#### Diperbaiki
* **Penghapusan Bug Query Tabel Non-Eksisten pada Hapus Laporan (`api/delete_report.php`)**:
  - Menghilangkan query `DELETE FROM report_shares` yang memicu error `SQLSTATE[42S02]: Table doesn't exist` pada basis data MySQL produksi.
* **Perbaikan Tautan Unduh PDF Laporan Shift WhatsApp (`daily_activity.php`)**:
  - Menghapus hardcoded path `/apsd/` agar tautan laporan shift yang dibagikan ke WhatsApp langsung mengarah ke URL aktif sistem.
* **Sinkronisasi Skrip Auto-Optimise Database (`cron_optimize.php` & `api/run_system_optimize.php`)**:
  - Memperbaiki nama tabel `system_settings` dan menyertakan seluruh tabel laporan PM (`pm_reports`, `pm_indoor_details`, `pm_outdoor_details`, `pm_photos`, `daily_activity_photos`, `user_document_shares`, `user_document_external_shares`).
* **Pembersihan Ikon Aksi Dokumen Cetak (`print_outdoor.php` & `print_indoor.php`)**:
  - Mengganti seluruh emoji pada tombol cetak dan unduh dengan ikon SVG inline profesional sesuai standar Anti-Slop AI.
* **Audit & Penguatan Keamanan Akses Formulir PM (`form_indoor.php`, `form_outdoor.php`, `reports.php`)**:
  - Menerapkan pemeriksaan login wajib sebelum query database dijalankan, mencegah pemrosesan data tanpa otentikasi.
* **Penanganan Loopback Port 443 & Flag Keamanan Headless Chrome (`download_pdf.php`, `batch_download_pdf.php`, `includes/discord.php`)**:
  - Memperbaiki URL loopback lokal internal agar menggunakan protokol HTTPS pada server dengan port 443 serta menambahkan opsi `--ignore-certificate-errors` dan `--allow-insecure-localhost`.
* **Pembaruan Versi Cache PWA Service Worker (`sw.js`)**:
  - Memperbarui `CACHE_NAME` ke `iass-pm-v2.7.6` agar browser klien otomatis membuang cache lama dan memuat aset terbaru.
* **Integritas Relasi Brankas Dokumen (`api/delete_document.php`)**:
  - Membersihkan data berbagi berkas pada `user_document_shares` dan `user_document_external_shares` sebelum dokumen dihapus.
* **Penguatan Proteksi Open Redirect (`login.php`)**:
  - Menolak URL redirect relatif protokol ganda (`//` atau `\\`) pada parameter redirect halaman login.
* **Optimasi Rute Hapus Master Site (`sites.php`)**:
  - Mengarahkan aksi hapus langsung ke URL kanonikal `sites?msg=deleted` tanpa melewati 301 hop redirect.

---

### [Versi 2.7.5] - 2026-10-17

#### Ditambahkan
* **Fitur Edit Master Site (`sites.php`)**:
  - Menghadirkan tombol aksi "Edit" pada setiap baris data master site untuk mengubah informasi site yang tersimpan.
  - Modal dialog interaktif "Edit Data Master Site" yang otomatis memuat seluruh data eksisting (Nama Site, Site ID, Detail Area, Region, Provinsi, Kota, Koordinat, Tinggi Site, Tipe Tower, Tipe Site, Anchor Tenant, Total Tenant, dan Alamat Lengkap).
  - Sinkronisasi instan ke basis data SQLite, pembersihan otomatis cache Redis (`dashboard:`), dan pencatatan riwayat perubahan ke tabel audit log.
* **Integrasi Interactive Map Coordinate Picker (Leaflet & OpenStreetMap)**:
  - Fitur pemilihan koordinat Latitude dan Longitude secara visual melalui peta interaktif Leaflet.js yang disematkan langsung di dalam sistem.
  - Pengguna dapat mengklik lokasi di peta atau menggeser pin marker merah (draggable pin) secara presisi dengan pembaruan koordinat 6 digit desimal secara langsung.
  - Tersedia tombol preset area strategis Bandara Internasional Kualanamu (Pusat KNO, Terminal KNO, Tower / SBU ICT).
  - Tombol pendeteksi otomatis koordinat sensor GPS perangkat ("GPS Saya") baik di dalam peta maupun langsung pada formulir penambahan dan pengeditan site.
* **Pencarian Cepat Data Master Site**:
  - Fitur pencarian instan di header tabel daftar master site untuk menyaring data berdasarkan nama site, ID site, area, atau tipe tower tanpa perlu memuat ulang halaman.
* **Redesain Halaman Login & Optimalisasi Responsif Mobile (`login.php`)**:
  - Tampilan split-screen modern enterprise untuk desktop: panel kiri menampilkan showcase operasional bandara (fitur inspeksi, logbook shift 24/7, audit trail) dan panel kanan formulir otentikasi.
  - Penyesuaian tata letak khusus layar seluler (< 992px) yang ringkas tanpa banner ganda, menghilangkan duplikasi logo, dan memastikan form login langsung tampak tanpa perlu scroll.
  - Penerapan standar Anti-Slop AI dengan mengganti seluruh emoji menjadi ikon inline SVG profesional, serta meniadakan animasi ornamen berlebihan.
  - Sinkronisasi dinamis identitas aplikasi dan footer hak cipta dengan konfigurasi basis data (`get_system_setting`).

#### Diperbaiki
* **Optimalisasi Penghapusan Laporan & Batch Action Bar (`reports.php` & `api/delete_report.php`)**:
  - Memperbaiki penundaan penghapusan laporan dengan membersihkan kunci Redis `reports:all_v2` dan seluruh awalan `reports:*` seketika saat penghapusan dilakukan.
  - Menyempurnakan floating batch action bar agar muncul saat checkbox "Pilih Semua" atau checkbox baris laporan dicentang, lengkap dengan tombol Unduh ZIP Terpilih dan Hapus Terpilih.

---

### [Versi 2.7.4] - 2026-10-17

#### Diperbaiki
* **Mobile Card Reflow untuk Formulir PM Indoor & Outdoor**:
  - Mengubah tata letak tabel checklist inspeksi pada layar smartphone (< 768px) dari tabel horizontal yang sempit menjadi kartu vertikal mandiri (`checklist-row`).
  - Nomor dan nama item pemeriksaan tampil leluasa di bagian atas kartu bersama referensi standar tanpa terhimpit.
  - Tombol pilihan status (OK / NOK / N/A) tersusun merata dengan ukuran sentuhan jari yang nyaman (tinggi min 42px).
  - Kolom **Keterangan / Catatan** kini mendapatkan baris khusus selebar 100% layar di bagian bawah kartu dengan label jelas, sehingga teknisi dapat membaca dan mengetik temuan inspeksi dengan leluasa tanpa terpotong.
* **Pembaruan Service Worker (v2.7.4)**:
  - Cache Service Worker dinaikkan ke versi `iass-pm-v2.7.4` dengan query `sw.js?v=17` untuk memastikan seluruh perangkat mobile langsung memperbarui cache CSS dan script terbaru.

---

### [Versi 2.7.3] - 2026-10-17

#### Ditambahkan
* **Tampilan Kartu Mobile untuk User Manager (`users.php`)**:
  - Menghadirkan antarmuka kartu mobile (`.mobile-user-card`) di bawah breakpoint 768px dengan inisial avatar, nama lengkap, username, badge peran, status aktif, tanggal login terakhir, dan tombol aksi (Reset Password, Edit, Hapus).
  - Fitur pencarian dan filter peran otomatis menyaring kartu mobile secara real time.
* **Floating Collapsible Progress Tracker Foto PM**:
  - Mendesain ulang indikator progres foto formulir PM di layar HP menjadi badge pill mengambang ringkas di sudut kanan bawah (`📷 0/18 Foto (0%) ▲ ×`).
  - Fitur tap-to-expand untuk melihat detail progres bar dan tombol lompat ke foto kosong, serta kontrol ciutkan dan tutup agar tidak menghalangi tombol simpan formulir.

#### Diperbaiki
* **Skala Tipografi Responsif Mobile**:
  - Mengganti ukuran font inline pada formulir PM dan Daily Activity dengan class CSS terstandarisasi (`.module-page-title`, `.daily-page-title`, `.daily-shift-title`, dll.) agar judul dan teks proporsional di layar HP.

---

### [Versi 2.7.2] - 2026-10-17

#### Diperbaiki
* **Pemecahan Isu PWA Installation di Android & Server Cloudflare**:
  - Memisahkan definisi tujuan icon manifest menjadi slot tersendiri untuk `purpose: "any"` dan `purpose: "maskable"` sesuai standar PWA W3C.
  - Menambahkan hash timestamp dan cache-busting query (`?v=2`) pada endpoint icon manifest untuk menembus cache Cloudflare CDN.
  - Memastikan kanvas icon launcher PWA berdimensi persegi presisi (192x192 dan 512x512) dengan background transparan/putih.
  - Menghilangkan redirect halaman login pada `start_url` agar lolos audit instalasi Google Chrome dan Microsoft Edge.
  - Mendaftarkan Service Worker pada layar login (`login.php`) agar notifikasi instalasi PWA langsung muncul sebelum login.

---

### [Versi 2.7.1] - 2026-10-17

#### Ditambahkan
* **Kotak Informasi Pengembang di Halaman About (`about.php`)**:
  - Menghadirkan kotak profil khusus pengembang (Developer: Habib Frambudi) lengkap dengan tautan interaktif ke profil GitHub dan portofolio.
* **Perapian Urutan Menu Master Data**:
  - Mengurutkan submenu Master Data pada sidebar desktop dan drawer mobile secara alfabetis (A-Z: Dokumen Teknis, Master Operator, Master Site, User Manager).

---

### [Versi 2.7.0] - 2026-10-17

#### Ditambahkan
* **Modul Repositori Dokumen Teknis (`documents.php`)**:
  - Pengelolaan dokumen standar operasional: SOP Pemeliharaan, Instruksi Kerja (IK), Service Level Agreement (SLA), Drawing/Topologi, dan Manual Pengguna.
  - Fitur upload file PDF/dokumen, pencarian, filter kategori, preview dokumen interaktif, dan tracking unduhan.
* **Modul Audit Log Terpusat (`audit_log.php`)**:
  - Rekam jejak aktivitas operasional, login/logout, dan modifikasi data dengan IP address dan browser tracking.
* **Fitur Berbagi Laporan Publik (`share.php`)**:
  - Pembuatan token tautan publik dengan masa berlaku fleksibel untuk akses pihak eksternal tanpa perlu login.
* **Fitur Unduh Batch Laporan (`batch_download_pdf.php`)**:
  - Fitur ekspor seluruh arsip laporan PDF bulanan ke dalam format ZIP terkompresi.
* **Modul Pemeliharaan & System Optimizer (`system_optimize.php`, `cron_optimize.php`)**:
  - Fitur pembersihan database SQLite VACUUM, rotasi log lawas, dan penjadwalan otomatis via CLI cron.

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
  - Fitur cetak laporan shift dan Monthly Logbook PDF.

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
