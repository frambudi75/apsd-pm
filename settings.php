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
        $appTitle = trim($_POST['app_title'] ?? '');
        $appSubtitle = trim($_POST['app_subtitle'] ?? '');
        $shiftOhTime = trim($_POST['shift_oh_time'] ?? '08:00 - 17:00 WIB');
        $shiftPagiTime = trim($_POST['shift_pagi_time'] ?? '08:00 - 20:00 WIB');
        $shiftMalamTime = trim($_POST['shift_malam_time'] ?? '20:00 - 08:00 WIB');
        $activityCategories = trim($_POST['activity_categories'] ?? 'Monitoring, Patroli, Troubleshooting, Maintenance, Administrasi, Lainnya');

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
        if ($appTitle !== '') set_system_setting('app_title', $appTitle);
        if ($appSubtitle !== '') set_system_setting('app_subtitle', $appSubtitle);
        set_system_setting('shift_oh_time', $shiftOhTime);
        set_system_setting('shift_pagi_time', $shiftPagiTime);
        set_system_setting('shift_malam_time', $shiftMalamTime);
        set_system_setting('activity_categories', $activityCategories);

        // Handle Branding Directory
        $brandDir = __DIR__ . '/uploads/branding';
        if (!is_dir($brandDir)) {
            @mkdir($brandDir, 0777, true);
        }
        @chmod($brandDir, 0777);

        // 1. Handle App Logo (Sidebar & Web UI)
        if (!empty($_POST['reset_app_logo'])) {
            set_system_setting('app_logo_path', 'assets/img/logo_ias.png?v=3');
        } elseif (isset($_FILES['app_logo_file']) && $_FILES['app_logo_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['app_logo_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'webp'])) {
                $filename = 'logo_app_' . time() . '.' . $ext;
                $dest = $brandDir . '/' . $filename;
                if (move_uploaded_file($_FILES['app_logo_file']['tmp_name'], $dest)) {
                    @chmod($dest, 0666);
                    set_system_setting('app_logo_path', 'uploads/branding/' . $filename);
                }
            } else {
                throw new Exception('Format file logo aplikasi tidak valid. Gunakan format PNG, JPG, SVG, atau WebP.');
            }
        }

        // 2. Handle Report PDF Left Logo (Kop Dokumen Cetak)
        if (!empty($_POST['reset_report_left_logo'])) {
            set_system_setting('report_left_logo_path', 'assets/img/logo_ias.png');
        } elseif (isset($_FILES['report_left_logo_file']) && $_FILES['report_left_logo_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['report_left_logo_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'webp'])) {
                $filename = 'logo_pdf_left_' . time() . '.' . $ext;
                $dest = $brandDir . '/' . $filename;
                if (move_uploaded_file($_FILES['report_left_logo_file']['tmp_name'], $dest)) {
                    @chmod($dest, 0666);
                    set_system_setting('report_left_logo_path', 'uploads/branding/' . $filename);
                }
            } else {
                throw new Exception('Format file logo laporan PDF tidak valid. Gunakan format PNG, JPG, SVG, atau WebP.');
            }
        }

        $successMsg = 'Pengaturan sistem dan logo berhasil diperbarui.';
    } catch (Exception $e) {
        $errorMsg = 'Gagal menyimpan pengaturan: ' . $e->getMessage();
    }
}

