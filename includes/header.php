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
$sysAppTitle = get_system_setting('app_title', get_system_setting('company_name', 'IASS SBU ICT KNO'));
$sysAppSubtitle = get_system_setting('app_subtitle', 'Sistem PM & NOC');
$sysAppLogo = get_system_setting('app_logo_path', 'assets/img/logo_ias.png?v=3');
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= htmlspecialchars($page_title) ?> - <?= htmlspecialchars($sysAppTitle) ?></title>
  
  <!-- PWA Meta Tags -->
  <link rel="manifest" href="manifest.json?v=<?= file_exists(__DIR__ . '/../manifest.json') ? filemtime(__DIR__ . '/../manifest.json') : time() ?>">
  <meta name="theme-color" content="#008080">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($sysAppTitle) ?>">
  <?php 
    $pwaTouchIcon = file_exists(__DIR__ . '/../uploads/branding/pwa-icon-192.png') 
      ? 'uploads/branding/pwa-icon-192.png?v=' . filemtime(__DIR__ . '/../uploads/branding/pwa-icon-192.png') 
      : $sysAppLogo;
  ?>
  <link rel="apple-touch-icon" href="<?= htmlspecialchars($pwaTouchIcon) ?>">
  
  <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/../assets/css/app.css') ?>">
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($pwaTouchIcon) ?>">

  <script>
    (function() {
      try {
        var t = localStorage.getItem('iass_theme');
        if (t === 'dark' || (!t && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
          document.documentElement.setAttribute('data-theme', 'dark');
        } else {
          document.documentElement.setAttribute('data-theme', 'light');
        }
        var c = localStorage.getItem('iass_sidebar_collapsed');
        if (c === '1' && window.innerWidth >= 992) {
          document.documentElement.classList.add('sidebar-collapsed');
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
    <img src="<?= htmlspecialchars($sysAppLogo) ?>" alt="Logo Aplikasi" height="34" style="max-width: 85px; object-fit: contain;">
    <div>
      <div style="font-weight: 700; font-size: 0.85rem; color: #ffffff;">Instal Aplikasi <?= htmlspecialchars($sysAppTitle) ?></div>
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
      <a href="./" class="sidebar-brand" title="<?= htmlspecialchars($sysAppTitle) ?> - <?= htmlspecialchars($sysAppSubtitle) ?>">
        <img src="<?= htmlspecialchars($sysAppLogo) ?>" alt="Logo Aplikasi" class="sidebar-brand-logo">
        <div class="sidebar-brand-info">
          <span class="sidebar-brand-title"><?= htmlspecialchars($sysAppTitle) ?></span>
          <span class="sidebar-brand-sub">
            <span><?= htmlspecialchars($sysAppSubtitle) ?></span>
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
            <a href="./" class="sidebar-nav-item <?= ($curPage === 'index.php') ? 'active' : '' ?>" title="Dashboard Operasional &amp; Monitoring">
              <span class="sidebar-nav-icon"><?= app_icon('dashboard', 18) ?></span>
              <span>Dashboard</span>
            </a>
          </li>
          <li>
            <a href="daily_activity" class="sidebar-nav-item <?= ($curPage === 'daily_activity.php') ? 'active' : '' ?>" title="Daily Activity Logbook NOC">
              <span class="sidebar-nav-icon"><?= app_icon('clock', 18) ?></span>
              <span>Daily Activity</span>
              <span class="sidebar-nav-badge">NOC</span>
            </a>
          </li>
          <?php if (has_role(['superadmin', 'admin'])): ?>
          <li>
            <a href="daily_reports" class="sidebar-nav-item <?= ($curPage === 'daily_reports.php') ? 'active' : '' ?>" title="Rekap &amp; Laporan Harian NOC Tim">
              <span class="sidebar-nav-icon"><?= app_icon('document', 18) ?></span>
              <span>Rekap &amp; Laporan NOC</span>
              <span class="sidebar-nav-badge" style="background: rgba(14, 165, 233, 0.15); color: #0284c7; border: 1px solid rgba(14, 165, 233, 0.3);">Tim</span>
            </a>
          </li>
          <?php endif; ?>
        </ul>
      </div>

      <!-- Section 2: PM Bulanan -->
      <div>
        <div class="sidebar-group-title">PM Bulanan</div>
        <ul class="sidebar-nav-list">
          <li>
            <a href="reports" class="sidebar-nav-item <?= ($curPage === 'reports.php') ? 'active' : '' ?>" title="Riwayat Arsip Dokumen PM">
              <span class="sidebar-nav-icon"><?= app_icon('clipboard-list', 18) ?></span>
              <span>Riwayat PM</span>
            </a>
          </li>
          <li>
            <a href="form_indoor" class="sidebar-nav-item <?= ($curPage === 'form_indoor.php') ? 'active' : '' ?>" title="Input Laporan PM Indoor IBC">
              <span class="sidebar-nav-icon"><?= app_icon('building', 18) ?></span>
              <span>+ PM Indoor</span>
            </a>
          </li>
          <li>
            <a href="form_outdoor" class="sidebar-nav-item <?= ($curPage === 'form_outdoor.php') ? 'active' : '' ?>" title="Input Laporan PM Outdoor Pole &amp; Tower">
              <span class="sidebar-nav-icon"><?= app_icon('tower', 18) ?></span>
              <span>+ PM Outdoor</span>
            </a>
          </li>
        </ul>
      </div>

      <!-- Section 3: Master Data (Khusus Super Admin) -->
      <?php if (has_role('superadmin')): ?>
      <div>
        <div class="sidebar-group-title">Master Data</div>
        <ul class="sidebar-nav-list">
          <li>
            <a href="sites" class="sidebar-nav-item <?= ($curPage === 'sites.php') ? 'active' : '' ?>" title="Data Master Site &amp; Tower">
              <span class="sidebar-nav-icon"><?= app_icon('map-pin', 18) ?></span>
              <span>Data Master Site</span>
            </a>
          </li>
          <li>
            <a href="operators" class="sidebar-nav-item <?= ($curPage === 'operators.php') ? 'active' : '' ?>" title="Data Master Operator Seluler">
              <span class="sidebar-nav-icon"><?= app_icon('wifi', 18) ?></span>
              <span>Master Operator</span>
            </a>
          </li>
          <li>
            <a href="users" class="sidebar-nav-item <?= ($curPage === 'users.php') ? 'active' : '' ?>" title="Manajemen Pengguna &amp; Akun">
              <span class="sidebar-nav-icon"><?= app_icon('users', 18) ?></span>
              <span>Kelola Pengguna</span>
            </a>
          </li>
          <li>
            <a href="settings" class="sidebar-nav-item <?= ($curPage === 'settings.php') ? 'active' : '' ?>" title="Pengaturan Sistem &amp; Preferensi">
              <span class="sidebar-nav-icon"><?= app_icon('settings', 18) ?></span>
              <span>Pengaturan Sistem</span>
            </a>
          </li>
        </ul>
      </div>
      <?php elseif (has_role('admin')): ?>
      <div>
        <div class="sidebar-group-title">Administrasi</div>
        <ul class="sidebar-nav-list">
          <li>
            <a href="users" class="sidebar-nav-item <?= ($curPage === 'users.php') ? 'active' : '' ?>" title="Manajemen Pengguna &amp; Akun">
              <span class="sidebar-nav-icon"><?= app_icon('users', 18) ?></span>
              <span>Kelola Pengguna</span>
            </a>
          </li>
        </ul>
      </div>
      <?php endif; ?>
    </div>

    <!-- Sidebar Bottom User Profile & Preferences -->
    <div class="sidebar-footer">
      <a href="profile" class="sidebar-user-card" title="Buka Profil Saya & Ganti Password" style="text-decoration: none; display: flex; align-items: center; gap: 10px; cursor: pointer; transition: background 0.2s ease;">
        <div class="sidebar-user-avatar">
          <?= strtoupper(substr($currentUser['username'] ?? 'U', 0, 2)) ?>
        </div>
        <div class="sidebar-user-info" style="flex: 1; min-width: 0;">
          <span class="sidebar-user-name" style="display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($currentUser['full_name'] ?? $currentUser['username'] ?? 'User') ?></span>
          <span class="user-role-badge role-<?= htmlspecialchars($currentUser['role'] ?? 'user') ?>" style="align-self: flex-start; margin-top: 2px;">
            <?= ($currentUser['role'] === 'superadmin') ? 'Super Admin' : (($currentUser['role'] === 'admin') ? 'Admin' : 'Teknisi') ?>
          </span>
        </div>
        <div style="color: var(--text-subtle); padding-right: 4px;" title="Kelola Profil">
          <?= app_icon('settings', 14) ?>
        </div>
      </a>

      <div class="sidebar-actions-row">
        <a href="profile" class="sidebar-action-btn <?= ($curPage === 'profile.php') ? 'active' : '' ?>" title="Pengaturan Akun & Profil">
          <span><?= app_icon('user', 15) ?></span>
          <span>Profil</span>
        </a>
        <button type="button" class="sidebar-action-btn" onclick="toggleIassTheme()" title="Beralih tema terang/gelap" id="btn_sidebar_theme">
          <span id="sidebar_theme_icon"><?= app_icon('moon', 15) ?></span>
          <span id="sidebar_theme_text">Tema</span>
        </button>
        <a href="logout" class="sidebar-action-btn sidebar-action-danger" onclick="return confirm('Apakah Anda yakin ingin keluar?')" title="Keluar dari sistem">
          <span><?= app_icon('log-out', 15) ?></span>
          <span>Keluar</span>
        </a>
      </div>
    </div>
  </aside>

  <!-- Main Viewport -->
  <div class="app-main-viewport">
    <header class="app-topbar no-print">
      <div class="topbar-left">
        <!-- Tombol Buka Menu di Layar HP & Tablet (< 992px) -->
        <button type="button" class="topbar-toggle-sidebar" id="btn_open_sidebar" aria-label="Buka Menu Sidebar">
          <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <!-- Tombol Minimize / Collapse Sidebar di Desktop (>= 992px) -->
        <button type="button" class="topbar-desktop-collapse" id="btn_toggle_sidebar_collapse" aria-label="Minimize atau Perluas Sidebar" title="Minimize / Perluas Sidebar">
          <svg class="icon-expand-sidebar" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="3" x2="9" y2="21"/><path d="M14 9l-3 3 3 3"/></svg>
        </button>
        <span class="topbar-page-title"><?= htmlspecialchars($page_title) ?></span>
      </div>
    </header>
    <div class="app-content-body">

    <script>
      function updateThemeUi(curTheme) {
        var icon = document.getElementById('sidebar_theme_icon');
        var text = document.getElementById('sidebar_theme_text');
        if (curTheme === 'dark') {
          if (icon) icon.innerHTML = '<svg class="app-svg-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>';
          if (text) text.textContent = 'Terang';
        } else {
          if (icon) icon.innerHTML = '<svg class="app-svg-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"></path></svg>';
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

      function toggleSidebarCollapse() {
        var html = document.documentElement;
        var isCollapsed = html.classList.toggle('sidebar-collapsed');
        try {
          localStorage.setItem('iass_sidebar_collapsed', isCollapsed ? '1' : '0');
        } catch(e) {}
      }

      document.addEventListener('DOMContentLoaded', function() {
        var curTheme = document.documentElement.getAttribute('data-theme') || 'light';
        updateThemeUi(curTheme);

        var sidebar = document.getElementById('app_sidebar');
        var backdrop = document.getElementById('sidebar_backdrop');
        var btnOpen = document.getElementById('btn_open_sidebar');
        var btnClose = document.getElementById('btn_close_sidebar');
        var btnCollapse = document.getElementById('btn_toggle_sidebar_collapse');

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
        if (btnCollapse) btnCollapse.addEventListener('click', toggleSidebarCollapse);
      });
    </script>

