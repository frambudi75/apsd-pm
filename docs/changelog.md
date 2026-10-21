# Catatan Perubahan (Changelog)

Seluruh riwayat pembaruan dan implementasi fitur sistem Preventive Maintenance (PM) PT Angkasa Pura Sarana Digital.

### [Versi 3.1.0] - 2026-10-21

#### Ditambahkan
* **Struktur Kolom Jabatan Personil & Manajemen Profil (`users.php`, `profile.php`, `config/database.php`)**:
  - Penambahan kolom `jabatan VARCHAR(100) NULL DEFAULT 'Teknisi Jaga NOC'` pada skema basis data MariaDB/MySQL dan SQLite dengan mekanisme auto-repair dan auto-migration teruji.
  - Tampilan jabatan resmi pada daftar pengguna desktop dan kartu sentuh seluler dengan aksen teks biru informatif di bawah nama lengkap.
  - Integrasi input jabatan pada modal Tambah Pengguna (`#modal_add_user`) dan modal Edit Pengguna (`#modal_edit_user`) terhubung penuh via JavaScript.
  - Kartu profil mandiri (`profile.php`) kini menampilkan informasi jabatan dinamis dan mengizinkan pembaruan jabatan mandiri bagi Administrator dan Superadmin.
* **Logika Pengesahan Cerdas Laporan / Anti Tanda Tangan Ganda (`print_shift.php`, `print_statistics.php`, `print_kpi.php`)**:
  - Deteksi otomatis `$isSelfDuty` / `$isSelfReport` saat Supervisor / Team Leader NOC (misal: Wahyu Wandira) bertugas sebagai teknisi pelaksana shift atau menyusun laporan rekapitulasi.
  - **Skenario Mandiri (Tanpa Manager)**: Jika tidak ada atasan manager yang dikonfigurasi, tanda tangan otomatis dirangkum ke dalam satu blok tunggal di tengah (*Single Centered Block* bertajuk "Supervisor / Pelaksana Shift NOC"). Menghilangkan duplikasi tanda tangan yang janggal (nama ganda di sisi kiri dan kanan).
  - **Skenario Eskalasi (Dengan Manager)**: Jika Manager Unit dikonfigurasi di Pengaturan, tanda tangan kiri otomatis memuat nama Leader dan tanda tangan kanan beralih ke Manager Unit ("Mengetahui & Menyetujui").
  - **Skenario Standar (Teknisi Biasa)**: Jika dikerjakan teknisi, tanda tangan kiri menampilkan nama teknisi dengan keterangan jabatannya secara dinamis (menggantikan teks statis "NOC Support KNO"), dan tanda tangan kanan tetap diverifikasi oleh Supervisor / Team Leader.
* **Konfigurasi Pengesahan Lanjutan Manager Unit (`settings.php`, `includes/signature_helper.php`)**:
  - Penambahan form pengaturan Manager Unit: Nama Manager (`default_manager_name`), Jabatan / Role Manager (`default_manager_role`), dan Tanda Tangan Digital Manager (`default_manager_signature`).
  - Dukungan tanda tangan digital Manager ganda: gores canvas layar sentuh / mouse dan upload berkas gambar.
* **Penyempurnaan Modul Evaluasi Kinerja & Rapor KPI (`kpi.php`, `print_kpi.php`)**:
  - Mengintegrasikan kolom jabatan dinamis pegawai pada lembar rapor cetak KPI individu (menggantikan hardcoded "Teknisi Jaga NOC KNO").
  - Menerapkan logika pengesahan cerdas anti tanda tangan ganda untuk rapor KPI individu pegawai dan rekapitulasi KPI tahunan seluruh tim.
* **Proteksi Null-Safe REQUEST_METHOD (`users.php`, `profile.php`, `settings.php`)**:
  - Penerapan pengecekan `($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'` untuk mencegah peringatan PHP 8 saat dijalankan via CLI runner, cron, atau lingkungan uji otomatis.

---

### [Versi 3.0.0] - 2026-10-21

