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
        $documentCategories = trim($_POST['document_categories'] ?? 'Sertifikat & Lisensi, Pass Bandara & Identitas, Surat Tugas & SK, Manual & Dokumen Teknis, Kontrak & Legalitas, Laporan & Notulen, Lainnya');

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
        set_system_setting('document_categories', $documentCategories);

        // Discord Multi-Channel Settings (Safe update: don't wipe out unless explicitly cleared)
        $discordNoc = trim($_POST['discord_webhook_noc'] ?? '');
        $discordSec = trim($_POST['discord_webhook_security'] ?? '');
        $discordBkp = trim($_POST['discord_webhook_backup'] ?? '');
        $notifyShift = isset($_POST['discord_notify_shift_complete']) ? '1' : '0';
        $notifyDoc = isset($_POST['discord_notify_doc_upload']) ? '1' : '0';
        $notifyOpt = isset($_POST['discord_notify_system_optimize']) ? '1' : '0';
        $notifyLogin = isset($_POST['discord_notify_login_failed']) ? '1' : '0';

        if (!empty($_POST['clear_discord_webhook_noc'])) {
            set_system_setting('discord_webhook_noc', '');
        } elseif ($discordNoc !== '') {
            set_system_setting('discord_webhook_noc', $discordNoc);
        }

        if (!empty($_POST['clear_discord_webhook_security'])) {
            set_system_setting('discord_webhook_security', '');
        } elseif ($discordSec !== '') {
            set_system_setting('discord_webhook_security', $discordSec);
        }

        if (!empty($_POST['clear_discord_webhook_backup'])) {
            set_system_setting('discord_webhook_backup', '');
        } elseif ($discordBkp !== '') {
            set_system_setting('discord_webhook_backup', $discordBkp);
        }

        set_system_setting('discord_notify_shift_complete', $notifyShift);
        set_system_setting('discord_notify_doc_upload', $notifyDoc);
        set_system_setting('discord_notify_system_optimize', $notifyOpt);
        set_system_setting('discord_notify_login_failed', $notifyLogin);

        // Handle Branding Directory
        $brandDir = __DIR__ . '/uploads/branding';
        if (!is_dir($brandDir)) {
            @mkdir($brandDir, 0777, true);
        }
        @chmod($brandDir, 0777);

        // Helper: Auto-crop transparent padding from uploaded PNG/WebP images
        $cropTransparency = function(string $filePath): void {
            if (!extension_loaded('gd') || !file_exists($filePath)) return;
            $info = @getimagesize($filePath);
            if (!$info) return;
            $mime = $info['mime'] ?? '';
            $src = null;
            if ($mime === 'image/png') {
                $src = @imagecreatefrompng($filePath);
            } elseif ($mime === 'image/webp') {
                $src = @imagecreatefromwebp($filePath);
            }
            if (!$src) return;

            $w = imagesx($src);
            $h = imagesy($src);
            $minX = $w; $minY = $h;
            $maxX = 0; $maxY = 0;
            $found = false;

            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $rgba = imagecolorat($src, $x, $y);
                    $alpha = ($rgba >> 24) & 0x7F;
                    if ($alpha < 120) {
                        if ($x < $minX) $minX = $x;
                        if ($x > $maxX) $maxX = $x;
                        if ($y < $minY) $minY = $y;
                        if ($y > $maxY) $maxY = $y;
                        $found = true;
                    }
                }
            }

            if ($found && $minX <= $maxX && $minY <= $maxY) {
                $cw = $maxX - $minX + 1;
                $ch = $maxY - $minY + 1;
                if (($cw * $ch) < ($w * $h * 0.88)) {
                    $pad = 6;
                    $cropX = max(0, $minX - $pad);
                    $cropY = max(0, $minY - $pad);
                    $cropW = min($w - $cropX, $cw + ($pad * 2));
                    $cropH = min($h - $cropY, $ch + ($pad * 2));

                    $dest = imagecreatetruecolor($cropW, $cropH);
                    imagealphablending($dest, false);
                    imagesavealpha($dest, true);
                    $trans = imagecolorallocatealpha($dest, 0, 0, 0, 127);
                    imagefilledrectangle($dest, 0, 0, $cropW, $cropH, $trans);

                    imagealphablending($dest, true);
                    imagecopy($dest, $src, 0, 0, $cropX, $cropY, $cropW, $cropH);
                    imagesavealpha($dest, true);

                    if ($mime === 'image/png') {
                        imagepng($dest, $filePath, 8);
                    } elseif ($mime === 'image/webp') {
                        imagewebp($dest, $filePath, 90);
                    }
                    imagedestroy($dest);
                }
            }
            imagedestroy($src);
        };

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
                    $cropTransparency($dest);
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
                    $cropTransparency($dest);
                    set_system_setting('report_left_logo_path', 'uploads/branding/' . $filename);
                }
            } else {
                throw new Exception('Format file logo laporan PDF tidak valid. Gunakan format PNG, JPG, SVG, atau WebP.');
            }
        }

        // Synchronize PWA Web App Manifest & App Icons
        require_once __DIR__ . '/includes/pwa.php';
        sync_pwa_manifest_and_icons();

        log_audit_event('SETTINGS', 'SETTINGS_UPDATE', 'Pembaruan konfigurasi identitas sistem & operasional', 'settings', null);

        $successMsg = 'Pengaturan sistem, logo, dan profil PWA aplikasi berhasil diperbarui.';
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
$curDocCategories = get_system_setting('document_categories', 'Sertifikat & Lisensi, Pass Bandara & Identitas, Surat Tugas & SK, Manual & Dokumen Teknis, Kontrak & Legalitas, Laporan & Notulen, Lainnya');
$curAppLogo = get_system_setting('app_logo_path', 'assets/img/logo_ias.png?v=3');
$curReportLeftLogo = get_system_setting('report_left_logo_path', 'assets/img/logo_ias.png');
$curDiscordNoc = get_system_setting('discord_webhook_noc', '');
$curDiscordSec = get_system_setting('discord_webhook_security', '');
$curDiscordBkp = get_system_setting('discord_webhook_backup', '');
$curNotifyShift = get_system_setting('discord_notify_shift_complete', '1');
$curNotifyDoc = get_system_setting('discord_notify_doc_upload', '1');
$curNotifyOpt = get_system_setting('discord_notify_system_optimize', '1');
$curNotifyLogin = get_system_setting('discord_notify_login_failed', '1');

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
              <div style="background: var(--bg-card); border: 1px dashed var(--border); border-radius: var(--radius-sm); padding: 1.25rem 1rem; display: flex; align-items: center; justify-content: center; min-height: 105px; margin-bottom: 0.85rem; background-image: radial-gradient(var(--border) 1px, transparent 1px); background-size: 14px 14px;">
                <img src="<?= htmlspecialchars($curAppLogo) ?>" alt="Preview Logo Aplikasi" id="preview_app_logo" style="max-height: 72px; max-width: 100%; object-fit: contain;">
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
              <div style="background: #ffffff; border: 1px dashed #cbd5e1; border-radius: var(--radius-sm); padding: 1.25rem 1rem; display: flex; align-items: center; justify-content: center; min-height: 105px; margin-bottom: 0.85rem; box-shadow: inset 0 1px 3px rgba(0,0,0,0.03);">
                <img src="<?= htmlspecialchars($curReportLeftLogo) ?>" alt="Preview Logo PDF Kiri" id="preview_report_logo" style="max-height: 68px; max-width: 100%; object-fit: contain;">
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

    <!-- Section 5: Integrasi Notifikasi Discord (Saluran Terpisah) -->
    <div class="settings-card" id="discord_backup">
      <div class="settings-card-header">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(88, 101, 242, 0.12); color: #5865f2; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <?= app_icon('message-circle', 20) ?>
          </div>
          <div>
            <h4 style="font-size: 1.05rem; font-weight: 800; color: var(--text-main); margin: 0;">Integrasi Notifikasi Discord (3 Saluran Terpisah)</h4>
            <p style="font-size: 0.78rem; color: var(--text-subtle); margin: 2px 0 0 0;">Kirim notifikasi otomatis ke saluran terpisah agar aktivitas operasional teknisi, audit keamanan, dan arsip pencadangan tidak tercampur.</p>
          </div>
        </div>
        <span class="user-role-badge role-admin" style="font-size: 0.72rem; background: rgba(88, 101, 242, 0.12); color: #5865f2; border: 1px solid rgba(88, 101, 242, 0.25);">Discord Multi-Channel</span>
      </div>

      <div class="settings-card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(310px, 1fr)); gap: 1rem;">
          
          <!-- Channel 1: NOC & Operasional Laporan -->
          <div style="background: var(--bg-alt); padding: 1.25rem; border-radius: var(--radius-md); border: 1px solid var(--border); display: flex; flex-direction: column; justify-content: space-between;">
            <div>
              <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
                <label class="form-label" style="font-size: 0.84rem; font-weight: 700; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 6px;">
                  <?= app_icon('clock', 15, 'text-primary') ?>
                  <span>Saluran 1: Operasional NOC</span>
                </label>
                <span class="badge" style="background: var(--primary-subtle); color: var(--primary); font-size: 0.7rem; font-weight: 700;">NOC Channel</span>
              </div>

              <div style="font-size: 0.74rem; color: var(--text-subtle); margin-bottom: 0.75rem;">
                Digunakan untuk mengirim ringkasan pergantian shift, logbook kegiatan, dan dokumen tim baru.
              </div>

              <div style="margin-bottom: 0.85rem;">
                <label style="font-size: 0.76rem; font-weight: 600; color: var(--text-main); margin-bottom: 4px; display: block;">
                  Webhook URL Saluran NOC:
                </label>
                <div class="settings-input-group">
                  <span class="settings-input-icon"><?= app_icon('globe', 16) ?></span>
                  <input type="url" name="discord_webhook_noc" id="discord_webhook_noc" class="form-control settings-control-iconic" value="<?= htmlspecialchars($curDiscordNoc) ?>" placeholder="https://discord.com/api/webhooks/...">
                </div>
                <?php if (!empty($curDiscordNoc)): ?>
                  <div style="margin-top: 6px; display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 0.72rem; color: var(--status-ok, #10b981); font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                      <?= app_icon('check-circle', 12) ?> Webhook aktif tersimpan
                    </span>
                    <button type="button" class="btn btn-link btn-sm text-danger p-0" style="font-size: 0.72rem; text-decoration: none;" onclick="clearWebhook('noc')">
                      Hapus
                    </button>
                    <input type="hidden" name="clear_discord_webhook_noc" id="clear_discord_webhook_noc" value="0">
                  </div>
                <?php endif; ?>
              </div>

              <!-- Event Toggles -->
              <div style="font-size: 0.76rem; font-weight: 600; color: var(--text-main); margin-bottom: 6px;">Pemicu Notifikasi Aktif:</div>
              <div style="display: flex; flex-direction: column; gap: 6px; margin-bottom: 1rem;">
                <label style="font-size: 0.76rem; color: var(--text-main); display: flex; align-items: center; gap: 8px; cursor: pointer;">
                  <input type="checkbox" name="discord_notify_shift_complete" value="1" <?= $curNotifyShift === '1' ? 'checked' : '' ?>>
                  <span>Kirim ringkasan otomatis saat sesi shift NOC selesai &amp; dikunci</span>
                </label>
                <label style="font-size: 0.76rem; color: var(--text-main); display: flex; align-items: center; gap: 8px; cursor: pointer;">
                  <input type="checkbox" name="discord_notify_doc_upload" value="1" <?= $curNotifyDoc === '1' ? 'checked' : '' ?>>
                  <span>Kirim pemberitahuan saat dokumen baru dibagikan ke tim di Brankas</span>
                </label>
              </div>
            </div>

            <div style="padding-top: 0.75rem; border-top: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap;">
              <button type="button" class="btn btn-sm btn-outline-secondary" id="btn_test_discord_noc" onclick="testDiscordWebhook('noc')">
                <?= app_icon('refresh', 14) ?> Uji Saluran NOC
              </button>
              <div id="status_discord_noc" style="font-size: 0.74rem;"></div>
            </div>
          </div>

          <!-- Channel 2: Keamanan & Pemeliharaan Sistem Admin -->
          <div style="background: var(--bg-alt); padding: 1.25rem; border-radius: var(--radius-md); border: 1px solid var(--border); display: flex; flex-direction: column; justify-content: space-between;">
            <div>
              <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
                <label class="form-label" style="font-size: 0.84rem; font-weight: 700; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 6px;">
                  <?= app_icon('shield', 15, '', '#dc2626') ?>
                  <span>Saluran 2: Keamanan Sistem</span>
                </label>
                <span class="badge" style="background: rgba(220, 38, 38, 0.12); color: #dc2626; font-size: 0.7rem; font-weight: 700;">Security Channel</span>
              </div>

              <div style="font-size: 0.74rem; color: var(--text-subtle); margin-bottom: 0.75rem;">
                Digunakan untuk mengirim alert percobaan login gagal dan hasil Auto-Optimise server.
              </div>

              <div style="margin-bottom: 0.85rem;">
                <label style="font-size: 0.76rem; font-weight: 600; color: var(--text-main); margin-bottom: 4px; display: block;">
                  Webhook URL Saluran Keamanan:
                </label>
                <div class="settings-input-group">
                  <span class="settings-input-icon"><?= app_icon('globe', 16) ?></span>
                  <input type="url" name="discord_webhook_security" id="discord_webhook_security" class="form-control settings-control-iconic" value="<?= htmlspecialchars($curDiscordSec) ?>" placeholder="https://discord.com/api/webhooks/...">
                </div>
                <?php if (!empty($curDiscordSec)): ?>
                  <div style="margin-top: 6px; display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 0.72rem; color: var(--status-ok, #10b981); font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                      <?= app_icon('check-circle', 12) ?> Webhook aktif tersimpan
                    </span>
                    <button type="button" class="btn btn-link btn-sm text-danger p-0" style="font-size: 0.72rem; text-decoration: none;" onclick="clearWebhook('security')">
                      Hapus
                    </button>
                    <input type="hidden" name="clear_discord_webhook_security" id="clear_discord_webhook_security" value="0">
                  </div>
                <?php endif; ?>
              </div>

              <!-- Event Toggles -->
              <div style="font-size: 0.76rem; font-weight: 600; color: var(--text-main); margin-bottom: 6px;">Pemicu Notifikasi Aktif:</div>
              <div style="display: flex; flex-direction: column; gap: 6px; margin-bottom: 1rem;">
                <label style="font-size: 0.76rem; color: var(--text-main); display: flex; align-items: center; gap: 8px; cursor: pointer;">
                  <input type="checkbox" name="discord_notify_system_optimize" value="1" <?= $curNotifyOpt === '1' ? 'checked' : '' ?>>
                  <span>Kirim laporan ringkasan kapasitas setelah Auto-Optimise selesai dijalankan</span>
                </label>
                <label style="font-size: 0.76rem; color: var(--text-main); display: flex; align-items: center; gap: 8px; cursor: pointer;">
                  <input type="checkbox" name="discord_notify_login_failed" value="1" <?= $curNotifyLogin === '1' ? 'checked' : '' ?>>
                  <span>Kirim peringatan keamanan instan saat terjadi kegagalan otentikasi login</span>
                </label>
              </div>
            </div>

            <div style="padding-top: 0.75rem; border-top: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap;">
              <button type="button" class="btn btn-sm btn-outline-secondary" id="btn_test_discord_sec" onclick="testDiscordWebhook('security')">
                <?= app_icon('shield', 14) ?> Uji Saluran Keamanan
              </button>
              <div id="status_discord_sec" style="font-size: 0.74rem;"></div>
            </div>
          </div>

          <!-- Channel 3: Saluran Cadangan & Disaster Recovery Vault -->
          <div style="background: var(--bg-alt); padding: 1.25rem; border-radius: var(--radius-md); border: 1px solid var(--border); display: flex; flex-direction: column; justify-content: space-between;">
            <div>
              <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
                <label class="form-label" style="font-size: 0.84rem; font-weight: 700; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 6px;">
                  <?= app_icon('package', 15, '', '#0284c7') ?>
                  <span>Saluran 3: Cadangan &amp; Arsip Sistem</span>
                </label>
                <span class="badge" style="background: rgba(14, 165, 233, 0.12); color: #0284c7; font-size: 0.7rem; font-weight: 700;">Backup Vault</span>
              </div>

              <div style="font-size: 0.74rem; color: var(--text-subtle); margin-bottom: 0.75rem;">
                Saluran khusus untuk mengarsipkan cadangan basis data (.sql) dan paket berkas sistem multi-batch (&le; 20 MB per batch).
              </div>

              <div style="margin-bottom: 0.85rem;">
                <label style="font-size: 0.76rem; font-weight: 600; color: var(--text-main); margin-bottom: 4px; display: block;">
                  Webhook URL Saluran Cadangan:
                </label>
                <div class="settings-input-group">
                  <span class="settings-input-icon"><?= app_icon('globe', 16) ?></span>
                  <input type="url" name="discord_webhook_backup" id="discord_webhook_backup" class="form-control settings-control-iconic" value="<?= htmlspecialchars($curDiscordBkp) ?>" placeholder="https://discord.com/api/webhooks/...">
                </div>
                <?php if (!empty($curDiscordBkp)): ?>
                  <div style="margin-top: 6px; display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 0.72rem; color: var(--status-ok, #10b981); font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                      <?= app_icon('check-circle', 12) ?> Webhook aktif tersimpan
                    </span>
                    <button type="button" class="btn btn-link btn-sm text-danger p-0" style="font-size: 0.72rem; text-decoration: none;" onclick="clearWebhook('backup')">
                      Hapus
                    </button>
                    <input type="hidden" name="clear_discord_webhook_backup" id="clear_discord_webhook_backup" value="0">
                  </div>
                <?php endif; ?>
              </div>

              <!-- Info Box -->
              <div style="padding: 0.65rem 0.85rem; background: rgba(14, 165, 233, 0.08); border-radius: var(--radius-sm); border: 1px solid rgba(14, 165, 233, 0.2); font-size: 0.74rem; color: #0369a1; line-height: 1.45; margin-bottom: 1rem;">
                <strong>Pemisahan Saluran:</strong> Menyimpan berkas cadangan di saluran khusus mencegah saluran percakapan atau audit keamanan penuh oleh berkas arsip biner berukuran besar. Jika dikosongkan, cadangan akan dialihkan ke Saluran 2.
              </div>
            </div>

            <div style="padding-top: 0.75rem; border-top: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap;">
              <button type="button" class="btn btn-sm btn-outline-secondary" id="btn_test_discord_bkp" onclick="testDiscordWebhook('backup')">
                <?= app_icon('package', 14) ?> Uji Saluran Cadangan
              </button>
              <div id="status_discord_bkp" style="font-size: 0.74rem;"></div>
            </div>
          </div>

        </div>

        <div style="margin-top: 1rem; padding: 0.85rem 1rem; background: var(--bg-alt); border-radius: var(--radius-sm); border: 1px solid var(--border); font-size: 0.74rem; color: var(--text-subtle); line-height: 1.5;">
          <strong style="color: var(--text-main);">Cara Membuat Webhook di Discord:</strong> Buka Discord &gt; Pengaturan Saluran / Edit Channel &gt; Tab <strong>Integrations</strong> &gt; Klik <strong>Webhooks</strong> &gt; <strong>New Webhook</strong> &gt; Salin <strong>Copy Webhook URL</strong> lalu tempelkan pada kolom di atas.
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

    <!-- Section 6: Master Kategori Brankas Dokumen -->
    <div class="settings-card">
      <div class="settings-card-header">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(0, 128, 128, 0.12); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <?= app_icon('folder', 20) ?>
          </div>
          <div>
            <h4 style="font-size: 1.05rem; font-weight: 800; color: var(--text-main); margin: 0;">Master Kategori Brankas Dokumen</h4>
            <p style="font-size: 0.78rem; color: var(--text-subtle); margin: 2px 0 0 0;">Kelola daftar opsi kategori dokumen (Sertifikat, Lisensi K3, Pass Bandara, SK, dsb) untuk upload dan filter berkas.</p>
          </div>
        </div>
        <span class="user-role-badge role-admin" style="font-size: 0.72rem;">Brankas Dokumen</span>
      </div>

      <div class="settings-card-body">
        <div>
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: var(--text-main);">
            Daftar Kategori Dokumen (Pisahkan dengan tanda koma)
          </label>
          <div class="settings-input-group">
            <span class="settings-input-icon"><?= app_icon('tag', 16) ?></span>
            <input type="text" name="document_categories" class="form-control settings-control-iconic" value="<?= htmlspecialchars($curDocCategories) ?>" placeholder="Sertifikat &amp; Lisensi, Pass Bandara &amp; Identitas, Surat Tugas &amp; SK, Manual &amp; Dokumen Teknis, Kontrak &amp; Legalitas, Lainnya" required>
          </div>
          <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 5px;">
            Setiap kategori akan otomatis tersedia pada menu pilihan saat upload dokumen dan bilah filter kategori di Brankas Dokumen.
          </div>
        </div>

        <div style="margin-top: 1rem; padding: 0.75rem 1rem; background: var(--bg-alt); border-radius: var(--radius-sm); border: 1px solid var(--border); font-size: 0.76rem; color: var(--text-main);">
          <strong style="color: var(--primary);">Pratinjau Kategori Dokumen:</strong>
          <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px;">
            <?php 
            $previewDocCats = array_filter(array_map('trim', explode(',', $curDocCategories)));
            foreach ($previewDocCats as $pdc): 
            ?>
              <span class="btn-cat-badge active" style="font-size: 0.74rem; padding: 3px 10px; background: rgba(0, 128, 128, 0.1); color: var(--primary); border: 1px solid rgba(0, 128, 128, 0.25); border-radius: 6px;"><?= htmlspecialchars($pdc) ?></span>
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
    var file = input.files[0];
    var reader = new FileReader();
    reader.onload = function(e) {
      var targetImg = document.getElementById(targetId);
      if (!targetImg) return;

      var tempImg = new Image();
      tempImg.onload = function() {
        try {
          var canvas = document.createElement('canvas');
          var ctx = canvas.getContext('2d');
          canvas.width = tempImg.naturalWidth;
          canvas.height = tempImg.naturalHeight;
          ctx.drawImage(tempImg, 0, 0);

          var imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
          var data = imgData.data;
          var minX = canvas.width, minY = canvas.height, maxX = 0, maxY = 0;
          var found = false;

          for (var y = 0; y < canvas.height; y++) {
            for (var x = 0; x < canvas.width; x++) {
              var alpha = data[((y * canvas.width) + x) * 4 + 3];
              if (alpha > 20) {
                if (x < minX) minX = x;
                if (x > maxX) maxX = x;
                if (y < minY) minY = y;
                if (y > maxY) maxY = y;
                found = true;
              }
            }
          }

          if (found && (maxX - minX > 5) && (maxY - minY > 5) && ((maxX - minX + 1) * (maxY - minY + 1) < canvas.width * canvas.height * 0.92)) {
            var pad = 6;
            var cropX = Math.max(0, minX - pad);
            var cropY = Math.max(0, minY - pad);
            var cropW = Math.min(canvas.width - cropX, (maxX - minX + 1) + (pad * 2));
            var cropH = Math.min(canvas.height - cropY, (maxY - minY + 1) + (pad * 2));

            var trimCanvas = document.createElement('canvas');
            trimCanvas.width = cropW;
            trimCanvas.height = cropH;
            var trimCtx = trimCanvas.getContext('2d');
            trimCtx.drawImage(canvas, cropX, cropY, cropW, cropH, 0, 0, cropW, cropH);
            targetImg.src = trimCanvas.toDataURL('image/png');
          } else {
            targetImg.src = e.target.result;
          }
        } catch (err) {
          targetImg.src = e.target.result;
        }
      };
      tempImg.src = e.target.result;
    };
    reader.readAsDataURL(file);
  }
}

