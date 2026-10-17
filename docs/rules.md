# Aturan Bisnis & Format Standar PDF APSD

### 1. Aturan Tampilan & Pencetakan PDF
1. **Ukuran Halaman**: Wajib format A4 Portrait (`210mm x 297mm`).
2. **Kop & Logo**:
   * PM Indoor: Logo `IAS SUPPORT` di kiri atas dan `Telkomsel` (atau tenant terpilih) di kanan atas pada halaman foto.
   * PM Outdoor: Logo `IAS SUPPORT` di kiri dan `LOGO TENANT / APSD` di kanan.
3. **Simbol Status Checklist**:
   * Opsi yang dipilih pada checklist menggunakan centang tebal `✓` atau lingkaran hitam pada teks pilihan (contoh: `(OK)` atau lingkaran oval pada pilihan `Pole`).
4. **Grid Foto**:
   * Setiap halaman foto berisikan 6 kotak (2 kolom x 3 baris).
   * Kotak memiliki border abu-abu gelap/hitam.
   * Jika tidak ada foto yang diupload, kotak diisi teks besar **`N/A`** tepat di tengah kotak.
   * Di bawah setiap kotak foto tercantum: Kode Foto (misal `Foto A-1`), Judul Foto (misal `KWH Multi Panel`), dan nilai pengukuran jika ada (misal `0.26` Ohm).

---

### 2. Aturan Input Data & Ergonomi Lapangan (Mobile-First)
1. **Kolom Wajib PM**: Nama Site, Tanggal Laporan, Petugas Inspeksi.
2. **Kolom Opsional PM**: Nomor PIC, Catatan Tambahan.
3. **Nilai Default**: Semua item checklist dapat diinisialisasi otomatis ke status `OK` via tombol "Set Semua Checklist OK" untuk mempercepat pekerjaan teknisi.
4. **Tampilan Mobile (< 768px)**:
   * Tabel checklist inspeksi wajib direflow menjadi kartu vertikal independen.
   * Target sentuhan tombol radio status (OK / NOK / N/A) minimal 42px.
   * Input teks catatan/keterangan wajib menempati baris tersendiri selebar 100% layar agar tidak terpotong.
   * Indikator progres foto harus berupa badge mengambang yang dapat diciutkan/ditutup agar tidak menghalangi tombol simpan.

---

### 3. Aturan Operasional Daily Activity & Shift Handover NOC
1. **Pembagian Shift Kerja**:
   * Shift Pagi: 08:00 sampai 16:00 WIB.
   * Shift Siang: 16:00 sampai 24:00 WIB.
   * Shift Malam: 00:00 sampai 08:00 WIB.
2. **Kewajiban Pengisian**:
   * Setiap aktivitas yang dilakukan wajib dicatat jam mulai dan selesainya, kategori pekerjaan, serta status (`done`, `in_progress`, `pending`).
   * Serah terima shift (handover) wajib diverifikasi oleh Team Leader shift terkait dengan menyertakan catatan inventaris dan isu pending.

---

### 4. Aturan Hak Akses Pengguna (RBAC)
1. **Teknisi**:
   * Dapat membuat, mengedit draf, dan melihat laporan PM miliknya.
   * Dapat mencatat Daily Activity dan mengunduh Dokumen Teknis SOP/IK.
   * Tidak dapat menghapus master data, mengubah setelan sistem, atau mengakses Audit Log.
2. **Supervisor**:
   * Memiliki akses penuh melihat seluruh laporan PM tim dan ringkasan metrik dashboard.
   * Dapat menyetujui (approve) handover shift dan mereview dokumen teknis.
3. **Admin**:
   * Memiliki hak akses penuh ke seluruh modul sistem, termasuk User Management, System Optimizer, Audit Log, dan Pengaturan Branding.