#### Ditambahkan
* **Sistem Tanda Tangan Digital Resmi E-Signature (`settings.php`, `profile.php`, `daily_activity.php`, `api/complete_shift.php`, `includes/signature_helper.php`, `assets/js/signature-pad.js`)**:
  - Mesin tanda tangan digital ganda: Mendukung gores tanda tangan langsung di layar (*Canvas Touchscreen* & *Mouse*) dan unggah berkas gambar tanda tangan (PNG transparan / JPG).
  - Pengelolaan tanda tangan digital khusus Supervisor / Team Leader NOC di Pengaturan Sistem (`settings.php`) dengan pratinjau aktif dan fitur reset.
  - Pengelolaan tanda tangan digital mandiri per akun teknisi/admin di Profil Saya (`profile.php`).
  - Integrasi tanda tangan saat menyelesaikan sesi shift di Daily Activity (`daily_activity.php` & `api/complete_shift.php`): Otomatis menyematkan tanda tangan akun atau opsi gores/unggah tanda tangan baru seketika.
  - Penandatanganan otomatis pada seluruh lembar cetak dokumen resmi: Logbook Shift Harian (`print_shift.php`), Rekapitulasi Statistik (`print_statistics.php`), dan Logbook Bulanan (`print_monthly_logbook.php`).
* **Pusat Statistik & Rekapitulasi Laporan Terpadu (`statistics.php`, `print_statistics.php`, `includes/header.php`, `includes/icons.php`)**:
  - Halaman dasbor analitik terpadu menghubungkan metrik performa PM (Indoor & Outdoor) dan Daily Activity NOC secara komprehensif.
  - KPI performa eksekutif, visualisasi ritme jam kerja harian, rasio pekerjaan indoor vs outdoor, breakdown kategori kegiatan, dan leaderboard produktivitas tim teknisi.
  - Lembar cetak dokumen resmi A4 terstandarisasi (`print_statistics.php`) dengan kop resmi, tabel terstruktur, dan kolom penandatanganan pengesahan.
* **Pengesahan Dinamis Admin & Supervisor (`print_statistics.php`)**:
  - Kolom tanda tangan kiri ("Dibuat / Dilaporkan oleh") menampilkan nama dan jabatan Admin pembuat laporan secara otomatis berdasarkan akun login aktif.
  - Kolom tanda tangan kanan ("Mengetahui & Menyetujui") menampilkan nama dan jabatan Supervisor / Team Leader NOC KNO dari konfigurasi sistem.
* **Auto-Migration Kolom Tanda Tangan Basis Data (`config/database.php`, `entrypoint.sh`)**:
  - Penambahan kolom `signature_path` pada tabel `users` dan `daily_shifts` secara terautomasi (MySQL & SQLite).
  - Penambahan direktori `/var/www/html/uploads/signatures` pada skrip inisialisasi Docker `entrypoint.sh`.

#### Diperbaiki
* **Optimasi Tampilan Seluler & Desain Kartu Responsif (`daily_reports.php`)**:
  - Mengatasi kendala teks daftar rekap laporan terpotong pada layar HP dan aplikasi PWA.
  - Mengimplementasikan kartu sentuh responsif (`.mobile-cards-container`) dengan area sentuh ramah jempol (min-height 42px) dan scrolling horizontal tabel desktop yang bersih.
* **Pembaruan Service Worker PWA (`sw.js`)**:
  - Pembaruan `CACHE_NAME` ke `iass-pm-v3.0.0` dan penambahan `assets/js/signature-pad.js` ke daftar aset statis offline.

---

### [Versi 2.9.0] - 2026-10-19

#### Ditambahkan
* **Pusat Cadangan & Pemulihan Sistem Terpusat (`backup.php`, `includes/backup_helper.php`, `api/run_backup.php`)**:
  - Halaman terpadu Disaster Recovery Vault khusus Superadmin untuk memonitor kapasitas basis data, total penyimpanan berkas fisik, status webhook Discord, dan riwayat arsip lokal.
  - Tiga pilihan tindakan pencadangan terstruktur:
    1. Basis data saja (`.sql.gz` / `.sql`) dengan pengekstrak SQL mandiri mencakup 15 tabel dan seluruh relasi.
    2. Berkas fisik unggahan (`.zip`) mencakup foto checklist PM, dokumentasi shift harian, dan brankas dokumen.
    3. Cadangan penuh sistem (`.zip`) menggabungkan dump SQL, direktori unggahan, dan berkas `manifest.json` integritas sistem.
