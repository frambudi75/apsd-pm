<?php
/**
 * Shared Application Header
 * PT Angkasa Pura Sarana Digital
 */
if (!isset($page_title)) {
    $page_title = 'Sistem Preventive Maintenance';
}
$curPage = basename($_SERVER['PHP_SELF'] ?? 'index.php');
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= htmlspecialchars($page_title) ?> - APSD PM System</title>
  
  <!-- PWA Meta Tags -->
  <link rel="manifest" href="manifest.json">
  <meta name="theme-color" content="#008080">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="APSD PM">
  <link rel="apple-touch-icon" href="assets/img/icon-192.png">
  
  <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/../assets/css/app.css') ?>">
  <link rel="icon" type="image/png" href="assets/img/logo_ias.png">
</head>
<body>

<!-- Offline Notification Banner (Shown automatically via JS if connection drops) -->
<div id="offline_notice" class="offline-banner" style="display: none;">
  <span>Mode Offline Aktif. Form dan data tetap dapat diakses via cache lokal.</span>
</div>

<!-- PWA Install Prompt Banner (Visible on mobile/desktop browsers when app is installable) -->
<div id="pwa_install_banner" class="pwa-install-banner" style="display: none;">
  <div class="pwa-install-info">
    <img src="assets/img/icon-192.png" alt="APSD PM Icon" width="36" height="36" style="border-radius: 8px;">
    <div>
      <div style="font-weight: 700; font-size: 0.85rem; color: #ffffff;">Instal Aplikasi APSD PM</div>
      <div style="font-size: 0.72rem; opacity: 0.9; color: #e0f2fe;">Akses cepat di lapangan tanpa browser bar</div>
    </div>
  </div>
  <div style="display: flex; gap: 6px;">
    <button id="btn_install_pwa" class="btn btn-sm" style="background: #ffffff; color: #008080; font-weight: 700; border: none; padding: 0.35rem 0.75rem;">Pasang</button>
    <button id="btn_dismiss_pwa" class="btn btn-sm" style="background: transparent; color: #ffffff; border: 1px solid rgba(255,255,255,0.4); padding: 0.35rem 0.5rem;" title="Tutup">&times;</button>
  </div>
</div>

<header class="app-navbar no-print">
  <div class="nav-container">
    <a href="index.php" class="brand-wrapper">
      <img src="assets/img/logo_ias.png?v=2" alt="IAS Support Logo" class="brand-logo">
      <div class="brand-text">
        <h1>PT Angkasa Pura Sarana Digital</h1>
        <span>Sistem Checklist & Laporan PM</span>
      </div>
    </a>
    
    <nav class="nav-actions web-nav">
      <a href="index.php" class="btn <?= ($curPage === 'index.php') ? 'btn-primary' : 'btn-secondary' ?> btn-sm">Dashboard</a>
      <a href="reports.php" class="btn <?= ($curPage === 'reports.php') ? 'btn-primary' : 'btn-secondary' ?> btn-sm" style="display: inline-flex; align-items: center; gap: 4px;">
        <span>📋</span> Riwayat Laporan
      </a>
      <a href="form_indoor.php" class="btn <?= ($curPage === 'form_indoor.php') ? 'btn-primary' : 'btn-secondary' ?> btn-sm">+ Buat PM Indoor</a>
      <a href="form_outdoor.php" class="btn <?= ($curPage === 'form_outdoor.php') ? 'btn-primary' : 'btn-secondary' ?> btn-sm">+ Buat PM Outdoor</a>
      <a href="sites.php" class="btn <?= ($curPage === 'sites.php') ? 'btn-primary' : 'btn-secondary' ?> btn-sm">Master Site</a>
    </nav>
  </div>
</header>
