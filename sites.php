<?php
/**
 * Master Data Site Management
 * PT Angkasa Pura Sarana Digital
 */
require_once __DIR__ . '/config/database.php';
$pdo = get_db_connection();

$message = '';
$messageType = '';

// Handle Add Site
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_site') {
    $site_name = trim($_POST['site_name'] ?? '');
    $site_id_str = trim($_POST['site_id_str'] ?? '');
    $location_detail = trim($_POST['location_detail'] ?? '');
    $region = trim($_POST['region'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');
    $site_height = trim($_POST['site_height'] ?? '');
    $tower_type = trim($_POST['tower_type'] ?? '');
    $site_type = trim($_POST['site_type'] ?? '');
    $anchor_tenant = trim($_POST['anchor_tenant'] ?? 'APSD');
    $total_tenant = intval($_POST['total_tenant'] ?? 1);

    if (!empty($site_name)) {
        $stmt = $pdo->prepare("
            INSERT INTO sites (
                site_name, site_id_str, location_detail, region, province, city, 
                address, latitude, longitude, site_height, tower_type, site_type, anchor_tenant, total_tenant
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $site_name, $site_id_str, $location_detail, $region, $province, $city,
            $address, $latitude, $longitude, $site_height, $tower_type, $site_type, $anchor_tenant, $total_tenant
        ]);
        $message = 'Master Site baru berhasil ditambahkan!';
        $messageType = 'success';
        require_once __DIR__ . '/config/redis.php';
        AppRedis::flushPrefix('dashboard:');
    } else {
        $message = 'Nama Site wajib diisi!';
        $messageType = 'danger';
    }
}

// Handle Delete Site
if (isset($_GET['delete'])) {
    $delId = intval($_GET['delete']);
    $stmt = $pdo->prepare("DELETE FROM sites WHERE id = ?");
    $stmt->execute([$delId]);
    require_once __DIR__ . '/config/redis.php';
    AppRedis::flushPrefix('dashboard:');
    header("Location: sites.php?msg=deleted");
    exit;
}

if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
    $message = 'Site berhasil dihapus.';
    $messageType = 'success';
}

$sites = $pdo->query("SELECT * FROM sites ORDER BY id DESC")->fetchAll();

$page_title = 'Kelola Master Data Site';
include __DIR__ . '/includes/header.php';
?>

<main class="container">
  
  <?php if (!empty($message)): ?>
    <div class="alert alert-<?= $messageType ?>">
      <?= htmlspecialchars($message) ?>
    </div>
  <?php endif; ?>

  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
      <h2 style="font-size: 1.35rem; font-weight: 700;">Master Data Lokasi Site</h2>
      <p style="color: var(--text-subtle); font-size: 0.9rem;">Daftar site telekomunikasi dan tower bandara untuk pengisian formulir otomatis.</p>
    </div>
    <a href="index.php" class="btn btn-secondary btn-sm">&larr; Kembali ke Dashboard</a>
  </div>

  <div class="card">
    <div class="card-header">
      <h3 class="card-title">+ Tambah Data Site Baru</h3>
    </div>
    <div class="card-body">
      <form action="sites.php" method="POST">
        <input type="hidden" name="action" value="add_site">

        <div class="form-grid" style="margin-bottom: 1rem;">
          <div class="form-group">
            <label class="form-label">Nama Site *</label>
            <input type="text" name="site_name" class="form-control" placeholder="Contoh: Bandara Kualanamu Pole 2" required>
          </div>

          <div class="form-group">
            <label class="form-label">Site ID</label>
            <input type="text" name="site_id_str" class="form-control" placeholder="Contoh: APSD-DSER-078-08">
          </div>

          <div class="form-group">
            <label class="form-label">Detail Lokasi / Area</label>
            <input type="text" name="location_detail" class="form-control" placeholder="Contoh: Server Domestik / Pole 2">
          </div>

          <div class="form-group">
            <label class="form-label">Region</label>
            <input type="text" name="region" class="form-control" placeholder="Contoh: Sumbagut / Deli Serdang">
          </div>

          <div class="form-group">
            <label class="form-label">Provinsi</label>
            <input type="text" name="province" class="form-control" value="Sumatra Utara">
          </div>

          <div class="form-group">
            <label class="form-label">Kota / Kabupaten</label>
            <input type="text" name="city" class="form-control" value="Medan">
          </div>

          <div class="form-group">
            <label class="form-label">Latitude</label>
            <input type="text" name="latitude" class="form-control" placeholder="Contoh: 3.618278">
          </div>

          <div class="form-group">
            <label class="form-label">Longitude</label>
            <input type="text" name="longitude" class="form-control" placeholder="Contoh: 98.861139">
          </div>

          <div class="form-group">
            <label class="form-label">Tinggi Site (Meter)</label>
            <input type="text" name="site_height" class="form-control" placeholder="Contoh: 18">
          </div>

          <div class="form-group">
            <label class="form-label">Tipe Tower</label>
            <input type="text" name="tower_type" class="form-control" placeholder="Contoh: Pole PJU 18 m, Non Pole">
          </div>

          <div class="form-group">
            <label class="form-label">Tipe Site</label>
            <select name="site_type" class="form-control">
              <option value="Indoor">Indoor</option>
              <option value="Green Field">Green Field (Outdoor)</option>
              <option value="Rooftop">Rooftop</option>
              <option value="Outdoor">Outdoor</option>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Anchor Tenant</label>
            <input type="text" name="anchor_tenant" class="form-control" value="APSD">
          </div>
        </div>

        <div class="form-group" style="margin-bottom: 1.25rem;">
          <label class="form-label">Alamat Lengkap Site</label>
          <textarea name="address" class="form-control" rows="2" placeholder="Jl. Bandara Kuala Namu, Ps. Enam Kuala Namu, Kec. Beringin..."></textarea>
        </div>

        <button type="submit" class="btn btn-primary">Simpan Master Site</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <h3 class="card-title">Daftar Master Site Tersimpan (<?= count($sites) ?>)</h3>
    </div>
    <div class="card-body" style="padding: 0; overflow-x: auto;">
      <table class="checklist-table">
        <thead>
          <tr>
            <th>No</th>
            <th>Nama Site & ID</th>
            <th>Tipe Site / Tower</th>
            <th>Region & Kota</th>
            <th>Koordinat (Lat, Long)</th>
            <th style="text-align: right;">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sites as $idx => $s): ?>
            <tr>
              <td><?= $idx + 1 ?></td>
              <td>
                <strong><?= htmlspecialchars($s['site_name']) ?></strong>
                <?php if (!empty($s['site_id_str'])): ?>
                  <span style="display: block; font-size: 0.75rem; color: var(--text-subtle);"><?= htmlspecialchars($s['site_id_str']) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <span style="font-weight: 600;"><?= htmlspecialchars($s['site_type'] ?: '-') ?></span>
                <div style="font-size: 0.75rem; color: var(--text-subtle);"><?= htmlspecialchars($s['tower_type'] ?: '-') ?></div>
              </td>
              <td>
                <?= htmlspecialchars($s['region'] ?: '-') ?>, <?= htmlspecialchars($s['city'] ?: '-') ?>
              </td>
              <td style="font-size: 0.8rem; font-family: monospace;">
                <?= htmlspecialchars($s['latitude'] ?: '-') ?>, <?= htmlspecialchars($s['longitude'] ?: '-') ?>
              </td>
              <td style="text-align: right;">
                <a href="sites.php?delete=<?= $s['id'] ?>" onclick="return confirm('Hapus site ini?');" class="btn btn-danger btn-sm">
                  Hapus
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
