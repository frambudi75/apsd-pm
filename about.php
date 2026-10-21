<?php
/**
 * Pusat Informasi & Tentang Sistem (About Platform)
 * IASS SBU ICT KNO
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/version.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$page_title = 'Tentang Aplikasi';
$currentUser = current_user();
$pdo = get_db_connection();

// Ambil identitas sistem dari pengaturan
$sysAppTitle = get_system_setting('app_title', get_system_setting('company_name', 'IASS SBU ICT KNO'));
$sysAppSubtitle = get_system_setting('app_subtitle', 'Sistem PM & NOC');
$sysAppLogo = get_system_setting('app_logo_path', 'assets/img/logo_ias.png?v=3');
$sysCompany = get_system_setting('company_name', 'IASS SBU ICT KNO');
$sysUnit = get_system_setting('unit_name', 'IASS SBU ICT KNO');
$sysLeader = get_system_setting('default_team_leader', 'Wahyu Wandira');
$sysLeaderRole = get_system_setting('default_leader_role', 'Supervisor / Team Leader NOC KNO');

// Hitung metrik statistik operasional
$totalSites = 0;
$totalPmReports = 0;
$totalShifts = 0;
$totalActivities = 0;
$totalDocuments = 0;

try {
    $totalSites = (int)$pdo->query("SELECT COUNT(*) FROM sites")->fetchColumn();
    $totalPmReports = (int)$pdo->query("SELECT COUNT(*) FROM pm_reports")->fetchColumn();
    $totalShifts = (int)$pdo->query("SELECT COUNT(*) FROM daily_shifts")->fetchColumn();
    $totalActivities = (int)$pdo->query("SELECT COUNT(*) FROM daily_activities")->fetchColumn();
    $totalDocuments = (int)$pdo->query("SELECT COUNT(*) FROM user_documents")->fetchColumn();
} catch (Exception $e) {}

$driverName = strtoupper($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

include __DIR__ . '/includes/header.php';
?>

<div class="container py-4" style="max-width: 1040px;">

  <!-- Breadcrumb -->
  <div style="font-size: 0.8rem; color: var(--text-subtle); display: flex; align-items: center; gap: 6px; margin-bottom: 1.25rem;">
    <a href="./" style="color: inherit; text-decoration: none;">Dashboard</a>
    <span>/</span>
    <span style="color: var(--primary); font-weight: 600;">Tentang Aplikasi</span>
  </div>

  <!-- Hero Banner Card -->
  <div class="settings-card" style="background: linear-gradient(135deg, rgba(0, 128, 128, 0.08) 0%, rgba(2, 132, 199, 0.05) 100%), var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg, 14px); padding: 2rem 1.75rem; margin-bottom: 2rem; position: relative; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.04);">
    <div style="position: absolute; right: -25px; bottom: -25px; opacity: 0.06; pointer-events: none;">
      <?= app_icon('shield', 220, 'text-primary') ?>
    </div>

    <div style="display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap;">
      <div style="width: 80px; height: 80px; border-radius: 16px; background: var(--bg-surface); border: 1px solid var(--border); padding: 10px; display: flex; align-items: center; justify-content: center; box-shadow: 0 6px 16px rgba(0,0,0,0.06); flex-shrink: 0;">
        <img src="<?= htmlspecialchars($sysAppLogo) ?>" alt="Logo Aplikasi" style="max-width: 100%; max-height: 100%; object-fit: contain;">
      </div>

      <div style="flex: 1; min-width: 260px;">
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
          <h1 style="font-size: 1.75rem; font-weight: 800; color: var(--text-main); margin: 0; letter-spacing: -0.02em;">
            <?= htmlspecialchars($sysAppTitle) ?>
          </h1>
          <span class="badge" style="background: var(--primary); color: #fff; font-weight: 800; font-size: 0.8rem; padding: 4px 10px; border-radius: 20px;">
            <?= htmlspecialchars(APP_VERSION) ?>
          </span>
          <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #059669; border: 1px solid rgba(16, 185, 129, 0.3); font-weight: 700; font-size: 0.74rem; padding: 3px 8px; border-radius: 20px; display: inline-flex; align-items: center; gap: 4px;">
            <span style="width: 6px; height: 6px; border-radius: 50%; background: #10b981; display: inline-block;"></span> Production Ready
          </span>
        </div>
        <p style="font-size: 0.95rem; font-weight: 600; color: var(--primary); margin: 0.35rem 0 0.5rem 0;">
          <?= htmlspecialchars($sysAppSubtitle) ?>
        </p>
        <p style="font-size: 0.82rem; color: var(--text-subtle); margin: 0; line-height: 1.5; max-width: 720px;">
          Platform digital terintegrasi untuk standarisasi inspeksi Preventive Maintenance (PM) seluler, pencatatan logbook operasional harian NOC, brankas berkas teknis tim, dan audit keamanan operasional Bandara Internasional Kualanamu.
        </p>
      </div>
    </div>
  </div>

  <!-- Operational KPI Stats Counter -->
  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
    
    <div class="settings-card" style="padding: 1.15rem; background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); display: flex; align-items: center; gap: 12px;">
      <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(0, 128, 128, 0.12); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
        <?= app_icon('map-pin', 22) ?>
      </div>
      <div>
        <div style="font-size: 1.35rem; font-weight: 800; color: var(--text-main); line-height: 1.1;"><?= number_format($totalSites) ?></div>
        <div style="font-size: 0.74rem; color: var(--text-subtle); font-weight: 600; margin-top: 2px;">Master Site &amp; Tower</div>
      </div>
    </div>

    <div class="settings-card" style="padding: 1.15rem; background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); display: flex; align-items: center; gap: 12px;">
      <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(2, 132, 199, 0.12); color: #0284c7; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
        <?= app_icon('clipboard-list', 22) ?>
      </div>
      <div>
        <div style="font-size: 1.35rem; font-weight: 800; color: var(--text-main); line-height: 1.1;"><?= number_format($totalPmReports) ?></div>
        <div style="font-size: 0.74rem; color: var(--text-subtle); font-weight: 600; margin-top: 2px;">Laporan PM Tersimpan</div>
      </div>
    </div>

    <div class="settings-card" style="padding: 1.15rem; background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); display: flex; align-items: center; gap: 12px;">
      <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(245, 158, 11, 0.12); color: #d97706; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
        <?= app_icon('clock', 22) ?>
      </div>
      <div>
        <div style="font-size: 1.35rem; font-weight: 800; color: var(--text-main); line-height: 1.1;"><?= number_format($totalActivities) ?></div>
        <div style="font-size: 0.74rem; color: var(--text-subtle); font-weight: 600; margin-top: 2px;">Kegiatan Logbook NOC</div>
      </div>
    </div>

    <div class="settings-card" style="padding: 1.15rem; background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); display: flex; align-items: center; gap: 12px;">
      <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(16, 185, 129, 0.12); color: #059669; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
        <?= app_icon('folder', 22) ?>
      </div>
      <div>
        <div style="font-size: 1.35rem; font-weight: 800; color: var(--text-main); line-height: 1.1;"><?= number_format($totalDocuments) ?></div>
        <div style="font-size: 0.74rem; color: var(--text-subtle); font-weight: 600; margin-top: 2px;">Dokumen di Brankas</div>
      </div>
    </div>

  </div>

  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
    
    <!-- Informasi Identitas & Lingkungan Kerja -->
    <div class="settings-card">
      <div class="settings-card-header">
        <div style="display: flex; align-items: center; gap: 10px;">
          <?= app_icon('building', 18, 'text-primary') ?>
          <h4 style="font-size: 0.95rem; font-weight: 700; color: var(--text-main); margin: 0;">Identitas Unit &amp; Operasional</h4>
        </div>
        <span class="badge" style="background: var(--bg-alt); color: var(--text-subtle); font-size: 0.72rem;">KNO</span>
      </div>
      <div class="settings-card-body" style="padding: 1.25rem;">
        <table style="width: 100%; border-collapse: collapse; font-size: 0.8rem;">
          <tr style="border-bottom: 1px solid var(--border);">
            <td style="padding: 8px 0; color: var(--text-subtle); width: 40%;">Nama Perusahaan</td>
            <td style="padding: 8px 0; font-weight: 700; color: var(--text-main);"><?= htmlspecialchars($sysCompany) ?></td>
          </tr>
          <tr style="border-bottom: 1px solid var(--border);">
            <td style="padding: 8px 0; color: var(--text-subtle);">Unit Kerja</td>
            <td style="padding: 8px 0; font-weight: 700; color: var(--text-main);"><?= htmlspecialchars($sysUnit) ?></td>
          </tr>
          <tr style="border-bottom: 1px solid var(--border);">
            <td style="padding: 8px 0; color: var(--text-subtle);">Lokasi Layanan</td>
            <td style="padding: 8px 0; font-weight: 600; color: var(--text-main);">Bandara Internasional Kualanamu (KNO)</td>
          </tr>
          <tr style="border-bottom: 1px solid var(--border);">
            <td style="padding: 8px 0; color: var(--text-subtle);">Supervisor / Leader</td>
            <td style="padding: 8px 0; font-weight: 600; color: var(--text-main);"><?= htmlspecialchars($sysLeader) ?></td>
          </tr>
          <tr>
            <td style="padding: 8px 0; color: var(--text-subtle);">Jabatan Leader</td>
            <td style="padding: 8px 0; font-weight: 600; color: var(--text-main);"><?= htmlspecialchars($sysLeaderRole) ?></td>
          </tr>
        </table>
      </div>
    </div>

    <!-- Spesifikasi Teknis & Runtime Engine -->
    <div class="settings-card">
      <div class="settings-card-header">
        <div style="display: flex; align-items: center; gap: 10px;">
          <?= app_icon('server', 18, 'text-primary') ?>
          <h4 style="font-size: 0.95rem; font-weight: 700; color: var(--text-main); margin: 0;">Spesifikasi &amp; Runtime Server</h4>
        </div>
        <span class="badge" style="background: rgba(14, 165, 233, 0.12); color: #0284c7; font-size: 0.72rem;">Runtime</span>
      </div>
      <div class="settings-card-body" style="padding: 1.25rem;">
        <table style="width: 100%; border-collapse: collapse; font-size: 0.8rem;">
          <tr style="border-bottom: 1px solid var(--border);">
            <td style="padding: 8px 0; color: var(--text-subtle); width: 40%;">Versi Rilis</td>
            <td style="padding: 8px 0; font-weight: 700; color: var(--primary);"><?= htmlspecialchars(APP_VERSION) ?> (<?= htmlspecialchars(APP_BUILD_DATE) ?>)</td>
          </tr>
          <tr style="border-bottom: 1px solid var(--border);">
            <td style="padding: 8px 0; color: var(--text-subtle);">Versi PHP</td>
            <td style="padding: 8px 0; font-weight: 600; color: var(--text-main);">PHP <?= PHP_VERSION ?></td>
          </tr>
          <tr style="border-bottom: 1px solid var(--border);">
            <td style="padding: 8px 0; color: var(--text-subtle);">Basis Data (PDO)</td>
            <td style="padding: 8px 0; font-weight: 600; color: var(--text-main);"><?= htmlspecialchars($driverName) ?> Engine</td>
          </tr>
          <tr style="border-bottom: 1px solid var(--border);">
            <td style="padding: 8px 0; color: var(--text-subtle);">Web Server</td>
            <td style="padding: 8px 0; font-weight: 600; color: var(--text-main);"><?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Apache / Nginx') ?></td>
          </tr>
          <tr style="border-bottom: 1px solid var(--border);">
            <td style="padding: 8px 0; color: var(--text-subtle);">Ekstensi Gambar</td>
            <td style="padding: 8px 0; font-weight: 600; color: var(--text-main);">
              <?= extension_loaded('gd') ? '<span style="color:#059669;">GD Active (WebP &amp; PNG)</span>' : '<span style="color:#ef4444;">Nonaktif</span>' ?>
            </td>
          </tr>
          <tr>
            <td style="padding: 8px 0; color: var(--text-subtle);">Integrasi cURL / SSL</td>
            <td style="padding: 8px 0; font-weight: 600; color: var(--text-main);">
              <?= extension_loaded('curl') ? '<span style="color:#059669;">cURL Ready (Discord Webhook)</span>' : '<span style="color:#ef4444;">Nonaktif</span>' ?>
            </td>
          </tr>
        </table>
      </div>
    </div>

  </div>

  <!-- Kotak Khusus Informasi Developer / Pembuat Aplikasi -->
  <div class="settings-card" style="margin-bottom: 2rem; border-left: 4px solid var(--primary); background: linear-gradient(135deg, rgba(0, 128, 128, 0.04) 0%, rgba(2, 132, 199, 0.03) 100%), var(--bg-surface);">
    <div class="settings-card-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
      <div style="display: flex; align-items: center; gap: 10px;">
        <span style="color: var(--primary);"><?= app_icon('user', 18) ?></span>
        <h4 style="font-size: 0.95rem; font-weight: 700; color: var(--text-main); margin: 0;">Pengembang Aplikasi</h4>
      </div>
      <span class="badge" style="background: rgba(0, 128, 128, 0.12); color: var(--primary); font-size: 0.72rem; font-weight: 700;">Developer</span>
    </div>
    <div class="settings-card-body" style="padding: 1.5rem;">
      <div style="display: flex; align-items: flex-start; gap: 1.25rem; flex-wrap: wrap;">
        <!-- Avatar Badge -->
        <div style="width: 54px; height: 54px; border-radius: 14px; background: linear-gradient(135deg, #008080 0%, #0284c7 100%); color: #ffffff; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; font-weight: 800; box-shadow: 0 4px 14px rgba(0, 128, 128, 0.25); flex-shrink: 0;">
          HF
        </div>

        <div style="flex: 1; min-width: 240px;">
          <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 4px;">
            <h3 style="margin: 0; font-size: 1.1rem; font-weight: 800; color: var(--text-main);">Habib Frambudi</h3>
            <span class="badge" style="background: rgba(16, 185, 129, 0.12); color: #059669; font-size: 0.7rem; font-weight: 700;">Pembuat Aplikasi</span>
          </div>

          <p style="font-size: 0.82rem; color: var(--text-subtle); margin: 0 0 1rem 0; line-height: 1.5; max-width: 600px;">
            Pembuat dan pengembang aplikasi Sistem Preventive Maintenance &amp; Logbook NOC.
          </p>

          <div>
            <a href="https://github.com/frambudi75" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-sm" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 700; padding: 0.45rem 1rem; text-decoration: none;" title="Buka Profil GitHub Habib Frambudi">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/></svg>
              <span>GitHub @frambudi75</span>
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"/></svg>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Modul & Fitur Unggulan Sistem -->
  <div class="settings-card" style="margin-bottom: 2rem;">
    <div class="settings-card-header">
      <div style="display: flex; align-items: center; gap: 10px;">
        <?= app_icon('grid', 18, 'text-primary') ?>
        <h4 style="font-size: 1rem; font-weight: 800; color: var(--text-main); margin: 0;">Modul Utama &amp; Kapabilitas Platform</h4>
      </div>
      <span class="user-role-badge role-admin" style="font-size: 0.72rem;">Fitur Sistem</span>
    </div>
    
    <div class="settings-card-body" style="padding: 1.5rem;">
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem;">
        
        <div style="padding: 1rem; border-radius: var(--radius-sm); background: var(--bg-alt); border: 1px solid var(--border);">
          <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
            <span style="color: var(--primary);"><?= app_icon('clipboard-list', 18) ?></span>
            <strong style="font-size: 0.88rem; color: var(--text-main);">PM Indoor IBC &amp; Outdoor Pole</strong>
          </div>
          <p style="font-size: 0.78rem; color: var(--text-subtle); margin: 0; line-height: 1.45;">
            Formulir audit standar Telkomsel dan operator nasional (7 &amp; 13 halaman) dilengkapi kalkulasi persentase otomatis, auto-save draft lokal, dan stempel timestamp &amp; GPS watermark kamera.
          </p>
        </div>

        <div style="padding: 1rem; border-radius: var(--radius-sm); background: var(--bg-alt); border: 1px solid var(--border);">
          <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
            <span style="color: #0284c7;"><?= app_icon('clock', 18) ?></span>
            <strong style="font-size: 0.88rem; color: var(--text-main);">NOC Daily Activity &amp; Handover Shift</strong>
          </div>
          <p style="font-size: 0.78rem; color: var(--text-subtle); margin: 0; line-height: 1.45;">
            Pencatatan sesi shift harian (Pagi, Malam, Office Hour), multi-foto lampiran kegiatan teknisi, verifikasi approval supervisor, dan kompilasi ekspor dokumen PDF bulanan otomatis.
          </p>
        </div>

        <div style="padding: 1rem; border-radius: var(--radius-sm); background: var(--bg-alt); border: 1px solid var(--border);">
          <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
            <span style="color: #059669;"><?= app_icon('folder', 18) ?></span>
            <strong style="font-size: 0.88rem; color: var(--text-main);">Brankas Dokumen &amp; Berbagi Terproteksi</strong>
          </div>
          <p style="font-size: 0.78rem; color: var(--text-subtle); margin: 0; line-height: 1.45;">
            Penyimpanan sertifikat, lisensi K3, pass bandara, dan berkas teknis dengan kompresi WebP hemat kuota, hak akses RBAC, serta tautan publik berbatas waktu dengan proteksi PIN 6 digit.
          </p>
        </div>

        <div style="padding: 1rem; border-radius: var(--radius-sm); background: var(--bg-alt); border: 1px solid var(--border);">
          <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
            <span style="color: #5865F2;"><?= app_icon('globe', 18) ?></span>
            <strong style="font-size: 0.88rem; color: var(--text-main);">Integrasi Dual-Channel Discord</strong>
          </div>
          <p style="font-size: 0.78rem; color: var(--text-subtle); margin: 0; line-height: 1.45;">
            Pengiriman ringkasan shift NOC otomatis dengan lampiran berkas PDF resmi ke Channel Operasional, serta notifikasi instan kegagalan login dan laporan auto-optimise ke Channel Keamanan.
          </p>
        </div>

        <div style="padding: 1rem; border-radius: var(--radius-sm); background: var(--bg-alt); border: 1px solid var(--border);">
          <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
            <span style="color: #dc2626;"><?= app_icon('shield', 18) ?></span>
            <strong style="font-size: 0.88rem; color: var(--text-main);">Audit Trail &amp; Keamanan Sistem</strong>
          </div>
          <p style="font-size: 0.78rem; color: var(--text-subtle); margin: 0; line-height: 1.45;">
            Pencatatan rekam jejak setiap aksi krusial pengguna (otentikasi, pembuatan laporan, perubahan pengaturan) dengan rekaman IP address dan user-agent khusus konsumsi Superadmin.
          </p>
        </div>

        <div style="padding: 1rem; border-radius: var(--radius-sm); background: var(--bg-alt); border: 1px solid var(--border);">
          <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
            <span style="color: #8b5cf6;"><?= app_icon('smartphone', 18) ?></span>
            <strong style="font-size: 0.88rem; color: var(--text-main);">Progressive Web App (PWA) Offline</strong>
          </div>
          <p style="font-size: 0.78rem; color: var(--text-subtle); margin: 0; line-height: 1.45;">
            Dukungan instalasi aplikasi langsung pada smartphone Android, iPhone iOS Safari, serta desktop tanpa melalui App Store dengan cache service worker tangguh di area sinyal minim.
          </p>
        </div>

      </div>
    </div>
  </div>

  <!-- Timeline Riwayat Pembaruan (Release Changelog) -->
  <div class="settings-card">
    <div class="settings-card-header">
      <div style="display: flex; align-items: center; gap: 10px;">
        <?= app_icon('clock', 18, 'text-primary') ?>
        <h4 style="font-size: 1rem; font-weight: 800; color: var(--text-main); margin: 0;">Riwayat Rilis &amp; Catatan Pembaruan</h4>
      </div>
      <span class="badge" style="background: var(--primary-subtle); color: var(--primary); font-size: 0.72rem; font-weight: 700;">Changelog</span>
    </div>

    <div class="settings-card-body" style="padding: 1.5rem;">
      <div style="position: relative; padding-left: 20px; border-left: 2px solid var(--border);">
        
        <!-- v3.0.0 -->
        <div style="margin-bottom: 1.75rem; position: relative;">
          <div style="position: absolute; left: -26px; top: 2px; width: 12px; height: 12px; border-radius: 50%; background: var(--primary); border: 2px solid var(--bg-surface);"></div>
          <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <strong style="font-size: 0.92rem; color: var(--text-main);">Versi 3.0.0</strong>
            <span class="badge" style="background: rgba(0, 128, 128, 0.12); color: var(--primary); font-size: 0.7rem; font-weight: 700;">Rilis Terbaru</span>
            <span style="font-size: 0.75rem; color: var(--text-subtle);">21 Oktober 2026</span>
          </div>
          <ul style="font-size: 0.78rem; color: var(--text-subtle); margin: 6px 0 0 0; padding-left: 18px; line-height: 1.5;">
            <li><strong>Sistem Statistik &amp; Rekapitulasi Laporan Terpadu</strong>: Dasbor analitik eksekutif terpadu menggabungkan metrik PM &amp; Daily Activity NOC, rasio indoor vs outdoor, ritme jam kerja, leaderboard teknisi, dan lembar cetak dokumen resmi A4.</li>
            <li><strong>Tanda Tangan Digital Resmi (E-Signature)</strong>: Fitur tanda tangan digital ganda (Gores di Layar touchscreen/mouse &amp; Upload File), pengaturan TTD SPV di Pengaturan Sistem, TTD mandiri di Profil Pengguna, serta verifikasi pengesahan saat menyelesaikan sesi shift NOC.</li>
            <li><strong>Pengesahan Dinamis Admin &amp; Supervisor</strong>: Kolom penandatanganan dokumen resmi otomatis mencantumkan nama dan jabatan Admin pelapor di sisi kiri serta Supervisor/Team Leader di sisi kanan.</li>
            <li><strong>Optimasi Responsif Seluler &amp; PWA</strong>: Mengatasi kendala daftar rekap laporan terpotong pada layar ponsel dengan kartu sentuh ergonomis dan navigasi sentuh ramah jempol.</li>
          </ul>
        </div>

        <!-- v2.9.0 -->
        <div style="margin-bottom: 1.75rem; position: relative;">
          <div style="position: absolute; left: -26px; top: 2px; width: 12px; height: 12px; border-radius: 50%; background: #0284c7; border: 2px solid var(--bg-surface);"></div>
          <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <strong style="font-size: 0.92rem; color: var(--text-main);">Versi 2.9.0</strong>
            <span style="font-size: 0.75rem; color: var(--text-subtle);">19 Oktober 2026</span>
          </div>
          <ul style="font-size: 0.78rem; color: var(--text-subtle); margin: 6px 0 0 0; padding-left: 18px; line-height: 1.5;">
            <li><strong>Pusat Cadangan &amp; Pemulihan Sistem (Disaster Recovery Vault)</strong>: Modul terpadu pencadangan basis data (dump SQL mandiri), pengemasan berkas unggahan, serta riwayat arsip lokal berkeamanan tinggi.</li>
            <li><strong>Mesin Kompresi Mandiri (*Pure PHP SimpleZipArchive*)</strong>: Dukungan pembuatan berkas ZIP terkompresi PKZIP 2.0 tanpa ketergantungan ekstensi server eksternal, kompatibel penuh di seluruh sistem operasi.</li>
            <li><strong>Pengarsipan Multi-Batch Discord</strong>: Pemecahan berkas otomatis menjadi beberapa batch (&le; 20 MB per batch) dengan verifikasi hash SHA-256 dan instruksi rekonstruksi penggabungan berkas.</li>
            <li><strong>Integrasi 3 Saluran Discord</strong>: Pemisahan saluran independen untuk Saluran 1 (Operasional NOC), Saluran 2 (Keamanan Admin), dan Saluran 3 (Cadangan &amp; Arsip).</li>
            <li><strong>Migrasi Basis Data MySQL &amp; Auto-Optimise</strong>: Skrip migrasi terautomasi SQLite ke MySQL/MariaDB serta pemeliharaan berkala terjadwal.</li>
          </ul>
        </div>

        <!-- v2.8.0 & v2.8.1 -->
        <div style="margin-bottom: 1.75rem; position: relative;">
          <div style="position: absolute; left: -26px; top: 2px; width: 12px; height: 12px; border-radius: 50%; background: #0284c7; border: 2px solid var(--bg-surface);"></div>
          <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <strong style="font-size: 0.92rem; color: var(--text-main);">Versi 2.8.1</strong>
            <span style="font-size: 0.75rem; color: var(--text-subtle);">18 Oktober 2026</span>
          </div>
          <ul style="font-size: 0.78rem; color: var(--text-subtle); margin: 6px 0 0 0; padding-left: 18px; line-height: 1.5;">
            <li>Peningkatan keandalan alur keluar sistem (logout), invalidasi sesi tuntas, dan tombol cepat di bilah atas.</li>
            <li>Refaktor dialog serah terima shift dan hapus logbook ke modal antarmuka kustom yang responsif.</li>
            <li>Penegakan SOP shift tak tertutup dengan deteksi shift aktif sebelumnya dan lockout shift kosong.</li>
          </ul>
        </div>

        <!-- v2.7.0 -->
        <div style="margin-bottom: 1.75rem; position: relative;">
          <div style="position: absolute; left: -26px; top: 2px; width: 12px; height: 12px; border-radius: 50%; background: #059669; border: 2px solid var(--bg-surface);"></div>
          <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <strong style="font-size: 0.92rem; color: var(--text-main);">Versi 2.7.0</strong>
            <span style="font-size: 0.75rem; color: var(--text-subtle);">17 Oktober 2026</span>
          </div>
          <ul style="font-size: 0.78rem; color: var(--text-subtle); margin: 6px 0 0 0; padding-left: 18px; line-height: 1.5;">
            <li>Pusat informasi komprehensif sistem (Halaman About Platform) dan ringkasan metrik operasional.</li>
            <li>Arsitektur Dual-Channel Discord terintegrasi dengan pengiriman otomatis berkas PDF shift resmi.</li>
            <li>Pemisahan independen logo aplikasi (tab bar / favicon / PWA) dan logo kop surat dokumen cetak.</li>
            <li>Infrastruktur penyimpanan webhook Discord aman tanpa risiko tertimpa form kosong dan validasi cache server instan.</li>
          </ul>
        </div>

        <!-- v2.6.0 -->
        <div style="margin-bottom: 1.75rem; position: relative;">
          <div style="position: absolute; left: -26px; top: 2px; width: 12px; height: 12px; border-radius: 50%; background: #0284c7; border: 2px solid var(--bg-surface);"></div>
          <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <strong style="font-size: 0.92rem; color: var(--text-main);">Versi 2.6.0</strong>
            <span style="font-size: 0.75rem; color: var(--text-subtle);">15 Oktober 2026</span>
          </div>
          <ul style="font-size: 0.78rem; color: var(--text-subtle); margin: 6px 0 0 0; padding-left: 18px; line-height: 1.5;">
            <li>Modul Audit Log keamanan &amp; rekam jejak aktivitas pengguna khusus Superadmin.</li>
            <li>Modul Auto-Optimise defragmentasi basis data, pemangkasan WAL log, dan pembersihan file temporer.</li>
            <li>Pemberitahuan panduan instalasi PWA interaktif khusus iOS, Android, dan Desktop.</li>
          </ul>
        </div>

        <!-- v2.5.0 -->
        <div style="margin-bottom: 1.75rem; position: relative;">
          <div style="position: absolute; left: -26px; top: 2px; width: 12px; height: 12px; border-radius: 50%; background: #059669; border: 2px solid var(--bg-surface);"></div>
          <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <strong style="font-size: 0.92rem; color: var(--text-main);">Versi 2.5.0</strong>
            <span style="font-size: 0.75rem; color: var(--text-subtle);">12 Oktober 2026</span>
          </div>
          <ul style="font-size: 0.78rem; color: var(--text-subtle); margin: 6px 0 0 0; padding-left: 18px; line-height: 1.5;">
            <li>Brankas Dokumen terpusat (Document Vault) dengan kompresi WebP otomatis.</li>
            <li>Berbagi tautan publik dengan tanggal kedaluwarsa dan proteksi PIN 6 digit.</li>
          </ul>
        </div>

        <!-- v2.0.0 -->
        <div style="position: relative;">
          <div style="position: absolute; left: -26px; top: 2px; width: 12px; height: 12px; border-radius: 50%; background: #64748b; border: 2px solid var(--bg-surface);"></div>
          <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <strong style="font-size: 0.92rem; color: var(--text-main);">Versi 2.0.0</strong>
            <span style="font-size: 0.75rem; color: var(--text-subtle);">September 2026</span>
          </div>
          <ul style="font-size: 0.78rem; color: var(--text-subtle); margin: 6px 0 0 0; padding-left: 18px; line-height: 1.5;">
            <li>Implementasi modul Daily Activity Logbook NOC dan shift handover teknisi.</li>
            <li>Kompilasi rekapitulasi laporan logbook bulanan otomatis dengan tanda tangan supervisor.</li>
          </ul>
        </div>

      </div>
    </div>
  </div>

</div>

<?php
include __DIR__ . '/includes/footer.php';
?>
