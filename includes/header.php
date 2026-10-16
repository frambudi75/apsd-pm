<?php
/**
 * Shared Application Header
 * IASS SBU ICT KNO
 */
require_once __DIR__ . '/auth.php';
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

<header class="app-navbar no-print">
  <div class="nav-container">
    <a href="./" class="brand-wrapper">
      <img src="assets/img/logo_ias.png?v=3" alt="IAS Support Logo" class="brand-logo">
      <div class="brand-text">
        <h1>IASS SBU ICT KNO</h1>
        <span>Sistem Checklist & Laporan PM</span>
      </div>
    </a>
    
    <!-- Desktop Navigation Links (Center) -->
    <nav class="nav-actions web-nav">
      <a href="./" class="nav-link-item <?= ($curPage === 'index.php') ? 'active' : '' ?>">
        <span>📊</span> Dashboard
      </a>
      <a href="reports" class="nav-link-item <?= ($curPage === 'reports.php') ? 'active' : '' ?>">
        <span>📋</span> Riwayat Laporan
      </a>
      <a href="sites" class="nav-link-item <?= ($curPage === 'sites.php') ? 'active' : '' ?>">
        <span>📍</span> Master Site
      </a>
      <a href="operators" class="nav-link-item <?= ($curPage === 'operators.php') ? 'active' : '' ?>">
        <span>🏢</span> Operator
      </a>
      <?php if (has_role(['superadmin', 'admin'])): ?>
        <a href="users" class="nav-link-item <?= ($curPage === 'users.php') ? 'active' : '' ?>">
          <span>👥</span> Pengguna
        </a>
      <?php endif; ?>
      
      <div class="nav-cta-buttons">
        <a href="form_indoor" class="btn btn-sm btn-primary-soft" title="Checklist PM Indoor IBC Telkomsel (7 Halaman)">+ PM Indoor</a>
        <a href="form_outdoor" class="btn btn-sm btn-primary" title="Checklist PM Outdoor Pole / Tower (13 Halaman)">+ PM Outdoor</a>
      </div>
    </nav>

    <!-- Right Controls: Sleek Unified User Profile & Preferences Dropdown -->
    <div class="user-menu-wrapper" id="user_menu_wrapper">
      <button type="button" class="user-menu-trigger" id="user_menu_trigger" aria-expanded="false" aria-haspopup="true" title="Profil Pengguna & Pengaturan Tema">
        <div class="user-avatar-circle">
          <?= strtoupper(substr($currentUser['username'] ?? 'U', 0, 2)) ?>
        </div>
        <div class="user-meta-compact web-nav">
          <span class="user-name-compact"><?= htmlspecialchars($currentUser['username'] ?? 'user') ?></span>
          <span class="user-role-badge role-<?= htmlspecialchars($currentUser['role'] ?? 'user') ?>">
            <?= ($currentUser['role'] === 'superadmin') ? 'Super Admin' : (($currentUser['role'] === 'admin') ? 'Admin' : 'Teknisi') ?>
          </span>
        </div>
        <svg class="chevron-icon web-nav" width="14" height="14" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
        </svg>
      </button>

      <!-- Dropdown Popover Menu -->
      <div class="user-dropdown-popover" id="user_dropdown_popover">
        <!-- User Info Header -->
        <div class="user-dropdown-header">
          <div class="user-dropdown-avatar">
            <?= strtoupper(substr($currentUser['username'] ?? 'U', 0, 2)) ?>
          </div>
          <div class="user-dropdown-details">
            <div class="user-dropdown-name"><?= htmlspecialchars($currentUser['full_name'] ?? 'User') ?></div>
            <div class="user-dropdown-sub">
              <span class="user-dropdown-username">@<?= htmlspecialchars($currentUser['username'] ?? 'user') ?></span>
              <span class="user-role-badge role-<?= htmlspecialchars($currentUser['role'] ?? 'user') ?>">
                <?= ($currentUser['role'] === 'superadmin') ? 'Super Admin' : (($currentUser['role'] === 'admin') ? 'Admin' : 'Teknisi') ?>
              </span>
            </div>
          </div>
        </div>

        <div class="user-dropdown-divider"></div>

        <!-- Quick Preferences: Theme Switcher -->
        <div class="user-dropdown-section">
          <button type="button" class="theme-switch-row" onclick="toggleIassTheme()" title="Klik untuk beralih mode terang/gelap">
            <div class="theme-switch-label">
              <span class="theme-switch-icon" id="theme_current_icon">🌙</span>
              <span>Mode Tampilan</span>
            </div>
            <span class="theme-pill-tag" id="theme_pill_tag">Gelap</span>
          </button>
        </div>

        <div class="user-dropdown-divider"></div>

        <!-- Links list inside dropdown -->
        <div class="user-dropdown-menu-list">
          <?php if (has_role(['superadmin', 'admin'])): ?>
            <a href="users" class="dropdown-item">
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
              <span>Kelola Pengguna</span>
            </a>
          <?php endif; ?>
          <a href="sites" class="dropdown-item">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            <span>Data Master Site</span>
          </a>
          <a href="operators" class="dropdown-item">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
            <span>Master Operator / Tenant</span>
          </a>
          <a href="reports" class="dropdown-item">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path></svg>
            <span>Riwayat Laporan PM</span>
          </a>
        </div>

        <div class="user-dropdown-divider"></div>

        <!-- Logout Action -->
        <a href="logout" class="dropdown-item dropdown-item-danger" onclick="return confirm('Apakah Anda yakin ingin keluar?')">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
          <span>Keluar dari Sistem</span>
        </a>
      </div>
    </div>

    <script>
      function updateThemeDropdownUi(curTheme) {
        var icon = document.getElementById('theme_current_icon');
        var pill = document.getElementById('theme_pill_tag');
        if (curTheme === 'dark') {
          if (icon) icon.textContent = '☀️';
          if (pill) { pill.textContent = 'Mode Terang'; pill.className = 'theme-pill-tag pill-dark-active'; }
        } else {
          if (icon) icon.textContent = '🌙';
          if (pill) { pill.textContent = 'Mode Gelap'; pill.className = 'theme-pill-tag pill-light-active'; }
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
        updateThemeDropdownUi(next);
      }

      // Dropdown Toggle Logic
      document.addEventListener('DOMContentLoaded', function() {
        var wrapper = document.getElementById('user_menu_wrapper');
        var trigger = document.getElementById('user_menu_trigger');
        var popover = document.getElementById('user_dropdown_popover');
        
        // Initial Theme Pill Setup
        var curTheme = document.documentElement.getAttribute('data-theme') || 'light';
        updateThemeDropdownUi(curTheme);

        if (trigger && popover) {
          trigger.addEventListener('click', function(e) {
            e.stopPropagation();
            var isOpen = popover.classList.contains('show');
            if (isOpen) {
              popover.classList.remove('show');
              trigger.setAttribute('aria-expanded', 'false');
            } else {
              popover.classList.add('show');
              trigger.setAttribute('aria-expanded', 'true');
            }
          });

          document.addEventListener('click', function(e) {
            if (popover.classList.contains('show') && !wrapper.contains(e.target)) {
              popover.classList.remove('show');
              trigger.setAttribute('aria-expanded', 'false');
            }
          });

          document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && popover.classList.contains('show')) {
              popover.classList.remove('show');
              trigger.setAttribute('aria-expanded', 'false');
            }
          });
        }
      });
    </script>
  </div>
</header>
