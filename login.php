<?php
/**
 * Login Screen - IASS SBU ICT KNO
 * Secure 12-Hour Session Authentication
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/version.php';

start_secure_session();

// If already authenticated, redirect to home
if (is_logged_in()) {
    $baseUrl = get_app_base_url();
    header('Location: ' . $baseUrl);
    exit;
}

$errorMessage = '';
$infoMessage = '';

$sysAppTitle = get_system_setting('app_title', 'ICT HUB');
$sysCompanyName = get_system_setting('company_name', 'IASS SBU ICT KNO');
$sysUnitName = get_system_setting('unit_name', 'SBU ICT KNO');
$sysAppSubtitle = get_system_setting('app_subtitle', 'Sistem Checklist & Laporan Preventive Maintenance');
$sysAppLogo = get_system_setting('app_logo_path', 'assets/img/logo_ias.png?v=3');

if (isset($_GET['expired'])) {
    $infoMessage = 'Sesi Anda telah berakhir setelah 12 jam. Silakan login kembali untuk melanjutkan.';
} elseif (isset($_GET['logout'])) {
    $infoMessage = 'Anda telah berhasil keluar dari sistem.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $errorMessage = 'Silakan masukkan username dan password.';
    } else {
        $authResult = authenticate_user($username, $password);
        if ($authResult['success']) {
            $redirectUrl = $_GET['redirect'] ?? '';
            $baseUrl = get_app_base_url();
            
            // Validate redirect URL to prevent open redirect
            if (!empty($redirectUrl) && (strpos($redirectUrl, 'http://') === false && strpos($redirectUrl, 'https://') === false)) {
                header('Location: ' . $redirectUrl);
            } else {
                header('Location: ' . $baseUrl);
            }
            exit;
        } else {
            $errorMessage = $authResult['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Login - <?= htmlspecialchars($sysAppTitle) ?></title>
  
  <link rel="manifest" href="manifest.json?v=<?= file_exists(__DIR__ . '/manifest.json') ? filemtime(__DIR__ . '/manifest.json') : time() ?>">
  <meta name="theme-color" content="#008080">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($sysAppTitle) ?>">
  <link rel="icon" type="image/png" href="<?= htmlspecialchars(get_app_favicon_url()) ?>">
  <link rel="shortcut icon" type="image/png" href="<?= htmlspecialchars(get_app_favicon_url()) ?>">
  <link rel="apple-touch-icon" href="<?= htmlspecialchars(get_app_favicon_url()) ?>">
  <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/assets/css/app.css') ?>">

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

    // Anti-Inspect Protection
    (function() {
      document.addEventListener('contextmenu', function(e) {
        if (e.target && e.target.closest('input, textarea, [contenteditable="true"]')) {
          return true;
        }
        e.preventDefault();
        return false;
      }, false);

      document.addEventListener('keydown', function(e) {
        if (e.key === 'F12' || e.keyCode === 123) {
          e.preventDefault();
          return false;
        }
        if ((e.ctrlKey || e.metaKey) && e.shiftKey && ['I', 'i', 'J', 'j', 'C', 'c'].indexOf(e.key) !== -1) {
          e.preventDefault();
          return false;
        }
        if ((e.ctrlKey || e.metaKey) && (e.key === 'U' || e.key === 'u')) {
          e.preventDefault();
          return false;
        }
        if ((e.ctrlKey || e.metaKey) && (e.key === 'S' || e.key === 's') && !e.target.closest('input, textarea')) {
          e.preventDefault();
          return false;
        }
      }, false);
    })();
  </script>

  <style>
    /* Split-Screen Modern Enterprise Layout */
    body {
      margin: 0;
      padding: 0;
      background: var(--bg-main);
      color: var(--text-main);
      font-family: var(--font-family, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif);
      overflow-x: hidden;
    }

    .login-split-wrapper {
      min-height: 100vh;
      display: flex;
      width: 100%;
    }

    /* Left Hero Showcase */
    .login-hero-pane {
      flex: 1.15;
      background: linear-gradient(145deg, #05161f 0%, #0a2d38 50%, #03141d 100%);
      color: #ffffff;
      padding: 3.5rem 3.5rem 2.5rem 3.5rem;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      position: relative;
      overflow: hidden;
    }

    .login-hero-pane::before {
      content: "";
      position: absolute;
      inset: 0;
      background-image: radial-gradient(rgba(255, 255, 255, 0.08) 1px, transparent 1px);
      background-size: 26px 26px;
      pointer-events: none;
      opacity: 0.65;
    }

    .login-hero-pane::after {
      content: "";
      position: absolute;
      top: -150px;
      right: -150px;
      width: 450px;
      height: 450px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(0, 128, 128, 0.38) 0%, transparent 70%);
      pointer-events: none;
      filter: blur(40px);
    }

    .hero-content {
      position: relative;
      z-index: 2;
    }

    .hero-brand-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 0.4rem 0.85rem;
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.15);
      border-radius: 999px;
      font-size: 0.8rem;
      font-weight: 600;
      color: #5eead4;
      backdrop-filter: blur(8px);
      margin-bottom: 1.5rem;
    }

    .hero-status-indicator {
      width: 8px;
      height: 8px;
      background: #10b981;
      border-radius: 50%;
      display: inline-block;
      flex-shrink: 0;
    }

    .hero-logo-box {
      display: flex;
      align-items: center;
      gap: 14px;
      margin-bottom: 2rem;
    }

    .hero-logo-img {
      height: 52px;
      max-width: 170px;
      object-fit: contain;
      filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.4));
    }

    .hero-app-title {
      font-size: 2.1rem;
      font-weight: 800;
      line-height: 1.2;
      letter-spacing: -0.02em;
      margin: 0 0 1rem 0;
      background: linear-gradient(180deg, #ffffff 30%, #99f6e4 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    .hero-subtitle {
      font-size: 1rem;
      line-height: 1.6;
      color: #94a3b8;
      max-width: 520px;
      margin: 0 0 2.5rem 0;
    }

    /* 3 Feature Highlight Cards */
    .hero-features-grid {
      display: flex;
      flex-direction: column;
      gap: 1rem;
      max-width: 540px;
    }

    .hero-feature-card {
      display: flex;
      align-items: flex-start;
      gap: 1rem;
      padding: 1rem 1.25rem;
      background: rgba(255, 255, 255, 0.04);
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: var(--radius-md, 12px);
      backdrop-filter: blur(10px);
      transition: transform 0.2s ease, background 0.2s ease, border-color 0.2s ease;
    }

    .hero-feature-card:hover {
      transform: translateX(4px);
      background: rgba(255, 255, 255, 0.07);
      border-color: rgba(20, 184, 166, 0.4);
    }

    .hero-feature-icon {
      width: 40px;
      height: 40px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.2rem;
      flex-shrink: 0;
      background: rgba(20, 184, 166, 0.15);
      border: 1px solid rgba(20, 184, 166, 0.3);
      color: #2dd4bf;
    }

    .hero-feature-title {
      font-size: 0.95rem;
      font-weight: 700;
      color: #f1f5f9;
      margin: 0 0 0.2rem 0;
    }

    .hero-feature-text {
      font-size: 0.825rem;
      color: #94a3b8;
      line-height: 1.45;
      margin: 0;
    }

    .hero-footer-meta {
      position: relative;
      z-index: 2;
      padding-top: 1.75rem;
      border-top: 1px solid rgba(255, 255, 255, 0.1);
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 0.78rem;
      color: #64748b;
      flex-wrap: wrap;
      gap: 0.75rem;
    }

    .hero-footer-meta strong {
      color: #cbd5e1;
    }

    /* Right Auth Pane */
    .login-auth-pane {
      flex: 0.95;
      background: var(--bg-card);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      padding: 2.5rem 3.5rem;
      position: relative;
    }

    .auth-top-bar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 2rem;
    }

    .auth-mobile-brand {
      display: none;
      align-items: center;
      gap: 10px;
    }

    .auth-mobile-logo {
      height: 38px;
      object-fit: contain;
    }

    .auth-theme-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 0.45rem 0.9rem;
      background: var(--bg-alt);
      border: 1px solid var(--border);
      border-radius: 999px;
      font-size: 0.8rem;
      font-weight: 600;
      color: var(--text-main);
      cursor: pointer;
      transition: background 0.2s ease, border-color 0.2s ease;
    }

    .auth-theme-btn:hover {
      border-color: var(--primary);
    }

    .auth-form-wrapper {
      max-width: 420px;
      width: 100%;
      margin: auto;
    }

    .auth-form-header {
      margin-bottom: 2rem;
    }

    .auth-form-title {
      font-size: 1.65rem;
      font-weight: 800;
      color: var(--text-main);
      margin: 0 0 0.45rem 0;
      letter-spacing: -0.015em;
    }

    .auth-form-desc {
      font-size: 0.875rem;
      color: var(--text-subtle);
      margin: 0;
      line-height: 1.5;
    }

    /* Refined Input Fields with Inner Icons */
    .input-icon-group {
      position: relative;
      display: flex;
      align-items: center;
    }

    .input-left-icon {
      position: absolute;
      left: 14px;
      color: var(--text-subtle);
      display: flex;
      align-items: center;
      justify-content: center;
      pointer-events: none;
      transition: color 0.2s ease;
    }

    .input-with-icon {
      width: 100%;
      min-height: 48px;
      padding: 0.65rem 1rem 0.65rem 44px;
      font-size: 0.95rem;
      background: var(--bg-main);
      border: 1.5px solid var(--border);
      border-radius: var(--radius-md, 8px);
      color: var(--text-main);
      transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
      box-sizing: border-box;
    }

    .input-with-icon:focus {
      outline: none;
      border-color: var(--primary, #008080);
      background: var(--bg-card);
      box-shadow: 0 0 0 3.5px rgba(0, 128, 128, 0.15);
    }

    .input-icon-group:focus-within .input-left-icon {
      color: var(--primary, #008080);
    }

    .pwd-toggle-btn {
      position: absolute;
      right: 8px;
      background: none;
      border: none;
      color: var(--text-subtle);
      cursor: pointer;
      padding: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 6px;
      min-width: 40px;
      min-height: 40px;
      transition: color 0.2s ease, background 0.2s ease;
    }

    .pwd-toggle-btn:hover {
      color: var(--text-main);
      background: var(--bg-alt);
    }

    /* Submit Button */
    .btn-login-submit {
      width: 100%;
      min-height: 48px;
      padding: 0.75rem 1.5rem;
      background: linear-gradient(135deg, #008080 0%, #0d9488 100%);
      color: #ffffff;
      border: none;
      border-radius: var(--radius-md, 8px);
      font-size: 0.95rem;
      font-weight: 700;
      letter-spacing: 0.01em;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      box-shadow: 0 4px 14px rgba(0, 128, 128, 0.35);
      transition: transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease;
    }

    .btn-login-submit:hover {
      transform: translateY(-1px);
      box-shadow: 0 6px 20px rgba(0, 128, 128, 0.45);
      filter: brightness(1.05);
    }

    .btn-login-submit:active {
      transform: translateY(0);
      box-shadow: 0 2px 8px rgba(0, 128, 128, 0.3);
    }

    /* Micro Security Badges */
    .auth-security-badges {
      margin-top: 1.75rem;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-wrap: wrap;
      gap: 1rem;
      font-size: 0.75rem;
      color: var(--text-subtle);
    }

    .security-badge-item {
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }

    /* Bottom Copyright Footer */
    .auth-footer {
      padding-top: 2rem;
      text-align: center;
      font-size: 0.775rem;
      color: var(--text-subtle);
      line-height: 1.5;
    }

    .auth-footer strong {
      color: var(--text-main);
    }

    /* Responsive Reflow for Mobile (< 992px) */
    @media (max-width: 991px) {
      .login-split-wrapper {
        flex-direction: column;
      }

      .login-hero-pane {
        flex: none;
        padding: 2.25rem 1.75rem 2rem 1.75rem;
      }

      .hero-features-grid,
      .hero-footer-meta {
        display: none; /* Hide heavy features on small screen for compact fast view */
      }

      .hero-app-title {
        font-size: 1.6rem;
      }

      .hero-subtitle {
        margin-bottom: 0;
        font-size: 0.9rem;
      }

      .login-auth-pane {
        flex: 1;
        padding: 2rem 1.5rem;
      }

      .auth-mobile-brand {
        display: flex;
      }
    }
  </style>
</head>
<body>

<div class="login-split-wrapper">
  
  <!-- Left Column: Hero Showcase (Desktop & Tablet) -->
  <aside class="login-hero-pane">
    <div class="hero-content">
      <div class="hero-brand-badge">
        <span class="hero-status-indicator"></span>
        <span>Bandara Internasional Kualanamu (KNO)</span>
      </div>

      <div class="hero-logo-box">
        <img src="<?= htmlspecialchars($sysAppLogo) ?>" alt="Logo Aplikasi" class="hero-logo-img">
      </div>

      <h1 class="hero-app-title"><?= htmlspecialchars($sysAppTitle) ?></h1>
      <p class="hero-subtitle"><?= htmlspecialchars($sysAppSubtitle) ?></p>

      <div class="hero-features-grid">
        <div class="hero-feature-card">
          <div class="hero-feature-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
              <path d="M4 22h16"></path>
              <path d="M7 22l5-18 5 18"></path>
              <path d="M9 13h6"></path>
              <path d="M8 17h8"></path>
              <circle cx="12" cy="4" r="1.5" fill="currentColor"></circle>
            </svg>
          </div>
          <div>
            <h4 class="hero-feature-title">Inspeksi Site &amp; Tower Digital</h4>
            <p class="hero-feature-text">Checklist pemeliharaan indoor dan outdoor dengan geotagging GPS akurat serta pratinjau stiker QR tiang.</p>
          </div>
        </div>

        <div class="hero-feature-card">
          <div class="hero-feature-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
              <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path>
              <rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect>
              <path d="M9 14l2 2 4-4"></path>
            </svg>
          </div>
          <div>
            <h4 class="hero-feature-title">Logbook &amp; Daily Activity 24/7</h4>
            <p class="hero-feature-text">Pencatatan shift teknisi, serah terima tugas dinas, dan dokumentasi foto ber-watermark otomatis.</p>
          </div>
        </div>

        <div class="hero-feature-card">
          <div class="hero-feature-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
              <polyline points="14 2 14 8 20 8"></polyline>
              <line x1="16" y1="13" x2="8" y2="13"></line>
              <line x1="16" y1="17" x2="8" y2="17"></line>
              <polyline points="10 9 9 9 8 9"></polyline>
            </svg>
          </div>
          <div>
            <h4 class="hero-feature-title">Audit Trail &amp; Laporan Siap Unduh</h4>
            <p class="hero-feature-text">Laporan berkala berstandar ISO, export PDF instan, integrasi Discord NOC, dan proteksi sesi aman.</p>
          </div>
        </div>
      </div>
    </div>

    <div class="hero-footer-meta">
      <div>
        <strong><?= htmlspecialchars($sysCompanyName) ?></strong> &bull; Unit <?= htmlspecialchars($sysUnitName) ?>
      </div>
      <div style="display: flex; align-items: center;">
        <span class="hero-status-indicator" style="margin-right: 6px;"></span>
        <span>Sistem Operasional Online &bull; Siaga 24/7</span>
      </div>
    </div>
  </aside>

  <!-- Right Column: Authentication Panel -->
  <main class="login-auth-pane">
    <!-- Top Action Bar -->
    <div class="auth-top-bar">
      <div class="auth-mobile-brand">
        <img src="<?= htmlspecialchars($sysAppLogo) ?>" alt="Logo" class="auth-mobile-logo">
      </div>
      <div style="margin-left: auto;">
        <button type="button" class="auth-theme-btn" onclick="toggleIassTheme()" title="Ganti Tema">
          <span class="theme-icon-light" aria-hidden="true" style="display: inline-flex; align-items: center;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="5"></circle>
              <line x1="12" y1="1" x2="12" y2="3"></line>
              <line x1="12" y1="21" x2="12" y2="23"></line>
              <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
              <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
              <line x1="1" y1="12" x2="3" y2="12"></line>
              <line x1="21" y1="12" x2="23" y2="12"></line>
              <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
              <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
            </svg>
          </span>
          <span class="theme-icon-dark" aria-hidden="true" style="display: inline-flex; align-items: center;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
            </svg>
          </span>
          <span style="font-size: 0.78rem;">Tema</span>
        </button>
      </div>
    </div>

    <!-- Center Login Form Box -->
    <div class="auth-form-wrapper">
      <div class="auth-form-header">
        <h2 class="auth-form-title">Masuk ke Portal</h2>
        <p class="auth-form-desc">Silakan masukkan username dan password Anda untuk memulai sesi kerja.</p>
      </div>

      <?php if (!empty($errorMessage)): ?>
        <div style="background: var(--status-nok-bg); color: var(--status-nok); border: 1px solid var(--status-nok-border); padding: 0.75rem 1rem; border-radius: var(--radius-sm, 6px); font-size: 0.85rem; font-weight: 600; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 10px;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink: 0;">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="8" x2="12" y2="12"></line>
            <line x1="12" y1="16" x2="12.01" y2="16"></line>
          </svg>
          <span><?= htmlspecialchars($errorMessage) ?></span>
        </div>
      <?php endif; ?>

      <?php if (!empty($infoMessage)): ?>
        <div style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 0.75rem 1rem; border-radius: var(--radius-sm, 6px); font-size: 0.85rem; font-weight: 600; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 10px;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink: 0;">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="16" x2="12" y2="12"></line>
            <line x1="12" y1="8" x2="12.01" y2="8"></line>
          </svg>
          <span><?= htmlspecialchars($infoMessage) ?></span>
        </div>
      <?php endif; ?>

      <form method="POST" action="" id="login_form">
        <div class="form-group" style="margin-bottom: 1.25rem;">
          <label class="form-label" for="username" style="font-weight: 600; font-size: 0.875rem;">Username</label>
          <div class="input-icon-group">
            <span class="input-left-icon">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                <circle cx="12" cy="7" r="4"></circle>
              </svg>
            </span>
            <input type="text" id="username" name="username" class="input-with-icon" placeholder="Masukkan username Anda..." required autofocus value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
          </div>
        </div>

        <div class="form-group" style="margin-bottom: 1.5rem;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
            <label class="form-label" for="password" style="font-weight: 600; font-size: 0.875rem; margin-bottom: 0;">Password</label>
          </div>
          <div class="input-icon-group">
            <span class="input-left-icon">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
              </svg>
            </span>
            <input type="password" id="password" name="password" class="input-with-icon" placeholder="Masukkan kata sandi..." required style="padding-right: 48px;">
            <button type="button" class="pwd-toggle-btn" id="btn_toggle_pwd" title="Lihat / Sembunyikan Password" aria-label="Toggle Password Visibility">
              <svg id="pwd_icon_show" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                <circle cx="12" cy="12" r="3"></circle>
              </svg>
              <svg id="pwd_icon_hide" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display: none;">
                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                <line x1="1" y1="1" x2="23" y2="23"></line>
              </svg>
            </button>
          </div>
        </div>

        <button type="submit" id="btn_submit_login" class="btn-login-submit">
          Masuk ke Sistem
        </button>
      </form>

      <!-- Security Micro Badges -->
      <div class="auth-security-badges">
        <span class="security-badge-item">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--primary);">
            <circle cx="12" cy="12" r="10"></circle>
            <polyline points="12 6 12 12 16 14"></polyline>
          </svg>
          <span>Sesi Otomatis 12 Jam</span>
        </span>
        <span style="color: var(--border);">&bull;</span>
        <span class="security-badge-item">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--primary);">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
          </svg>
          <span>Proteksi Multi-Peran</span>
        </span>
        <span style="color: var(--border);">&bull;</span>
        <span class="security-badge-item">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--primary);">
            <ellipse cx="12" cy="5" rx="9" ry="3"></ellipse>
            <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path>
            <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path>
          </svg>
          <span>Akselerasi Redis</span>
        </span>
      </div>
    </div>

    <!-- Bottom Synchronized Copyright Footer -->
    <footer class="auth-footer">
      <div>
        &copy; <?= date('Y') ?> <strong><?= htmlspecialchars($sysAppTitle) ?></strong> &bull; <?= htmlspecialchars($sysCompanyName) ?>
      </div>
      <div style="margin-top: 4px; font-size: 0.725rem;">
        Versi <?= APP_VERSION ?> (Build <?= APP_BUILD_DATE ?>) &bull; Hak Cipta Dilindungi
      </div>
    </footer>
  </main>

</div>

<script>
  function toggleIassTheme() {
    var html = document.documentElement;
    var cur = html.getAttribute('data-theme') || 'light';
    var next = (cur === 'dark') ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    try {
      localStorage.setItem('iass_theme', next);
    } catch(e) {}
  }

  // Toggle Password Visibility
  const toggleBtn = document.getElementById('btn_toggle_pwd');
  const pwdInput = document.getElementById('password');
  const iconShow = document.getElementById('pwd_icon_show');
  const iconHide = document.getElementById('pwd_icon_hide');

  if (toggleBtn && pwdInput) {
    toggleBtn.addEventListener('click', function() {
      const isPwd = pwdInput.type === 'password';
      pwdInput.type = isPwd ? 'text' : 'password';
      if (iconShow && iconHide) {
        iconShow.style.display = isPwd ? 'none' : 'block';
        iconHide.style.display = isPwd ? 'block' : 'none';
      }
    });
  }

  // Register Service Worker for PWA installation on login screen
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js?v=17')
        .then(function (reg) {
          console.log('PWA ServiceWorker registered with scope:', reg.scope);
        })
        .catch(function (err) {
          console.log('PWA ServiceWorker registration failed:', err);
        });
    });
  }
</script>

</body>
</html>
