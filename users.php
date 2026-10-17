<?php
/**
 * User Management & Role-Based Access Control (RBAC)
 * IASS SBU ICT KNO
 * 
 * Rules:
 * - Only superadmin & admin can access this page
 * - superadmin can add any user and reset password for any user
 * - admin can add 'user' and reset password for 'user' (roles below admin)
 * - Users are automatically logged out after 12 hours of session
 */

require_once __DIR__ . '/includes/auth.php';
require_role(['superadmin', 'admin']);

$pdo = get_db_connection();
$currentUser = current_user();
$page_title = 'Manajemen Pengguna';

$successMsg = '';
$errorMsg = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. TAMBAH PENGGUNA BARU
    if ($action === 'add') {
        $username = strtolower(trim($_POST['username'] ?? ''));
        $fullName = trim($_POST['full_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'user';
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (empty($username) || empty($fullName) || empty($password)) {
            $errorMsg = 'Semua field wajib diisi.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,30}$/', $username)) {
            $errorMsg = 'Username harus 3-30 karakter alfanumerik (huruf, angka, titik, strip).';
        } elseif (strlen($password) < 6) {
            $errorMsg = 'Password minimal terdiri dari 6 karakter.';
        } elseif (!can_assign_role($role)) {
            $errorMsg = 'Anda tidak memiliki wewenang untuk membuat pengguna dengan role ' . htmlspecialchars($role) . '.';
        } else {
            // Check username uniqueness
            $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $chk->execute([$username]);
            if ($chk->fetchColumn() > 0) {
                $errorMsg = 'Username "' . htmlspecialchars($username) . '" sudah digunakan. Silakan pilih username lain.';
            } else {
                $pwdHash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, password_hash, full_name, role, is_active)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$username, $pwdHash, $fullName, $role, $isActive]);
                $newUserId = (int)$pdo->lastInsertId();
                log_audit_event('AUTH', 'USER_CREATE', 'Tambah pengguna baru: ' . $fullName . ' (@' . $username . ', role: ' . $role . ')', 'user', $newUserId);
                $successMsg = 'Pengguna baru "' . htmlspecialchars($fullName) . '" (' . htmlspecialchars($role) . ') berhasil ditambahkan.';
            }
        }
    }

    // 2. RESET PASSWORD
    elseif ($action === 'reset_password') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';

        if (empty($newPassword) || strlen($newPassword) < 6) {
            $errorMsg = 'Password baru minimal 6 karakter.';
        } else {
            $target = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $target->execute([$targetId]);
            $targetUser = $target->fetch();

            if (!$targetUser) {
                $errorMsg = 'Pengguna tidak ditemukan.';
            } elseif (!can_manage_role($targetUser['role'])) {
                $errorMsg = 'Anda tidak memiliki wewenang untuk mereset password role ' . htmlspecialchars($targetUser['role']) . '.';
            } else {
                $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
                $upd = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $upd->execute([$newHash, $targetId]);
                log_audit_event('AUTH', 'USER_RESET_PASSWORD', 'Reset password akun pengguna: @' . $targetUser['username'], 'user', $targetId);
                $successMsg = 'Password untuk pengguna "' . htmlspecialchars($targetUser['full_name']) . '" berhasil direset!';
            }
        }
    }

    // 3. EDIT PENGGUNA
    elseif ($action === 'edit') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $role = $_POST['role'] ?? 'user';
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $target = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $target->execute([$targetId]);
        $targetUser = $target->fetch();

        if (!$targetUser) {
            $errorMsg = 'Pengguna tidak ditemukan.';
        } elseif (!can_manage_role($targetUser['role'])) {
            $errorMsg = 'Anda tidak memiliki wewenang untuk mengubah akun ini.';
        } elseif (empty($fullName)) {
            $errorMsg = 'Nama lengkap tidak boleh kosong.';
        } else {
            // If admin is editing, role stays as-is unless superadmin
            if ($currentUser['role'] !== 'superadmin') {
                $role = $targetUser['role'];
            }

            // Cannot deactivate oneself
            if ($targetId === (int)$currentUser['id']) {
                $isActive = 1;
            }

            $upd = $pdo->prepare("UPDATE users SET full_name = ?, role = ?, is_active = ? WHERE id = ?");
            $upd->execute([$fullName, $role, $isActive, $targetId]);
            log_audit_event('AUTH', 'USER_UPDATE', 'Ubah data pengguna: ' . $fullName . ' (@' . $targetUser['username'] . ', role: ' . $role . ', aktif: ' . $isActive . ')', 'user', $targetId);
            $successMsg = 'Data pengguna "' . htmlspecialchars($fullName) . '" berhasil diperbarui.';
        }
    }

    // 4. HAPUS PENGGUNA
    elseif ($action === 'delete') {
        $targetId = (int)($_POST['user_id'] ?? 0);

        if ($targetId === (int)$currentUser['id']) {
            $errorMsg = 'Anda tidak dapat menghapus akun Anda sendiri.';
        } else {
            $target = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $target->execute([$targetId]);
            $targetUser = $target->fetch();

            if (!$targetUser) {
                $errorMsg = 'Pengguna tidak ditemukan.';
            } elseif (!can_manage_role($targetUser['role'])) {
                $errorMsg = 'Anda tidak memiliki wewenang untuk menghapus pengguna dengan role ' . htmlspecialchars($targetUser['role']) . '.';
            } else {
                $del = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $del->execute([$targetId]);
                log_audit_event('AUTH', 'USER_DELETE', 'Hapus pengguna: ' . $targetUser['full_name'] . ' (@' . $targetUser['username'] . ')', 'user', $targetId);
                $successMsg = 'Pengguna "' . htmlspecialchars($targetUser['full_name']) . '" berhasil dihapus.';
            }
        }
    }
}

