<?php
/**
 * Shared Application Header
 * IASS SBU ICT KNO
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/version.php';
require_login();

$currentUser = current_user();

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
  <link rel="manifest" href="manifest.json?v=3">
  <meta name="theme-color" content="#008080">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="IASS SBU ICT KNO">
  <link rel="apple-touch-icon" href="assets/img/logo_ias.png?v=3">
  
  <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/../assets/css/app.css') ?>">
  <link rel="icon" type="image/png" href="assets/img/logo_ias.png?v=3">

  <script>
    (function() {
      try {
        var t = localStorage.getItem('iass_theme');
        if (t === 'dark' || (!t && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
          document.documentElement.setAttribute('data-theme', 'dark');
        } else {
          document.documentElement.setAttribute('data-theme', 'light');
        }
      } catch(e) {}
    })();

    // Smart Anti-Inspect & DevTools Protection
    (function() {
      // 1. Disable Right Click except on form inputs/textareas
      document.addEventListener('contextmenu', function(e) {
        if (e.target && e.target.closest('input, textarea, [contenteditable="true"]')) {
          return true;
        }
        e.preventDefault();
        return false;
      }, false);

      // 2. Disable DevTools and Source Shortcuts
      document.addEventListener('keydown', function(e) {
        // F12
        if (e.key === 'F12' || e.keyCode === 123) {
          e.preventDefault();
          return false;
        }
        // Ctrl+Shift+I (Inspect), Ctrl+Shift+J (Console), Ctrl+Shift+C (Element selector)
        if ((e.ctrlKey || e.metaKey) && e.shiftKey && ['I', 'i', 'J', 'j', 'C', 'c'].indexOf(e.key) !== -1) {
          e.preventDefault();
          return false;
        }
        // Ctrl+U (View Source)
        if ((e.ctrlKey || e.metaKey) && (e.key === 'U' || e.key === 'u')) {
          e.preventDefault();
          return false;
        }
        // Ctrl+S (Save webpage outside form)
        if ((e.ctrlKey || e.metaKey) && (e.key === 'S' || e.key === 's') && !e.target.closest('input, textarea')) {
          e.preventDefault();
          return false;
        }
      }, false);

      // Security Console Notice
      try {
        console.log('%c[KEAMANAN SISTEM]', 'color: #e11424; font-size: 16px; font-weight: bold;');
        console.log('%cAplikasi Sistem PM IASS SBU ICT KNO dilindungi oleh sistem keamanan client-server.', 'font-size: 12px; color: #64748b;');
      } catch(e) {}
    })();
  </script>
</head>
<body>

<!-- Offline Notification Banner (Shown automatically via JS if connection drops) -->
<div id="offline_notice" class="offline-banner" style="display: none;">
  <span>Mode Offline Aktif. Form dan data tetap dapat diakses via cache lokal.</span>
</div>

<!-- PWA Install Prompt Banner (Visible on mobile/desktop browsers when app is installable) -->
<div id="pwa_install_banner" class="pwa-install-banner" style="display: none;">
  <div class="pwa-install-info">
    <img src="assets/img/logo_ias.png?v=3" alt="IAS Support Logo" height="34" style="max-width: 85px; object-fit: contain;">
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

<div class="sidebar-backdrop no-print" id="sidebar_backdrop"></div>

<div class="app-layout">
  <!-- Modern Left Sidebar Navigation -->
  <aside class="app-sidebar no-print" id="app_sidebar">
    <div class="sidebar-header">
      <a href="./" class="sidebar-brand">
        <img src="assets/img/logo_ias.png?v=3" alt="IAS Support Logo" class="sidebar-brand-logo">
        <div class="sidebar-brand-info">
          <span class="sidebar-brand-title">IASS SBU ICT KNO</span>
          <span class="sidebar-brand-sub">
            <span>Sistem PM &amp; NOC</span>
            <span class="app-version-badge-sm"><?= APP_VERSION ?></span>
          </span>
        </div>
      </a>
      <button type="button" class="sidebar-close-btn" id="btn_close_sidebar" aria-label="Tutup Menu">&times;</button>
    </div>

    <div class="sidebar-scroll-area">
      <!-- Section 1: Utama & NOC -->
      <div>
        <div class="sidebar-group-title">Utama &amp; NOC</div>
        <ul class="sidebar-nav-list">
          <li>
            <a href="./" class="sidebar-nav-item <?= ($curPage === 'index.php') ? 'active' : '' ?>">
              <span class="sidebar-nav-icon">📊</span>
              <span>Dashboard</span>
            </a>
          </li>
          <li>
            <a href="daily_activity" class="sidebar-nav-item <?= ($curPage === 'daily_activity.php') ? 'active' : '' ?>">
              <span class="sidebar-nav-icon">⏱️</span>
              <span>Daily Activity</span>
              <span class="sidebar-nav-badge">NOC</span>
            </a>
          </li>
        </ul>
      </div>

      <!-- Section 2: PM Bulanan -->
      <div>
        <div class="sidebar-group-title">PM Bulanan</div>
        <ul class="sidebar-nav-list">
          <li>
            <a href="reports" class="sidebar-nav-item <?= ($curPage === 'reports.php') ? 'active' : '' ?>">
              <span class="sidebar-nav-icon">📋</span>
              <span>Riwayat PM</span>
            </a>
          </li>
          <li>
            <a href="form_indoor" class="sidebar-nav-item <?= ($curPage === 'form_indoor.php') ? 'active' : '' ?>">
              <span class="sidebar-nav-icon">🏢</span>
              <span>+ PM Indoor</span>
            </a>
          </li>
          <li>
            <a href="form_outdoor" class="sidebar-nav-item <?= ($curPage === 'form_outdoor.php') ? 'active' : '' ?>">
              <span class="sidebar-nav-icon">🗼</span>
              <span>+ PM Outdoor</span>
            </a>
          </li>
        </ul>
      </div>

      <!-- Section 3: Master Data -->
      <div>
        <div class="sidebar-group-title">Master Data</div>
        <ul class="sidebar-nav-list">
          <li>
            <a href="sites" class="sidebar-nav-item <?= ($curPage === 'sites.php') ? 'active' : '' ?>">
              <span class="sidebar-nav-icon">📍</span>
              <span>Data Master Site</span>
            </a>
          </li>
          <li>
            <a href="operators" class="sidebar-nav-item <?= ($curPage === 'operators.php') ? 'active' : '' ?>">
              <span class="sidebar-nav-icon">📡</span>
              <span>Master Operator</span>
            </a>
          </li>
          <?php if (has_role(['superadmin', 'admin'])): ?>
          <li>
            <a href="users" class="sidebar-nav-item <?= ($curPage === 'users.php') ? 'active' : '' ?>">
              <span class="sidebar-nav-icon">👥</span>
              <span>Kelola Pengguna</span>
            </a>
          </li>
          <?php endif; ?>
          <?php if (has_role('superadmin')): ?>
          <li>
            <a href="settings" class="sidebar-nav-item <?= ($curPage === 'settings.php') ? 'active' : '' ?>">
              <span class="sidebar-nav-icon">⚙️</span>
              <span>Pengaturan Sistem</span>
            </a>
          </li>
          <?php endif; ?>
        </ul>
      </div>
    </div>

    <!-- Sidebar Bottom User Profile & Preferences -->
    <div class="sidebar-footer">
      <div class="sidebar-user-card">
        <div class="sidebar-user-avatar">
          <?= strtoupper(substr($currentUser['username'] ?? 'U', 0, 2)) ?>
        </div>
        <div class="sidebar-user-info">
          <span class="sidebar-user-name"><?= htmlspecialchars($currentUser['full_name'] ?? $currentUser['username'] ?? 'User') ?></span>
          <span class="user-role-badge role-<?= htmlspecialchars($currentUser['role'] ?? 'user') ?>" style="align-self: flex-start; margin-top: 2px;">
            <?= ($currentUser['role'] === 'superadmin') ? 'Super Admin' : (($currentUser['role'] === 'admin') ? 'Admin' : 'Teknisi') ?>
          </span>
        </div>
      </div>

      <div class="sidebar-actions-row">
        <button type="button" class="sidebar-action-btn" onclick="toggleIassTheme()" title="Beralih tema terang/gelap" id="btn_sidebar_theme">
          <span id="sidebar_theme_icon">🌙</span>
          <span id="sidebar_theme_text">Tema</span>
        </button>
        <a href="logout" class="sidebar-action-btn sidebar-action-danger" onclick="return confirm('Apakah Anda yakin ingin keluar?')" title="Keluar dari sistem">
          <span>🚪</span>
          <span>Keluar</span>
        </a>
      </div>
    </div>
  </aside>

  <!-- Main Viewport -->
  <div class="app-main-viewport">
    <header class="app-topbar no-print">
      <div class="topbar-left">
        <button type="button" class="topbar-toggle-sidebar" id="btn_open_sidebar" aria-label="Buka Menu Sidebar">
          <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <span class="topbar-page-title"><?= htmlspecialchars($page_title) ?></span>
      </div>
      <div class="topbar-right">
        <a href="daily_activity" class="btn btn-sm btn-primary-soft" style="display: inline-flex; align-items: center; gap: 4px;" title="Buka Catatan Kegiatan Harian NOC">
          <span>⏱️</span> Daily Activity
        </a>
        <div class="user-avatar-circle" style="width: 32px; height: 32px; font-size: 0.8rem;" title="<?= htmlspecialchars($currentUser['full_name'] ?? 'User') ?> (<?= htmlspecialchars($currentUser['role'] ?? 'user') ?>)">
          <?= strtoupper(substr($currentUser['username'] ?? 'U', 0, 2)) ?>
        </div>
      </div>
    </header>
    <div class="app-content-body">

    <script>
      function updateThemeUi(curTheme) {
        var icon = document.getElementById('sidebar_theme_icon');
        var text = document.getElementById('sidebar_theme_text');
        if (curTheme === 'dark') {
          if (icon) icon.textContent = '☀️';
          if (text) text.textContent = 'Terang';
        } else {
          if (icon) icon.textContent = '🌙';
          if (text) text.textContent = 'Gelap';
        }
      }

      function toggleIassTheme() {
        var html = document.documentElement;
        var cur = html.getAttribute('data-theme') || 'light';
        var next = (cur === 'dark') ? 'light' : 'dark';
        html.setAttribute('data-theme', next);
        if (document.body) {
          document.body.setAttribute('data-theme', next);
        }
        try {
          localStorage.setItem('iass_theme', next);
        } catch(e) {}
        updateThemeUi(next);
      }

      document.addEventListener('DOMContentLoaded', function() {
        var curTheme = document.documentElement.getAttribute('data-theme') || 'light';
        updateThemeUi(curTheme);

        var sidebar = document.getElementById('app_sidebar');
        var backdrop = document.getElementById('sidebar_backdrop');
        var btnOpen = document.getElementById('btn_open_sidebar');
        var btnClose = document.getElementById('btn_close_sidebar');

        function openSidebar() {
          if (sidebar) sidebar.classList.add('show');
          if (backdrop) backdrop.classList.add('show');
        }
        function closeSidebar() {
          if (sidebar) sidebar.classList.remove('show');
          if (backdrop) backdrop.classList.remove('show');
        }

        if (btnOpen) btnOpen.addEventListener('click', openSidebar);
        if (btnClose) btnClose.addEventListener('click', closeSidebar);
        if (backdrop) backdrop.addEventListener('click', closeSidebar);
      });
    </script>

