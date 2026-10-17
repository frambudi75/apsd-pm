<?php
/**
 * Shared Application Footer with Mobile PWA Navigation
 * IASS SBU ICT KNO
 */
require_once __DIR__ . '/../config/version.php';
$curPage = basename($_SERVER['PHP_SELF'] ?? 'index.php');
?>
    </div><!-- /.app-content-body -->
    <footer class="no-print desktop-footer">
      <div class="container" style="max-width: 100%;">
        <p>&copy; <?= date('Y') ?> <?= htmlspecialchars(get_system_setting('app_title', get_system_setting('company_name', 'IASS SBU ICT KNO'))) ?>. All rights reserved. &bull; <a href="about" style="color: inherit; text-decoration: none;" title="Buka Informasi Platform & Versi"><span class="footer-version-tag">Versi <?= APP_VERSION ?> (Build <?= APP_BUILD_DATE ?>)</span></a></p>
        <p style="font-size: 0.75rem; margin-top: 0.25rem;">Sistem Laporan Pemeliharaan Berkala (Preventive Maintenance) &amp; Daily Activity NOC Standar Resmi</p>
      </div>
    </footer>
  </div><!-- /.app-main-viewport -->
</div><!-- /.app-layout -->

<!-- PWA Mobile Bottom Navigation Bar (Visible on phones) -->
<nav class="mobile-bottom-nav no-print">
  <a href="./" class="mobile-nav-item <?= ($curPage === 'index.php') ? 'active' : '' ?>">
    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
    <span>Home</span>
  </a>
  <a href="daily_activity" class="mobile-nav-item <?= ($curPage === 'daily_activity.php') ? 'active' : '' ?>">
    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
    <span>Daily</span>
  </a>
  <a href="reports" class="mobile-nav-item <?= ($curPage === 'reports.php') ? 'active' : '' ?>">
    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path></svg>
    <span>Riwayat</span>
  </a>
  <a href="form_outdoor" class="mobile-nav-item <?= ($curPage === 'form_outdoor.php') ? 'active' : '' ?>">
    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
    <span>+ PM</span>
  </a>
  <?php if (function_exists('has_role') && has_role('superadmin')): ?>
    <a href="settings" class="mobile-nav-item <?= ($curPage === 'settings.php') ? 'active' : '' ?>">
      <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/></svg>
      <span>Setting</span>
    </a>
  <?php elseif (function_exists('has_role') && has_role('admin')): ?>
    <a href="users" class="mobile-nav-item <?= ($curPage === 'users.php') ? 'active' : '' ?>">
      <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
      <span>Pengguna</span>
    </a>
  <?php else: ?>
    <a href="form_indoor" class="mobile-nav-item <?= ($curPage === 'form_indoor.php') ? 'active' : '' ?>">
      <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
      <span>Indoor</span>
    </a>
  <?php endif; ?>
</nav>

<!-- Live In-App Camera Modal with Timestamp & GPS -->
<div id="camera_modal" class="camera-modal no-print" style="display: none;">
  <div class="camera-modal-content">
    
    <!-- Top Bar Controls -->
    <div class="camera-top-bar">
      <div class="camera-slot-indicator">
        <span id="camera_slot_badge">Foto</span>
      </div>
      <div style="display: flex; gap: 8px;">
        <button type="button" id="btn_toggle_grid" class="camera-ctrl-btn" title="Garis Bantu Simetris (Grid 3x3)">
          <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16v16H4zM4 10h16M4 16h16M10 4v16M16 4v16"/></svg>
        </button>
        <button type="button" id="btn_switch_camera" class="camera-ctrl-btn" title="Ganti Kamera Depan/Belakang">
          <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
        </button>
        <button type="button" id="btn_close_camera" class="camera-ctrl-btn camera-close-btn" title="Tutup Kamera">&times;</button>
      </div>
    </div>

    <!-- Live Video Viewfinder -->
    <div class="camera-viewfinder-wrapper">
      <video id="camera_video" autoplay playsinline muted></video>
      <canvas id="camera_canvas" style="display: none;"></canvas>

      <!-- Optional Rule-of-Thirds Grid Overlay -->
      <div id="camera_grid_overlay" class="camera-grid-overlay" style="display: none;">
        <div class="grid-line-v grid-line-v1"></div>
        <div class="grid-line-v grid-line-v2"></div>
        <div class="grid-line-h grid-line-h1"></div>
        <div class="grid-line-h grid-line-h2"></div>
      </div>

      <!-- Live Watermark Overlay (Realtime HUD over camera stream) -->
      <div id="live_watermark_hud" class="live-watermark-hud">
        <div class="hud-brand"><?= htmlspecialchars(get_system_setting('app_title', 'IASS SBU ICT KNO')) ?></div>
        <div id="hud_time" class="hud-line">WAKTU: -</div>
        <div id="hud_site_name" class="hud-line">SITE : -</div>
        <div id="hud_coords" class="hud-line">GPS  : Mendeteksi koordinat...</div>
        <div id="hud_slot_info" class="hud-line">FOTO : -</div>
      </div>
    </div>

    <!-- Bottom Shutter Bar -->
    <div class="camera-bottom-bar">
      <div style="width: 44px;"></div>
      <button type="button" id="btn_capture_photo" class="camera-shutter-btn" title="Ambil Foto">
        <div class="shutter-inner"></div>
      </button>
      <button type="button" id="btn_camera_gallery_fallback" class="camera-ctrl-btn" title="Pilih dari Galeri">
        <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
      </button>
    </div>

  </div>