$curLeader = get_system_setting('default_team_leader', 'Eko Sulistyo');
$curRole = get_system_setting('default_leader_role', 'Supervisor / Team Leader NOC KNO');
$curVendor = get_system_setting('default_om_vendor', 'PT. Naer Tunas Indonesia');
$curWa = get_system_setting('whatsapp_target_phone', '');
$curCompany = get_system_setting('company_name', 'IASS SBU ICT KNO');
$curUnit = get_system_setting('unit_name', 'IASS SBU ICT KNO');
$curAppTitle = get_system_setting('app_title', get_system_setting('company_name', 'IASS SBU ICT KNO'));
$curAppSubtitle = get_system_setting('app_subtitle', 'Sistem PM & NOC');
$curShiftOh = get_system_setting('shift_oh_time', '08:00 - 17:00 WIB');
$curShiftPagi = get_system_setting('shift_pagi_time', '08:00 - 20:00 WIB');
$curShiftMalam = get_system_setting('shift_malam_time', '20:00 - 08:00 WIB');
$curCategories = get_system_setting('activity_categories', 'Monitoring, Patroli, Troubleshooting, Maintenance, Administrasi, Lainnya');
$curAppLogo = get_system_setting('app_logo_path', 'assets/img/logo_ias.png?v=3');
$curReportLeftLogo = get_system_setting('report_left_logo_path', 'assets/img/logo_ias.png');

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
      <h2 style="font-size: 1.45rem; font-weight: 800; color: var(--text-main); margin-top: 0.25rem; letter-spacing: -0.02em; display: flex; align-items: center; gap: 8px;">
        <?= app_icon('settings', 24, 'text-primary') ?>
        <span>Konfigurasi &amp; Pengaturan Sistem</span>
      </h2>
      <p style="font-size: 0.82rem; color: var(--text-subtle); margin: 0.2rem 0 0 0;">
        Pusat kendali nama aplikasi, logo sistem &amp; kop PDF, identitas unit, jam shift NOC, dan nomor integrasi WhatsApp.
      </p>
    </div>
    <span class="user-role-badge role-superadmin" style="font-size: 0.8rem; padding: 5px 12px; display: inline-flex; align-items: center; gap: 6px;">
      <?= app_icon('shield', 14) ?> Akses Khusus Super Admin
    </span>
  </div>

  <?php if (!empty($successMsg)): ?>
    <div class="alert alert-success" style="background: var(--status-ok-bg); color: var(--status-ok); border: 1px solid var(--status-ok-border); border-radius: var(--radius-md); padding: 0.85rem 1.1rem; margin-bottom: 1.5rem; font-weight: 600; display: flex; align-items: center; gap: 8px;">
      <?= app_icon('check-circle', 18) ?>
      <span><?= htmlspecialchars($successMsg) ?></span>
    </div>
  <?php endif; ?>

  <?php if (!empty($errorMsg)): ?>
    <div class="alert alert-danger" style="background: var(--status-nok-bg); color: var(--status-nok); border: 1px solid var(--status-nok-border); border-radius: var(--radius-md); padding: 0.85rem 1.1rem; margin-bottom: 1.5rem; font-weight: 600; display: flex; align-items: center; gap: 8px;">
      <?= app_icon('alert-triangle', 18) ?>
      <span><?= htmlspecialchars($errorMsg) ?></span>
    </div>
  <?php endif; ?>

  <form method="POST" action="settings" enctype="multipart/form-data" style="max-width: 980px;">
    
    <!-- Section 1: Identitas & Branding Sistem (Nama Aplikasi & Logo) -->
    <div class="settings-card">
      <div class="settings-card-header">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(0, 128, 128, 0.12); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <?= app_icon('image', 20) ?>
          </div>
          <div>
            <h4 style="font-size: 1.05rem; font-weight: 800; color: var(--text-main); margin: 0;">Identitas &amp; Logo Sistem (Branding)</h4>
            <p style="font-size: 0.78rem; color: var(--text-subtle); margin: 2px 0 0 0;">Ubah nama aplikasi, tagline, logo tampilan web, dan logo kop surat resmi cetak PDF secara terpisah.</p>
          </div>
        </div>
        <span class="user-role-badge role-superadmin" style="font-size: 0.72rem;">Branding Sistem</span>
      </div>

      <div class="settings-card-body">
        <!-- Row 1: Nama Aplikasi & Tagline -->
        <div class="settings-grid-2 mb-4">
          <div>
            <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 4px;">
              <span>Nama / Judul Aplikasi (Sidebar &amp; Browser)</span>
              <span style="color: var(--status-nok, #ef4444);">*</span>
            </label>
            <div class="settings-input-group">
              <span class="settings-input-icon"><?= app_icon('globe', 16) ?></span>
              <input type="text" name="app_title" class="form-control settings-control-iconic" value="<?= htmlspecialchars($curAppTitle) ?>" placeholder="Contoh: IASS SBU ICT KNO" required>
            </div>
            <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 5px;">
              Nama utama yang muncul di bagian atas sidebar menu dan judul tab browser.
            </div>
          </div>

          <div>
            <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 4px;">
              <span>Sub-judul / Tagline Aplikasi</span>
              <span style="color: var(--status-nok, #ef4444);">*</span>
            </label>
            <div class="settings-input-group">
              <span class="settings-input-icon"><?= app_icon('tag', 16) ?></span>
              <input type="text" name="app_subtitle" class="form-control settings-control-iconic" value="<?= htmlspecialchars($curAppSubtitle) ?>" placeholder="Contoh: Sistem PM &amp; NOC" required>
            </div>
            <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 5px;">
              Teks keterangan kecil yang muncul tepat di bawah nama aplikasi pada sidebar.
            </div>
          </div>
        </div>

        <!-- Row 2: Pemisahan Logo Web & Logo PDF Kop Surat -->
        <div class="settings-grid-2">
          
          <!-- Logo 1: Logo Aplikasi (Sidebar & Web UI) -->
          <div style="background: var(--bg-alt); padding: 1.25rem; border-radius: var(--radius-md); border: 1px solid var(--border); display: flex; flex-direction: column; justify-content: space-between;">
            <div>
              <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
                <label class="form-label" style="font-size: 0.84rem; font-weight: 700; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 6px;">
                  <?= app_icon('globe', 15, 'text-primary') ?>
                  <span>Logo Aplikasi (Sidebar &amp; Header Web)</span>
                </label>
                <span class="badge" style="background: var(--primary-subtle); color: var(--primary); font-size: 0.7rem; font-weight: 700;">Tampilan Web</span>
              </div>
              
              <!-- Preview Area -->
              <div style="background: var(--bg-card); border: 1px dashed var(--border); border-radius: var(--radius-sm); padding: 1rem; display: flex; align-items: center; justify-content: center; min-height: 80px; margin-bottom: 0.85rem;">
                <img src="<?= htmlspecialchars($curAppLogo) ?>" alt="Preview Logo Aplikasi" id="preview_app_logo" style="max-height: 52px; max-width: 100%; object-fit: contain;">
              </div>

              <div style="font-size: 0.73rem; color: var(--text-subtle); line-height: 1.4; margin-bottom: 0.75rem;">
                Ditampilkan pada navigasi sidebar kiri atas dan banner instalasi aplikasi web di HP/Laptop.
              </div>

              <div>
                <label class="form-label" style="font-size: 0.76rem; font-weight: 600; color: var(--text-main); margin-bottom: 4px;">Pilih File Logo Baru:</label>
                <input type="file" name="app_logo_file" accept="image/png,image/jpeg,image/svg+xml,image/webp" class="form-control" style="font-size: 0.78rem; padding: 0.35rem 0.5rem;" onchange="previewSelectedImage(this, 'preview_app_logo')">
                <div style="font-size: 0.68rem; color: var(--text-subtle); margin-top: 4px;">Format: PNG, JPG, SVG, WebP (Rekomendasi latar transparan).</div>
              </div>
            </div>

            <div style="margin-top: 0.85rem; padding-top: 0.75rem; border-top: 1px solid var(--border);">
              <label style="font-size: 0.76rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                <input type="checkbox" name="reset_app_logo" value="1">
                <span>Reset ke Logo Standar IAS Support</span>
              </label>
            </div>
          </div>

          <!-- Logo 2: Logo Dokumen Cetak PDF (Kop Sebelah Kiri) -->
          <div style="background: var(--bg-alt); padding: 1.25rem; border-radius: var(--radius-md); border: 1px solid var(--border); display: flex; flex-direction: column; justify-content: space-between;">
            <div>
              <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
                <label class="form-label" style="font-size: 0.84rem; font-weight: 700; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 6px;">
                  <?= app_icon('file-text', 15, 'text-primary') ?>
                  <span>Logo Laporan PDF (Sebelah Kiri Kop)</span>
                </label>
                <span class="badge" style="background: rgba(14, 165, 233, 0.15); color: #0284c7; font-size: 0.7rem; font-weight: 700;">Dokumen Resmi</span>
              </div>
              
              <!-- Preview Area (Paper white mockup) -->
              <div style="background: #ffffff; border: 1px dashed #cbd5e1; border-radius: var(--radius-sm); padding: 1rem; display: flex; align-items: center; justify-content: center; min-height: 80px; margin-bottom: 0.85rem; box-shadow: inset 0 1px 3px rgba(0,0,0,0.03);">
                <img src="<?= htmlspecialchars($curReportLeftLogo) ?>" alt="Preview Logo PDF Kiri" id="preview_report_logo" style="max-height: 52px; max-width: 100%; object-fit: contain;">
              </div>

              <div style="font-size: 0.73rem; color: var(--text-subtle); line-height: 1.4; margin-bottom: 0.75rem;">
                Ditampilkan di sudut kiri atas kop surat seluruh lembar cetak PDF (PM Indoor, PM Outdoor, Logbook Shift, dan Rekapitulasi).
              </div>

              <div>
                <label class="form-label" style="font-size: 0.76rem; font-weight: 600; color: var(--text-main); margin-bottom: 4px;">Pilih File Logo Baru:</label>
                <input type="file" name="report_left_logo_file" accept="image/png,image/jpeg,image/svg+xml,image/webp" class="form-control" style="font-size: 0.78rem; padding: 0.35rem 0.5rem;" onchange="previewSelectedImage(this, 'preview_report_logo')">
                <div style="font-size: 0.68rem; color: var(--text-subtle); margin-top: 4px;">Format: PNG, JPG, SVG, WebP (Rekomendasi rasio horizontal resolusi tinggi).</div>
              </div>
            </div>

            <div style="margin-top: 0.85rem; padding-top: 0.75rem; border-top: 1px solid var(--border);">
              <label style="font-size: 0.76rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                <input type="checkbox" name="reset_report_left_logo" value="1">
                <span>Reset ke Logo Standar IAS Support</span>
              </label>
            </div>
          </div>

        </div>
      </div>
    </div>

    <!-- Section 2: Team Leader / Supervisor -->
    <div class="settings-card">
      <div class="settings-card-header">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div style="width: 38px; height: 38px; border-radius: 10px; background: var(--primary-subtle); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <?= app_icon('briefcase', 20) ?>
          </div>
          <div>
            <h4 style="font-size: 1.05rem; font-weight: 800; color: var(--text-main); margin: 0;">Pengaturan Team Leader &amp; Supervisor</h4>
            <p style="font-size: 0.78rem; color: var(--text-subtle); margin: 2px 0 0 0;">Nama default yang dicantumkan pada pengesahan dokumen PM dan lembar Daily Activity.</p>
          </div>
        </div>
        <span class="user-role-badge role-superadmin" style="font-size: 0.72rem;">Pengesahan Resmi</span>
      </div>

      <div class="settings-card-body">
        <div class="settings-grid-2">
          <div>
            <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 4px;">
              <span>Nama Default Team Leader / Supervisor</span>
              <span style="color: var(--status-nok, #ef4444);">*</span>
            </label>
            <div class="settings-input-group">
              <span class="settings-input-icon"><?= app_icon('user', 16) ?></span>
              <input type="text" name="default_team_leader" class="form-control settings-control-iconic" value="<?= htmlspecialchars($curLeader) ?>" placeholder="Contoh: Eko Sulistyo" required>
            </div>
            <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 5px;">
              Otomatis terisi pada form PM dan verifikasi shift teknisi.
            </div>
          </div>

          <div>
            <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 4px;">
              <span>Jabatan / Role Leader</span>
              <span style="color: var(--status-nok, #ef4444);">*</span>
            </label>
            <div class="settings-input-group">
              <span class="settings-input-icon"><?= app_icon('tag', 16) ?></span>
              <input type="text" name="default_leader_role" class="form-control settings-control-iconic" value="<?= htmlspecialchars($curRole) ?>" placeholder="Contoh: Supervisor / Team Leader NOC KNO" required>
            </div>
            <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 5px;">
              Keterangan jabatan resmi di bawah tanda tangan pengesahan.
            </div>
          </div>
        </div>

        <div style="margin-top: 1rem; padding: 0.75rem 1rem; background: var(--bg-alt); border-radius: var(--radius-sm); border: 1px dashed var(--border); font-size: 0.76rem; color: var(--text-subtle); display: flex; align-items: center; gap: 8px;">
          <?= app_icon('info', 16, 'text-primary') ?>
          <div>
            <strong>Pratinjau Pengesahan:</strong> Lembar verifikasi akan mencantumkan pengesahan oleh <strong style="color: var(--primary);"><?= htmlspecialchars($curLeader) ?></strong> (<?= htmlspecialchars($curRole) ?>).
          </div>
        </div>
      </div>
    </div>

    <!-- Section 2: Konfigurasi Jam Kerja & Shift NOC -->
    <div class="settings-card">
      <div class="settings-card-header">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div style="width: 38px; height: 38px; border-radius: 10px; background: #fef3c7; color: #b45309; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <?= app_icon('clock', 20) ?>
          </div>
          <div>
            <h4 style="font-size: 1.05rem; font-weight: 800; color: var(--text-main); margin: 0;">Pengaturan Jam Kerja &amp; Kategori Shift NOC</h4>
            <p style="font-size: 0.78rem; color: var(--text-subtle); margin: 2px 0 0 0;">Konfigurasi rentang jam kerja Office Hour (OH) dan Operasional Shift (Pagi &amp; Malam).</p>
          </div>
        </div>
        <span class="sla-status-pill sla-warning" style="font-size: 0.72rem;">Siklus 24 Jam</span>
      </div>

      <div class="settings-card-body">
        <div class="settings-grid-3">
          <!-- Office Hour -->
          <div>
            <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 6px;">
              <?= app_icon('building', 14, 'text-primary') ?>
              <span>Office Hour (OH)</span>
              <span style="color: var(--status-nok, #ef4444);">*</span>
            </label>
            <div class="settings-input-group">
              <span class="settings-input-icon"><?= app_icon('building', 16) ?></span>
              <input type="text" name="shift_oh_time" class="form-control settings-control-iconic" value="<?= htmlspecialchars($curShiftOh) ?>" placeholder="Contoh: 08:00 - 17:00 WIB" required>
            </div>
            <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 5px;">
              Staf harian non-shift operasional 24 jam.
            </div>
          </div>

          <!-- Shift Pagi -->
          <div>
            <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 6px;">
              <?= app_icon('sun', 14, '', '#d97706') ?>
              <span>Operasional Shift Pagi</span>
              <span style="color: var(--status-nok, #ef4444);">*</span>
            </label>
            <div class="settings-input-group">
              <span class="settings-input-icon"><?= app_icon('sun', 16, '', '#d97706') ?></span>
              <input type="text" name="shift_pagi_time" class="form-control settings-control-iconic" value="<?= htmlspecialchars($curShiftPagi) ?>" placeholder="Contoh: 08:00 - 20:00 WIB" required>
            </div>
            <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 5px;">
              Shift operasional siang NOC Bandara KNO.
            </div>
          </div>

          <!-- Shift Malam -->
          <div>
            <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 6px;">
              <?= app_icon('moon', 14, '', '#0284c7') ?>
              <span>Operasional Shift Malam</span>
              <span style="color: var(--status-nok, #ef4444);">*</span>
            </label>
            <div class="settings-input-group">
              <span class="settings-input-icon"><?= app_icon('moon', 16, '', '#0284c7') ?></span>
              <input type="text" name="shift_malam_time" class="form-control settings-control-iconic" value="<?= htmlspecialchars($curShiftMalam) ?>" placeholder="Contoh: 20:00 - 08:00 WIB" required>
            </div>
            <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 5px;">
              Shift operasional malam hingga pagi.
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Section 3: Identitas Perusahaan & Vendor -->
    <div class="settings-card">
      <div class="settings-card-header">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div style="width: 38px; height: 38px; border-radius: 10px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <?= app_icon('building', 20) ?>
          </div>
          <div>
            <h4 style="font-size: 1.05rem; font-weight: 800; color: var(--text-main); margin: 0;">Identitas Unit &amp; O&amp;M Vendor</h4>
            <p style="font-size: 0.78rem; color: var(--text-subtle); margin: 2px 0 0 0;">Kop dokumen resmi dan default vendor operasional pemeliharaan.</p>
          </div>
        </div>
        <span class="cat-badge cat-monitoring" style="font-size: 0.72rem;">Kop Laporan</span>
      </div>

      <div class="settings-card-body">
        <div class="settings-grid-2 mb-3">
          <div>
            <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: var(--text-main);">Nama Perusahaan / Entitas</label>
            <div class="settings-input-group">
              <span class="settings-input-icon"><?= app_icon('building', 16) ?></span>
              <input type="text" name="company_name" class="form-control settings-control-iconic" value="<?= htmlspecialchars($curCompany) ?>" placeholder="Contoh: IASS SBU ICT KNO" required>
            </div>
          </div>
          <div>
            <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: var(--text-main);">Unit Kerja / Lokasi</label>
            <div class="settings-input-group">
              <span class="settings-input-icon"><?= app_icon('map-pin', 16) ?></span>
              <input type="text" name="unit_name" class="form-control settings-control-iconic" value="<?= htmlspecialchars($curUnit) ?>" placeholder="Contoh: KNO" required>
            </div>
          </div>
        </div>

        <div>
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: var(--text-main);">Default Vendor O&amp;M</label>
          <div class="settings-input-group">
            <span class="settings-input-icon"><?= app_icon('briefcase', 16) ?></span>
            <input type="text" name="default_om_vendor" class="form-control settings-control-iconic" value="<?= htmlspecialchars($curVendor) ?>" placeholder="Contoh: PT. Naer Tunas Indonesia" required>
          </div>
        </div>
      </div>
    </div>

    <!-- Section 4: Notifikasi & WhatsApp -->
    <div class="settings-card">
      <div class="settings-card-header">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div style="width: 38px; height: 38px; border-radius: 10px; background: #dcfce7; color: #15803d; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <?= app_icon('phone', 20) ?>
          </div>
          <div>
            <h4 style="font-size: 1.05rem; font-weight: 800; color: var(--text-main); margin: 0;">Integrasi Laporan WhatsApp</h4>
            <p style="font-size: 0.78rem; color: var(--text-subtle); margin: 2px 0 0 0;">Nomor tujuan untuk pengiriman cepat ringkasan dan tautan dokumen shift NOC.</p>
          </div>
        </div>
        <span class="user-role-badge role-admin" style="font-size: 0.72rem;">Integrasi WA</span>
      </div>

      <div class="settings-card-body">
        <div>
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: var(--text-main);">
            Nomor WhatsApp Tujuan Laporan (Opsional)
          </label>
          <div class="settings-input-group">
            <span class="settings-input-icon"><?= app_icon('phone', 16) ?></span>
            <input type="text" name="whatsapp_target_phone" class="form-control settings-control-iconic" value="<?= htmlspecialchars($curWa) ?>" placeholder="Contoh: 6281234567890 (awali dengan 62 atau 08)">
          </div>
          <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 5px;">
            Jika diisi, tombol "Kirim via WhatsApp" akan langsung membuka chat ke nomor ini. Jika dikosongkan, teknisi bebas memilih kontak atau grup WhatsApp tujuan di aplikasi WA.
          </div>
        </div>

        <div style="margin-top: 1rem; padding: 0.75rem 1rem; background: #f0fdf4; border-radius: var(--radius-sm); border: 1px solid #bbf7d0; font-size: 0.76rem; color: #166534; display: flex; align-items: center; gap: 8px;">
          <?= app_icon('check-circle', 16, '', '#16a34a') ?>
          <div>
            <strong>Status Tautan:</strong> Nomor aktif saat ini: <code><?= htmlspecialchars($curWa ?: 'Belum diset (bebas pilih di WA)') ?></code>.
          </div>
        </div>
      </div>
    </div>

    <!-- Section 5: Master Kategori Kegiatan Harian NOC -->
    <div class="settings-card">
      <div class="settings-card-header">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div style="width: 38px; height: 38px; border-radius: 10px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <?= app_icon('tag', 20) ?>
          </div>
          <div>
            <h4 style="font-size: 1.05rem; font-weight: 800; color: var(--text-main); margin: 0;">Master Kategori Kegiatan NOC</h4>
            <p style="font-size: 0.78rem; color: var(--text-subtle); margin: 2px 0 0 0;">Daftar pill kategori cepat yang muncul pada modal input logbook harian teknisi.</p>
          </div>
        </div>
        <span class="user-role-badge role-admin" style="font-size: 0.72rem;">Logbook NOC</span>
      </div>

      <div class="settings-card-body">
        <div>
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: var(--text-main);">
            Daftar Kategori Default (Pisahkan dengan tanda koma)
          </label>
          <div class="settings-input-group">
            <span class="settings-input-icon"><?= app_icon('clipboard-list', 16) ?></span>
            <input type="text" name="activity_categories" class="form-control settings-control-iconic" value="<?= htmlspecialchars($curCategories) ?>" placeholder="Monitoring, Patroli, Troubleshooting, Maintenance, Administrasi, Instalasi, Koordinasi" required>
          </div>
          <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 5px;">
            Setiap kategori di atas akan menjadi tombol badge cepat saat teknisi mencatat kegiatan di Daily Activity. Teknisi juga dapat menambah kategori kustom baru langsung saat menginput kegiatan.
          </div>
        </div>

        <div style="margin-top: 1rem; padding: 0.75rem 1rem; background: var(--bg-alt); border-radius: var(--radius-sm); border: 1px solid var(--border); font-size: 0.76rem; color: var(--text-main);">
          <strong style="color: var(--primary);">Pratinjau Badge Kategori:</strong>
          <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px;">
            <?php 
            $previewCats = array_filter(array_map('trim', explode(',', $curCategories)));
            foreach ($previewCats as $pc): 
            ?>
              <span class="btn-cat-badge active" style="font-size: 0.74rem; padding: 3px 10px;"><?= htmlspecialchars($pc) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Informasi Versi & Rilis Sistem -->
    <div class="settings-card" style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
      <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(0, 128, 128, 0.1); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <?= app_icon('info', 20) ?>
          </div>
          <div>
            <div style="font-size: 0.95rem; font-weight: 700; color: var(--text-main);">Informasi Versi & Rilis Sistem</div>
            <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 2px;"><?= htmlspecialchars(APP_RELEASE_TITLE) ?></div>
          </div>
        </div>
        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
          <span class="badge" style="background: var(--primary); color: #fff; font-weight: 700; font-size: 0.8rem; padding: 4px 10px; border-radius: 6px;">
            <?= htmlspecialchars(APP_VERSION) ?>
          </span>
          <span class="badge" style="background: var(--bg-alt); color: var(--text-muted); border: 1px solid var(--border); font-size: 0.76rem; padding: 4px 10px; border-radius: 6px;">
            Build <?= htmlspecialchars(APP_BUILD_DATE) ?>
          </span>
          <span class="badge" style="background: var(--bg-alt); color: var(--text-muted); border: 1px solid var(--border); font-size: 0.76rem; padding: 4px 10px; border-radius: 6px;">
            PHP <?= PHP_VERSION ?> (<?= strtoupper($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) ?>)
          </span>
        </div>
      </div>
    </div>

    <!-- Action Buttons -->
    <div style="display: flex; justify-content: flex-end; align-items: center; gap: 12px; margin-top: 1rem; margin-bottom: 2rem;">
      <a href="./" class="btn btn-secondary" style="padding: 0.6rem 1.4rem;">Batal</a>
      <button type="submit" class="btn btn-primary" style="padding: 0.6rem 1.8rem; display: inline-flex; align-items: center; gap: 8px; font-weight: 700; box-shadow: 0 4px 12px rgba(0, 128, 128, 0.25);">
        <?= app_icon('save', 16) ?>
        <span>Simpan Pengaturan Sistem</span>
      </button>
    </div>

  </form>

</div>

<script>
function previewSelectedImage(input, targetId) {
  if (input.files && input.files[0]) {
    var reader = new FileReader();
    reader.onload = function(e) {
      var img = document.getElementById(targetId);
      if (img) img.src = e.target.result;
    };
    reader.readAsDataURL(input.files[0]);
  }
}
</script>

<?php
include __DIR__ . '/includes/footer.php';
?>
