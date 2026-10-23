<?php
/**
 * Auto-Optimise & Pemeliharaan Sistem
 * PT Angkasa Pura Sarana Digital - Sistem PM
 *
 * Khusus Superadmin: Mengoptimasi database, membersihkan temporary storage,
 * menganalisis rasio kompresi brankas dokumen, dan memonitor kesehatan server.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/icons.php';

// Strict RBAC: Hanya Superadmin yang berwenang
require_role('superadmin');

$pdo = get_db_connection();
$currentUser = current_user();
$page_title = 'Auto-Optimise & Pemeliharaan Sistem';

// Helper format bytes
function format_bytes_sys(int $bytes): string {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = (int)floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

// 1. Gather Database Metrics & Auto-Repair Status
$dbDriver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
$repairHealth = auto_repair_database($pdo, false);
$dbSizeFormatted = '-';
$dbPath = __DIR__ . '/database/apsd.sqlite';
$isWal = false;

if ($dbDriver === 'sqlite') {
    if (file_exists($dbPath)) {
        $dbSizeFormatted = format_bytes_sys((int)filesize($dbPath));
    }
    try {
        $walCheck = $pdo->query("PRAGMA journal_mode;")->fetchColumn();
        $isWal = (strtolower((string)$walCheck) === 'wal');
    } catch (Exception $e) {}
} else {
    try {
        $q = $pdo->query("
            SELECT SUM(data_length + index_length) 
            FROM information_schema.TABLES 
            WHERE table_schema = DATABASE()
        ");
        $dbSizeFormatted = format_bytes_sys((int)$q->fetchColumn());
    } catch (Exception $e) {
        $dbSizeFormatted = 'Terkoneksi (MySQL)';
    }
}

// 2. Gather Document Vault Metrics
$docCount = 0;
$rawSizeTotal = 0;
$compSizeTotal = 0;
try {
    $qDocs = $pdo->query("SELECT COUNT(*) as c, SUM(file_size_raw) as raw_sz, SUM(file_size_compressed) as comp_sz FROM user_documents")->fetch();
    if ($qDocs) {
        $docCount = (int)($qDocs['c'] ?? 0);
        $rawSizeTotal = (int)($qDocs['raw_sz'] ?? 0);
        $compSizeTotal = (int)($qDocs['comp_sz'] ?? 0);
    }
} catch (Exception $e) {}

$savedVaultBytes = ($rawSizeTotal > $compSizeTotal) ? ($rawSizeTotal - $compSizeTotal) : 0;
$savedVaultPct = ($rawSizeTotal > 0) ? round(($savedVaultBytes / $rawSizeTotal) * 100, 1) : 0;

// 3. Gather Temp Storage Metrics
$tempDir = __DIR__ . '/uploads/temp';
$tempFilesCount = 0;
$tempFilesSize = 0;
if (is_dir($tempDir)) {
    $scanned = scandir($tempDir);
    foreach ($scanned as $f) {
        if ($f === '.' || $f === '..' || $f === '.gitignore' || $f === '.htaccess') continue;
        $fp = $tempDir . '/' . $f;
        if (is_file($fp)) {
            $tempFilesCount++;
            $tempFilesSize += (int)filesize($fp);
        }
    }
}

// 4. Gather External Shares Metrics
$activeShares = 0;
$expiredShares = 0;
try {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $activeShares = (int)$pdo->query("
            SELECT COUNT(*) FROM user_document_external_shares 
            WHERE is_active = 1 AND (expires_at IS NULL OR expires_at > datetime('now', 'localtime'))
        ")->fetchColumn();
        $expiredShares = (int)$pdo->query("
            SELECT COUNT(*) FROM user_document_external_shares 
            WHERE is_active = 0 OR (expires_at IS NOT NULL AND expires_at <= datetime('now', 'localtime'))
        ")->fetchColumn();
    } else {
        $activeShares = (int)$pdo->query("
            SELECT COUNT(*) FROM user_document_external_shares 
            WHERE is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())
        ")->fetchColumn();
        $expiredShares = (int)$pdo->query("
            SELECT COUNT(*) FROM user_document_external_shares 
            WHERE is_active = 0 OR (expires_at IS NOT NULL AND expires_at <= NOW())
        ")->fetchColumn();
    }
} catch (Exception $e) {}

// 5. Fetch Recent System Optimizations
$recentOptimizations = [];
try {
    $stmtOpt = $pdo->query("
        SELECT * FROM audit_logs 
        WHERE action_type = 'SYSTEM' 
        ORDER BY id DESC LIMIT 6
    ");
    $recentOptimizations = $stmtOpt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

include __DIR__ . '/includes/header.php';
?>

<div class="container py-4">

  <!-- Breadcrumb & Top Bar -->
  <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem;">
    <div>
      <div style="font-size: 0.8rem; color: var(--text-subtle); display: flex; align-items: center; gap: 6px;">
        <a href="./" style="color: inherit; text-decoration: none;">Dashboard</a>
        <span>/</span>
        <span style="color: var(--primary); font-weight: 600;">Auto-Optimise</span>
      </div>
      <h2 style="font-size: 1.45rem; font-weight: 800; color: var(--text-main); margin-top: 0.25rem; letter-spacing: -0.02em; display: flex; align-items: center; gap: 8px;">
        <?= app_icon('refresh', 22, 'text-primary') ?>
        <span>Auto-Optimise &amp; Pemeliharaan Sistem</span>
        <span class="badge" style="font-size: 0.68rem; font-weight: 700; background: rgba(16, 185, 129, 0.12); color: #059669; border: 1px solid rgba(16, 185, 129, 0.25); padding: 2px 7px; border-radius: 6px;">Status: Optimal</span>
      </h2>
      <p style="font-size: 0.82rem; color: var(--text-subtle); margin: 0.2rem 0 0 0;">
        Pusat pemeliharaan basis data, defragmentasi storage, pembersihan berkas sementara, dan analisis kompresi brankas dokumen.
      </p>
    </div>

    <!-- Quick Action Buttons -->
    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
      <a href="audit_log" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;" title="Buka Rekam Jejak Audit Log">
        <?= app_icon('shield', 14) ?>
        <span>Buka Audit Log</span>
      </a>
      <a href="system_optimize" class="btn btn-primary-soft btn-sm" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;" title="Segarkan Data Metrik">
        <?= app_icon('refresh', 14) ?>
        <span>Segarkan</span>
      </a>
    </div>
  </div>

  <!-- Summary Statistics Grid -->
  <div class="stats-grid mb-4" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    <!-- Stat 1: Database Size -->
    <div class="card stat-filter-card" style="margin-bottom: 0; border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden; background: var(--bg-card);">
      <div class="card-body" style="display: flex; align-items: center; justify-content: space-between; padding: 1.15rem 1.25rem;">
        <div>
          <span style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; letter-spacing: 0.03em;">Kapasitas Basis Data</span>
          <p style="font-size: 1.65rem; font-weight: 800; color: var(--primary); margin: 0.2rem 0 0.15rem 0;"><?= $dbSizeFormatted ?></p>
          <span style="font-size: 0.72rem; color: var(--text-subtle);"><?= strtoupper($dbDriver) ?><?= $isWal ? ' (WAL)' : '' ?> &bull; <?= $repairHealth['existing_count'] ?>/<?= $repairHealth['total_required'] ?> Tabel Utuh</span>
        </div>
        <div class="stat-icon-wrapper" style="width: 44px; height: 44px; border-radius: 12px; background: var(--primary-subtle); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
          <?= app_icon('archive', 22) ?>
        </div>
      </div>
    </div>

    <!-- Stat 2: Vault Compression Savings -->
    <div class="card stat-filter-card" style="margin-bottom: 0; border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden; background: var(--bg-card);">
      <div class="card-body" style="display: flex; align-items: center; justify-content: space-between; padding: 1.15rem 1.25rem;">
        <div>
          <span style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; letter-spacing: 0.03em;">Hemat Kompresi Vault</span>
          <p style="font-size: 1.65rem; font-weight: 800; color: #059669; margin: 0.2rem 0 0.15rem 0;"><?= format_bytes_sys($savedVaultBytes) ?></p>
          <span style="font-size: 0.72rem; color: var(--text-subtle);"><?= $savedVaultPct ?>% hemat dari <?= number_format($docCount) ?> dokumen</span>
        </div>
        <div class="stat-icon-wrapper" style="width: 44px; height: 44px; border-radius: 12px; background: #dcfce7; color: #16a34a; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
          <?= app_icon('folder', 22) ?>
        </div>
      </div>
    </div>

    <!-- Stat 3: Temporary Files -->
    <div class="card stat-filter-card" style="margin-bottom: 0; border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden; background: var(--bg-card);">
      <div class="card-body" style="display: flex; align-items: center; justify-content: space-between; padding: 1.15rem 1.25rem;">
        <div>
          <span style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; letter-spacing: 0.03em;">Berkas Sementara (Temp)</span>
          <p style="font-size: 1.65rem; font-weight: 800; color: #d97706; margin: 0.2rem 0 0.15rem 0;"><?= number_format($tempFilesCount) ?> file</p>
          <span style="font-size: 0.72rem; color: var(--text-subtle);">Kapasitas: <?= format_bytes_sys($tempFilesSize) ?></span>
        </div>
        <div class="stat-icon-wrapper" style="width: 44px; height: 44px; border-radius: 12px; background: #fef3c7; color: #d97706; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
          <?= app_icon('trash', 22) ?>
        </div>
      </div>
    </div>

    <!-- Stat 4: External Shares -->
    <div class="card stat-filter-card" style="margin-bottom: 0; border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden; background: var(--bg-card);">
      <div class="card-body" style="display: flex; align-items: center; justify-content: space-between; padding: 1.15rem 1.25rem;">
        <div>
          <span style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; letter-spacing: 0.03em;">Tautan Berbagi Publik</span>
          <p style="font-size: 1.65rem; font-weight: 800; color: #0284c7; margin: 0.2rem 0 0.15rem 0;"><?= number_format($activeShares) ?> aktif</p>
          <span style="font-size: 0.72rem; color: var(--text-subtle);"><?= number_format($expiredShares) ?> kadaluwarsa / nonaktif</span>
        </div>
        <div class="stat-icon-wrapper" style="width: 44px; height: 44px; border-radius: 12px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
          <?= app_icon('share', 22) ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Main Execution Card -->
  <div class="card" style="border: 1px solid var(--border); border-radius: var(--radius-lg); background: var(--bg-card); overflow: hidden; box-shadow: var(--shadow-sm); margin-bottom: 1.5rem;">
    <div style="padding: 1.25rem 1.5rem; background: var(--bg-alt); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
      <div>
        <h3 style="font-size: 1.15rem; font-weight: 800; color: var(--text-main); margin-bottom: 4px;">
          Eksekusi Pemeliharaan &amp; Auto-Optimise Seketika
        </h3>
        <p style="font-size: 0.82rem; color: var(--text-subtle); margin: 0; max-width: 720px;">
          Lakukan defragmentasi basis data, pemangkasan WAL log, pembersihan file temp lama, dan penonaktifan tautan dokumen yang telah expired secara terpusat.
        </p>
      </div>

      <div>
        <button id="btn_run_optimize" class="btn btn-primary" style="font-size: 0.9rem; font-weight: 700; padding: 0.65rem 1.25rem; display: inline-flex; align-items: center; gap: 8px;">
          <span id="btn_opt_icon"><?= app_icon('refresh', 16) ?></span>
          <span id="btn_opt_text">Jalankan Auto-Optimise Sekarang</span>
        </button>
      </div>
    </div>

    <div style="padding: 1.25rem 1.5rem;">
      <!-- Operations Checklist -->
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; margin-bottom: 1.25rem;">
        <div style="padding: 10px 14px; border-radius: var(--radius-md); background: var(--bg-alt); border: 1px solid var(--border); display: flex; align-items: flex-start; gap: 8px;">
          <span style="color: #059669; margin-top: 2px; flex-shrink: 0;"><?= app_icon('shield', 16) ?></span>
          <div style="font-size: 0.82rem;">
            <strong style="color: var(--text-main); display: block;">Auto-Repair &amp; Pemulihan Skema</strong>
            <span style="color: var(--text-subtle);">Mendeteksi &amp; membuat ulang otomatis jika tabel terhapus, memperbaiki kerusakan tabel (CHECK/REPAIR TABLE), serta memastikan seluruh kolom mutakhir.</span>
          </div>
        </div>

        <div style="padding: 10px 14px; border-radius: var(--radius-md); background: var(--bg-alt); border: 1px solid var(--border); display: flex; align-items: flex-start; gap: 8px;">
          <span style="color: #059669; margin-top: 2px; flex-shrink: 0;"><?= app_icon('check-circle', 16) ?></span>
          <div style="font-size: 0.82rem;">
            <strong style="color: var(--text-main); display: block;">Defragmentasi Basis Data</strong>
            <span style="color: var(--text-subtle);"><?= $dbDriver === 'mysql' ? 'Menjalankan MySQL OPTIMIZE TABLE untuk defragmentasi tabel dan reorganisasi indeks.' : 'Menjalankan SQLite VACUUM &amp; WAL truncate untuk mengembalikan ruang kosong fisik disk.' ?></span>
          </div>
        </div>

        <div style="padding: 10px 14px; border-radius: var(--radius-md); background: var(--bg-alt); border: 1px solid var(--border); display: flex; align-items: flex-start; gap: 8px;">
          <span style="color: #059669; margin-top: 2px; flex-shrink: 0;"><?= app_icon('check-circle', 16) ?></span>
          <div style="font-size: 0.82rem;">
            <strong style="color: var(--text-main); display: block;">Pembersihan File Temp (&gt; 24 Jam)</strong>
            <span style="color: var(--text-subtle);">Menghapus potongan berkas sementara dan file test sisa pengunggahan lampau.</span>
          </div>
        </div>

        <div style="padding: 10px 14px; border-radius: var(--radius-md); background: var(--bg-alt); border: 1px solid var(--border); display: flex; align-items: flex-start; gap: 8px;">
          <span style="color: #059669; margin-top: 2px; flex-shrink: 0;"><?= app_icon('check-circle', 16) ?></span>
          <div style="font-size: 0.82rem;">
            <strong style="color: var(--text-main); display: block;">Pengarsipan Tautan Eksternal</strong>
            <span style="color: var(--text-subtle);">Menonaktifkan token tautan publik yang telah melewati masa berlaku secara otomatis.</span>
          </div>
        </div>

        <div style="padding: 10px 14px; border-radius: var(--radius-md); background: var(--bg-alt); border: 1px solid var(--border); display: flex; align-items: flex-start; gap: 8px;">
          <span style="color: #059669; margin-top: 2px; flex-shrink: 0;"><?= app_icon('check-circle', 16) ?></span>
          <div style="font-size: 0.82rem;">
            <strong style="color: var(--text-main); display: block;">Pencatatan Audit Trail</strong>
            <span style="color: var(--text-subtle);">Seluruh hasil optimasi dan durasi eksekusi dicatat permanen dalam Audit Log.</span>
          </div>
        </div>
      </div>

      <!-- Live Terminal / Console Box -->
      <div id="optimize_console_wrapper" style="display: none;">
        <div style="font-size: 0.82rem; font-weight: 700; color: var(--text-main); margin-bottom: 6px; display: flex; align-items: center; justify-content: space-between;">
          <span>Log Eksekusi Real-time:</span>
          <span id="opt_time_badge" class="badge" style="background: var(--primary-subtle); color: var(--primary); font-size: 0.75rem; font-weight: 700; padding: 3px 8px; border-radius: 6px;">0 ms</span>
        </div>
        <div id="optimize_console" style="background: #0f172a; color: #38bdf8; font-family: 'Consolas', 'Courier New', monospace; font-size: 0.82rem; padding: 1rem 1.25rem; border-radius: 8px; min-height: 120px; max-height: 260px; overflow-y: auto; line-height: 1.6; border: 1px solid #1e293b;">
        </div>
      </div>
    </div>
  </div>

  <!-- Bottom Grid: Background Automation & Server Environment -->
  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    <!-- Automation Card -->
    <div class="card" style="margin-bottom: 0; border: 1px solid var(--border); border-radius: var(--radius-lg); background: var(--bg-card); padding: 1.25rem; box-shadow: var(--shadow-sm);">
      <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
        <span style="color: #0284c7;"><?= app_icon('clock', 18) ?></span>
        <h4 style="font-size: 0.96rem; font-weight: 800; color: var(--text-main); margin: 0;">
          Penjadwalan Otomatis (Task Scheduler &amp; Cron)
        </h4>
      </div>
      <p style="font-size: 0.82rem; color: var(--text-subtle); margin-bottom: 1rem;">
        Jalankan script CLI <code style="font-size: 0.8rem; background: var(--bg-alt); padding: 2px 6px; border-radius: 4px; border: 1px solid var(--border);">cron_optimize.php</code> secara terjadwal (misal: setiap Minggu pukul 02:00 WIB) agar sistem tetap bersih secara otomatis.
      </p>

      <div style="background: var(--bg-alt); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 0.85rem 1rem; margin-bottom: 0.85rem;">
        <div style="font-size: 0.72rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; margin-bottom: 4px;">
          Windows Task Scheduler (XAMPP):
        </div>
        <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
          <code id="cmd_windows" style="font-size: 0.78rem; color: var(--text-main); word-break: break-all;">
            C:\xampp\php\php.exe <?= htmlspecialchars(__DIR__) ?>\cron_optimize.php
          </code>
          <button class="btn btn-sm btn-outline-secondary" onclick="copyText('cmd_windows')" style="padding: 2px 10px; font-size: 0.75rem; flex-shrink: 0;" title="Salin Perintah">
            Salin
          </button>
        </div>
      </div>

      <div style="background: var(--bg-alt); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 0.85rem 1rem;">
        <div style="font-size: 0.72rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; margin-bottom: 4px;">
          Linux Crontab (Server / Docker):
        </div>
        <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
          <code id="cmd_linux" style="font-size: 0.78rem; color: var(--text-main); word-break: break-all;">
            0 2 * * 0 docker compose exec -T web php cron_optimize.php &gt; /dev/null 2&gt;&amp;1
          </code>
          <button class="btn btn-sm btn-outline-secondary" onclick="copyText('cmd_linux')" style="padding: 2px 10px; font-size: 0.75rem; flex-shrink: 0;" title="Salin Perintah">
            Salin
          </button>
        </div>
      </div>
    </div>

    <!-- Server Specs Card -->
    <div class="card" style="margin-bottom: 0; border: 1px solid var(--border); border-radius: var(--radius-lg); background: var(--bg-card); padding: 1.25rem; box-shadow: var(--shadow-sm);">
      <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
        <span style="color: #9333ea;"><?= app_icon('settings', 18) ?></span>
        <h4 style="font-size: 0.96rem; font-weight: 800; color: var(--text-main); margin: 0;">
          Informasi Lingkungan Server
        </h4>
      </div>

      <ul style="list-style: none; padding: 0; margin: 0; font-size: 0.82rem; line-height: 2.1;">
        <li style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--border); padding-bottom: 4px; margin-bottom: 4px;">
          <span style="color: var(--text-subtle);">Versi PHP:</span>
          <strong style="color: var(--text-main);"><?= PHP_VERSION ?></strong>
        </li>
        <li style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--border); padding-bottom: 4px; margin-bottom: 4px;">
          <span style="color: var(--text-subtle);">Sistem Operasi:</span>
          <strong style="color: var(--text-main);"><?= PHP_OS_FAMILY ?> (<?= php_uname('s') ?>)</strong>
        </li>
        <li style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--border); padding-bottom: 4px; margin-bottom: 4px;">
          <span style="color: var(--text-subtle);">Driver Database:</span>
          <strong style="color: var(--text-main);"><?= strtoupper($dbDriver) ?><?= $isWal ? ' (WAL Mode)' : '' ?></strong>
        </li>
        <li style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--border); padding-bottom: 4px; margin-bottom: 4px;">
          <span style="color: var(--text-subtle);">Memory Limit:</span>
          <strong style="color: var(--text-main);"><?= ini_get('memory_limit') ?></strong>
        </li>
        <li style="display: flex; justify-content: space-between;">
          <span style="color: var(--text-subtle);">Max Upload Size:</span>
          <strong style="color: var(--text-main);"><?= ini_get('upload_max_filesize') ?></strong>
        </li>
      </ul>
    </div>
  </div>

  <!-- Riwayat Optimasi Terakhir -->
  <div class="card" style="border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; background: var(--bg-card); box-shadow: var(--shadow-sm); margin-bottom: 1.5rem;">
    <div style="padding: 0.85rem 1.25rem; background: var(--bg-alt); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
      <h4 style="font-size: 0.88rem; font-weight: 700; color: var(--text-main); margin: 0;">
        Riwayat Pemeliharaan Sistem Terakhir
      </h4>
      <a href="audit_log?action_type=SYSTEM" style="font-size: 0.78rem; font-weight: 600; color: var(--primary); text-decoration: none;">
        Lihat Semua di Audit Log &raquo;
      </a>
    </div>

    <div class="table-responsive" style="margin: 0;">
      <table class="table table-hover align-middle" style="margin: 0; font-size: 0.84rem;">
        <thead style="background: var(--bg-alt); border-bottom: 1px solid var(--border);">
          <tr>
            <th style="width: 170px; color: var(--text-subtle); font-weight: 700;">Waktu Eksekusi</th>
            <th style="width: 140px; color: var(--text-subtle); font-weight: 700;">Eksekutor</th>
            <th style="width: 170px; color: var(--text-subtle); font-weight: 700;">Peristiwa</th>
            <th style="color: var(--text-subtle); font-weight: 700;">Ringkasan Hasil</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recentOptimizations)): ?>
            <tr>
              <td colspan="4" style="text-align: center; padding: 2rem; color: var(--text-subtle);">
                Belum ada catatan riwayat pemeliharaan sistem.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($recentOptimizations as $opt): ?>
              <tr>
                <td style="font-weight: 700; color: var(--text-main);">
                  <?= date('d/m/Y H:i:s', strtotime($opt['created_at'])) ?>
                </td>
                <td>
                  <span class="badge" style="background: var(--primary-subtle); color: var(--primary); font-weight: 700; padding: 2px 8px; border-radius: 4px;">
                    <?= htmlspecialchars($opt['username'] ?? 'Superadmin') ?>
                  </span>
                </td>
                <td>
                  <?= format_audit_badge($opt['action_type'], $opt['action_name']) ?>
                </td>
                <td style="color: var(--text-main); font-weight: 500;">
                  <?= htmlspecialchars($opt['description'] ?? '-') ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<script>
document.getElementById('btn_run_optimize').addEventListener('click', function() {
  const btn = this;
  const icon = document.getElementById('btn_opt_icon');
  const text = document.getElementById('btn_opt_text');
  const consoleWrap = document.getElementById('optimize_console_wrapper');
  const consoleBox = document.getElementById('optimize_console');
  const badge = document.getElementById('opt_time_badge');

  if (btn.disabled) return;
  if (!confirm('Jalankan proses Auto-Optimise basis data dan pemeliharaan storage sekarang?')) {
    return;
  }

  btn.disabled = true;
  icon.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true" style="width: 14px; height: 14px; border-width: 2px;"></span>';
  text.textContent = 'Sedang Mengoptimasi Sistem...';

  consoleWrap.style.display = 'block';
  consoleBox.innerHTML = '<div style="color: #94a3b8;">[' + new Date().toLocaleTimeString() + '] Memulai inisialisasi tugas pemeliharaan...</div>';

  fetch('api/run_system_optimize.php', {
    method: 'POST',
    headers: {
      'Accept': 'application/json'
    }
  })
  .then(async (res) => {
    const rawText = await res.text();
    let data;
    try {
      data = JSON.parse(rawText);
    } catch (parseErr) {
      const cleanSnippet = rawText.replace(/<[^>]*>?/gm, ' ').replace(/\s+/g, ' ').trim().substring(0, 300);
      throw new Error(cleanSnippet || ('HTTP ' + res.status + ' ' + res.statusText));
    }
    return data;
  })
  .then(data => {
    btn.disabled = false;
    icon.innerHTML = '<?= addslashes(app_icon('refresh', 16)) ?>';
    text.textContent = 'Jalankan Auto-Optimise Sekarang';

    if (data.success) {
      badge.textContent = data.execution_time_ms + ' ms';
      badge.style.background = 'rgba(16, 185, 129, 0.15)';
      badge.style.color = '#059669';

      let out = '<div style="color: #4ade80; font-weight: bold;">[' + new Date().toLocaleTimeString() + '] ' + data.message + '</div>';
      
      if (Array.isArray(data.steps)) {
        data.steps.forEach((s, idx) => {
          out += '<div style="margin-top: 6px; color: #f8fafc;"><strong>' + (idx + 1) + '. ' + s.name + '</strong></div>';
          out += '<div style="color: #cbd5e1; padding-left: 14px;">&bull; ' + s.detail + '</div>';
        });
      }

      out += '<div style="margin-top: 8px; color: #38bdf8; font-weight: bold;">[Ringkasan] Total kapasitas dibebaskan: ' + data.freed_formatted + ' | Selesai dalam ' + data.execution_time_ms + ' ms.</div>';
      consoleBox.innerHTML = out;

      alert('Auto-Optimise sistem selesai dieksekusi dengan sukses!\nTotal kapasitas dibebaskan: ' + data.freed_formatted);
      setTimeout(() => {
        window.location.reload();
      }, 1500);
    } else {
      badge.textContent = 'Gagal';
      badge.style.background = 'rgba(239, 68, 68, 0.15)';
      badge.style.color = '#dc2626';
      consoleBox.innerHTML += '<div style="color: #f87171; font-weight: bold; margin-top: 8px;">[' + new Date().toLocaleTimeString() + '] Gagal: ' + (data.message || 'Terjadi kesalahan sistem') + '</div>';
      alert('Gagal menjalankan optimasi: ' + (data.message || 'Terjadi kesalahan sistem'));
    }
  })
  .catch(err => {
    btn.disabled = false;
    icon.innerHTML = '<?= addslashes(app_icon('refresh', 16)) ?>';
    text.textContent = 'Jalankan Auto-Optimise Sekarang';
    consoleBox.innerHTML += '<div style="color: #f87171; font-weight: bold; margin-top: 8px;">[Error Jaringan] ' + err.message + '</div>';
    alert('Terjadi kesalahan koneksi atau server: ' + err.message);
  });
});

function copyText(elementId) {
  const el = document.getElementById(elementId);
  if (!el) return;
  const text = el.textContent.trim();
  navigator.clipboard.writeText(text).then(() => {
    alert('Perintah berhasil disalin ke clipboard:\n' + text);
  }).catch(() => {
    alert('Silakan salin teks secara manual:\n' + text);
  });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