// Fetch list of all users
$stmt = $pdo->query("SELECT * FROM users ORDER BY CASE role WHEN 'superadmin' THEN 1 WHEN 'admin' THEN 2 ELSE 3 END, id ASC");
$users = $stmt->fetchAll();

// Calculate counts
$totalUsers = count($users);
$totalSuperadmin = 0;
$totalAdmin = 0;
$totalRegular = 0;

foreach ($users as $u) {
    if ($u['role'] === 'superadmin') $totalSuperadmin++;
    elseif ($u['role'] === 'admin') $totalAdmin++;
    else $totalRegular++;
}

include __DIR__ . '/includes/header.php';
?>

<main class="container" style="max-width: 1200px; margin: 1.5rem auto; padding: 0 1rem;">

  <!-- Header Banner -->
  <div class="card" style="margin-bottom: 1.5rem; background: linear-gradient(135deg, rgba(20, 184, 166, 0.08) 0%, rgba(56, 189, 248, 0.08) 100%), var(--bg-card);">
    <div class="card-body" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; padding: 1.5rem 1.75rem;">
      <div>
        <div style="font-size: 0.8rem; font-weight: 700; color: var(--primary); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">
          Sistem Hak Akses & Keamanan
        </div>
        <h2 style="font-size: 1.45rem; font-weight: 800; color: var(--text-main); margin: 0;">
          Manajemen Pengguna (User Management)
        </h2>
        <p style="color: var(--text-subtle); font-size: 0.88rem; margin: 4px 0 0 0;">
          Kelola akun teknisi & admin, otorisasi role berjenjang, dan reset password dengan sesi aktif 12 jam.
        </p>
      </div>
      <div>
        <button type="button" class="btn btn-primary" onclick="openAddUserModal()" style="display: inline-flex; align-items: center; gap: 6px; min-height: 44px; padding: 0.6rem 1.25rem; font-weight: 700;">
          <span>➕</span> Tambah Pengguna Baru
        </button>
      </div>
    </div>
  </div>

  <?php if (!empty($successMsg)): ?>
    <div style="background: var(--status-ok-bg); color: var(--status-ok); border: 1px solid var(--status-ok-border); padding: 0.85rem 1.25rem; border-radius: var(--radius-md); font-weight: 600; font-size: 0.9rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 10px;">
      <span style="font-size: 1.2rem;">✅</span>
      <span><?= htmlspecialchars($successMsg) ?></span>
    </div>
  <?php endif; ?>

  <?php if (!empty($errorMsg)): ?>
    <div style="background: var(--status-nok-bg); color: var(--status-nok); border: 1px solid var(--status-nok-border); padding: 0.85rem 1.25rem; border-radius: var(--radius-md); font-weight: 600; font-size: 0.9rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 10px;">
      <span style="font-size: 1.2rem;">⚠️</span>
      <span><?= htmlspecialchars($errorMsg) ?></span>
    </div>
  <?php endif; ?>

  <!-- Stat Cards -->
  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    <div class="card" style="padding: 1.25rem;">
      <div style="color: var(--text-subtle); font-size: 0.8rem; font-weight: 700; text-transform: uppercase;">Total Pengguna</div>
      <div style="font-size: 2rem; font-weight: 800; color: var(--text-main); margin-top: 4px;"><?= $totalUsers ?></div>
      <div style="font-size: 0.75rem; color: var(--text-subtle); margin-top: 2px;">Akun terdaftar dalam sistem</div>
    </div>
    <div class="card" style="padding: 1.25rem;">
      <div style="color: #6d28d9; font-size: 0.8rem; font-weight: 700; text-transform: uppercase;">Super Admin</div>
      <div style="font-size: 2rem; font-weight: 800; color: #6d28d9; margin-top: 4px;"><?= $totalSuperadmin ?></div>
      <div style="font-size: 0.75rem; color: var(--text-subtle); margin-top: 2px;">Akses penuh & reset semua level</div>
    </div>
    <div class="card" style="padding: 1.25rem;">
      <div style="color: var(--primary); font-size: 0.8rem; font-weight: 700; text-transform: uppercase;">Administrator</div>
      <div style="font-size: 2rem; font-weight: 800; color: var(--primary); margin-top: 4px;"><?= $totalAdmin ?></div>
      <div style="font-size: 0.75rem; color: var(--text-subtle); margin-top: 2px;">Manajemen user biasa / teknisi</div>
    </div>
    <div class="card" style="padding: 1.25rem;">
      <div style="color: #0284c7; font-size: 0.8rem; font-weight: 700; text-transform: uppercase;">User Biasa / Teknisi</div>
      <div style="font-size: 2rem; font-weight: 800; color: #0284c7; margin-top: 4px;"><?= $totalRegular ?></div>
      <div style="font-size: 0.75rem; color: var(--text-subtle); margin-top: 2px;">Pengisi checklist & foto lapangan</div>
    </div>
  </div>

  <!-- Table Container Card -->
  <div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
      <div style="font-weight: 700; font-size: 1rem; color: var(--text-main);">
        Daftar Akun Pengguna
      </div>
      <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
        <input type="text" id="user_search_input" class="table-search-input" placeholder="Cari nama, username..." style="min-width: 220px; min-height: 38px;">
        <select id="user_role_filter" class="filter-select" style="min-width: 150px; min-height: 38px;">
          <option value="all">Semua Role</option>
          <option value="superadmin">Super Admin</option>
          <option value="admin">Admin</option>
          <option value="user">User Biasa</option>
        </select>
      </div>
    </div>

    <div class="card-body" style="padding: 0;">
      <div class="desktop-table-container">
        <table class="checklist-table" id="users_table">
          <thead>
            <tr>
              <th style="width: 50px;">No</th>
              <th>Nama Lengkap & Username</th>
              <th>Role / Level Akses</th>
              <th>Status</th>
              <th>Terakhir Login</th>
              <th style="text-align: right; width: 230px;">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $idx => $u): ?>
              <?php 
                $canManage = can_manage_role($u['role']);
                $isSelf = ($u['id'] == $currentUser['id']);
                $roleLabel = ($u['role'] === 'superadmin') ? 'Super Admin' : (($u['role'] === 'admin') ? 'Admin' : 'User Biasa');
                $roleClass = 'role-' . $u['role'];
                $lastLoginFormatted = $u['last_login'] ? date('d/m/Y H:i', strtotime($u['last_login'])) : 'Belum pernah';
                $searchMeta = strtolower($u['full_name'] . ' ' . $u['username'] . ' ' . $u['role']);
              ?>
              <tr class="user-row" data-search="<?= htmlspecialchars($searchMeta) ?>" data-role="<?= htmlspecialchars($u['role']) ?>">
                <td style="font-weight: 700; color: var(--text-subtle);"><?= $idx + 1 ?></td>
                <td>
                  <strong style="color: var(--text-main); font-size: 0.95rem; display: block;">
                    <?= htmlspecialchars($u['full_name']) ?>
                    <?php if ($isSelf): ?>
                      <span style="font-size: 0.7rem; background: var(--bg-alt); color: var(--text-subtle); padding: 2px 6px; border-radius: 4px; margin-left: 4px;">(Anda)</span>
                    <?php endif; ?>
                  </strong>
                  <span style="font-size: 0.8rem; color: var(--text-subtle);">@<?= htmlspecialchars($u['username']) ?></span>
                </td>
                <td>
                  <span class="user-role-pill <?= $roleClass ?>"><?= $roleLabel ?></span>
                </td>
                <td>
                  <?php if (!empty($u['is_active'])): ?>
                    <span style="display: inline-flex; align-items: center; gap: 5px; font-size: 0.75rem; font-weight: 700; color: var(--status-ok);">
                      <span style="width: 7px; height: 7px; border-radius: 50%; background: var(--status-ok);"></span> Aktif
                    </span>
                  <?php else: ?>
                    <span style="display: inline-flex; align-items: center; gap: 5px; font-size: 0.75rem; font-weight: 700; color: var(--status-nok);">
                      <span style="width: 7px; height: 7px; border-radius: 50%; background: var(--status-nok);"></span> Non-Aktif
                    </span>
                  <?php endif; ?>
                </td>
                <td>
                  <span style="font-size: 0.85rem; color: var(--text-subtle);"><?= $lastLoginFormatted ?></span>
                </td>
                <td style="text-align: right;">
                  <?php if ($canManage): ?>
                    <div class="table-action-group">
                      <button type="button" class="btn-action-view" title="Reset Password" onclick='openResetModal(<?= json_encode($u) ?>)' style="min-height: 32px;">
                        🔑 Reset
                      </button>
                      <button type="button" class="btn-action-dl" title="Edit Pengguna" onclick='openEditModal(<?= json_encode($u) ?>)' style="min-height: 32px;">
                        ✏️ Edit
                      </button>
                      <?php if (!$isSelf): ?>
                        <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus pengguna <?= htmlspecialchars($u['full_name']) ?>?');">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                          <button type="submit" class="btn-action-del" title="Hapus Pengguna" style="min-height: 32px;">
                            🗑️
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  <?php else: ?>
                    <span style="font-size: 0.75rem; color: var(--text-subtle); font-style: italic;">Wewenang Superadmin</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</main>

