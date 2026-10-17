# Product Requirements Document (PRD)
## Sistem Preventive Maintenance (PM) Checklist & Generator Laporan PDF
**PT Angkasa Pura Sarana Digital (APSD) / PT IAS Support Indonesia**

---

### 1. Latar Belakang & Tujuan
Petugas pemeliharaan (Preventive Maintenance / PM) dan tim operasional Network Operations Center (NOC) di lingkungan bandara dan fasilitas telekomunikasi rutin melakukan inspeksi berkala pada site indoor (ruang server domestik, perangkat IBC, BTS, RRU, AC), site outdoor (tiang/pole, tower, pagar, sistem penangkal petir, grounding, genset, KWH), serta pencatatan logbook kegiatan shift 24/7.

Sistem ini dirancang untuk:
1. Memungkinkan teknisi menginput checklist secara cepat dan responsif lewat ponsel atau laptop di lapangan.
2. Memudahkan upload foto bukti inspeksi per slot foto yang terstandarisasi dengan watermark GPS dan waktu otomatis.
3. Mencatat kegiatan harian tim NOC beserta prosedur serah terima shift (handover) secara transparan dan akuntabel.
4. Menyediakan pusat repositori dokumen teknis kerja (SOP, Instruksi Kerja, SLA) yang mudah diakses dari mana saja.
5. Menghasilkan output dokumen PDF resmi yang format, tata letak tabel, kop logo, dan grid fotonya 100% identik dengan format fisik PT IAS Support Indonesia / APSD.

---

### 2. Ruang Lingkup Sistem

#### Modul 1: Preventive Maintenance Indoor (Template IBC Telkomsel)
* **Target Output**: Laporan PDF 7 Halaman.
  * Halaman 1: Kop Dokumen, Informasi Lokasi & Petugas, Metadata Site, Checklist Bagian A (Air Conditioner), Bagian B (Ruang Server), Bagian C (Daya Listrik MCB).
  * Halaman 2: Checklist Bagian D (Optical Termination Box), Bagian E (Remote Radio Unit), Bagian F (Multi Network Combiner), Bagian G (Splitter 2 Way), Bagian H (Splitter 3 Way).
  * Halaman 3: Checklist Bagian I (Splitter 4 Way), Bagian J (Antenna Planar), Bagian K (Antenna Omnidirectional), Catatan Bebas.
  * Halaman 4: Grid Foto A-1 s/d A-6 (KWH Multi Panel, Meter Telkomsel, ACPDB Closed/Open, View Area Domestik, View Kebersihan).
  * Halaman 5: Grid Foto B-1 s/d B-6 (MCB ACPDB, Pengukuran Tegangan R-S, R-T, S-T, R-N, G-N).
  * Halaman 6: Grid Foto C-1 s/d C-6 (BTS Recty, RRU, Grounding Busbar 1 & 2, View AC, Suhu Ruangan).
  * Halaman 7: Grid Foto D-1 s/d D-6 (APAR, Suhu Ruangan, Foto Keterangan Tambahan).
* **Fitur Tambahan**: Mobile card reflow dengan input catatan lebar penuh, draf otomatis LocalStorage, dan floating photo tracker.

#### Modul 2: Preventive Maintenance Outdoor / Pole (Mechanical Electrical)
* **Target Output**: Laporan PDF 13 Halaman.
  * Halaman 1: Cover Informasi Site (APSD Site ID, Nama, Region, Alamat, Koordinat, Tinggi Site, Tipe Tower/Site, Tenant List, OM Vendor).
  * Halaman 2: Checklist Site Information (Pengukuran Grounding, Status Grounding, Kondisi Tower, Lighting, Fence, Other).
  * Halaman 3: Base Service (Members Completeness, Korosi Tower, Ketegakan/Alignment, Pedestal & Baseplate, Kondisi Tanah, Kondisi Bangunan, Tanda Tangan Hamdhani / PT. Naer).
  * Halaman 4: Tenant ME (AC #1 s/d #4 komplit, Exhaust Fan, Shelter, Alarm System, Grounding Tenant, Pengukuran Tegangan KWH, PLN Visual Check, Genset Permanen).
  * Halaman 5: Tenant PLN Function Test (Kondisi KWH, Saklar, MCB, Putaran meteran, Indikator R-S-T).
  * Halaman 6 s/d 13: Dokumentasi Foto (Foto A-1 s/d H-6, total 48 slot foto termasuk foto tower segments, foto panorama 0-300 derajat, pengukuran grounding, dan foto safety APD).

#### Modul 3: Daily Activity & Shift Handover NOC
* Pencatatan logbook dinas harian per shift (Pagi 08:00 - 16:00, Siang 16:00 - 24:00, Malam 00:00 - 08:00).
* Daftar item aktivitas dengan waktu mulai/selesai, kategori pekerjaan, deskripsi, status, dan upload foto dokumentasi.
* Formulir serah terima shift (handover) terstruktur: catatan pending tasks, pengecekan inventaris, dan checklist serah terima.
* Cetak laporan PDF per shift (A4) dan cetak Monthly Logbook gabungan.

#### Modul 4: Repositori Dokumen Teknis (SOP, IK, SLA)
* Tempat penyimpanan terpusat untuk Standar Operasional Prosedur, Instruksi Kerja, dan Service Level Agreement.
* Upload dokumen PDF/file pendukung, kategorisasi, preview interaktif, dan pencatatan riwayat unduhan.

#### Modul 5: Master Data & Manajemen Pengguna
* **Master Site**: Menyimpan data lokasi, koordinat GPS, alamat, dan spesifikasi tiang.
* **Master Operator**: Menyimpan daftar tenant seluler (Telkomsel, Indosat, XL, Smartfren) dan logo resmi kop kanan.
* **User Manager & RBAC**: Pengelolaan pengguna dengan 3 peran (Admin, Supervisor, Teknisi), status akun, reset password, dan tampilan kartu responsif di mobile.

#### Modul 6: Keamanan, Audit Log & Pemeliharaan Sistem
* Rekam jejak seluruh mutasi data dan login/logout via `audit_log.php`.
* Pemeliharaan database dan performa sistem via `system_optimize.php` dan `cron_optimize.php`.
* Pengaturan branding dan konfigurasi logo terpusat di `settings.php`.

#### Modul 7: Engine Cetak PDF, Berbagi Publik & Batch ZIP
* Dukungan cetak langsung via Browser (Print Preview presisi A4).
* Download PDF satu-klik via engine headless browser.
* Berbagi laporan publik dengan token unik dan masa kedaluwarsa fleksibel (`share.php`).
* Download batch seluruh laporan bulanan dalam format ZIP (`batch_download_pdf.php`).

---

### 3. Kriteria Keberhasilan
1. Formulir input mudah digunakan di mobile: tombol status OK/NOK/NA besar (min 42px), tabel checklist direflow menjadi kartu vertikal dengan kolom catatan 100% terlihat.
2. Slot foto yang kosong otomatis tercetak sebagai "N/A" tanpa merusak tata letak halaman PDF.
3. Format hasil cetak PDF presisi sesuai sampel dokumen fisik acuan PT IAS Support Indonesia / APSD.
4. Aplikasi dapat diinstal sebagai PWA di perangkat Android dan desktop serta berjalan stabil saat offline.
