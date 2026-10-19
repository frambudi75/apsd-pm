<?php
/**
 * Cadangan & Pemulihan Sistem (Disaster Recovery Vault)
 * PT Angkasa Pura Sarana Digital - Sistem PM
 *
 * Khusus Superadmin: Mengontrol pencadangan basis data, berkas unggahan,
 * pengiriman otomatis ke Discord dalam bentuk batch, serta riwayat cadangan lokal.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/backup_helper.php';
require_once __DIR__ . '/includes/icons.php';

// Strict RBAC: Hanya Superadmin yang berwenang
require_role('superadmin');

$pdo = get_db_connection();
$page_title = 'Cadangan & Pemulihan Sistem';

// 1. Metrik Basis Data
$dbDriver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
$dbSizeFormatted = '-';
if ($dbDriver === 'sqlite') {
    $dbPath = __DIR__ . '/database/apsd.sqlite';
    if (file_exists($dbPath)) {
        $dbSizeFormatted = format_backup_bytes((int)filesize($dbPath));
    }
} else {
    try {
        $q = $pdo->query("SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()");
        $dbSizeFormatted = format_backup_bytes((int)$q->fetchColumn());
    } catch (Exception $e) {
        $dbSizeFormatted = 'Terkoneksi (MySQL)';
    }
}

// 2. Metrik Berkas Unggahan (Storage)
$uploadsDir = __DIR__ . '/uploads';
$uploadsCount = 0;
$uploadsTotalBytes = 0;

function scan_uploads_stats(string $dir, int &$count, int &$bytes): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $it) {
        if ($it === '.' || $it === '..' || $it === '.gitignore' || $it === '.htaccess' || $it === 'temp') continue;
        $p = $dir . '/' . $it;
        if (is_dir($p)) {
            scan_uploads_stats($p, $count, $bytes);
        } elseif (is_file($p)) {
            $count++;
            $bytes += (int)filesize($p);
        }
    }
}
scan_uploads_stats($uploadsDir, $uploadsCount, $uploadsTotalBytes);
$uploadsSizeFormatted = format_backup_bytes($uploadsTotalBytes);

// 3. Status Webhook Discord
$securityWebhook = get_system_setting('discord_webhook_security', '');
$isDiscordReady = !empty($securityWebhook) && filter_var($securityWebhook, FILTER_VALIDATE_URL);

// 4. Riwayat Berkas Cadangan Lokal
$backups = get_backup_history();
$totalBackupFiles = count($backups);
$totalBackupStorage = 0;
foreach ($backups as $b) {
    $totalBackupStorage += $b['size'];
}
$totalBackupStorageFormatted = format_backup_bytes($totalBackupStorage);

include __DIR__ . '/includes/header.php';
?>

<div class="container py-4">

  <!-- Breadcrumb & Top Bar -->
  <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem;">
    <div>
      <div style="font-size: 0.8rem; color: var(--text-subtle); display: flex; align-items: center; gap: 6px;">
        <a href="./" style="color: inherit; text-decoration: none;">Dashboard</a>
        <span>/</span>
        <span style="color: var(--primary); font-weight: 600;">Cadangan Sistem</span>
      </div>
      <h2 style="font-size: 1.45rem; font-weight: 800; color: var(--text-main); margin-top: 0.25rem; letter-spacing: -0.02em; display: flex; align-items: center; gap: 8px;">
        <?= app_icon('package', 22, 'text-primary') ?>
        <span>Pusat Cadangan &amp; Pemulihan Sistem</span>
        <span class="badge" style="font-size: 0.68rem; font-weight: 700; background: rgba(14, 165, 233, 0.12); color: #0284c7; border: 1px solid rgba(14, 165, 233, 0.25); padding: 2px 7px; border-radius: 6px;">
          Disaster Recovery
        </span>
      </h2>
      <p style="font-size: 0.82rem; color: var(--text-subtle); margin: 0.2rem 0 0 0;">
        Pencadangan terpusat untuk skema basis data, seluruh berkas unggahan, dan pengarsipan otomatis ke saluran Discord dalam format batch.
      </p>
    </div>

    <!-- Quick Action Buttons -->
    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
      <a href="system_optimize" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600; min-height: 42px;" title="Buka Modul Auto-Optimise">
        <?= app_icon('refresh', 14) ?>
        <span>Auto-Optimise</span>
      </a>
      <a href="settings" class="btn btn-primary-soft btn-sm" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600; min-height: 42px;" title="Konfigurasi Webhook Discord">
        <?= app_icon('settings', 14) ?>
        <span>Pengaturan Webhook</span>
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
          <span style="font-size: 0.72rem; color: var(--text-subtle);"><?= strtoupper($dbDriver) ?> Engine &bull; 15 Tabel Aktif</span>
        </div>
        <div class="stat-icon-wrapper" style="width: 44px; height: 44px; border-radius: 12px; background: var(--primary-subtle); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
          <?= app_icon('database', 22) ?>
        </div>
      </div>
    </div>

    <!-- Stat 2: Uploads Storage -->
    <div class="card stat-filter-card" style="margin-bottom: 0; border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden; background: var(--bg-card);">
      <div class="card-body" style="display: flex; align-items: center; justify-content: space-between; padding: 1.15rem 1.25rem;">
        <div>
          <span style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; letter-spacing: 0.03em;">Berkas Unggahan Fisik</span>
          <p style="font-size: 1.65rem; font-weight: 800; color: #059669; margin: 0.2rem 0 0.15rem 0;"><?= $uploadsSizeFormatted ?></p>
          <span style="font-size: 0.72rem; color: var(--text-subtle);"><?= number_format($uploadsCount) ?> foto &amp; dokumen tersimpan</span>
        </div>
        <div class="stat-icon-wrapper" style="width: 44px; height: 44px; border-radius: 12px; background: #dcfce7; color: #16a34a; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
          <?= app_icon('folder', 22) ?>
        </div>
      </div>
    </div>

    <!-- Stat 3: Discord Status -->
    <div class="card stat-filter-card" style="margin-bottom: 0; border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden; background: var(--bg-card);">
      <div class="card-body" style="display: flex; align-items: center; justify-content: space-between; padding: 1.15rem 1.25rem;">
        <div>
          <span style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; letter-spacing: 0.03em;">Saluran Cadangan Discord</span>
          <p style="font-size: 1.35rem; font-weight: 800; color: <?= $isDiscordReady ? '#0284c7' : '#dc2626' ?>; margin: 0.35rem 0 0.15rem 0;">
            <?= $isDiscordReady ? 'Siap Digunakan' : 'Belum Disetel' ?>
          </p>
          <span style="font-size: 0.72rem; color: var(--text-subtle);">
            <?= $isDiscordReady ? 'Maksimal 25 MB per batch' : 'Isi Webhook di Pengaturan' ?>
          </span>
        </div>
        <div class="stat-icon-wrapper" style="width: 44px; height: 44px; border-radius: 12px; background: <?= $isDiscordReady ? '#e0f2fe' : '#fee2e2' ?>; color: <?= $isDiscordReady ? '#0284c7' : '#dc2626' ?>; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
          <?= app_icon('cloud', 22) ?>
        </div>
      </div>
    </div>

    <!-- Stat 4: Stored Backups -->
    <div class="card stat-filter-card" style="margin-bottom: 0; border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden; background: var(--bg-card);">
      <div class="card-body" style="display: flex; align-items: center; justify-content: space-between; padding: 1.15rem 1.25rem;">
        <div>
          <span style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; letter-spacing: 0.03em;">Cadangan di Server</span>
          <p style="font-size: 1.65rem; font-weight: 800; color: #d97706; margin: 0.2rem 0 0.15rem 0;"><?= number_format($totalBackupFiles) ?> berkas</p>
          <span style="font-size: 0.72rem; color: var(--text-subtle);">Kapasitas: <?= $totalBackupStorageFormatted ?></span>
        </div>
        <div class="stat-icon-wrapper" style="width: 44px; height: 44px; border-radius: 12px; background: #fef3c7; color: #d97706; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
          <?= app_icon('archive', 22) ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Backup Execution Options Section (3 Interactive Cards) -->
  <div class="mb-4">
    <div style="margin-bottom: 0.85rem;">
      <h3 style="font-size: 1.12rem; font-weight: 800; color: var(--text-main); margin-bottom: 4px;">
        Pilihan Tindakan Pencadangan Sistem
      </h3>
      <p style="font-size: 0.82rem; color: var(--text-subtle); margin: 0;">
        Pilih jenis data yang ingin dicadangkan. Anda dapat mengunduh berkas langsung ke komputer atau mengirimkannya ke saluran Discord dalam bentuk batch.
      </p>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem;">
      
      <!-- Option 1: Database Only -->
      <div class="card" style="border: 1px solid var(--border); border-radius: var(--radius-lg); background: var(--bg-card); padding: 1.25rem; display: flex; flex-direction: column; justify-content: space-between; box-shadow: var(--shadow-sm);">
        <div>
          <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
            <span style="color: var(--primary);"><?= app_icon('database', 20) ?></span>
            <h4 style="font-size: 1rem; font-weight: 800; color: var(--text-main); margin: 0;">
              1. Basis Data Saja (.sql.gz)
            </h4>
          </div>
          <p style="font-size: 0.82rem; color: var(--text-subtle); line-height: 1.5; margin-bottom: 1rem;">
            Mengekspor seluruh skema 15 tabel, struktur relasi, dan data pengguna, shift, checklist PM, serta audit log ke dalam format SQL terkompresi. Proses instan (&lt; 1 detik).
          </p>
        </div>

        <div style="display: flex; flex-direction: column; gap: 8px; margin-top: auto;">
          <button class="btn btn-outline-primary btn-sm" onclick="startBackupProcess('db', 'download')" style="display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-weight: 600; min-height: 42px;">
            <?= app_icon('download', 14) ?>
            <span>Buat &amp; Unduh Database</span>
          </button>
          <button class="btn btn-primary-soft btn-sm" onclick="startBackupProcess('db', 'discord')" <?= !$isDiscordReady ? 'disabled title="Webhook Discord belum diisi"' : '' ?> style="display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-weight: 600; min-height: 42px;">
            <?= app_icon('cloud', 14) ?>
            <span>Kirim Database ke Discord</span>
          </button>
        </div>
      </div>

      <!-- Option 2: Uploads Only -->
      <div class="card" style="border: 1px solid var(--border); border-radius: var(--radius-lg); background: var(--bg-card); padding: 1.25rem; display: flex; flex-direction: column; justify-content: space-between; box-shadow: var(--shadow-sm);">
        <div>
          <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
            <span style="color: #059669;"><?= app_icon('folder', 20) ?></span>
            <h4 style="font-size: 1rem; font-weight: 800; color: var(--text-main); margin: 0;">
              2. Berkas Unggahan Saja (.zip)
            </h4>
          </div>
          <p style="font-size: 0.82rem; color: var(--text-subtle); line-height: 1.5; margin-bottom: 1rem;">
            Mengemas seluruh foto kegiatan harian, foto laporan PM tower/indoor, berkas brankas dokumen, dan logo branding ke dalam arsip ZIP utuh.
          </p>
        </div>

        <div style="display: flex; flex-direction: column; gap: 8px; margin-top: auto;">
          <button class="btn btn-outline-primary btn-sm" onclick="startBackupProcess('files', 'download')" style="display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-weight: 600; min-height: 42px;">
            <?= app_icon('download', 14) ?>
            <span>Buat &amp; Unduh Berkas ZIP</span>
          </button>
          <button class="btn btn-primary-soft btn-sm" onclick="startBackupProcess('files', 'discord')" <?= !$isDiscordReady ? 'disabled title="Webhook Discord belum diisi"' : '' ?> style="display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-weight: 600; min-height: 42px;">
            <?= app_icon('cloud', 14) ?>
            <span>Kirim ke Discord (Auto Batch)</span>
          </button>
        </div>
      </div>

      <!-- Option 3: Full Backup Master -->
      <div class="card" style="border: 2px solid var(--primary); border-radius: var(--radius-lg); background: var(--bg-card); padding: 1.25rem; display: flex; flex-direction: column; justify-content: space-between; box-shadow: var(--shadow-md); position: relative;">
        <span style="position: absolute; top: -10px; right: 14px; background: var(--primary); color: #ffffff; font-size: 0.68rem; font-weight: 800; padding: 2px 8px; border-radius: 6px; text-transform: uppercase;">Rekomendasi</span>
        <div>
          <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
            <span style="color: var(--primary);"><?= app_icon('package', 20) ?></span>
            <h4 style="font-size: 1rem; font-weight: 800; color: var(--text-main); margin: 0;">
              3. Cadangan Penuh Sistem (.zip)
            </h4>
          </div>
          <p style="font-size: 0.82rem; color: var(--text-subtle); line-height: 1.5; margin-bottom: 1rem;">
            Paket cadangan pemulihan bencana (*Disaster Recovery*) lengkap: Dump basis data SQL, seluruh foto kegiatan harian, berkas PM, serta manifest informasi sistem.
          </p>
        </div>

        <div style="display: flex; flex-direction: column; gap: 8px; margin-top: auto;">
          <button class="btn btn-primary btn-sm" onclick="startBackupProcess('full', 'download')" style="display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-weight: 700; min-height: 42px;">
            <?= app_icon('download', 14) ?>
            <span>Buat &amp; Unduh Cadangan Penuh</span>
          </button>
          <button class="btn btn-secondary btn-sm" onclick="startBackupProcess('full', 'discord')" <?= !$isDiscordReady ? 'disabled title="Webhook Discord belum diisi"' : '' ?> style="display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-weight: 700; min-height: 42px; border: 1px solid var(--border);">
            <?= app_icon('cloud', 14) ?>
            <span>Cadangkan ke Discord (Multi-Batch)</span>
          </button>
        </div>
      </div>

    </div>
  </div>

  <!-- Live Progress Console Modal Box -->
  <div id="backup_console_wrapper" class="card mb-4" style="display: none; border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; background: var(--bg-card); box-shadow: var(--shadow-sm);">
    <div style="padding: 0.85rem 1.25rem; background: var(--bg-alt); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between;">
      <div style="display: flex; align-items: center; gap: 8px;">
        <span id="console_spinner" class="spinner-border spinner-border-sm text-primary" role="status" style="width: 14px; height: 14px; border-width: 2px;"></span>
        <strong id="console_status_title" style="font-size: 0.88rem; color: var(--text-main);">Memproses Pencadangan Sistem...</strong>
      </div>
      <span id="console_time_badge" class="badge" style="background: var(--primary-subtle); color: var(--primary); font-size: 0.75rem; font-weight: 700; padding: 3px 8px; border-radius: 6px;">0s</span>
    </div>
    <div id="backup_console" style="background: #0f172a; color: #38bdf8; font-family: 'Consolas', 'Courier New', monospace; font-size: 0.82rem; padding: 1rem 1.25rem; min-height: 120px; max-height: 240px; overflow-y: auto; line-height: 1.6;">
    </div>
  </div>

  <!-- Stored Backups History Table -->
  <div class="card mb-4" style="border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; background: var(--bg-card); box-shadow: var(--shadow-sm);">
    <div style="padding: 0.85rem 1.25rem; background: var(--bg-alt); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
      <h4 style="font-size: 0.92rem; font-weight: 800; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 6px;">
        <?= app_icon('archive', 16, 'text-primary') ?>
        <span>Riwayat Berkas Cadangan Tersimpan di Server</span>
      </h4>
      <span style="font-size: 0.75rem; color: var(--text-subtle);">Lokasi: <code style="font-size: 0.75rem; background: var(--bg-card); padding: 1px 5px; border-radius: 4px; border: 1px solid var(--border);">backups/</code></span>
    </div>

    <div class="table-responsive" style="margin: 0;">
      <table class="table table-hover align-middle" style="margin: 0; font-size: 0.84rem;">
        <thead style="background: var(--bg-alt); border-bottom: 1px solid var(--border);">
          <tr>
            <th style="color: var(--text-subtle); font-weight: 700;">Nama Berkas Cadangan</th>
            <th style="width: 170px; color: var(--text-subtle); font-weight: 700;">Tipe Cadangan</th>
            <th style="width: 120px; color: var(--text-subtle); font-weight: 700;">Kapasitas</th>
            <th style="width: 160px; color: var(--text-subtle); font-weight: 700;">Waktu Dibuat</th>
            <th style="width: 220px; text-align: right; color: var(--text-subtle); font-weight: 700;">Aksi</th>
          </tr>
        </thead>
        <tbody id="backup_table_body">
          <?php if (empty($backups)): ?>
            <tr>
              <td colspan="5" style="text-align: center; padding: 2.5rem 1rem; color: var(--text-subtle);">
                Belum ada berkas cadangan di server. Silakan buat cadangan baru di atas.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($backups as $b): ?>
              <tr>
                <td style="font-weight: 700; color: var(--text-main); word-break: break-all;">
                  <?= htmlspecialchars($b['filename']) ?>
                </td>
                <td>
                  <span class="badge" style="background: rgba(0, 128, 128, 0.1); color: var(--primary); font-weight: 700; padding: 3px 8px; border-radius: 4px;">
                    <?= htmlspecialchars($b['type']) ?>
                  </span>
                </td>
                <td style="font-weight: 600; color: var(--text-main);">
                  <?= $b['size_formatted'] ?>
                </td>
                <td style="color: var(--text-subtle);">
                  <?= date('d/m/Y H:i:s', strtotime($b['created_at'])) ?>
                </td>
                <td style="text-align: right;">
                  <div style="display: inline-flex; align-items: center; gap: 6px;">
                    <a href="api/run_backup?action=download&filename=<?= urlencode($b['filename']) ?>" class="btn btn-outline-primary btn-sm" style="padding: 3px 8px; font-size: 0.78rem; font-weight: 600;" title="Unduh Berkas ke Komputer">
                      <?= app_icon('download', 13) ?>
                      <span>Unduh</span>
                    </a>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="sendExistingToDiscord('<?= htmlspecialchars(addslashes($b['filename'])) ?>')" style="padding: 3px 8px; font-size: 0.78rem; font-weight: 600;" title="Kirim Ulang ke Discord (Multi-Batch)">
                      <?= app_icon('cloud', 13) ?>
                      <span>Discord</span>
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteBackup('<?= htmlspecialchars(addslashes($b['filename'])) ?>')" style="padding: 3px 8px; font-size: 0.78rem;" title="Hapus Berkas Cadangan">
                      <?= app_icon('trash', 13) ?>
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Documentation & Restore Guide Collapsible -->
  <div class="card" style="border: 1px solid var(--border); border-radius: var(--radius-lg); background: var(--bg-card); padding: 1.25rem; box-shadow: var(--shadow-sm);">
    <h4 style="font-size: 0.95rem; font-weight: 800; color: var(--text-main); margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
      <?= app_icon('info', 16, 'text-primary') ?>
      <span>Panduan Pemulihan Data (*Disaster Recovery Guide*)</span>
    </h4>
    <div style="font-size: 0.82rem; color: var(--text-subtle); line-height: 1.6;">
      <p style="margin-bottom: 0.5rem;">
        <strong>1. Jika Mengunduh Berkas Multi-Batch dari Discord:</strong><br>
        Discord membatasi ukuran maksimal berkas sebesar 25 MB per pesan. Cadangan yang melebihi batas tersebut otomatis dipecah menjadi file <code style="background: var(--bg-alt); padding: 1px 4px; border-radius: 3px;">.part01_of_03</code>, dst. Gabungkan kembali dengan perintah terminal:
      </p>
      <div style="background: var(--bg-alt); border: 1px solid var(--border); border-radius: 6px; padding: 0.65rem 0.85rem; margin-bottom: 0.75rem; font-family: monospace; font-size: 0.78rem; color: var(--text-main);">
        Windows (CMD): copy /b "backup_apsd_full_*.part*" "backup_apsd_full.zip"<br>
        Linux / Server: cat backup_apsd_full_*.part* &gt; backup_apsd_full.zip
      </div>

      <p style="margin-bottom: 0.5rem;">
        <strong>2. Cara Memulihkan Basis Data (Restore SQL):</strong><br>
        Ekstrak berkas SQL dari ZIP atau buka berkas <code style="background: var(--bg-alt); padding: 1px 4px; border-radius: 3px;">.sql</code>, lalu jalankan di terminal server:
      </p>
      <div style="background: var(--bg-alt); border: 1px solid var(--border); border-radius: 6px; padding: 0.65rem 0.85rem; font-family: monospace; font-size: 0.78rem; color: var(--text-main);">
        Docker: docker compose exec -T db mysql -u apsd_user -papsd_password_2026 apsd_pm &lt; database.sql<br>
        XAMPP: C:\xampp\mysql\bin\mysql.exe -u root apsd_pm &lt; database.sql
      </div>
    </div>
  </div>

</div>

<script>
let consoleTimer = null;
let secondsElapsed = 0;

function logToConsole(msg, color = '#38bdf8') {
  const box = document.getElementById('backup_console');
  const d = new Date().toLocaleTimeString();
  box.innerHTML += '<div style="color: ' + color + ';">[' + d + '] ' + msg + '</div>';
  box.scrollTop = box.scrollHeight;
}

function startBackupProcess(type, target) {
  const isDiscord = (target === 'discord');
  const label = type === 'db' ? 'Basis Data Saja' : (type === 'files' ? 'Berkas Unggahan' : 'Cadangan Penuh Sistem');
  
  if (!confirm('Mulai proses pembuatan ' + label + (isDiscord ? ' dan kirim otomatis ke Discord?' : ' dan unduh ke perangkat?'))) {
    return;
  }

  const wrapper = document.getElementById('backup_console_wrapper');
  const box = document.getElementById('backup_console');
  const spinner = document.getElementById('console_spinner');
  const title = document.getElementById('console_status_title');
  const timeBadge = document.getElementById('console_time_badge');

  wrapper.style.display = 'block';
  box.innerHTML = '';
  spinner.style.display = 'inline-block';
  title.textContent = 'Memulai Pembuatan Cadangan ' + label + '...';
  
  secondsElapsed = 0;
  timeBadge.textContent = '0s';
  clearInterval(consoleTimer);
  consoleTimer = setInterval(() => {
    secondsElapsed++;
    timeBadge.textContent = secondsElapsed + 's';
  }, 1000);

  logToConsole('Inisialisasi modul pencadangan (' + label + ')...', '#94a3b8');

  // Step 1: Create Backup on Server
  fetch('api/run_backup', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'create', type: type })
  })
  .then(res => res.json())
  .then(res => {
    if (!res.success) {
      throw new Error(res.message || 'Gagal membuat cadangan');
    }

    const data = res.data;
    logToConsole('Arsip berhasil dibuat: ' + data.filename + ' (' + data.size_formatted + ')', '#4ade80');
    logToConsole('Checksum SHA-256: ' + data.sha256, '#cbd5e1');

    // Step 2: Either send to Discord or Download
    if (isDiscord) {
      logToConsole('Memulai pengiriman arsip ke Saluran Discord...', '#38bdf8');
      title.textContent = 'Mengirim Berkas Cadangan ke Discord...';

      return fetch('api/run_backup', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'send_discord', filename: data.filename })
      })
      .then(dRes => dRes.json())
      .then(dRes => {
        clearInterval(consoleTimer);
        spinner.style.display = 'none';

        if (dRes.success) {
          title.textContent = 'Cadangan Sukses Terkirim ke Discord!';
          logToConsole('SUKSES: ' + dRes.message, '#4ade80');
          alert('Pencadangan sukses!\nBerkas telah terkirim ke Discord dalam ' + dRes.total_parts + ' bagian.');
          setTimeout(() => { window.location.reload(); }, 1500);
        } else {
          title.textContent = 'Peringatan Pengiriman Discord';
          logToConsole('GAGAL: ' + dRes.message, '#f87171');
          alert('Arsip tersimpan di server, namun gagal terkirim ke Discord:\n' + dRes.message);
          setTimeout(() => { window.location.reload(); }, 2000);
        }
      });

    } else {
      // Direct browser download
      clearInterval(consoleTimer);
      spinner.style.display = 'none';
      title.textContent = 'Pencadangan Selesai!';
      logToConsole('Memulai pengunduhan berkas ke komputer...', '#4ade80');
      
      // Trigger browser download via window.location
      window.location.href = 'api/run_backup?action=download&filename=' + encodeURIComponent(data.filename);

      setTimeout(() => {
        window.location.reload();
      }, 2000);
    }
  })
  .catch(err => {
    clearInterval(consoleTimer);
    spinner.style.display = 'none';
    title.textContent = 'Proses Terhenti Karena Kesalahan';
    logToConsole('ERROR: ' + err.message, '#f87171');
    alert('Terjadi kesalahan pencadangan:\n' + err.message);
  });
}

function sendExistingToDiscord(filename) {
  if (!confirm('Kirim berkas cadangan "' + filename + '" ke saluran Discord sekarang?')) {
    return;
  }

  const wrapper = document.getElementById('backup_console_wrapper');
  const box = document.getElementById('backup_console');
  const spinner = document.getElementById('console_spinner');
  const title = document.getElementById('console_status_title');
  const timeBadge = document.getElementById('console_time_badge');

  wrapper.style.display = 'block';
  box.innerHTML = '';
  spinner.style.display = 'inline-block';
  title.textContent = 'Mengirim Berkas Cadangan ke Discord...';
  
  secondsElapsed = 0;
  timeBadge.textContent = '0s';
  clearInterval(consoleTimer);
  consoleTimer = setInterval(() => {
    secondsElapsed++;
    timeBadge.textContent = secondsElapsed + 's';
  }, 1000);

  logToConsole('Mempersiapkan pengiriman berkas: ' + filename, '#38bdf8');

  fetch('api/run_backup', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'send_discord', filename: filename })
  })
  .then(res => res.json())
  .then(res => {
    clearInterval(consoleTimer);
    spinner.style.display = 'none';

    if (res.success) {
      title.textContent = 'Cadangan Sukses Terkirim ke Discord!';
      logToConsole('SUKSES: ' + res.message, '#4ade80');
      alert('Berkas cadangan "' + filename + '" berhasil dikirim ke Discord dalam ' + res.total_parts + ' bagian.');
    } else {
      title.textContent = 'Gagal Mengirim ke Discord';
      logToConsole('GAGAL: ' + res.message, '#f87171');
      alert('Gagal mengirim ke Discord:\n' + res.message);
    }
  })
  .catch(err => {
    clearInterval(consoleTimer);
    spinner.style.display = 'none';
    title.textContent = 'Kesalahan Jaringan';
    logToConsole('ERROR: ' + err.message, '#f87171');
    alert('Terjadi kesalahan jaringan:\n' + err.message);
  });
}

function deleteBackup(filename) {
  if (!confirm('Hapus berkas cadangan "' + filename + '" dari server secara permanen?')) {
    return;
  }

  fetch('api/run_backup', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'delete', filename: filename })
  })
  .then(res => res.json())
  .then(res => {
    if (res.success) {
      alert(res.message);
      window.location.reload();
    } else {
      alert('Gagal menghapus: ' + res.message);
    }
  })
  .catch(err => {
    alert('Terjadi kesalahan: ' + err.message);
  });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