</div>

<!-- QR Code Scanner Modal (Live Viewfinder & File Fallback) -->
<div id="qr_scanner_modal" class="qr-modal-overlay no-print" style="display: none;">
  <div class="qr-scanner-card">
    <div class="qr-scanner-header">
      <div style="display: flex; align-items: center; gap: 8px;">
        <span style="font-size: 1.25rem;">📷</span>
        <h4>Pindai QR Code Tiang / Shelter</h4>
      </div>
      <button type="button" id="btn_close_qr" style="color: #ffffff; background: none; border: none; font-size: 1.75rem; cursor: pointer; min-width: 44px; min-height: 44px; display: flex; align-items: center; justify-content: center;">&times;</button>
    </div>
    
    <div class="qr-viewfinder-container">
      <video id="qr_video" autoplay playsinline muted></video>
      <div class="qr-target-box"></div>
    </div>
    
    <div class="qr-scanner-footer">
      <p id="qr_scan_status" style="color: #94a3b8; font-size: 0.85rem; margin: 0;">
        Arahkan kamera ke stiker QR Code pada site untuk memilih otomatis...
      </p>
      <div style="display: flex; gap: 0.5rem; justify-content: center; align-items: center;">
        <label for="qr_fallback_file" class="btn btn-secondary btn-sm" style="cursor: pointer; min-height: 44px; display: inline-flex; align-items: center; gap: 6px; padding: 0.5rem 1rem;">
          📁 Unggah Foto QR
        </label>
        <input type="file" id="qr_fallback_file" accept="image/*" style="display: none;">
      </div>
    </div>
  </div>
</div>

