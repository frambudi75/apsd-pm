<?php
/**
 * Login Screen - IASS SBU ICT KNO
 * Secure 12-Hour Session Authentication
 */
require_once __DIR__ . '/includes/auth.php';

start_secure_session();

// If already authenticated, redirect to home
if (is_logged_in()) {
    $baseUrl = get_app_base_url();
    header('Location: ' . $baseUrl);
    exit;
}

$errorMessage = '';
$infoMessage = '';

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
  <title>Login - IASS SBU ICT KNO</title>
  
  <link rel="manifest" href="manifest.json?v=3">
  <meta name="theme-color" content="#008080">
  <link rel="icon" type="image/png" href="assets/img/logo_ias.png?v=3">
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
  </script>

  <style>
    .login-container {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 1.5rem 1rem;
      background: radial-gradient(circle at 50% 20%, rgba(20, 184, 166, 0.08), transparent 70%), var(--bg-main);
    }
    .login-card {
      width: 100%;
      max-width: 420px;
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-md);
      overflow: hidden;
      animation: fadeIn 0.3s ease;
    }
    .login-header {
      padding: 2.25rem 2rem 1.5rem 2rem;
      text-align: center;
      border-bottom: 1px solid var(--border);
      background: linear-gradient(180deg, var(--bg-alt) 0%, var(--bg-card) 100%);
      position: relative;
    }
    .login-logo {
      height: 52px;
      max-width: 160px;
      object-fit: contain;
      margin-bottom: 1rem;
    }
    .login-title {
      font-size: 1.25rem;
      font-weight: 800;
      color: var(--text-main);
      margin: 0;
      letter-spacing: -0.01em;
    }
    .login-subtitle {
      font-size: 0.8rem;
      color: var(--text-subtle);
      margin-top: 0.35rem;
    }
    .login-body {
      padding: 2rem;
    }
    .password-wrapper {
      position: relative;
      display: flex;
      align-items: center;
    }
    .password-toggle-btn {
      position: absolute;
      right: 10px;
      background: none;
      border: none;
      color: var(--text-subtle);
      cursor: pointer;
      font-size: 1.1rem;
      padding: 6px;
      display: flex;
      align-items: center;
      justify-content: center;
      min-width: 44px;
      min-height: 44px;
    }
    .login-footer-info {
      margin-top: 1.5rem;
      text-align: center;
      font-size: 0.75rem;
      color: var(--text-subtle);
    }
    .login-theme-pos {
      position: absolute;
      top: 1rem;
      right: 1rem;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(8px); }
      to { opacity: 1; transform: translateY(0); }
    }
  </style>
</head>
<body>

<div class="login-container">
  
  <div class="login-card">
    <div class="login-header">
      <div class="login-theme-pos">
        <button type="button" id="theme_toggle_btn" class="theme-toggle-btn" onclick="toggleIassTheme()" title="Ganti Mode Terang / Gelap" aria-label="Ganti Tema">
          <span class="theme-icon-light" aria-hidden="true">☀️</span>
          <span class="theme-icon-dark" aria-hidden="true">🌙</span>
        </button>
      </div>

      <img src="assets/img/logo_ias.png?v=3" alt="IAS Support Logo" class="login-logo">
      <h1 class="login-title">IASS SBU ICT KNO</h1>
      <p class="login-subtitle">Sistem Checklist & Laporan Preventive Maintenance</p>
    </div>

    <div class="login-body">
      <?php if (!empty($errorMessage)): ?>
        <div style="background: var(--status-nok-bg); color: var(--status-nok); border: 1px solid var(--status-nok-border); padding: 0.75rem 1rem; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 600; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 8px;">
          <span>⚠️</span>
          <span><?= htmlspecialchars($errorMessage) ?></span>
        </div>
      <?php endif; ?>

      <?php if (!empty($infoMessage)): ?>
        <div style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 0.75rem 1rem; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 600; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 8px;">
          <span>ℹ️</span>
          <span><?= htmlspecialchars($infoMessage) ?></span>
        </div>
      <?php endif; ?>

      <form method="POST" action="" id="login_form">
        <div class="form-group" style="margin-bottom: 1.25rem;">
          <label class="form-label" for="username">Username</label>
          <input type="text" id="username" name="username" class="form-control" placeholder="Masukkan username..." required autofocus value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" style="min-height: 44px;">
        </div>

        <div class="form-group" style="margin-bottom: 1.5rem;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
            <label class="form-label" for="password" style="margin-bottom: 0;">Password</label>
          </div>
          <div class="password-wrapper">
            <input type="password" id="password" name="password" class="form-control" placeholder="Masukkan password..." required style="min-height: 44px; padding-right: 48px;">
            <button type="button" class="password-toggle-btn" id="btn_toggle_pwd" title="Lihat / Sembunyikan Password" aria-label="Toggle Password Visibility">
              👁️
            </button>
          </div>
        </div>

        <button type="submit" id="btn_submit_login" class="btn btn-primary" style="width: 100%; min-height: 46px; font-weight: 700; font-size: 0.95rem;">
          Masuk ke Sistem
        </button>
      </form>

    </div>
  </div>

  <div class="login-footer-info">
    &copy; <?= date('Y') ?> IASS SBU ICT KNO. Sesi login aktif selama 12 jam.
  </div>

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
  if (toggleBtn && pwdInput) {
    toggleBtn.addEventListener('click', function() {
      const isPwd = pwdInput.type === 'password';
      pwdInput.type = isPwd ? 'text' : 'password';
      this.textContent = isPwd ? '🙈' : '👁️';
    });
  }
</script>

</body>
</html>
