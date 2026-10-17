<?php
/**
 * Audit Log & Jejak Keamanan Sistem
 * PT Angkasa Pura Sarana Digital - Sistem PM
 *
 * Khusus Superadmin: Memantau rekaman jejak audit, aktivitas akun,
 * keamanan berkas brankas dokumen, operasional NOC, dan perubahan sistem.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/icons.php';

// Strict RBAC: Hanya Superadmin yang berwenang mengakses Audit Log
require_role('superadmin');

$pdo = get_db_connection();
$currentUser = current_user();
$page_title = 'Audit Log & Jejak Keamanan';

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportFilters = [
        'search' => trim($_GET['search'] ?? ''),
        'action_type' => trim($_GET['action_type'] ?? ''),
        'user_id' => !empty($_GET['user_id']) ? (int)$_GET['user_id'] : null,
        'date_from' => trim($_GET['date_from'] ?? ''),
        'date_to' => trim($_GET['date_to'] ?? '')
    ];

    $logsToExport = get_audit_logs($exportFilters, 5000, 0);

    $filename = 'Audit_Log_APSD_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    // UTF-8 BOM for Microsoft Excel compatibility
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, [
        'ID',
        'Waktu (WIB)',
        'Username',
        'Role',
        'Kategori',
        'Aksi (Action)',
        'Target Tipe',
        'Target ID',
        'Deskripsi',
        'IP Address',
        'User Agent'
    ]);

    foreach ($logsToExport as $row) {
        fputcsv($output, [
            $row['id'],
            $row['created_at'],
            $row['username'] ?? 'Sistem/Tamu',
            $row['user_role'] ?? 'guest',
            $row['action_type'],
            $row['action_name'],
            $row['target_type'] ?? '',
            $row['target_id'] ?? '',
            $row['description'] ?? '',
            $row['ip_address'] ?? '',
            $row['user_agent'] ?? ''
        ]);
    }

    fclose($output);
    exit;
}

// Filters & Safe Pagination (Prevents division by zero)
$filterSearch = trim($_GET['search'] ?? '');
$filterType = trim($_GET['action_type'] ?? '');
$filterUserId = !empty($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo = trim($_GET['date_to'] ?? '');

$page = max(1, (int)($_GET['page'] ?? 1));
$rawPerPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
$perPage = in_array($rawPerPage, [25, 50, 100], true) ? $rawPerPage : 50;
if ($perPage <= 0) {
    $perPage = 50;
}
$offset = max(0, ($page - 1) * $perPage);

$filters = [
    'search' => $filterSearch,
    'action_type' => $filterType,
    'user_id' => $filterUserId > 0 ? $filterUserId : null,
    'date_from' => $filterDateFrom,
    'date_to' => $filterDateTo
];

$totalRecords = count_audit_logs($filters);
$safePerPage = max(1, $perPage);
$totalPages = max(1, (int)ceil($totalRecords / $safePerPage));
$logs = get_audit_logs($filters, $safePerPage, $offset);
$stats = get_audit_log_stats();

// Fetch all users for user filter dropdown
$userList = [];
try {
    $userList = $pdo->query("SELECT id, username, full_name, role FROM users ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
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
        <span style="color: var(--primary); font-weight: 600;">Audit Log</span>
      </div>
      <h2 style="font-size: 1.45rem; font-weight: 800; color: var(--text-main); margin-top: 0.25rem; letter-spacing: -0.02em; display: flex; align-items: center; gap: 8px;">
        <?= app_icon('shield', 22, 'text-primary') ?>
        <span>Audit Log &amp; Rekam Jejak Sistem</span>
        <span class="badge" style="font-size: 0.68rem; font-weight: 700; background: rgba(220, 38, 38, 0.12); color: #dc2626; border: 1px solid rgba(220, 38, 38, 0.25); padding: 2px 7px; border-radius: 6px;">Superadmin</span>
      </h2>
      <p style="font-size: 0.82rem; color: var(--text-subtle); margin: 0.2rem 0 0 0;">
        Pusat pemantauan audit keamanan, otentikasi login, manipulasi berkas brankas, shift NOC, dan pemeliharaan server.
      </p>
    </div>

    <!-- Quick Action Buttons -->
    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
      <?php
        $exportQuery = $_GET;
        $exportQuery['export'] = 'csv';
        $exportUrl = 'audit_log?' . http_build_query($exportQuery);
      ?>
      <a href="<?= htmlspecialchars($exportUrl) ?>" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;" title="Ekspor rekam jejak saat ini ke format file CSV">
        <?= app_icon('download', 14) ?>
        <span>Ekspor CSV</span>
      </a>
      <a href="audit_log" class="btn btn-primary-soft btn-sm" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;" title="Segarkan Data Log">
        <?= app_icon('refresh', 14) ?>
        <span>Segarkan</span>
      </a>
      <a href="system_optimize" class="btn btn-primary btn-sm" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 700;" title="Buka Halaman Auto-Optimise Sistem">
        <?= app_icon('settings', 14) ?>
        <span>Auto-Optimise</span>
      </a>
    </div>
  </div>

  <!-- Summary Statistics Grid -->
  <div class="stats-grid mb-4" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    <!-- Stat 1: Log Hari Ini -->
    <div class="card stat-filter-card" style="margin-bottom: 0; border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden; background: var(--bg-card);">
      <div class="card-body" style="display: flex; align-items: center; justify-content: space-between; padding: 1.15rem 1.25rem;">
        <div>
          <span style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; letter-spacing: 0.03em;">Total Log Hari Ini</span>
          <p style="font-size: 1.65rem; font-weight: 800; color: var(--primary); margin: 0.2rem 0 0.15rem 0;"><?= number_format($stats['today']) ?></p>
          <span style="font-size: 0.72rem; color: var(--text-subtle);">Aktivitas 24 jam terakhir</span>
        </div>
        <div class="stat-icon-wrapper" style="width: 44px; height: 44px; border-radius: 12px; background: var(--primary-subtle); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
          <?= app_icon('activity', 22) ?>
        </div>
      </div>
    </div>

    <!-- Stat 2: Log Otentikasi & Akun -->
    <div class="card stat-filter-card" style="margin-bottom: 0; border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden; background: var(--bg-card);">
      <div class="card-body" style="display: flex; align-items: center; justify-content: space-between; padding: 1.15rem 1.25rem;">
        <div>
          <span style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; letter-spacing: 0.03em;">Otentikasi &amp; Akun</span>
          <p style="font-size: 1.65rem; font-weight: 800; color: #0284c7; margin: 0.2rem 0 0.15rem 0;"><?= number_format($stats['auth']) ?></p>
          <span style="font-size: 0.72rem; color: var(--text-subtle);">Login, logout &amp; kelola user</span>
        </div>
        <div class="stat-icon-wrapper" style="width: 44px; height: 44px; border-radius: 12px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
          <?= app_icon('shield', 22) ?>
        </div>
      </div>
    </div>

    <!-- Stat 3: Brankas Dokumen -->
    <div class="card stat-filter-card" style="margin-bottom: 0; border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden; background: var(--bg-card);">
      <div class="card-body" style="display: flex; align-items: center; justify-content: space-between; padding: 1.15rem 1.25rem;">
        <div>
          <span style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; letter-spacing: 0.03em;">Brankas &amp; Berbagi</span>
          <p style="font-size: 1.65rem; font-weight: 800; color: #9333ea; margin: 0.2rem 0 0.15rem 0;"><?= number_format($stats['documents']) ?></p>
          <span style="font-size: 0.72rem; color: var(--text-subtle);">Berkas, upload &amp; tautan link</span>
        </div>
        <div class="stat-icon-wrapper" style="width: 44px; height: 44px; border-radius: 12px; background: #f3e8ff; color: #9333ea; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
          <?= app_icon('folder', 22) ?>
        </div>
      </div>
    </div>

    <!-- Stat 4: Daily Activity NOC -->
    <div class="card stat-filter-card" style="margin-bottom: 0; border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden; background: var(--bg-card);">
      <div class="card-body" style="display: flex; align-items: center; justify-content: space-between; padding: 1.15rem 1.25rem;">
        <div>
          <span style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; letter-spacing: 0.03em;">Daily Activity NOC</span>
          <p style="font-size: 1.65rem; font-weight: 800; color: #d97706; margin: 0.2rem 0 0.15rem 0;"><?= number_format($stats['daily_activity']) ?></p>
          <span style="font-size: 0.72rem; color: var(--text-subtle);">Shift logbook &amp; butir kegiatan</span>
        </div>
        <div class="stat-icon-wrapper" style="width: 44px; height: 44px; border-radius: 12px; background: #fef3c7; color: #d97706; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
          <?= app_icon('clock', 22) ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Filter Card -->
  <div style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.15rem 1.35rem; margin-bottom: 1.5rem; box-shadow: var(--shadow-sm);">
    <form method="GET" action="audit_log" id="filter_form">
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.85rem; align-items: flex-end;">
        
        <!-- Filter Kata Kunci -->
        <div>
          <label style="font-size: 0.76rem; font-weight: 700; color: var(--text-subtle); margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
            <?= app_icon('search', 12) ?>
            <span>Pencarian Kata Kunci</span>
          </label>
          <input type="text" name="search" class="form-control" placeholder="Cari user, deskripsi, IP..." value="<?= htmlspecialchars($filterSearch) ?>" style="min-height: 38px;">
        </div>

        <!-- Filter Kategori -->
        <div>
          <label style="font-size: 0.76rem; font-weight: 700; color: var(--text-subtle); margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
            <?= app_icon('tag', 12) ?>
            <span>Kategori Peristiwa</span>
          </label>
          <select name="action_type" class="form-control" style="min-height: 38px; font-weight: 600;">
            <option value="">Semua Kategori</option>
            <option value="AUTH" <?= $filterType === 'AUTH' ? 'selected' : '' ?>>Otentikasi &amp; Pengguna (AUTH)</option>
            <option value="DOCUMENT" <?= $filterType === 'DOCUMENT' ? 'selected' : '' ?>>Brankas Dokumen (DOCUMENT)</option>
            <option value="EXTERNAL_SHARE" <?= $filterType === 'EXTERNAL_SHARE' ? 'selected' : '' ?>>Berbagi Eksternal (EXTERNAL_SHARE)</option>
            <option value="DAILY_ACTIVITY" <?= $filterType === 'DAILY_ACTIVITY' ? 'selected' : '' ?>>Daily Activity NOC (DAILY_ACTIVITY)</option>
            <option value="SETTINGS" <?= $filterType === 'SETTINGS' ? 'selected' : '' ?>>Pengaturan Sistem (SETTINGS)</option>
            <option value="SYSTEM" <?= $filterType === 'SYSTEM' ? 'selected' : '' ?>>Pemeliharaan &amp; Optimasi (SYSTEM)</option>
          </select>
        </div>

        <!-- Filter Pengguna -->
        <div>
          <label style="font-size: 0.76rem; font-weight: 700; color: var(--text-subtle); margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
            <?= app_icon('user', 12) ?>
            <span>Pengguna / Akun</span>
          </label>
          <select name="user_id" class="form-control" style="min-height: 38px;">
            <option value="">Semua Pengguna</option>
            <?php foreach ($userList as $u): ?>
              <option value="<?= (int)$u['id'] ?>" <?= $filterUserId === (int)$u['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($u['full_name']) ?> (@<?= htmlspecialchars($u['username']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Filter Tanggal Mulai -->
        <div>
          <label style="font-size: 0.76rem; font-weight: 700; color: var(--text-subtle); margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
            <?= app_icon('calendar', 12) ?>
            <span>Dari Tanggal</span>
          </label>
          <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($filterDateFrom) ?>" style="min-height: 38px;">
        </div>

        <!-- Filter Tanggal Selesai -->
        <div>
          <label style="font-size: 0.76rem; font-weight: 700; color: var(--text-subtle); margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
            <?= app_icon('calendar', 12) ?>
            <span>Sampai Tanggal</span>
          </label>
          <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($filterDateTo) ?>" style="min-height: 38px;">
        </div>

        <!-- Action Buttons -->
        <div style="display: flex; gap: 8px;">
          <button type="submit" class="btn btn-primary w-100" style="min-height: 38px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-weight: 700;">
            <?= app_icon('filter', 14) ?>
            <span>Terapkan</span>
          </button>
          <?php if (!empty($filterSearch) || !empty($filterType) || !empty($filterUserId) || !empty($filterDateFrom) || !empty($filterDateTo)): ?>
            <a href="audit_log" class="btn btn-outline-secondary" style="min-height: 38px; display: inline-flex; align-items: center; justify-content: center; padding: 0 12px; font-weight: 600;" title="Hapus Filter">
              Reset
            </a>
          <?php endif; ?>
        </div>

      </div>
    </form>
  </div>

  <!-- Data Table Card -->
  <div class="card" style="border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden; background: var(--bg-card); margin-bottom: 1.5rem;">
    <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.85rem 1.25rem; background: var(--bg-alt); border-bottom: 1px solid var(--border); flex-wrap: wrap; gap: 10px;">
      <div style="font-size: 0.84rem; font-weight: 700; color: var(--text-main);">
        Menampilkan <?= number_format(min($totalRecords, $offset + 1)) ?> - <?= number_format(min($totalRecords, $offset + count($logs))) ?> dari <?= number_format($totalRecords) ?> total rekam jejak
      </div>
      <div style="display: flex; align-items: center; gap: 8px;">
        <label for="select_per_page" style="font-size: 0.78rem; color: var(--text-subtle); margin: 0;">Baris per halaman:</label>
        <select id="select_per_page" class="form-control form-control-sm" style="width: auto; min-height: 30px; font-size: 0.8rem; padding: 2px 10px;" onchange="changePerPage(this.value)">
          <option value="25" <?= $perPage === 25 ? 'selected' : '' ?>>25</option>
          <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
          <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
        </select>
      </div>
    </div>

    <div class="table-responsive" style="margin: 0;">
      <table class="table table-hover align-middle" style="margin: 0; font-size: 0.84rem;">
        <thead style="background: var(--bg-alt); border-bottom: 1px solid var(--border);">
          <tr>
            <th style="width: 55px; text-align: center; color: var(--text-subtle); font-weight: 700;">ID</th>
            <th style="width: 165px; color: var(--text-subtle); font-weight: 700;">Waktu (WIB)</th>
            <th style="width: 175px; color: var(--text-subtle); font-weight: 700;">Pengguna &amp; Role</th>
            <th style="width: 185px; color: var(--text-subtle); font-weight: 700;">Kategori &amp; Aksi</th>
            <th style="color: var(--text-subtle); font-weight: 700;">Detail Peristiwa</th>
            <th style="width: 140px; color: var(--text-subtle); font-weight: 700;">IP &amp; Perangkat</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($logs)): ?>
            <tr>
              <td colspan="6" style="text-align: center; padding: 3.5rem 1rem;">
                <div style="color: var(--text-subtle); font-size: 2.2rem; margin-bottom: 0.75rem;">
                  <?= app_icon('shield', 42) ?>
                </div>
                <div style="font-weight: 700; font-size: 1rem; color: var(--text-main); margin-bottom: 4px;">
                  Tidak ada rekam jejak yang sesuai
                </div>
                <div style="font-size: 0.82rem; color: var(--text-subtle); max-width: 420px; margin: 0 auto;">
                  Belum ditemukan log audit dengan parameter filter yang Anda pilih. Coba sesuaikan kata kunci atau rentang tanggal.
                </div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($logs as $log): ?>
              <tr>
                <td style="text-align: center; color: var(--text-subtle); font-size: 0.78rem; font-family: monospace;">
                  #<?= $log['id'] ?>
                </td>

                <td>
                  <div style="font-weight: 700; color: var(--text-main); white-space: nowrap;">
                    <?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?>
                  </div>
                  <div style="font-size: 0.72rem; color: var(--text-subtle); white-space: nowrap;">
                    Waktu Server
                  </div>
                </td>

                <td>
                  <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="width: 30px; height: 30px; border-radius: 50%; background: var(--primary-subtle); color: var(--primary); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.78rem; flex-shrink: 0;">
                      <?= strtoupper(substr($log['username'] ?? 'S', 0, 1)) ?>
                    </div>
                    <div style="line-height: 1.25;">
                      <div style="font-weight: 700; color: var(--text-main); font-size: 0.84rem;">
                        <?= htmlspecialchars($log['username'] ?? 'Sistem/Tamu') ?>
                      </div>
                      <span class="badge" style="font-size: 0.65rem; padding: 2px 6px; font-weight: 700; border-radius: 4px; background: <?= $log['user_role'] === 'superadmin' ? 'rgba(220, 38, 38, 0.12)' : ($log['user_role'] === 'admin' ? 'rgba(14, 165, 233, 0.12)' : 'var(--bg-alt)') ?>; color: <?= $log['user_role'] === 'superadmin' ? '#dc2626' : ($log['user_role'] === 'admin' ? '#0284c7' : 'var(--text-subtle)') ?>; border: 1px solid <?= $log['user_role'] === 'superadmin' ? 'rgba(220, 38, 38, 0.25)' : 'var(--border)' ?>;">
                        <?= htmlspecialchars(strtoupper($log['user_role'] ?? 'guest')) ?>
                      </span>
                    </div>
                  </div>
                </td>

                <td>
                  <div style="margin-bottom: 3px;">
                    <?= format_audit_badge($log['action_type'], $log['action_name']) ?>
                  </div>
                  <div style="font-size: 0.72rem; color: var(--text-subtle);">
                    <?= htmlspecialchars($log['action_type']) ?>
                  </div>
                </td>

                <td>
                  <div style="font-weight: 500; color: var(--text-main); word-break: break-word;">
                    <?= htmlspecialchars($log['description'] ?? '-') ?>
                  </div>
                  <?php if (!empty($log['target_type']) && !empty($log['target_id'])): ?>
                    <div style="margin-top: 4px;">
                      <span style="font-size: 0.7rem; background: var(--bg-alt); color: var(--text-subtle); padding: 1px 6px; border-radius: 4px; border: 1px solid var(--border);">
                        Target: <?= htmlspecialchars($log['target_type']) ?> #<?= htmlspecialchars($log['target_id']) ?>
                      </span>
                    </div>
                  <?php endif; ?>
                </td>

                <td>
                  <div style="font-family: monospace; font-size: 0.8rem; font-weight: 700; color: var(--text-main);">
                    <?= htmlspecialchars($log['ip_address'] ?? '127.0.0.1') ?>
                  </div>
                  <?php if (!empty($log['user_agent'])): ?>
                    <div style="font-size: 0.7rem; color: var(--text-subtle); max-width: 140px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($log['user_agent']) ?>">
                      <?= htmlspecialchars($log['user_agent']) ?>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination Footer -->
    <?php if ($totalPages > 1): ?>
      <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; padding: 0.85rem 1.25rem; border-top: 1px solid var(--border); background: var(--bg-alt);">
        <div style="font-size: 0.8rem; color: var(--text-subtle);">
          Halaman <?= $page ?> dari <?= $totalPages ?> (Total <?= number_format($totalRecords) ?> rekaman)
        </div>

        <div style="display: flex; align-items: center; gap: 4px;">
          <?php
            $buildPageUrl = function($p) {
                $params = $_GET;
                $params['page'] = $p;
                return 'audit_log?' . http_build_query($params);
            };
          ?>

          <?php if ($page > 1): ?>
            <a href="<?= htmlspecialchars($buildPageUrl($page - 1)) ?>" class="btn btn-sm btn-outline-secondary" style="font-size: 0.78rem; min-height: 32px; padding: 0 10px;">
              &laquo; Sebelumnya
            </a>
          <?php endif; ?>

          <?php
            $startP = max(1, $page - 2);
            $endP = min($totalPages, $page + 2);
            for ($p = $startP; $p <= $endP; $p++):
          ?>
            <a href="<?= htmlspecialchars($buildPageUrl($p)) ?>" class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-outline-secondary' ?>" style="font-size: 0.78rem; min-width: 32px; min-height: 32px; padding: 0 6px; display: inline-flex; align-items: center; justify-content: center;">
              <?= $p ?>
            </a>
          <?php endfor; ?>

          <?php if ($page < $totalPages): ?>
            <a href="<?= htmlspecialchars($buildPageUrl($page + 1)) ?>" class="btn btn-sm btn-outline-secondary" style="font-size: 0.78rem; min-height: 32px; padding: 0 10px;">
              Berikutnya &raquo;
            </a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

</div>

<script>
function changePerPage(val) {
  const url = new URL(window.location.href);
  url.searchParams.set('per_page', val);
  url.searchParams.set('page', 1);
  window.location.href = url.toString();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