* **Mesin Pengarsipan ZIP Mandiri Pure PHP SimpleZipArchive (`includes/backup_helper.php`)**:
  - Mesin pembuat arsip ZIP berbasis format standar PKZIP 2.0 yang ditulis murni menggunakan PHP tanpa ketergantungan pada ekstensi PHP `ZipArchive` maupun alat CLI Linux.
  - Berfungsi sebagai *automatic fallback* yang menjaga proses pencadangan tetap berhasil 100% pada lingkungan container atau server yang belum mengaktifkan ekstensi `zip`.
  - Berkas arsip terverifikasi kompatibel penuh dengan Windows Explorer, macOS Archive Utility, dan Linux `unzip`.
* **Pengarsipan Multi-Batch & Pemecahan Berkas Discord Webhook (`includes/backup_helper.php`)**:
  - Algoritma pemecahan berkas otomatis (*chunking*) untuk arsip yang melebihi batas unggah webhook Discord (&le; 20 MB per bagian).
  - Notifikasi pembuka informatif dengan verifikasi hash integritas SHA-256.
  - Pengiriman setiap bagian berkas secara sekuensial dengan jeda anti-rate limit Discord.
  - Notifikasi penutup otomatis berisi panduan perintah rekonstruksi/penggabungan berkas satu baris untuk terminal Windows (`copy /b`) dan Linux (`cat`).
* **Pemisahan Saluran Webhook Cadangan Mandiri / Saluran 3 (`settings.php`, `api/test_discord_webhook.php`, `includes/backup_helper.php`)**:
  - Menghadirkan saluran ke-3 resmi: **Saluran Cadangan & Arsip Sistem** (`discord_webhook_backup`) di Pengaturan Sistem.
  - Mengisolasi berkas arsip berukuran besar dari saluran percakapan operasional NOC (Saluran 1) dan saluran audit keamanan (Saluran 2).
  - Dilengkapi tombol pengujian mandiri (*Uji Saluran Cadangan*), tombol hapus webhook, dan mekanisme *fail-safe fallback* ke Saluran 2 jika saluran cadangan belum disetel.
* **Otomatisasi Cron Cadangan Berkala & Migrasi Basis Data (`cron_backup.php`, `database/migrate_sqlite_to_mysql.php`, `Dockerfile`, `docker-compose.yml`)**:
  - Skrip eksekusi pencadangan otomatis di latar belakang melalui CLI (`cron_backup.php`).
  - Skrip migrasi terautomasi SQLite ke MySQL/MariaDB dengan verifikasi struktur dan pemindahan seluruh baris data.
  - Integrasi volume persisten `./backups:/var/www/html/backups` pada Docker Compose.
* **Pembaruan Service Worker PWA (`sw.js`)**:
  - Pembaruan `CACHE_NAME` ke `iass-pm-v2.9.0` untuk cache-busting aset dan pembaruan antarmuka.

---

### [Versi 2.8.1] - 2026-10-18

#### Diperbaiki
* **Refaktor Dialog Selesai Shift & Hapus Laporan ke Modal Kustom (`daily_activity.php`)**:
  - Mengganti dialog native browser `confirm()` dan `prompt()` dengan modal kustom elegan `#complete_shift_modal` dan `#delete_shift_modal`.
  - Mengatasi bug peramban di mana klik ganda atau kehilangan fokus menyebabkan dialog browser native otomatis tertutup dalam 1 detik atau tidak merespons.
  - Memastikan modal memiliki input catatan serah terima yang rapi, tombol konfirmasi dengan indikator pemrosesan, dan penutupan mulus via backdrop.