<!-- MODAL TAMBAH PENGGUNA -->
<div id="modal_add_user" class="qr-modal-overlay" style="display: none;">
  <div class="card" style="max-width: 480px; width: 90%; margin: auto; animation: fadeIn 0.2s ease;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
      <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-main); margin: 0;">➕ Tambah Pengguna Baru</h3>
      <button type="button" onclick="closeAddUserModal()" style="background: none; border: none; font-size: 1.5rem; color: var(--text-subtle); cursor: pointer; min-width: 40px; min-height: 40px;">&times;</button>
    </div>
    <form method="POST" action="">
      <input type="hidden" name="action" value="add">
      <div class="card-body">
        <div class="form-group" style="margin-bottom: 1rem;">
          <label class="form-label">Nama Lengkap *</label>
          <input type="text" name="full_name" class="form-control" required placeholder="Contoh: Budi Santoso">
        </div>
        <div class="form-group" style="margin-bottom: 1rem;">
          <label class="form-label">Username (Login) *</label>
          <input type="text" name="username" class="form-control" required placeholder="Contoh: budi.s" pattern="[a-zA-Z0-9_.-]{3,30}" title="3-30 karakter alfanumerik">
        </div>
        <div class="form-group" style="margin-bottom: 1rem;">
          <label class="form-label">Role / Level Akses *</label>
          <select name="role" class="form-control" required>
            <?php if ($currentUser['role'] === 'superadmin'): ?>
              <option value="superadmin">Super Administrator (Akses Penuh)</option>
              <option value="admin">Administrator (Manajemen User & Form)</option>
            <?php endif; ?>
            <option value="user" selected>User Biasa / Teknisi Lapangan</option>
          </select>
        </div>
        <div class="form-group" style="margin-bottom: 1rem;">
          <label class="form-label">Password Sementara *</label>
          <input type="password" name="password" class="form-control" required minlength="6" placeholder="Minimal 6 karakter">
        </div>
        <div style="display: flex; align-items: center; gap: 8px; margin-top: 0.5rem;">
          <input type="checkbox" id="add_active_check" name="is_active" value="1" checked style="width: 18px; height: 18px;">
          <label for="add_active_check" style="font-size: 0.85rem; font-weight: 600; color: var(--text-main); cursor: pointer; margin: 0;">Akun Langsung Aktif</label>
        </div>
      </div>
      <div class="card-header" style="display: flex; justify-content: flex-end; gap: 0.75rem; border-top: 1px solid var(--border);">
        <button type="button" class="btn btn-secondary" onclick="closeAddUserModal()">Batal</button>
        <button type="submit" class="btn btn-primary" style="font-weight: 700;">Simpan Pengguna</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL RESET PASSWORD -->
