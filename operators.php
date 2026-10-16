<?php
/**
 * Master Data Operator & Tenant Management
 * IASS SBU ICT KNO - PT Angkasa Pura Sarana Digital
 */
require_once __DIR__ . '/includes/auth.php';
require_login();
require_role('superadmin');

$pdo = get_db_connection();
$page_title = 'Kelola Master Operator / Tenant';

$successMsg = '';
$errorMsg = '';

// Ensure upload directory exists
$uploadDir = __DIR__ . '/uploads/operators';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0777, true);
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. TAMBAH OPERATOR BARU
    if ($action === 'add') {
        $brandName = trim($_POST['brand_name'] ?? '');
        $operatorName = trim($_POST['operator_name'] ?? '');
        $operatorCode = strtolower(trim($_POST['operator_code'] ?? ''));
        $displayOrder = intval($_POST['display_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        // Auto-generate code if empty
        if (empty($operatorCode) && !empty($brandName)) {
            $operatorCode = preg_replace('/[^a-z0-9]/', '', strtolower($brandName));
        }

        if (empty($brandName) || empty($operatorName) || empty($operatorCode)) {
            $errorMsg = 'Nama Brand, Nama Resmi Operator, dan Kode Operator wajib diisi.';
        } else {
            // Check uniqueness of operator_code
            $chk = $pdo->prepare("SELECT COUNT(*) FROM operators WHERE operator_code = ?");
            $chk->execute([$operatorCode]);
            if ($chk->fetchColumn() > 0) {
                $errorMsg = 'Kode operator "' . htmlspecialchars($operatorCode) . '" sudah digunakan. Silakan gunakan kode unik lain.';
            } else {
                $logoPath = null;
                // Handle Logo Upload
                if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'webp'])) {
                        $filename = 'logo_' . $operatorCode . '_' . time() . '.' . $ext;
                        $destPath = $uploadDir . '/' . $filename;
                        if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $destPath)) {
                            $logoPath = 'uploads/operators/' . $filename;
                        }
                    } else {
                        $errorMsg = 'Format file logo tidak valid (gunakan PNG, JPG, SVG, atau WebP).';
                    }
                }

                if (empty($errorMsg)) {
                    $stmt = $pdo->prepare("
                        INSERT INTO operators (operator_code, operator_name, brand_name, logo_path, is_active, display_order)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$operatorCode, $operatorName, $brandName, $logoPath, $isActive, $displayOrder]);
                    $successMsg = 'Operator baru "' . htmlspecialchars($brandName) . '" berhasil ditambahkan!';
                }
            }
        }
    }

    // 2. EDIT OPERATOR
    elseif ($action === 'edit') {
        $id = intval($_POST['operator_id'] ?? 0);
        $brandName = trim($_POST['brand_name'] ?? '');
        $operatorName = trim($_POST['operator_name'] ?? '');
        $displayOrder = intval($_POST['display_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (empty($brandName) || empty($operatorName)) {
            $errorMsg = 'Nama Brand dan Nama Resmi Operator wajib diisi.';
        } else {
            $target = $pdo->prepare("SELECT * FROM operators WHERE id = ?");
            $target->execute([$id]);
            $existing = $target->fetch();

            if (!$existing) {
                $errorMsg = 'Operator tidak ditemukan.';
            } else {
                $logoPath = $existing['logo_path'];
                // Check if new logo is uploaded
                if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'webp'])) {
                        $filename = 'logo_' . $existing['operator_code'] . '_' . time() . '.' . $ext;
                        $destPath = $uploadDir . '/' . $filename;
                        if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $destPath)) {
                            $logoPath = 'uploads/operators/' . $filename;
                        }
                    }
                }

                $stmt = $pdo->prepare("
                    UPDATE operators 
                    SET brand_name = ?, operator_name = ?, logo_path = ?, is_active = ?, display_order = ?
                    WHERE id = ?
                ");
                $stmt->execute([$brandName, $operatorName, $logoPath, $isActive, $displayOrder, $id]);
                $successMsg = 'Data operator "' . htmlspecialchars($brandName) . '" berhasil diperbarui!';
            }
        }
    }

    // 3. TOGGLE STATUS AKTIF / NONAKTIF
    elseif ($action === 'toggle_status') {
        $id = intval($_POST['operator_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE operators SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?");
        $stmt->execute([$id]);
        $successMsg = 'Status aktif operator berhasil diubah.';
    }

    // 4. HAPUS OPERATOR
    elseif ($action === 'delete') {
        $id = intval($_POST['operator_id'] ?? 0);
        $target = $pdo->prepare("SELECT * FROM operators WHERE id = ?");
        $target->execute([$id]);
        $op = $target->fetch();

        if ($op) {
            $stmt = $pdo->prepare("DELETE FROM operators WHERE id = ?");
            $stmt->execute([$id]);
            $successMsg = 'Operator "' . htmlspecialchars($op['brand_name']) . '" berhasil dihapus.';
        }
    }

    // Invalidate Redis cache if used
    if (file_exists(__DIR__ . '/config/redis.php')) {
        require_once __DIR__ . '/config/redis.php';
        AppRedis::flushPrefix('dashboard:');
    }
}

// Fetch all operators sorted by display_order, then id
$operators = $pdo->query("SELECT * FROM operators ORDER BY display_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/includes/header.php';
?>

<main class="container">
  
  <?php if (!empty($successMsg)): ?>
    <div class="alert alert-success" style="margin-bottom: 1.25rem;">
      <?= htmlspecialchars($successMsg) ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($errorMsg)): ?>
    <div class="alert alert-danger" style="margin-bottom: 1.25rem;">
      <?= htmlspecialchars($errorMsg) ?>
    </div>
  <?php endif; ?>

  <!-- Header Section -->
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
      <span style="font-size: 0.8rem; font-weight: 700; color: var(--primary); text-transform: uppercase;">Master Data Sistem</span>
      <h2 style="font-size: 1.4rem; font-weight: 700; color: var(--text-main); margin-top: 2px;">Kelola Master Operator / Tenant</h2>
      <p style="color: var(--text-subtle); font-size: 0.9rem;">Daftar provider seluler dan tenant resmi untuk kop laporan, logo kop, dan keterangan slot foto otomatis.</p>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
      <button type="button" class="btn btn-primary" onclick="openAddModal()" style="min-height: 42px; font-weight: 600;">
        + Tambah Operator Baru
      </button>
      <a href="./" class="btn btn-secondary btn-sm" style="min-height: 42px; display: inline-flex; align-items: center;">&larr; Dashboard</a>
    </div>
  </div>

  <!-- Operator Cards Grid -->
  <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.25rem; margin-bottom: 2rem;">
    <?php foreach ($operators as $op): ?>
      <?php 
        $hasLogo = !empty($op['logo_path']) && (file_exists(__DIR__ . '/' . explode('?', $op['logo_path'])[0]));
        $isActive = (int)$op['is_active'] === 1;
      ?>
      <div class="card" style="display: flex; flex-direction: column; justify-content: space-between; border-left: 4px solid <?= $isActive ? 'var(--primary)' : 'var(--text-subtle)' ?>; opacity: <?= $isActive ? '1' : '0.65' ?>;">
        <div class="card-body">
          <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; gap: 0.75rem;">
            <div style="width: 140px; height: 55px; background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 6px; display: flex; align-items: center; justify-content: center;">
              <?php if ($hasLogo): ?>
                <img src="<?= htmlspecialchars($op['logo_path']) ?>" alt="<?= htmlspecialchars($op['brand_name']) ?>" style="max-height: 42px; max-width: 125px; object-fit: contain;">
              <?php else: ?>
                <span style="font-size: 0.75rem; color: #94a3b8; font-weight: 600;">Tanpa Logo</span>
              <?php endif; ?>
            </div>
            <div style="text-align: right;">
              <span class="status-badge <?= $isActive ? 'status-badge-ok' : 'status-badge-warning' ?>" style="font-size: 0.75rem; padding: 0.2rem 0.6rem;">
                <?= $isActive ? '✓ Aktif' : 'Nonaktif' ?>
              </span>
              <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 4px;">Urutan: <?= htmlspecialchars($op['display_order']) ?></div>
            </div>
          </div>

          <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.25rem;">
            <?= htmlspecialchars($op['brand_name']) ?>
          </h3>
          <div style="font-size: 0.85rem; color: var(--text-muted); font-weight: 600; margin-bottom: 0.5rem;">
            <?= htmlspecialchars($op['operator_name']) ?>
          </div>
          <div style="font-size: 0.75rem; color: var(--text-subtle); background: var(--bg-alt); padding: 4px 8px; border-radius: 4px; display: inline-block;">
            Kode: <code style="font-weight: 700; color: var(--primary);"><?= htmlspecialchars($op['operator_code']) ?></code>
          </div>
        </div>

        <div class="card-footer" style="background: var(--bg-alt); display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1.25rem;">
          <form method="POST" style="margin: 0; display: inline;">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="operator_id" value="<?= $op['id'] ?>">
            <button type="submit" class="btn btn-sm btn-secondary" style="font-size: 0.75rem; min-height: 32px;" title="<?= $isActive ? 'Nonaktifkan' : 'Aktifkan' ?>">
              <?= $isActive ? 'Nonaktifkan' : 'Aktifkan' ?>
            </button>
          </form>

          <div style="display: flex; gap: 0.5rem;">
            <button type="button" class="btn btn-sm btn-secondary" style="font-size: 0.75rem; min-height: 32px;" 
                    onclick='openEditModal(<?= json_encode($op, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
              ✏️ Edit
            </button>
            <form method="POST" style="margin: 0; display: inline;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus operator <?= htmlspecialchars($op['brand_name']) ?>?');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="operator_id" value="<?= $op['id'] ?>">
              <button type="submit" class="btn btn-sm" style="background: #fee2e2; color: #ef4444; border: 1px solid #fca5a5; font-size: 0.75rem; min-height: 32px;" title="Hapus">
                🗑️
              </button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Modal Tambah Operator -->
  <div id="addModal" class="modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 1rem;">
    <div class="modal-card" style="background: var(--bg-card); width: 100%; max-width: 520px; border-radius: var(--radius-md); box-shadow: 0 10px 25px rgba(0,0,0,0.25); overflow: hidden;">
      <div style="padding: 1.25rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
        <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--text-main); margin: 0;">+ Tambah Operator / Tenant Baru</h3>
        <button type="button" onclick="closeAddModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-subtle);">&times;</button>
      </div>
      <form action="operators" method="POST" enctype="multipart/form-data" style="padding: 1.25rem;">
        <input type="hidden" name="action" value="add">
        
        <div class="form-group" style="margin-bottom: 1rem;">
          <label class="form-label">Nama Brand / Merk Singkat *</label>
          <input type="text" name="brand_name" id="add_brand_name" class="form-control" placeholder="Contoh: Smartfren, Tri, Mitratel" required oninput="autoFillCode(this.value)">
        </div>

        <div class="form-group" style="margin-bottom: 1rem;">
          <label class="form-label">Nama Lengkap / Resmi Operator *</label>
          <input type="text" name="operator_name" class="form-control" placeholder="Contoh: SMARTFREN TELECOM, PT INDOSAT OOREDOO" required>
        </div>

        <div class="form-grid" style="margin-bottom: 1rem;">
          <div class="form-group">
            <label class="form-label">Kode Unik Sistem *</label>
            <input type="text" name="operator_code" id="add_operator_code" class="form-control" placeholder="Contoh: smartfren" required>
            <span style="font-size: 0.72rem; color: var(--text-subtle);">Gunakan huruf kecil tanpa spasi.</span>
          </div>

          <div class="form-group">
            <label class="form-label">Urutan Tampilan</label>
            <input type="number" name="display_order" class="form-control" value="<?= count($operators) + 1 ?>">
          </div>
        </div>

        <div class="form-group" style="margin-bottom: 1.25rem;">
          <label class="form-label">Upload File Logo (PNG, SVG, JPG, WebP)</label>
          <input type="file" name="logo_file" accept="image/*" class="form-control">
          <span style="font-size: 0.72rem; color: var(--text-subtle);">Logo akan ditampilkan di kop kanan laporan PDF dan pratinjau formulir.</span>
        </div>

        <div class="form-group" style="margin-bottom: 1.5rem;">
          <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.85rem; font-weight: 600; color: var(--text-main);">
            <input type="checkbox" name="is_active" value="1" checked style="width: 18px; height: 18px; accent-color: var(--primary);">
            Aktifkan langsung di formulir PM
          </label>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
          <button type="button" onclick="closeAddModal()" class="btn btn-secondary">Batal</button>
          <button type="submit" class="btn btn-primary" style="font-weight: 700;">Simpan Operator</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal Edit Operator -->
  <div id="editModal" class="modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 1rem;">
    <div class="modal-card" style="background: var(--bg-card); width: 100%; max-width: 520px; border-radius: var(--radius-md); box-shadow: 0 10px 25px rgba(0,0,0,0.25); overflow: hidden;">
      <div style="padding: 1.25rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
        <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--text-main); margin: 0;">✏️ Edit Data Operator</h3>
        <button type="button" onclick="closeEditModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-subtle);">&times;</button>
      </div>
      <form action="operators" method="POST" enctype="multipart/form-data" style="padding: 1.25rem;">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="operator_id" id="edit_operator_id">

        <div class="form-group" style="margin-bottom: 1rem;">
          <label class="form-label">Nama Brand / Merk Singkat *</label>
          <input type="text" name="brand_name" id="edit_brand_name" class="form-control" required>
        </div>

        <div class="form-group" style="margin-bottom: 1rem;">
          <label class="form-label">Nama Lengkap / Resmi Operator *</label>
          <input type="text" name="operator_name" id="edit_operator_name" class="form-control" required>
        </div>

        <div class="form-group" style="margin-bottom: 1rem;">
          <label class="form-label">Urutan Tampilan</label>
          <input type="number" name="display_order" id="edit_display_order" class="form-control">
        </div>

        <div class="form-group" style="margin-bottom: 1.25rem;">
          <label class="form-label">Ganti File Logo (Opsional)</label>
          <input type="file" name="logo_file" accept="image/*" class="form-control">
          <div id="edit_logo_preview" style="margin-top: 6px;"></div>
        </div>

        <div class="form-group" style="margin-bottom: 1.5rem;">
          <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.85rem; font-weight: 600; color: var(--text-main);">
            <input type="checkbox" name="is_active" id="edit_is_active" value="1" style="width: 18px; height: 18px; accent-color: var(--primary);">
            Operator Aktif di Formulir PM
          </label>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
          <button type="button" onclick="closeEditModal()" class="btn btn-secondary">Batal</button>
          <button type="submit" class="btn btn-primary" style="font-weight: 700;">Simpan Perubahan</button>
        </div>
      </form>
    </div>
  </div>

