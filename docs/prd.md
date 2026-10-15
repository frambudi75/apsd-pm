# Product Requirements Document (PRD)
## Sistem Preventive Maintenance (PM) Checklist & Generator Laporan PDF
**PT Angkasa Pura Sarana Digital (APSD) / PT IAS Support Indonesia**

---

### 1. Latar Belakang & Tujuan
Petugas pemeliharaan (Preventive Maintenance / PM) di lingkungan bandara dan fasilitas telekomunikasi rutin melakukan inspeksi berkala pada site indoor (ruang server domestik, perangkat IBC, BTS, RRU, AC) dan site outdoor (tiang/pole, tower, pagar, sistem penangkal petir, grounding, genset, KWH).
Selama ini pengisian laporan membutuhkan waktu karena harus memindahkan data dan foto ke template dokumen secara manual.
Sistem ini dirancang untuk:
1. Memungkinkan teknisi menginput checklist secara cepat dan responsif lewat ponsel atau laptop di lapangan.
2. Memudahkan upload foto bukti inspeksi per slot foto yang terstandarisasi.
3. Menghasilkan output dokumen PDF resmi yang format, tata letak tabel, kop logo, dan grid fotonya 100% identik dengan format fisik PT IAS Support Indonesia / APSD.

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

#### Modul 2: Preventive Maintenance Outdoor / Pole (Mechanical Electrical)
* **Target Output**: Laporan PDF 13 Halaman.
  * Halaman 1: Cover Informasi Site (APSD Site ID, Nama, Region, Alamat, Koordinat, Tinggi Site, Tipe Tower/Site, Tenant List, OM Vendor).
  * Halaman 2: Checklist Site Information (Pengukuran Grounding, Status Grounding, Kondisi Tower, Lighting, Fence, Other).
  * Halaman 3: Base Service (Members Completeness, Korosi Tower, Ketegakan/Alignment, Pedestal & Baseplate, Kondisi Tanah, Kondisi Bangunan, Tanda Tangan Hamdhani / PT. Naer).
  * Halaman 4: Tenant ME (AC #1 s/d #4 komplit, Exhaust Fan, Shelter, Alarm System, Grounding Tenant, Pengukuran Tegangan KWH, PLN Visual Check, Genset Permanen).
  * Halaman 5: Tenant PLN Function Test (Kondisi KWH, Saklar, MCB, Putaran meteran, Indikator R-S-T).
  * Halaman 6 s/d 13: Dokumentasi Foto (Foto A-1 s/d H-6, total 48 slot foto termasuk foto tower segments, foto panorama 0-300 derajat, pengukuran grounding, dan foto safety APD).

#### Modul 3: Master Data & Manajemen Site
* Menyimpan daftar site (Nama Site, ID, Alamat, Region, Latitude, Longitude, Tipe) sehingga saat teknisi membuat laporan baru, informasi site langsung terisi otomatis.

#### Modul 4: Engine Export & Cetak PDF
* Mendukung cetak langsung via Browser (Print to PDF dengan ukuran pas A4).
* Mendukung download PDF satu-klik via engine Microsoft Edge headless yang terintegrasi di sistem operasi Windows.

---

### 3. Kriteria Keberhasilan
1. Form input mudah digunakan di mobile: tombol status OK/NOK/NA besar (min 44px), fitur tombol "Set All OK" untuk mempercepat pekerjaan.
2. Slot foto yang kosong otomatis tercetak sebagai "N/A" tanpa merusak tata letak halaman PDF.
3. Format hasil cetak PDF presisi sesuai sampel dokumen acuan.
