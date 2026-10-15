<?php
/**
 * Shared Application Header
 * IASS SBU ICT KNO
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
  <title><?= htmlspecialchars($page_title) ?> - IASS SBU ICT KNO</title>
  
  <!-- PWA Meta Tags -->
  <link rel="manifest" href="manifest.json?v=2">
  <meta name="theme-color" content="#008080">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="IASS SBU ICT KNO">
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
    <img src="assets/img/icon-192.png" alt="IASS PM Icon" width="36" height="36" style="border-radius: 8px;">
    <div>
      <div style="font-weight: 700; font-size: 0.85rem; color: #ffffff;">Instal Aplikasi IASS SBU ICT KNO</div>
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
        <h1>IASS SBU ICT KNO</h1>
        <span>Sistem Checklist & Laporan PM</span>
      </div>
    </a>
    
    <nav class="nav-actions web-nav">
      <a href="index.php" class="nav-link-item <?= ($curPage === 'index.php') ? 'active' : '' ?>">
        <span>📊</span> Dashboard
      </a>
      <a href="reports.php" class="nav-link-item <?= ($curPage === 'reports.php') ? 'active' : '' ?>">
        <span>📋</span> Riwayat Laporan
      </a>
      <a href="sites.php" class="nav-link-item <?= ($curPage === 'sites.php') ? 'active' : '' ?>">
        <span>📍</span> Master Site
      </a>
      
      <div class="nav-cta-buttons">
        <a href="form_indoor.php" class="btn btn-sm btn-primary-soft" title="Checklist PM Indoor IBC Telkomsel (7 Halaman)">+ PM Indoor</a>
        <a href="form_outdoor.php" class="btn btn-sm btn-primary" title="Checklist PM Outdoor Pole / Tower (13 Halaman)">+ PM Outdoor</a>
      </div>
    </nav>
  </div>
</header>