</main>

<script>
function openAddModal() {
  document.getElementById('addModal').style.display = 'flex';
}
function closeAddModal() {
  document.getElementById('addModal').style.display = 'none';
}
function autoFillCode(val) {
  const codeInp = document.getElementById('add_operator_code');
  if (codeInp) {
    codeInp.value = val.toLowerCase().replace(/[^a-z0-9]/g, '');
  }
}

function openEditModal(op) {
  document.getElementById('edit_operator_id').value = op.id;
  document.getElementById('edit_brand_name').value = op.brand_name;
  document.getElementById('edit_operator_name').value = op.operator_name;
  document.getElementById('edit_display_order').value = op.display_order;
  document.getElementById('edit_is_active').checked = (parseInt(op.is_active) === 1);

  const prev = document.getElementById('edit_logo_preview');
  if (op.logo_path) {
    prev.innerHTML = '<span style="font-size:0.75rem; color:var(--text-subtle);">Logo saat ini:</span> <img src="' + op.logo_path + '" style="max-height:28px; margin-left:6px; vertical-align:middle; object-fit:contain;">';
  } else {
    prev.innerHTML = '<span style="font-size:0.75rem; color:var(--text-subtle);">Belum ada logo terunggah.</span>';
  }

  document.getElementById('editModal').style.display = 'flex';
}

function closeEditModal() {
  document.getElementById('editModal').style.display = 'none';
}

// Close modals when clicking outside card
window.addEventListener('click', function(e) {
  const addM = document.getElementById('addModal');
  const editM = document.getElementById('editModal');
  if (e.target === addM) closeAddModal();
  if (e.target === editM) closeEditModal();
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
