<?php
/**
 * Pengaturan Sistem (Khusus Superadmin)
 * IASS SBU ICT KNO
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
require_role('superadmin');

$page_title = 'Pengaturan Sistem';
$pdo = get_db_connection();
$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $defaultLeader = trim($_POST['default_team_leader'] ?? '');
        $leaderRole = trim($_POST['default_leader_role'] ?? '');
        $omVendor = trim($_POST['default_om_vendor'] ?? '');
        $waPhone = trim($_POST['whatsapp_target_phone'] ?? '');
        $companyName = trim($_POST['company_name'] ?? '');
        $unitName = trim($_POST['unit_name'] ?? '');

        // Sanitize phone (remove + and spaces if starts with +)
        $waPhone = preg_replace('/[^0-9]/', '', $waPhone);
        if (str_starts_with($waPhone, '0')) {
            $waPhone = '62' . substr($waPhone, 1);
        }

        set_system_setting('default_team_leader', $defaultLeader);
        set_system_setting('default_leader_role', $leaderRole);
        set_system_setting('default_om_vendor', $omVendor);
        set_system_setting('whatsapp_target_phone', $waPhone);
        set_system_setting('company_name', $companyName);
        set_system_setting('unit_name', $unitName);

        $successMsg = 'Pengaturan sistem berhasil diperbarui.';
    } catch (Exception $e) {
        $errorMsg = 'Gagal menyimpan pengaturan: ' . $e->getMessage();
    }
}

$curLeader = get_system_setting('default_team_leader', 'Eko Sulistyo');
$curRole = get_system_setting('default_leader_role', 'Supervisor / Team Leader NOC KNO');
$curVendor = get_system_setting('default_om_vendor', 'PT. Naer Tunas Indonesia');
$curWa = get_system_setting('whatsapp_target_phone', '');
$curCompany = get_system_setting('company_name', 'PT Angkasa Pura Sarana Digital');
$curUnit = get_system_setting('unit_name', 'IASS SBU ICT KNO');

include __DIR__ . '/includes/header.php';
?>

<div class="container py-4">

  <!-- Breadcrumb & Title -->
  <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
    <div>
      <div style="font-size: 0.8rem; color: var(--text-subtle); display: flex; align-items: center; gap: 6px;">
        <a href="./" style="color: inherit; text-decoration: none;">Dashboard</a>
        <span>/</span>
        <span style="color: var(--primary); font-weight: 600;">Pengaturan Sistem</span>
      </div>
      <h2 style="font-size: 1.5rem; font-weight: 800; color: var(--text-main); margin-top: 0.25rem; letter-spacing: -0.02em;">
        Konfigurasi &amp; Pengaturan Sistem
      </h2>
    </div>
    <span class="user-role-badge role-superadmin" style="font-size: 0.8rem; padding: 4px 10px;">
      Akses Khusus Super Admin
    </span>
  </div>

  <?php if (!empty($successMsg)): ?>
    <div class="alert alert-success" style="background: var(--status-ok-bg); color: var(--status-ok); border: 1px solid var(--status-ok-border); border-radius: var(--radius-md); padding: 0.75rem 1rem; margin-bottom: 1.5rem; font-weight: 600;">
      ✓ <?= htmlspecialchars($successMsg) ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($errorMsg)): ?>
    <div class="alert alert-danger" style="background: var(--status-nok-bg); color: var(--status-nok); border: 1px solid var(--status-nok-border); border-radius: var(--radius-md); padding: 0.75rem 1rem; margin-bottom: 1.5rem; font-weight: 600;">
      ⚠️ <?= htmlspecialchars($errorMsg) ?>
    </div>
  <?php endif; ?>

  <form method="POST" action="settings" style="max-width: 850px;">
    
    <!-- Section 1: Team Leader / Supervisor -->
    <div class="card p-4 mb-4" style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);">
      <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 1.25rem;">
        <div style="width: 36px; height: 36px; border-radius: 50%; background: var(--primary-subtle); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
          👔
        </div>
        <div>
          <h4 style="font-size: 1.05rem; font-weight: 700; color: var(--text-main); margin: 0;">Pengaturan Team Leader &amp; Supervisor</h4>
          <p style="font-size: 0.78rem; color: var(--text-subtle); margin: 0;">Nama default yang dicantumkan pada pengesahan dokumen PM dan Daily Activity.</p>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-md-6 mb-3">
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: var(--text-main);">
            Nama Default Team Leader / Supervisor <span style="color: red;">*</span>
          </label>
          <input type="text" name="default_team_leader" class="form-control" value="<?= htmlspecialchars($curLeader) ?>" placeholder="Contoh: Eko Sulistyo" required>
          <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 4px;">
            Otomatis terisi pada form PM Indoor/Outdoor dan lembar tanda tangan shift.
          </div>
        </div>

        <div class="col-md-6 mb-3">
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: var(--text-main);">
            Jabatan / Role Leader <span style="color: red;">*</span>
          </label>
          <input type="text" name="default_leader_role" class="form-control" value="<?= htmlspecialchars($curRole) ?>" placeholder="Contoh: Supervisor / Team Leader NOC KNO" required>
          <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 4px;">
            Keterangan jabatan di bawah tanda tangan pengesahan supervisor.
          </div>
        </div>
      </div>
    </div>

    <!-- Section 2: Notifikasi & WhatsApp -->
    <div class="card p-4 mb-4" style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);">
      <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 1.25rem;">
        <div style="width: 36px; height: 36px; border-radius: 50%; background: #dcfce7; color: #15803d; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
          📲
        </div>
        <div>
          <h4 style="font-size: 1.05rem; font-weight: 700; color: var(--text-main); margin: 0;">Integrasi Laporan WhatsApp</h4>
          <p style="font-size: 0.78rem; color: var(--text-subtle); margin: 0;">Nomor tujuan untuk pengiriman cepat ringkasan dan tautan PDF shift NOC.</p>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: var(--text-main);">
          Nomor WhatsApp Tujuan Laporan (Opsional)
        </label>
        <input type="text" name="whatsapp_target_phone" class="form-control" value="<?= htmlspecialchars($curWa) ?>" placeholder="Contoh: 6281234567890 (awali dengan 62 atau 08)">
        <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 4px;">
          Jika diisi, tombol "Kirim via WhatsApp" akan langsung mengarahkan pesan ke nomor ini. Jika dikosongkan, teknisi bebas memilih kontak atau grup WhatsApp tujuan.
        </div>
      </div>
    </div>

    <!-- Section 3: Identitas Perusahaan & Vendor -->
    <div class="card p-4 mb-4" style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);">
      <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 1.25rem;">
        <div style="width: 36px; height: 36px; border-radius: 50%; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
          🏢
        </div>
        <div>
          <h4 style="font-size: 1.05rem; font-weight: 700; color: var(--text-main); margin: 0;">Identitas Unit &amp; O&amp;M Vendor</h4>
          <p style="font-size: 0.78rem; color: var(--text-subtle); margin: 0;">Kop dokumen dan default vendor operasional.</p>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-md-6 mb-3">
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: var(--text-main);">Nama Perusahaan</label>
          <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($curCompany) ?>" required>
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: var(--text-main);">Unit Kerja / Lokasi</label>
          <input type="text" name="unit_name" class="form-control" value="<?= htmlspecialchars($curUnit) ?>" required>
        </div>
        <div class="col-md-12 mb-2">
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: var(--text-main);">Default Vendor O&amp;M</label>
          <input type="text" name="default_om_vendor" class="form-control" value="<?= htmlspecialchars($curVendor) ?>" required>
        </div>
      </div>
    </div>

    <div style="display: flex; justify-content: flex-end; gap: 8px;">
      <a href="./" class="btn btn-secondary">Batal</a>
      <button type="submit" class="btn btn-primary" style="padding: 0.6rem 1.75rem;">
        Simpan Pengaturan Sistem
      </button>
    </div>

  </form>

</div>

<?php
include __DIR__ . '/includes/footer.php';
?>