<!-- Modal Bantuan & Panduan Pasang Aplikasi PWA -->
<div id="pwa_install_modal" class="qr-modal-overlay no-print" style="display: none; align-items: center; justify-content: center; z-index: 99999;" onclick="if(event.target === this) closePwaModal();">
  <div style="background: var(--bg-card); color: var(--text-main); border-radius: var(--radius-md); max-width: 480px; width: 92%; box-shadow: 0 20px 40px rgba(0,0,0,0.3); border: 1px solid var(--border); overflow: hidden; animation: pwaSlideUp 0.25s ease-out;">
    <div style="padding: 1rem 1.25rem; background: var(--bg-alt); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between;">
      <div style="display: flex; align-items: center; gap: 10px;">
        <span style="font-size: 1.25rem;">📱</span>
        <h4 style="margin: 0; font-size: 1rem; font-weight: 800; color: var(--text-main);">Pasang Aplikasi PWA</h4>
      </div>
      <button type="button" onclick="closePwaModal()" style="background: none; border: none; font-size: 1.5rem; color: var(--text-muted); cursor: pointer; line-height: 1; padding: 4px 8px;" title="Tutup">&times;</button>
    </div>

    <div style="padding: 1.25rem; font-size: 0.85rem; line-height: 1.6;">
      <!-- State 1: Already installed (Standalone) -->
      <div id="pwa_state_installed" style="display: none;">
        <div style="padding: 0.85rem 1rem; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: var(--radius-sm); color: #166534; margin-bottom: 1rem; display: flex; align-items: center; gap: 10px;">
          <span style="font-size: 1.3rem;">✅</span>
          <div>
            <strong>Aplikasi Sudah Terpasang!</strong>
            <div style="font-size: 0.78rem;">Sistem sedang berjalan dalam mode aplikasi mandiri (standalone) tanpa bilah peramban.</div>
          </div>
        </div>
      </div>

      <!-- State 2: Prompt Ready (Click to Install) -->
      <div id="pwa_state_prompt_ready" style="display: none; margin-bottom: 1rem;">
        <p style="margin-bottom: 0.75rem; color: var(--text-main);">
          Peramban Anda siap memasang aplikasi ke layar beranda atau desktop untuk akses cepat di lapangan.
        </p>
        <button type="button" class="btn btn-primary w-100" id="btn_modal_do_install" onclick="executeDeferredPrompt()" style="padding: 0.65rem 1rem; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 8px;">
          <?= app_icon('download', 16) ?>
          <span>Pasang Sekarang ke Perangkat</span>
        </button>
      </div>

      <!-- State 3: Apple iOS (Safari) -->
      <div id="pwa_state_ios" style="display: none;">
        <div style="padding: 0.85rem 1rem; background: #e0f2fe; border: 1px solid #bae6fd; border-radius: var(--radius-sm); color: #0369a1; margin-bottom: 1rem;">
          <strong style="display: block; margin-bottom: 4px;">Cara Pasang di iPhone / iPad (Safari):</strong>
          <ol style="margin: 0; padding-left: 1.2rem; font-size: 0.8rem; display: flex; flex-direction: column; gap: 4px;">
            <li>Buka halaman ini menggunakan peramban <strong>Safari</strong>.</li>
            <li>Ketuk tombol <strong>Bagikan / Share</strong> (ikon kotak dengan panah ke atas di bilah bawah).</li>
            <li>Gulir ke bawah dan ketuk <strong>Tambah ke Layar Utama (Add to Home Screen)</strong>.</li>
            <li>Ketuk <strong>Tambah (Add)</strong> di pojok kanan atas.</li>
          </ol>
        </div>
      </div>

      <!-- State 4: Desktop Chrome / Edge & Android Manual -->
      <div id="pwa_state_desktop" style="display: none;">
        <p style="margin-bottom: 0.5rem; color: var(--text-main); font-weight: 600;">
          Panduan Pasang Aplikasi di Peramban:
        </p>
        <ul style="margin: 0 0 1rem 0; padding-left: 1.2rem; font-size: 0.8rem; color: var(--text-subtle); display: flex; flex-direction: column; gap: 6px;">
          <li>
            <strong>Komputer / Laptop (Chrome &amp; Edge):</strong> Klik ikon pasang (komputer dengan tanda panah ke bawah) di sebelah kanan bilah alamat URL (omnibox), atau klik menu titik tiga di pojok kanan atas &gt; pilih <em>Pasang Aplikasi</em>.
          </li>
          <li>
            <strong>Ponsel Android (Chrome):</strong> Ketuk menu titik tiga (⋮) di pojok kanan atas peramban &gt; pilih <strong>Tambahkan ke Layar Utama</strong> atau <strong>Pasang / Instal Aplikasi</strong>.
          </li>
        </ul>
      </div>

      <!-- State 5: Insecure Context / Local IP Notice -->
      <div id="pwa_state_insecure" style="display: none;">
        <div style="padding: 0.85rem 1rem; background: #fffbeb; border: 1px solid #fde68a; border-radius: var(--radius-sm); color: #92400e; margin-bottom: 1rem; font-size: 0.78rem;">
          <strong>Akses Melalui IP Jaringan Lokal (Non-HTTPS):</strong>
          <div style="margin-top: 4px;">
            Kebijakan keamanan Google Chrome dan Android mewajibkan koneksi aman (<strong>HTTPS</strong>) atau <strong>http://localhost</strong> agar banner instalasi otomatis diizinkan. Jika mengakses lewat IP (misal: <code>http://192.168.x.x</code>), pasang sertifikat SSL atau buka langsung di komputer server.
          </div>
        </div>
      </div>

      <!-- Reset Dismiss History -->
      <div style="border-top: 1px solid var(--border); padding-top: 0.85rem; margin-top: 0.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
        <span style="font-size: 0.75rem; color: var(--text-subtle);">Pernah menutup banner sebelumnya?</span>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetPwaBanner()" style="font-size: 0.75rem; padding: 0.3rem 0.7rem;">
          <?= app_icon('refresh', 13) ?> Reset Notifikasi Banner
        </button>
      </div>
    </div>
  </div>
