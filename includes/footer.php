<?php
/**
 * Shared Application Footer with Mobile PWA Navigation
 * PT Angkasa Pura Sarana Digital
 */
$curPage = basename($_SERVER['PHP_SELF'] ?? 'index.php');
?>
<footer class="no-print desktop-footer">
  <div class="container">
    <p>&copy; <?= date('Y') ?> PT Angkasa Pura Sarana Digital / PT IAS Support Indonesia. All rights reserved.</p>
    <p style="font-size: 0.75rem; margin-top: 0.25rem;">Sistem Laporan Pemeliharaan Berkala (Preventive Maintenance) Standar Resmi</p>
  </div>
</footer>

<!-- PWA Mobile Bottom Navigation Bar (Visible on phones) -->
<nav class="mobile-bottom-nav no-print">
  <a href="index.php" class="mobile-nav-item <?= ($curPage === 'index.php') ? 'active' : '' ?>">
    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
    <span>Home</span>
  </a>
  <a href="reports.php" class="mobile-nav-item <?= ($curPage === 'reports.php') ? 'active' : '' ?>">
    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path></svg>
    <span>Riwayat</span>
  </a>
  <a href="form_indoor.php" class="mobile-nav-item <?= ($curPage === 'form_indoor.php') ? 'active' : '' ?>">
    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
    <span>+ Indoor</span>
  </a>
  <a href="form_outdoor.php" class="mobile-nav-item <?= ($curPage === 'form_outdoor.php') ? 'active' : '' ?>">
    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
    <span>+ Outdoor</span>
  </a>
  <a href="sites.php" class="mobile-nav-item <?= ($curPage === 'sites.php') ? 'active' : '' ?>">
    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
    <span>Sites</span>
  </a>
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

      <!-- Live Watermark Overlay (Realtime HUD over camera stream) -->
      <div id="live_watermark_hud" class="live-watermark-hud">
        <div class="hud-brand">PT ANGKASA PURA SARANA DIGITAL</div>
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

<script src="assets/js/qrious.min.js"></script>
<script src="assets/js/html2pdf.bundle.min.js"></script>
<script src="assets/js/draft_manager.js?v=<?= filemtime(__DIR__ . '/../assets/js/draft_manager.js') ?>"></script>
<script src="assets/js/qr_scanner.js?v=<?= filemtime(__DIR__ . '/../assets/js/qr_scanner.js') ?>"></script>
<script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>_v9"></script>
<script>
  // Register Service Worker for PWA
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js')
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