* **Keandalan & Aksesibilitas Logout / Keluar Sistem (`includes/header.php`, `includes/auth.php`, `login.php`, `profile.php`)**:
  - Menghapus penghalang dialog konfirmasi browser native pada tautan keluar yang sebelumnya dapat memblokir navigasi saat dibatalkan atau ditekan cepat.
  - Memastikan tautan keluar menggunakan alamat absolut dinamis `get_app_base_url() . 'logout'` agar selalu valid dari halaman mana pun.
  - Menghadirkan tombol cepat "Keluar" dan lencana profil pengguna pada bilah navigasi atas (topbar) sehingga pengguna desktop maupun seluler dapat langsung keluar tanpa harus membuka sidebar terlebih dahulu.
  - Menambahkan tombol keluar mandiri pada kartu ringkasan profil pengguna (`profile.php`).
  - Menyempurnakan fungsi `logout_user()` di `includes/auth.php` dengan inisialisasi sesi penuh, pembersihan cookie sesi pada root path `/`, dan penghancuran sesi tuntas.
  - Menambahkan pembersihan sesi paksa dan invalidasi cache Service Worker di `login.php?logout=1` untuk mencegah auto-redirect balik ke dashboard.
* **Penyempurnaan Service Worker PWA (`sw.js`)**:
  - Menambahkan pengecualian bypass langsung pada Service Worker untuk rute `/logout`, `/login`, dan `/api/` agar tidak dicegat atau disajikan dari cache saat status autentikasi berubah.
  - Pembaruan `CACHE_NAME` ke `iass-pm-v2.8.1`.

---

### [Versi 2.8.0] - 2026-10-18

#### Ditambahkan
* **Penegakan Disiplin SOP Sesi Shift Tak Tertutup (`daily_activity.php`, `api/start_shift.php`, `api/complete_shift.php`)**:
  - Penahanan otomatis pembukaan shift baru jika teknisi masih memiliki sesi shift aktif sebelumnya yang belum diselesaikan / ditutup.
  - **Kasus A (Shift memiliki catatan kegiatan)**: Menampilkan banner peringatan oranye informatif dengan tombol 1-klik untuk langsung menuju sesi shift sebelumnya agar dapat diselesaikan dan diserahkan.
  - **Kasus B (Shift kosong tanpa kegiatan)**: Menerapkan penahanan ketat (lockout merah) dengan larangan mandiri menutup shift kosong. Menampilkan tombol hubungi Superadmin via WhatsApp untuk evaluasi dan reset penutupan sesi secara resmi.
  - Superadmin / Administrator memiliki hak wewenang override untuk menutup paksa shift kosong dengan catatan audit otomatis.

#### Diperbaiki
* **Keandalan & Kebersihan Notifikasi Discord Logbook NOC (`api/complete_shift.php`, `includes/discord.php`)**:
  - Memperbaiki query SQL pengambilan kegiatan shift agar menyertakan kolom `title`, memastikan judul kegiatan resmi tersemat akurat di pesan Discord.
  - Mengganti encoding entitas HTML (`htmlspecialchars`) dengan pembersihan tag murni (`strip_tags`) pada embed Discord agar karakter seperti `&` tidak berubah menjadi `&amp;`.
  - Memastikan fail-safe pengiriman notifikasi Discord dan pelampiran otomatis berkas PDF resmi logbook shift.
* **Pembaruan Versi PWA Service Worker (`sw.js`)**:
  - Pembaruan `CACHE_NAME` ke `iass-pm-v2.8.0` untuk pembaruan skrip dan aset antarmuka peramban teknisi.

---

### [Versi 2.7.9] - 2026-10-18