</div>

<script>
  // Top-level early capture for PWA Install Prompt
  window.deferredPrompt = window.deferredPrompt || null;
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    window.deferredPrompt = e;
    var topbarBtn = document.getElementById('btn_topbar_install_pwa');
    var sidebarBtn = document.getElementById('sidebar_pwa_item');
    var promptReadyState = document.getElementById('pwa_state_prompt_ready');
    if (topbarBtn) topbarBtn.style.display = 'inline-flex';
    if (sidebarBtn) sidebarBtn.style.display = 'block';
    if (promptReadyState) promptReadyState.style.display = 'block';
  });

  window.triggerPwaInstall = async function () {
    if (window.deferredPrompt) {
      try {
        window.deferredPrompt.prompt();
        var choice = await window.deferredPrompt.userChoice;
        if (choice && choice.outcome === 'accepted') {
          window.deferredPrompt = null;
          var banner = document.getElementById('pwa_install_banner');
          if (banner) banner.style.display = 'none';
          if (window.closePwaModal) window.closePwaModal();
        }
        return;
      } catch (err) {
        console.warn('Deferred prompt error:', err);
      }
    }

    var modalEl = document.getElementById('pwa_install_modal');
    if (!modalEl) {
      alert('Panduan: Buka menu titik tiga pada browser Anda, lalu pilih "Install Aplikasi" atau "Tambahkan ke Layar Utama".');
      return;
    }

    var isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    var isIos = /iphone|ipad|ipod/.test(window.navigator.userAgent.toLowerCase());
    var isSecure = window.isSecureContext || window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';

    var stateInstalled = document.getElementById('pwa_state_installed');
    var statePromptReady = document.getElementById('pwa_state_prompt_ready');
    var stateIos = document.getElementById('pwa_state_ios');
    var stateInsecure = document.getElementById('pwa_state_insecure');
    var stateDesktop = document.getElementById('pwa_state_desktop');

    if (stateInstalled) stateInstalled.style.display = isStandalone ? 'block' : 'none';
    if (statePromptReady) statePromptReady.style.display = (!isStandalone && window.deferredPrompt) ? 'block' : 'none';
    if (stateIos) stateIos.style.display = (!isStandalone && isIos) ? 'block' : 'none';
    if (stateInsecure) stateInsecure.style.display = (!isStandalone && !isIos && !isSecure) ? 'block' : 'none';
    if (stateDesktop) stateDesktop.style.display = (!isStandalone && !isIos) ? 'block' : 'none';

    modalEl.style.display = 'flex';
  };

  window.closePwaModal = function () {
    var modalEl = document.getElementById('pwa_install_modal');
    if (modalEl) modalEl.style.display = 'none';
  };

  window.executeDeferredPrompt = async function () {
    if (window.deferredPrompt) {
      window.deferredPrompt.prompt();
      try {
        var choice = await window.deferredPrompt.userChoice;
        if (choice && choice.outcome === 'accepted') {
          window.deferredPrompt = null;
          var banner = document.getElementById('pwa_install_banner');
          if (banner) banner.style.display = 'none';
          closePwaModal();
        }
      } catch (e) {
        console.warn('PWA execution choice error:', e);
      }
    } else {
      alert('Gunakan menu browser (titik tiga) di pojok kanan atas, lalu pilih "Install Aplikasi" atau "Tambahkan ke Layar Utama".');
    }
  };

  window.resetPwaBanner = function () {
    localStorage.removeItem('pwa_prompt_dismissed');
    localStorage.removeItem('pwa_prompt_dismissed_at');
    var banner = document.getElementById('pwa_install_banner');
    var isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    if (banner && !isStandalone) banner.style.display = 'flex';
    closePwaModal();
    alert('Notifikasi banner pasang PWA telah direset dan diaktifkan kembali.');
  };

  window.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      window.closePwaModal();
    }
  });
</script>

<script src="assets/js/qrious.min.js"></script>
<script src="assets/js/html2pdf.bundle.min.js"></script>
<script src="assets/js/draft_manager.js?v=<?= filemtime(__DIR__ . '/../assets/js/draft_manager.js') ?>"></script>
<script src="assets/js/qr_scanner.js?v=<?= filemtime(__DIR__ . '/../assets/js/qr_scanner.js') ?>"></script>
<script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>_v10"></script>
<script>


  // Register Service Worker for PWA
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js?v=12')
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