<div id="modal_reset_pwd" class="qr-modal-overlay" style="display: none;">
  <div class="card" style="max-width: 440px; width: 90%; margin: auto; animation: fadeIn 0.2s ease;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
      <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-main); margin: 0;">🔑 Reset Password Pengguna</h3>
      <button type="button" onclick="closeResetModal()" style="background: none; border: none; font-size: 1.5rem; color: var(--text-subtle); cursor: pointer; min-width: 40px; min-height: 40px;">&times;</button>
    </div>
    <form method="POST" action="">
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="user_id" id="reset_user_id">
      <div class="card-body">
        <p style="font-size: 0.85rem; color: var(--text-subtle); margin-bottom: 1rem;">
          Mereset password untuk akun: <strong id="reset_user_name" style="color: var(--text-main);"></strong> (<span id="reset_user_role"></span>).
        </p>
        <div class="form-group" style="margin-bottom: 1rem;">
          <label class="form-label">Password Baru *</label>
          <input type="password" id="new_password_input" name="new_password" class="form-control" required minlength="6" placeholder="Masukkan password baru (min 6 karakter)">
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
          <input type="checkbox" id="chk_show_reset_pwd" onchange="toggleResetPwdVisibility(this)" style="width: 16px; height: 16px;">
          <label for="chk_show_reset_pwd" style="font-size: 0.8rem; color: var(--text-subtle); cursor: pointer; margin: 0;">Tampilkan Password</label>
        </div>
      </div>
      <div class="card-header" style="display: flex; justify-content: flex-end; gap: 0.75rem; border-top: 1px solid var(--border);">
        <button type="button" class="btn btn-secondary" onclick="closeResetModal()">Batal</button>
        <button type="submit" class="btn btn-primary" style="font-weight: 700;">Simpan Password Baru</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL EDIT PENGGUNA -->
