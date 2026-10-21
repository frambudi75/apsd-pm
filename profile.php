<?php
/**
 * Profil Pengguna & Manajemen Akun Mandiri
 * IASS SBU ICT KNO
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/signature_helper.php';
require_login();

$page_title = 'Profil Saya';
$pdo = get_db_connection();
$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);

$successMsg = '';
$errorMsg = '';
$activeTab = 'info';

// Ambil data user lengkap dari database
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$userData = $stmt->fetch();

if (!$userData) {
    die("Pengguna tidak ditemukan.");
}

// Tangani aksi form POST
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // 1. UPDATE INFORMASI PROFIL
    if ($action === 'update_profile') {
        $activeTab = 'info';
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $canEditJabatan = in_array($userData['role'], ['superadmin', 'admin']);
        $jabatan = $canEditJabatan ? trim($_POST['jabatan'] ?? '') : ($userData['jabatan'] ?? 'Teknisi Jaga NOC');
        if (empty($jabatan)) $jabatan = $userData['jabatan'] ?: 'Teknisi Jaga NOC';

        if (empty($fullName)) {
            $errorMsg = 'Nama lengkap wajib diisi.';
        } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMsg = 'Format alamat email tidak valid.';
        } else {
            // Bersihkan nomor telepon
            $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);

            try {
                $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'sqlite') {
                    $upd = $pdo->prepare("UPDATE users SET full_name = ?, jabatan = ?, email = ?, phone = ?, updated_at = datetime('now', 'localtime') WHERE id = ?");
                } else {
                    $upd = $pdo->prepare("UPDATE users SET full_name = ?, jabatan = ?, email = ?, phone = ?, updated_at = NOW() WHERE id = ?");
                }
                $upd->execute([$fullName, $jabatan, $email ?: null, $cleanPhone ?: null, $userId]);

                // Perbarui sesi aktif agar langsung tampil di header & sidebar
                $_SESSION['full_name'] = $fullName;

                // Muat ulang data user
                $stmt->execute([$userId]);
                $userData = $stmt->fetch();

                $successMsg = 'Informasi profil berhasil diperbarui.';
            } catch (Exception $e) {
                $errorMsg = 'Gagal menyimpan profil: ' . $e->getMessage();
            }
        }
    }

    // 2. GANTI / RESET PASSWORD MANDIRI
    elseif ($action === 'change_password') {
        $activeTab = 'security';
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($currentPassword)) {
            $errorMsg = 'Password saat ini wajib diisi untuk verifikasi keamanan.';
        } elseif (!password_verify($currentPassword, $userData['password_hash'])) {
            $errorMsg = 'Password saat ini tidak sesuai. Mohon periksa kembali.';
        } elseif (empty($newPassword) || strlen($newPassword) < 6) {
            $errorMsg = 'Password baru minimal harus terdiri dari 6 karakter.';
        } elseif ($newPassword !== $confirmPassword) {
            $errorMsg = 'Konfirmasi password baru tidak cocok. Silakan ulangi.';
        } elseif ($currentPassword === $newPassword) {
            $errorMsg = 'Password baru tidak boleh sama dengan password saat ini.';
        } else {
            try {
                $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
                $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'sqlite') {
                    $updPwd = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = datetime('now', 'localtime') WHERE id = ?");
                } else {
                    $updPwd = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
                }
                $updPwd->execute([$newHash, $userId]);

                // Muat ulang data user
                $stmt->execute([$userId]);
                $userData = $stmt->fetch();

                $successMsg = 'Password berhasil diubah. Gunakan password baru Anda saat login berikutnya.';
            } catch (Exception $e) {
                $errorMsg = 'Gagal memperbarui password: ' . $e->getMessage();
            }
        }
    }

    // 3. UPDATE TANDA TANGAN DIGITAL
    elseif ($action === 'update_signature') {
        $activeTab = 'signature';
        $newSig = null;

        if (!empty($_POST['user_signature_canvas'])) {
            $newSig = save_signature_from_base64($_POST['user_signature_canvas'], 'sig_user_' . $userId);
        } elseif (isset($_FILES['user_signature_file']) && $_FILES['user_signature_file']['error'] === UPLOAD_ERR_OK) {
            $newSig = save_signature_from_file($_FILES['user_signature_file'], 'sig_user_' . $userId);
        }

        if ($newSig) {
            if (!empty($userData['signature_path']) && $userData['signature_path'] !== $newSig) {
                delete_signature_file($userData['signature_path']);
            }
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $timeSql = ($driver === 'sqlite') ? "datetime('now', 'localtime')" : "NOW()";
            $upd = $pdo->prepare("UPDATE users SET signature_path = ?, updated_at = {$timeSql} WHERE id = ?");
            $upd->execute([$newSig, $userId]);

            $stmt->execute([$userId]);
            $userData = $stmt->fetch();
            $successMsg = 'Tanda tangan digital Anda berhasil disimpan dan siap digunakan.';
        } else {
            $errorMsg = 'Gagal menyimpan tanda tangan. Mohon goreskan tanda tangan pada canvas atau pilih file gambar yang valid.';
        }
    }

    // 4. HAPUS TANDA TANGAN DIGITAL
    elseif ($action === 'delete_signature') {
        $activeTab = 'signature';
        if (!empty($userData['signature_path'])) {
            delete_signature_file($userData['signature_path']);
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $timeSql = ($driver === 'sqlite') ? "datetime('now', 'localtime')" : "NOW()";
            $upd = $pdo->prepare("UPDATE users SET signature_path = NULL, updated_at = {$timeSql} WHERE id = ?");
            $upd->execute([$userId]);

            $stmt->execute([$userId]);
            $userData = $stmt->fetch();
            $successMsg = 'Tanda tangan digital Anda telah dihapus.';
        }
    }
}

// Statistik aktivitas pengguna
$userStats = [
    'shifts_count' => 0,
    'activities_count' => 0,
    'photos_count' => 0
];
try {
    $st1 = $pdo->prepare("SELECT COUNT(*) FROM daily_shifts WHERE user_id = ?");
    $st1->execute([$userId]);
    $userStats['shifts_count'] = (int)$st1->fetchColumn();

    $st2 = $pdo->prepare("SELECT COUNT(*) FROM daily_activities WHERE user_id = ?");
    $st2->execute([$userId]);
    $userStats['activities_count'] = (int)$st2->fetchColumn();

    $st3 = $pdo->prepare("
        SELECT COUNT(p.id) 
        FROM daily_activity_photos p
        INNER JOIN daily_activities a ON p.activity_id = a.id
        WHERE a.user_id = ?
    ");
    $st3->execute([$userId]);
    $userStats['photos_count'] = (int)$st3->fetchColumn();
} catch (Exception $e) {}

$roleLabel = ($userData['role'] === 'superadmin') ? 'Super Administrator' : (($userData['role'] === 'admin') ? 'Administrator' : 'Teknisi Lapangan');
$roleBadgeClass = ($userData['role'] === 'superadmin') ? 'role-superadmin' : (($userData['role'] === 'admin') ? 'role-admin' : 'role-user');
$initials = strtoupper(substr($userData['full_name'] ?: $userData['username'], 0, 2));

include __DIR__ . '/includes/header.php';
?>

<div class="container py-4">

  <!-- Breadcrumb & Top Bar -->
  <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
    <div>
      <div style="font-size: 0.8rem; color: var(--text-subtle); display: flex; align-items: center; gap: 6px;">
        <a href="./" style="color: inherit; text-decoration: none;">Dashboard</a>
        <span>/</span>
        <span style="color: var(--primary); font-weight: 600;">Profil Pengguna</span>
      </div>
      <h2 style="font-size: 1.45rem; font-weight: 800; color: var(--text-main); margin-top: 0.25rem; letter-spacing: -0.02em; display: flex; align-items: center; gap: 8px;">
        <?= app_icon('user', 24, 'text-primary') ?>
        <span>Pengaturan Akun &amp; Profil Saya</span>
      </h2>
      <p style="font-size: 0.82rem; color: var(--text-subtle); margin: 0.2rem 0 0 0;">
        Kelola identitas akun Anda, perbarui kontak, dan lakukan penggantian password mandiri secara berkala demi keamanan.
      </p>
    </div>

    <div>
      <span class="user-role-badge <?= $roleBadgeClass ?>" style="font-size: 0.8rem; padding: 6px 14px; display: inline-flex; align-items: center; gap: 6px;">
        <?= app_icon('shield', 14) ?> <?= htmlspecialchars($roleLabel) ?>
      </span>
    </div>
  </div>

  <?php if (!empty($successMsg)): ?>
    <div class="alert alert-success" style="background: var(--status-ok-bg); color: var(--status-ok); border: 1px solid var(--status-ok-border); border-radius: var(--radius-md); padding: 0.85rem 1.15rem; margin-bottom: 1.5rem; font-weight: 600; display: flex; align-items: center; gap: 8px;">
      <?= app_icon('check-circle', 18) ?>
      <span><?= htmlspecialchars($successMsg) ?></span>
    </div>
  <?php endif; ?>

  <?php if (!empty($errorMsg)): ?>
    <div class="alert alert-danger" style="background: var(--status-nok-bg); color: var(--status-nok); border: 1px solid var(--status-nok-border); border-radius: var(--radius-md); padding: 0.85rem 1.15rem; margin-bottom: 1.5rem; font-weight: 600; display: flex; align-items: center; gap: 8px;">
      <?= app_icon('alert-triangle', 18) ?>
      <span><?= htmlspecialchars($errorMsg) ?></span>
    </div>
  <?php endif; ?>

  <!-- Main Profile Layout Grid -->
  <div style="display: grid; grid-template-columns: 320px 1fr; gap: 1.5rem; align-items: flex-start;" class="profile-layout-grid">
    
    <!-- LEFT CARD: Identity & Quick Stats -->
    <div class="card" style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden;">
      
      <!-- Profile Header Accent Banner -->
      <div style="height: 80px; background: linear-gradient(135deg, var(--primary) 0%, #005f73 100%);"></div>

      <!-- Avatar & Info -->
      <div style="padding: 0 1.25rem 1.25rem 1.25rem; text-align: center; margin-top: -45px;">
        <div style="width: 88px; height: 88px; border-radius: 50%; background: var(--bg-card); border: 4px solid var(--bg-card); box-shadow: var(--shadow-md); color: var(--primary); font-size: 1.9rem; font-weight: 800; display: flex; align-items: center; justify-content: center; margin: 0 auto 0.75rem auto;">
          <?= $initials ?>
        </div>
        
        <h3 style="font-size: 1.15rem; font-weight: 800; color: var(--text-main); margin: 0 0 0.2rem 0;">
          <?= htmlspecialchars($userData['full_name'] ?: $userData['username']) ?>
        </h3>
        <p style="font-size: 0.82rem; color: var(--text-subtle); margin: 0 0 0.5rem 0; font-family: monospace;">
          @<?= htmlspecialchars($userData['username']) ?>
        </p>

        <div style="display: inline-block; margin-bottom: 1rem;">
          <span class="user-role-badge <?= $roleBadgeClass ?>" style="font-size: 0.75rem; padding: 4px 10px;">
            <?= htmlspecialchars($roleLabel) ?>
          </span>
        </div>

        <div style="border-top: 1px solid var(--border); padding-top: 1rem; margin-top: 0.25rem; text-align: left; font-size: 0.8rem; display: flex; flex-direction: column; gap: 8px;">
          <div style="display: flex; justify-content: space-between; align-items: center;">
            <span style="color: var(--text-subtle); display: flex; align-items: center; gap: 5px;">
              <?= app_icon('briefcase', 13) ?> Jabatan
            </span>
            <span style="font-weight: 700; color: #0284c7;">
              <?= htmlspecialchars($userData['jabatan'] ?: 'Teknisi Jaga NOC') ?>
            </span>
          </div>

          <div style="display: flex; justify-content: space-between; align-items: center;">
            <span style="color: var(--text-subtle); display: flex; align-items: center; gap: 5px;">
              <?= app_icon('mail', 13) ?> Email
            </span>
            <span style="font-weight: 600; color: var(--text-main);">
              <?= !empty($userData['email']) ? htmlspecialchars($userData['email']) : '<em style="color:var(--text-subtle);">Belum diisi</em>' ?>
            </span>
          </div>

          <div style="display: flex; justify-content: space-between; align-items: center;">
            <span style="color: var(--text-subtle); display: flex; align-items: center; gap: 5px;">
              <?= app_icon('phone', 13) ?> Telepon
            </span>
            <span style="font-weight: 600; color: var(--text-main);">
              <?= !empty($userData['phone']) ? htmlspecialchars($userData['phone']) : '<em style="color:var(--text-subtle);">Belum diisi</em>' ?>
            </span>
          </div>

          <div style="display: flex; justify-content: space-between; align-items: center;">
            <span style="color: var(--text-subtle); display: flex; align-items: center; gap: 5px;">
              <?= app_icon('clock', 13) ?> Terakhir Masuk
            </span>
            <span style="font-weight: 600; color: var(--text-main);">
              <?= !empty($userData['last_login']) ? date('d M Y H:i', strtotime($userData['last_login'])) : 'Sesi Ini' ?>
            </span>
          </div>

          <div style="display: flex; justify-content: space-between; align-items: center;">
            <span style="color: var(--text-subtle); display: flex; align-items: center; gap: 5px;">
              <?= app_icon('calendar', 13) ?> Terdaftar Sejak
            </span>
            <span style="font-weight: 600; color: var(--text-main);">
              <?= !empty($userData['created_at']) ? date('d M Y', strtotime($userData['created_at'])) : '-' ?>
            </span>
          </div>
        </div>

      </div>

      <!-- Quick Contribution Mini Stats -->
      <div style="background: var(--bg-alt); border-top: 1px solid var(--border); padding: 0.85rem 1rem;">
        <span style="font-size: 0.72rem; font-weight: 700; color: var(--text-subtle); text-transform: uppercase; letter-spacing: 0.04em; display: block; margin-bottom: 0.5rem;">
          Rekapitulasi Aktivitas Anda
        </span>
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px; text-align: center;">
          <div style="background: var(--bg-card); padding: 6px 4px; border-radius: var(--radius-sm); border: 1px solid var(--border);">
            <div style="font-size: 1.15rem; font-weight: 800; color: var(--primary);"><?= $userStats['shifts_count'] ?></div>
            <div style="font-size: 0.65rem; color: var(--text-subtle); font-weight: 600;">Sesi Shift</div>
          </div>
          <div style="background: var(--bg-card); padding: 6px 4px; border-radius: var(--radius-sm); border: 1px solid var(--border);">
            <div style="font-size: 1.15rem; font-weight: 800; color: #0284c7;"><?= $userStats['activities_count'] ?></div>
            <div style="font-size: 0.65rem; color: var(--text-subtle); font-weight: 600;">Kegiatan</div>
          </div>
          <div style="background: var(--bg-card); padding: 6px 4px; border-radius: var(--radius-sm); border: 1px solid var(--border);">
            <div style="font-size: 1.15rem; font-weight: 800; color: #16a34a;"><?= $userStats['photos_count'] ?></div>
            <div style="font-size: 0.65rem; color: var(--text-subtle); font-weight: 600;">Foto</div>
          </div>
        </div>
      </div>

      <!-- Quick Logout Action -->
      <div style="background: var(--bg-card); border-top: 1px solid var(--border); padding: 0.85rem 1rem;">
        <a href="<?= get_app_base_url() ?>logout" class="btn btn-danger" id="btn_profile_logout" style="width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 8px; font-weight: 700; padding: 0.55rem; text-decoration: none; border-radius: var(--radius-sm);" title="Keluar dari sesi akun saat ini">
          <?= app_icon('log-out', 16) ?>
          <span>Keluar dari Akun</span>
        </a>
      </div>

    </div>

    <!-- RIGHT SECTION: Edit Forms (Profile Info & Password Reset) -->
    <div style="display: flex; flex-direction: column; gap: 1.5rem;">

      <!-- CARD 1: Edit Informasi Profil -->
      <div class="settings-card" style="margin-bottom: 0;">
        <div class="settings-card-header">
          <div class="settings-card-header-icon">
            <?= app_icon('user', 18) ?>
          </div>
          <div>
            <h3 class="settings-card-title">Informasi Profil Pengguna</h3>
            <p class="settings-card-desc">Perbarui nama tampilan dan kontak resmi Anda untuk laporan kegiatan harian NOC.</p>
          </div>
        </div>
        <div class="settings-card-body">
          <form method="POST" action="profile">
            <input type="hidden" name="action" value="update_profile">

            <div class="settings-grid-2">
              <div class="form-group">
                <label class="form-label" style="font-weight: 700; font-size: 0.82rem;">
                  Nama Lengkap <span style="color: red;">*</span>
                </label>
                <div class="settings-input-group">
                  <span class="settings-input-icon"><?= app_icon('user', 15) ?></span>
                  <input type="text" name="full_name" class="form-control settings-control-iconic" value="<?= htmlspecialchars($userData['full_name']) ?>" required placeholder="Contoh: Budi Santoso">
                </div>
                <div class="settings-help-text">Nama lengkap resmi yang akan tercantum di lembar logbook dan tanda tangan laporan.</div>
              </div>

              <div class="form-group">
                <label class="form-label" style="font-weight: 700; font-size: 0.82rem;">
                  Jabatan / Posisi Resmi <?= in_array($userData['role'], ['superadmin', 'admin']) ? '<span style="color: red;">*</span>' : '' ?>
                </label>
                <div class="settings-input-group">
                  <span class="settings-input-icon"><?= app_icon('briefcase', 15) ?></span>
                  <?php if (in_array($userData['role'], ['superadmin', 'admin'])): ?>
                    <input type="text" name="jabatan" class="form-control settings-control-iconic" value="<?= htmlspecialchars($userData['jabatan'] ?: 'Teknisi Jaga NOC') ?>" required placeholder="Contoh: Supervisor NOC, Teknisi Jaga NOC">
                  <?php else: ?>
                    <input type="text" class="form-control settings-control-iconic" value="<?= htmlspecialchars($userData['jabatan'] ?: 'Teknisi Jaga NOC') ?>" disabled readonly style="background: var(--bg-alt); cursor: not-allowed;">
                  <?php endif; ?>
                </div>
                <div class="settings-help-text">
                  <?= in_array($userData['role'], ['superadmin', 'admin']) ? 'Jabatan resmi yang dicantumkan pada pengesahan laporan logbook dan PM.' : 'Ditetapkan oleh Administrator / Superadmin melalui menu Kelola Pengguna.' ?>
                </div>
              </div>

              <div class="form-group">
                <label class="form-label" style="font-weight: 700; font-size: 0.82rem;">
                  Username Sistem
                </label>
                <div class="settings-input-group">
                  <span class="settings-input-icon"><?= app_icon('shield', 15) ?></span>
                  <input type="text" class="form-control settings-control-iconic" value="<?= htmlspecialchars($userData['username']) ?>" disabled readonly style="background: var(--bg-alt); cursor: not-allowed;">
                </div>
                <div class="settings-help-text">Username akun bersifat permanen dan hanya dapat diubah oleh Super Admin.</div>
              </div>
            </div>

            <div class="settings-grid-2" style="margin-top: 1rem;">
              <div class="form-group">
                <label class="form-label" style="font-weight: 700; font-size: 0.82rem;">
                  Alamat Email (Opsional)
                </label>
                <div class="settings-input-group">
                  <span class="settings-input-icon"><?= app_icon('mail', 15) ?></span>
                  <input type="email" name="email" class="form-control settings-control-iconic" value="<?= htmlspecialchars($userData['email'] ?? '') ?>" placeholder="nama@perusahaan.co.id">
                </div>
                <div class="settings-help-text">Email aktif untuk korespondensi dokumen atau pemberitahuan sistem.</div>
              </div>

              <div class="form-group">
                <label class="form-label" style="font-weight: 700; font-size: 0.82rem;">
                  Nomor Telepon / WhatsApp (Opsional)
                </label>
                <div class="settings-input-group">
                  <span class="settings-input-icon"><?= app_icon('phone', 15) ?></span>
                  <input type="text" name="phone" class="form-control settings-control-iconic" value="<?= htmlspecialchars($userData['phone'] ?? '') ?>" placeholder="Contoh: 081234567890">
                </div>
                <div class="settings-help-text">Nomor telepon atau WhatsApp teknisi untuk komunikasi koordinasi lapangan.</div>
              </div>
            </div>

            <div style="display: flex; justify-content: flex-end; margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--border);">
              <button type="submit" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 700; padding: 0.55rem 1.4rem;">
                <?= app_icon('save', 15) ?>
                <span>Simpan Perubahan Profil</span>
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- CARD 2: Tanda Tangan Digital Resmi (E-Signature) -->
      <div class="settings-card" style="margin-bottom: 0;">
        <div class="settings-card-header">
          <div class="settings-card-header-icon" style="background: rgba(0, 128, 128, 0.12); color: var(--primary);">
            <?= app_icon('edit', 18) ?>
          </div>
          <div>
            <h3 class="settings-card-title">Tanda Tangan Digital Resmi</h3>
            <p class="settings-card-desc">Tanda tangan Anda otomatis dicantumkan saat menyelesaikan sesi shift NOC dan pada lembar cetak laporan resmi.</p>
          </div>
        </div>
        <div class="settings-card-body">
          <?php if (!empty($userData['signature_path']) && file_exists(__DIR__ . '/' . $userData['signature_path'])): ?>
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; background: var(--bg-alt); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px 18px; margin-bottom: 1.25rem;">
              <div style="display: flex; align-items: center; gap: 16px;">
                <div style="background: #ffffff; border: 1px dashed #cbd5e1; border-radius: 6px; padding: 6px 14px; display: inline-flex; align-items: center; justify-content: center; min-width: 140px; min-height: 60px;">
                  <img src="<?= htmlspecialchars($userData['signature_path']) ?>?v=<?= time() ?>" alt="TTD Saya" style="max-height: 52px; max-width: 140px; object-fit: contain;">
                </div>
                <div>
                  <div style="display: flex; align-items: center; gap: 6px;">
                    <span style="font-size: 0.88rem; font-weight: 700; color: var(--text-main);">Tanda Tangan Aktif</span>
                    <span class="user-role-badge role-admin" style="font-size: 0.7rem; padding: 2px 7px;">Siap Digunakan</span>
                  </div>
                  <div style="font-size: 0.74rem; color: var(--text-subtle); margin-top: 2px;">Tanda tangan ini otomatis diterapkan pada setiap shift yang Anda selesaikan.</div>
                </div>
              </div>

              <form method="POST" action="profile" onsubmit="return confirm('Apakah Anda yakin ingin menghapus tanda tangan digital ini?');" style="margin: 0;">
                <input type="hidden" name="action" value="delete_signature">
                <button type="submit" class="btn btn-outline-danger btn-sm" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600; font-size: 0.78rem;">
                  <?= app_icon('trash', 14) ?>
                  <span>Hapus Tanda Tangan</span>
                </button>
              </form>
            </div>
          <?php endif; ?>

          <form method="POST" action="profile" enctype="multipart/form-data" id="form_signature">
            <input type="hidden" name="action" value="update_signature">

            <div style="background: var(--bg-alt); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px;">
              <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                <button type="button" class="btn btn-sm btn-secondary sig-user-tab-btn active" id="tab_btn_user_draw" onclick="switchUserSigTab('draw')" style="font-size: 0.76rem; font-weight: 700;">
                  Gores di Layar
                </button>
                <button type="button" class="btn btn-sm btn-secondary sig-user-tab-btn" id="tab_btn_user_upload" onclick="switchUserSigTab('upload')" style="font-size: 0.76rem; font-weight: 700; opacity: 0.75;">
                  Upload File Gambar
                </button>
              </div>

              <!-- Panel Gores Layar Canvas -->
              <div id="panel_user_draw">
                <div style="position: relative; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; overflow: hidden; width: 100%; height: 150px;">
                  <canvas id="canvas_user_signature" style="width: 100%; height: 150px; display: block; cursor: crosshair;"></canvas>
                  <div style="position: absolute; bottom: 6px; right: 8px;">
                    <button type="button" class="btn btn-light btn-xs" id="btn_clear_user_canvas" style="font-size: 0.7rem; padding: 3px 8px; background: rgba(255,255,255,0.92); border: 1px solid #cbd5e1; border-radius: 4px;">
                      Bersihkan Goresan
                    </button>
                  </div>
                  <div style="position: absolute; top: 6px; left: 10px; font-size: 0.68rem; color: #94a3b8; pointer-events: none;">
                    Goreskan tanda tangan Anda di area ini (Touchscreen / Mouse)
                  </div>
                </div>
                <input type="hidden" name="user_signature_canvas" id="input_user_signature_canvas" value="">
              </div>

              <!-- Panel Upload File -->
              <div id="panel_user_upload" style="display: none;">
                <input type="file" name="user_signature_file" accept="image/png,image/jpeg,image/webp" class="form-control" style="font-size: 0.8rem;">
                <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 5px;">
                  Format didukung: PNG (latar belakang transparan sangat disarankan), JPG, atau WebP. Maks 5 MB.
                </div>
              </div>
            </div>

            <div style="display: flex; justify-content: flex-end; margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--border);">
              <button type="submit" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 700; padding: 0.55rem 1.4rem;">
                <?= app_icon('check', 15) ?>
                <span>Simpan Tanda Tangan Digital</span>
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- CARD 2: Ganti / Reset Password Mandiri -->
      <div class="settings-card" style="margin-bottom: 0;">
        <div class="settings-card-header">
          <div class="settings-card-header-icon" style="background: #fef3c7; color: #d97706;">
            <?= app_icon('shield', 18) ?>
          </div>
          <div>
            <h3 class="settings-card-title">Ganti &amp; Reset Password Mandiri</h3>
            <p class="settings-card-desc">Perbarui password akun Anda secara mandiri untuk menjaga keamanan akses sistem IASS SBU ICT KNO.</p>
          </div>
        </div>
        <div class="settings-card-body">
          <form method="POST" action="profile" id="form_change_pwd">
            <input type="hidden" name="action" value="change_password">

            <div class="form-group mb-3">
              <label class="form-label" style="font-weight: 700; font-size: 0.82rem;">
                Password Saat Ini <span style="color: red;">*</span>
              </label>
              <div class="settings-input-group">
                <span class="settings-input-icon"><?= app_icon('shield', 15) ?></span>
                <input type="password" name="current_password" id="input_cur_pwd" class="form-control settings-control-iconic has-toggle" required placeholder="Masukkan password yang sedang digunakan saat ini">
                <button type="button" class="btn-toggle-pwd" onclick="togglePwdVisibility('input_cur_pwd', this)" title="Lihat / sembunyikan password">
                  <?= app_icon('eye', 15) ?>
                </button>
              </div>
              <div class="settings-help-text">Verifikasi password lama diperlukan untuk memastikan keaslian pemegang akun.</div>
            </div>

            <div class="settings-grid-2">
              <div class="form-group">
                <label class="form-label" style="font-weight: 700; font-size: 0.82rem;">
                  Password Baru <span style="color: red;">*</span>
                </label>
                <div class="settings-input-group">
                  <span class="settings-input-icon"><?= app_icon('shield', 15) ?></span>
                  <input type="password" name="new_password" id="input_new_pwd" class="form-control settings-control-iconic has-toggle" minlength="6" required placeholder="Minimal 6 karakter">
                  <button type="button" class="btn-toggle-pwd" onclick="togglePwdVisibility('input_new_pwd', this)" title="Lihat / sembunyikan password">
                    <?= app_icon('eye', 15) ?>
                  </button>
                </div>
                <div class="settings-help-text">Gunakan kombinasi huruf dan angka minimal 6 karakter.</div>
              </div>

              <div class="form-group">
                <label class="form-label" style="font-weight: 700; font-size: 0.82rem;">
                  Konfirmasi Password Baru <span style="color: red;">*</span>
                </label>
                <div class="settings-input-group">
                  <span class="settings-input-icon"><?= app_icon('check-circle', 15) ?></span>
                  <input type="password" name="confirm_password" id="input_conf_pwd" class="form-control settings-control-iconic has-toggle" minlength="6" required placeholder="Ketik ulang password baru">
                  <button type="button" class="btn-toggle-pwd" onclick="togglePwdVisibility('input_conf_pwd', this)" title="Lihat / sembunyikan password">
                    <?= app_icon('eye', 15) ?>
                  </button>
                </div>
                <div class="settings-help-text" id="pwd_match_hint">Pastikan konfirmasi password sama persis dengan password baru.</div>
              </div>
            </div>

            <div style="display: flex; justify-content: flex-end; margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--border);">
              <button type="submit" class="btn btn-warning" style="background: #d97706; color: #ffffff; border: none; display: inline-flex; align-items: center; gap: 8px; font-weight: 700; padding: 0.55rem 1.4rem;">
                <?= app_icon('shield', 15) ?>
                <span>Perbarui Password Akun</span>
              </button>
            </div>
          </form>
        </div>
      </div>

    </div>

  </div>

</div>

<style>
@media (max-width: 860px) {
  .profile-layout-grid {
    grid-template-columns: 1fr !important;
  }
}
</style>

<script>
function togglePwdVisibility(inputId, btn) {
  var input = document.getElementById(inputId);
  if (!input) return;
  if (input.type === 'password') {
    input.type = 'text';
    btn.style.color = 'var(--primary)';
  } else {
    input.type = 'password';
    btn.style.color = 'var(--text-subtle)';
  }
}

// Live password match check
document.addEventListener('DOMContentLoaded', function() {
  var newPwd = document.getElementById('input_new_pwd');
  var confPwd = document.getElementById('input_conf_pwd');
  var hint = document.getElementById('pwd_match_hint');

  function checkMatch() {
    if (!newPwd || !confPwd || !hint) return;
    if (confPwd.value.length === 0) {
      hint.textContent = 'Pastikan konfirmasi password sama persis dengan password baru.';
      hint.style.color = 'var(--text-subtle)';
      return;
    }
    if (newPwd.value === confPwd.value) {
      hint.textContent = 'Password cocok!';
      hint.style.color = 'var(--status-ok)';
    } else {
      hint.textContent = 'Password belum cocok, mohon samakan.';
      hint.style.color = 'var(--status-nok)';
    }
  }

  if (newPwd) newPwd.addEventListener('input', checkMatch);
  if (confPwd) confPwd.addEventListener('input', checkMatch);
});
</script>

<script src="assets/js/signature-pad.js"></script>
<script>
var userSigPad = null;

function switchUserSigTab(type) {
  var btnDraw = document.getElementById('tab_btn_user_draw');
  var btnUp = document.getElementById('tab_btn_user_upload');
  var pnlDraw = document.getElementById('panel_user_draw');
  var pnlUp = document.getElementById('panel_user_upload');

  if (type === 'draw') {
    if (btnDraw) btnDraw.style.opacity = '1';
    if (btnUp) btnUp.style.opacity = '0.75';
    if (pnlDraw) pnlDraw.style.display = 'block';
    if (pnlUp) pnlUp.style.display = 'none';
  } else {
    if (btnDraw) btnDraw.style.opacity = '0.75';
    if (btnUp) btnUp.style.opacity = '1';
    if (pnlDraw) pnlDraw.style.display = 'none';
    if (pnlUp) pnlUp.style.display = 'block';
  }
}

document.addEventListener('DOMContentLoaded', function() {
  var canvasEl = document.getElementById('canvas_user_signature');
  if (canvasEl && window.SignaturePad) {
    userSigPad = new window.SignaturePad(canvasEl, {
      strokeColor: '#0f172a',
      strokeWidth: 2.5
    });

    var clearBtn = document.getElementById('btn_clear_user_canvas');
    if (clearBtn) {
      clearBtn.addEventListener('click', function(e) {
        e.preventDefault();
        userSigPad.clear();
      });
    }

    var formEl = document.getElementById('form_signature');
    if (formEl) {
      formEl.addEventListener('submit', function() {
        if (userSigPad && !userSigPad.isEmpty()) {
          var inputVal = document.getElementById('input_user_signature_canvas');
          if (inputVal) {
            inputVal.value = userSigPad.toDataURL();
          }
        }
      });
    }
  }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
