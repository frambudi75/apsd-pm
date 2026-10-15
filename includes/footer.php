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
    <span>Dashboard</span>
  </a>
  <a href="form_indoor.php" class="mobile-nav-item <?= ($curPage === 'form_indoor.php') ? 'active' : '' ?>">
    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg>
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

<script src="assets/js/app.js"></script>
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