<div id="modal_edit_user" class="qr-modal-overlay" style="display: none;">
  <div class="card" style="max-width: 460px; width: 90%; margin: auto; animation: fadeIn 0.2s ease;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
      <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-main); margin: 0;">✏️ Edit Data Pengguna</h3>
      <button type="button" onclick="closeEditModal()" style="background: none; border: none; font-size: 1.5rem; color: var(--text-subtle); cursor: pointer; min-width: 40px; min-height: 40px;">&times;</button>
    </div>
    <form method="POST" action="">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="user_id" id="edit_user_id">
      <div class="card-body">
        <div class="form-group" style="margin-bottom: 1rem;">
          <label class="form-label">Username (Tetap)</label>
          <input type="text" id="edit_username" class="form-control" disabled style="background: var(--bg-alt); opacity: 0.8;">
        </div>
        <div class="form-group" style="margin-bottom: 1rem;">
          <label class="form-label">Nama Lengkap *</label>
          <input type="text" id="edit_fullname" name="full_name" class="form-control" required>
        </div>
        <?php if ($currentUser['role'] === 'superadmin'): ?>
          <div class="form-group" style="margin-bottom: 1rem;">
            <label class="form-label">Role / Level Akses</label>
            <select id="edit_role" name="role" class="form-control">
              <option value="superadmin">Super Administrator</option>
              <option value="admin">Administrator</option>
              <option value="user">User Biasa</option>
            </select>
          </div>
        <?php endif; ?>
        <div style="display: flex; align-items: center; gap: 8px; margin-top: 0.5rem;">
          <input type="checkbox" id="edit_is_active" name="is_active" value="1" style="width: 18px; height: 18px;">
          <label for="edit_is_active" style="font-size: 0.85rem; font-weight: 600; color: var(--text-main); cursor: pointer; margin: 0;">Status Akun Aktif</label>
        </div>
      </div>
      <div class="card-header" style="display: flex; justify-content: flex-end; gap: 0.75rem; border-top: 1px solid var(--border);">
        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Batal</button>
        <button type="submit" class="btn btn-primary" style="font-weight: 700;">Simpan Perubahan</button>
      </div>
    </form>
  </div>