function testDiscordWebhook(channel) {
  var idMap = {
    noc: { url: 'discord_webhook_noc', btn: 'btn_test_discord_noc', status: 'status_discord_noc' },
    security: { url: 'discord_webhook_security', btn: 'btn_test_discord_sec', status: 'status_discord_sec' },
    backup: { url: 'discord_webhook_backup', btn: 'btn_test_discord_bkp', status: 'status_discord_bkp' }
  };

  var conf = idMap[channel] || idMap.noc;
  var urlInput = document.getElementById(conf.url);
  var btn = document.getElementById(conf.btn);
  var statusDiv = document.getElementById(conf.status);

  if (!urlInput || !btn || !statusDiv) return;

  var webhookUrl = urlInput.value.trim();
  if (!webhookUrl) {
    statusDiv.innerHTML = '<span style="color: var(--status-nok, #ef4444); font-weight: 600;">Masukkan URL webhook terlebih dahulu.</span>';
    urlInput.focus();
    return;
  }

  var originalBtnHtml = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = 'Mengirim...';
  statusDiv.innerHTML = '<span style="color: var(--text-subtle);">Menghubungi Discord...</span>';

  var formData = new FormData();
  formData.append('webhook_url', webhookUrl);
  formData.append('channel_type', channel);
  formData.append('save', '1');

  fetch('api/test_discord_webhook.php', {
    method: 'POST',
    body: formData
  })
  .then(function(res) {
    return res.json();
  })
  .then(function(data) {
    btn.disabled = false;
    btn.innerHTML = originalBtnHtml;
    if (data.success) {
      statusDiv.innerHTML = '<span style="color: var(--status-ok, #10b981); font-weight: 600;">✓ Berhasil &amp; Webhook Tersimpan! Cek channel Discord Anda.</span>';
    } else {
      statusDiv.innerHTML = '<span style="color: var(--status-nok, #ef4444); font-weight: 600;">✗ ' + (data.message || 'Gagal mengirim pesan.') + '</span>';
    }
  })
  .catch(function(err) {
    btn.disabled = false;
    btn.innerHTML = originalBtnHtml;
    statusDiv.innerHTML = '<span style="color: var(--status-nok, #ef4444); font-weight: 600;">✗ Gagal: ' + err.message + '</span>';
  });
}

function clearWebhook(channel) {
  if (!confirm('Apakah Anda yakin ingin menghapus konfigurasi webhook Discord ini?')) return;
  var idMap = {
    noc: { url: 'discord_webhook_noc', clear: 'clear_discord_webhook_noc', status: 'status_discord_noc' },
    security: { url: 'discord_webhook_security', clear: 'clear_discord_webhook_security', status: 'status_discord_sec' },
    backup: { url: 'discord_webhook_backup', clear: 'clear_discord_webhook_backup', status: 'status_discord_bkp' }
  };
  var conf = idMap[channel] || idMap.noc;
  var input = document.getElementById(conf.url);
  var clearFlag = document.getElementById(conf.clear);
  if (input) input.value = '';
  if (clearFlag) clearFlag.value = '1';
  var statusDiv = document.getElementById(conf.status);
  if (statusDiv) statusDiv.innerHTML = '<span style="color: var(--text-subtle);">Webhook akan dihapus saat form disimpan.</span>';
}
</script>

<?php
include __DIR__ . '/includes/footer.php';
?>