#### Ditambahkan
* **Pengingat Pintar Serah Terima Shift / Shift Handover Alert (`daily_activity.php`, `assets/css/app.css`)**:
  - Menghadirkan banner pengingat otomatis pada halaman Daily Activity yang aktif 30 menit sebelum jam operasional shift berakhir (pukul 19:30 WIB untuk Shift Pagi, 07:30 WIB untuk Shift Malam, dan 16:30 WIB untuk Office Hour).
  - Dilengkapi tombol 1-klik "Kirim WA" untuk langsung membuka format laporan siap kirim ke grup WhatsApp dan tombol "Serahkan Shift" untuk mengunci status laporan shift tanpa harus mencari tombol lain.
  - Peringatan status mendesak (urgent alert merah) otomatis menyala jika jam operasional shift telah terlampaui tetapi status shift masih berstatus aktif (belum diserahkan).
  - Timer real-time di sisi browser (client-side background checker) yang otomatis menampilkan notifikasi tanpa perlu refresh halaman saat teknisi sedang bertugas.
  - Opsi tutup sementara pengingat selama 15 menit jika teknisi masih menyelesaikan pencatatan kegiatan terakhir.
* **Pembaruan Versi PWA Service Worker (`sw.js`)**:
  - Pembaruan `CACHE_NAME` ke `iass-pm-v2.7.9` untuk penyegaran aset CSS dan JavaScript di peramban teknisi.

---

### [Versi 2.7.8] - 2026-10-18

#### Diperbaiki
* **Preservasi Aspek Rasio Utuh & Watermark Timestamp Foto Cetak Logbook (`print_shift.php`, `print_monthly_logbook.php`)**:
  - Mengubah perlakuan render foto dokumen lampiran kegiatan shift dari `object-fit: cover` menjadi `object-fit: contain` dengan tinggi frame dinamis yang proporsional.
  - Memastikan seluruh foto lanskap (16:9 dan 4:3) maupun potret dari kamera ponsel teknisi tampil utuh 100% tanpa ada tepi atau bagian bawah yang terpotong.
  - Teks watermark stempel waktu (jam, tanggal, menit) dan koordinat GPS di bagian sudut bawah foto kini terlihat jelas, tajam, dan lengkap pada mode pratinjau cetak browser maupun ekspor PDF.
* **Pembaruan Cache Service Worker PWA (`sw.js`)**:
  - Peningkatan `CACHE_NAME` ke `iass-pm-v2.7.8` agar pembaruan tata letak cetak dokumen langsung aktif di perangkat seluler pengguna.

---

### [Versi 2.7.7] - 2026-10-18

#### Ditambahkan
* **Penetapan Global Zona Waktu Asia/Jakarta (WIB) (`config/database.php`)**:
  - Memastikan seluruh perhitungan waktu, tanggal, pencatatan logbook, dan stempel jam di aplikasi selalu akurat dan sinkron dengan Waktu Indonesia Barat (WIB).
* **Deteksi Otomatis Jenis Shift & Tanggal Real-Time NOC (`daily_activity.php`)**:
  - Sistem otomatis memilih Shift Pagi saat teknisi membuka logbook di rentang jam 08:00 - 19:59 WIB dengan tanggal hari ini.
  - Sistem otomatis memilih Shift Malam saat diakses pada jam 20:00 - 23:59 WIB (tanggal hari ini) dan 00:00 - 07:59 WIB dini hari (mengaitkan otomatis ke sesi Shift Malam yang dimulai kemarin).
* **Proteksi Pintar Pencegahan Salah Pilih Shift (Smart Mismatch Warning)**:
  - Dialog konfirmasi pencegahan saat teknisi mencoba memilih atau membuka shift yang tidak sesuai dengan jam nyata operasional saat itu.
  - Spanduk peringatan visual yang informatif jika teknisi sedang melihat data shift di luar jam operasional aktif, lengkap dengan tombol 1-klik untuk kembali ke shift aktif saat ini.
* **Pembaruan Versi PWA Service Worker (`sw.js`)**:
  - Pembaruan `CACHE_NAME` ke `iass-pm-v2.7.7` untuk invalidasi cache browser seluler teknisi.
* **Redesain Modern Pintasan Formulir PM (`form_indoor.php`, `form_outdoor.php`, `assets/css/app.css`)**:
  - Menghilangkan tampilan link biru default peramban pada pintasan navigasi formulir di desktop.
  - Memindahkan posisi bilah pintasan ke bawah judul formulir dan merancang ulang sebagai bilah navigasi pil elegan (`quick-jump-chip`) dengan nomor langkah, efek hover aksen teal, dan dukungan dark mode.

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