</div>

<script>
  // Search and Role Filter Logic
  (function() {
    const searchInput = document.getElementById('user_search_input');
    const roleFilter = document.getElementById('user_role_filter');
    const rows = document.querySelectorAll('.user-row');

    function applyFilter() {
      const q = (searchInput ? searchInput.value : '').toLowerCase().trim();
      const r = (roleFilter ? roleFilter.value : 'all');

      rows.forEach(function(row) {
        const searchMeta = row.getAttribute('data-search') || '';
        const role = row.getAttribute('data-role') || '';

        const matchSearch = !q || searchMeta.includes(q);
        const matchRole = (r === 'all') || (role === r);

        row.style.display = (matchSearch && matchRole) ? '' : 'none';
      });
    }

    if (searchInput) searchInput.addEventListener('input', applyFilter);
    if (roleFilter) roleFilter.addEventListener('change', applyFilter);
  })();

  // Modal Control Functions
  function openAddUserModal() {
    document.getElementById('modal_add_user').style.display = 'flex';
  }
  function closeAddUserModal() {
    document.getElementById('modal_add_user').style.display = 'none';
  }

  function openResetModal(user) {
    document.getElementById('reset_user_id').value = user.id;
    document.getElementById('reset_user_name').innerText = user.full_name;
    document.getElementById('reset_user_role').innerText = user.role;
    document.getElementById('new_password_input').value = '';
    document.getElementById('modal_reset_pwd').style.display = 'flex';
  }
  function closeResetModal() {
    document.getElementById('modal_reset_pwd').style.display = 'none';
  }
  function toggleResetPwdVisibility(checkbox) {
    const input = document.getElementById('new_password_input');
    if (input) {
      input.type = checkbox.checked ? 'text' : 'password';
    }
  }

  function openEditModal(user) {
    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('edit_username').value = user.username;
    document.getElementById('edit_fullname').value = user.full_name;
    const roleSelect = document.getElementById('edit_role');
    if (roleSelect) {
      roleSelect.value = user.role;
    }
    document.getElementById('edit_is_active').checked = (parseInt(user.is_active) === 1);
    document.getElementById('modal_edit_user').style.display = 'flex';
  }
  function closeEditModal() {
    document.getElementById('modal_edit_user').style.display = 'none';
  }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
