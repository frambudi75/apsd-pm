<?php
/**
 * Master Data Site Management
 * PT Angkasa Pura Sarana Digital
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
require_role('superadmin');

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
    $anchor_tenant = trim($_POST['anchor_tenant'] ?? 'IASS SBU ICT KNO');
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
        $newSiteId = $pdo->lastInsertId();
        $message = 'Master Site baru berhasil ditambahkan!';
        $messageType = 'success';
        require_once __DIR__ . '/config/redis.php';
        AppRedis::flushPrefix('dashboard:');
        require_once __DIR__ . '/includes/audit.php';
        log_audit_event('MASTER_DATA', 'SITE_CREATE', 'Tambah master site: ' . $site_name . ' (' . ($site_id_str ?: 'ID ' . $newSiteId) . ')', 'sites', $newSiteId ? intval($newSiteId) : null);
    } else {
        $message = 'Nama Site wajib diisi!';
        $messageType = 'danger';
    }
}

// Handle Edit Site
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_site') {
    $site_id = intval($_POST['site_id'] ?? 0);
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
    $anchor_tenant = trim($_POST['anchor_tenant'] ?? 'IASS SBU ICT KNO');
    $total_tenant = intval($_POST['total_tenant'] ?? 1);

    if ($site_id > 0 && !empty($site_name)) {
        $stmt = $pdo->prepare("
            UPDATE sites SET
                site_name = ?, site_id_str = ?, location_detail = ?, region = ?, province = ?, city = ?, 
                address = ?, latitude = ?, longitude = ?, site_height = ?, tower_type = ?, site_type = ?, 
                anchor_tenant = ?, total_tenant = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $site_name, $site_id_str, $location_detail, $region, $province, $city,
            $address, $latitude, $longitude, $site_height, $tower_type, $site_type, 
            $anchor_tenant, $total_tenant, $site_id
        ]);
        $message = 'Data Master Site berhasil diperbarui!';
        $messageType = 'success';
        require_once __DIR__ . '/config/redis.php';
        AppRedis::flushPrefix('dashboard:');
        require_once __DIR__ . '/includes/audit.php';
        log_audit_event('MASTER_DATA', 'SITE_UPDATE', 'Pembaruan data master site: ' . $site_name . ' (' . ($site_id_str ?: 'ID ' . $site_id) . ')', 'sites', $site_id);
    } else {
        $message = 'Gagal memperbarui: ID Site atau Nama Site tidak valid!';
        $messageType = 'danger';
    }
}

// Handle Delete Site
if (isset($_GET['delete'])) {
    $delId = intval($_GET['delete']);
    $targetStmt = $pdo->prepare("SELECT site_name, site_id_str FROM sites WHERE id = ?");
    $targetStmt->execute([$delId]);
    $siteInfo = $targetStmt->fetch();

    $stmt = $pdo->prepare("DELETE FROM sites WHERE id = ?");
    $stmt->execute([$delId]);
    require_once __DIR__ . '/config/redis.php';
    AppRedis::flushPrefix('dashboard:');
    if ($siteInfo) {
        require_once __DIR__ . '/includes/audit.php';
        log_audit_event('MASTER_DATA', 'SITE_DELETE', 'Hapus master site: ' . $siteInfo['site_name'] . ' (' . ($siteInfo['site_id_str'] ?: 'ID ' . $delId) . ')', 'sites', $delId);
    }
    header("Location: sites?msg=deleted");
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
<link rel="stylesheet" href="assets/css/leaflet.css">

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
    <a href="./" class="btn btn-secondary btn-sm">&larr; Kembali ke Dashboard</a>
  </div>

  <div class="card">
    <div class="card-header">
      <h3 class="card-title">+ Tambah Data Site Baru</h3>
    </div>
    <div class="card-body">
      <form action="sites" method="POST">
        <input type="hidden" name="action" value="add_site">

        <div class="form-grid" style="margin-bottom: 1rem;">
          <div class="form-group">
            <label class="form-label">Nama Site *</label>
            <input type="text" name="site_name" class="form-control" placeholder="Contoh: Bandara Kualanamu Pole 2" required>
          </div>

          <div class="form-group">
            <label class="form-label">Site ID</label>
            <input type="text" name="site_id_str" class="form-control" placeholder="Contoh: IASS-DSER-078-08">
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

          <div class="form-group" style="grid-column: span 2;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem; flex-wrap: wrap; gap: 0.5rem;">
              <label class="form-label" style="margin: 0; font-weight: 600;">Koordinat Lokasi GPS (Latitude & Longitude)</label>
              <div style="display: flex; gap: 0.4rem; align-items: center;">
                <button type="button" class="btn btn-secondary btn-sm btn-open-map" data-target="add" style="padding: 0.25rem 0.65rem; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 4px;">
                  🗺️ Pilih dari Peta
                </button>
                <button type="button" class="btn btn-secondary btn-sm btn-get-gps" data-target="add" style="padding: 0.25rem 0.65rem; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 4px;">
                  📍 GPS Saya
                </button>
              </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
              <div>
                <input type="text" name="latitude" id="add_latitude" class="form-control" placeholder="Latitude, cth: 3.642200">
              </div>
              <div>
                <input type="text" name="longitude" id="add_longitude" class="form-control" placeholder="Longitude, cth: 98.885300">
              </div>
            </div>
            <span style="font-size: 0.75rem; color: var(--text-subtle); display: block; margin-top: 4px;">
              Gunakan "Pilih dari Peta" untuk memilih lokasi presisi pada peta interaktif Leaflet.
            </span>
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
            <input type="text" name="anchor_tenant" class="form-control" value="IASS SBU ICT KNO">
          </div>

          <div class="form-group">
            <label class="form-label">Total Tenant</label>
            <input type="number" name="total_tenant" class="form-control" min="1" value="1">
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
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
      <h3 class="card-title" style="margin: 0;">Daftar Master Site Tersimpan (<span id="site_count_display"><?= count($sites) ?></span>)</h3>
      <div style="display: flex; gap: 0.5rem; align-items: center;">
        <input type="text" id="site_search_input" class="form-control" placeholder="Cari nama site, ID, atau tipe..." style="font-size: 0.85rem; max-width: 260px; padding: 0.35rem 0.75rem;">
      </div>
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
        <tbody id="site_table_body">
          <?php foreach ($sites as $idx => $s): ?>
            <tr class="site-table-row" data-search="<?= strtolower(htmlspecialchars(($s['site_name'] ?? '') . ' ' . ($s['site_id_str'] ?? '') . ' ' . ($s['region'] ?? '') . ' ' . ($s['city'] ?? '') . ' ' . ($s['site_type'] ?? '') . ' ' . ($s['tower_type'] ?? '') . ' ' . ($s['location_detail'] ?? ''))) ?>">
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
                <div style="display: inline-flex; gap: 0.35rem; align-items: center;">
                  <button type="button" 
                    class="btn btn-secondary btn-sm btn-print-sticker"
                    data-id="<?= $s['id'] ?>"
                    data-name="<?= htmlspecialchars($s['site_name']) ?>"
                    data-code="<?= htmlspecialchars($s['site_id_str']) ?>"
                    data-location="<?= htmlspecialchars($s['location_detail'] ?: $s['region']) ?>"
                    data-coords="<?= htmlspecialchars($s['latitude'] . ', ' . $s['longitude']) ?>"
                    data-type="<?= htmlspecialchars($s['site_type'] . ' - ' . $s['tower_type']) ?>"
                    data-sitetype="<?= htmlspecialchars($s['site_type']) ?>"
                    title="Cetak Stiker QR Code untuk Tiang / Shelter">
                    🏷️ Stiker QR
                  </button>
                  <button type="button" 
                    class="btn btn-primary btn-sm btn-edit-site"
                    data-id="<?= $s['id'] ?>"
                    data-site_name="<?= htmlspecialchars($s['site_name'] ?? '') ?>"
                    data-site_id_str="<?= htmlspecialchars($s['site_id_str'] ?? '') ?>"
                    data-location_detail="<?= htmlspecialchars($s['location_detail'] ?? '') ?>"
                    data-region="<?= htmlspecialchars($s['region'] ?? '') ?>"
                    data-province="<?= htmlspecialchars($s['province'] ?? '') ?>"
                    data-city="<?= htmlspecialchars($s['city'] ?? '') ?>"
                    data-latitude="<?= htmlspecialchars($s['latitude'] ?? '') ?>"
                    data-longitude="<?= htmlspecialchars($s['longitude'] ?? '') ?>"
                    data-site_height="<?= htmlspecialchars($s['site_height'] ?? '') ?>"
                    data-tower_type="<?= htmlspecialchars($s['tower_type'] ?? '') ?>"
                    data-site_type="<?= htmlspecialchars($s['site_type'] ?? '') ?>"
                    data-anchor_tenant="<?= htmlspecialchars($s['anchor_tenant'] ?? '') ?>"
                    data-total_tenant="<?= htmlspecialchars($s['total_tenant'] ?? 1) ?>"
                    data-address="<?= htmlspecialchars($s['address'] ?? '') ?>"
                    title="Edit Data Master Site">
                    ✏️ Edit
                  </button>
                  <a href="sites?delete=<?= $s['id'] ?>" onclick="return confirm('Hapus site ini?');" class="btn btn-danger btn-sm">
                    Hapus
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</main>

<!-- Modal Edit Master Site -->
<div id="modal_edit_site" class="qr-modal-overlay" style="display: none;">
  <div class="card" style="max-width: 800px; width: 95%; max-height: 90vh; overflow-y: auto; margin: auto; animation: fadeIn 0.2s ease;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; background: var(--bg-card); z-index: 10; border-bottom: 1px solid var(--border);">
      <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--text-main); margin: 0;">✏️ Edit Data Master Site</h3>
      <button type="button" id="btn_close_edit_site" style="background: none; border: none; font-size: 1.5rem; color: var(--text-subtle); cursor: pointer; min-width: 40px; min-height: 40px;">&times;</button>
    </div>
    <form method="POST" action="sites">
      <input type="hidden" name="action" value="edit_site">
      <input type="hidden" name="site_id" id="edit_site_id">

      <div class="card-body">
        <div class="form-grid" style="margin-bottom: 1rem;">
          <div class="form-group">
            <label class="form-label">Nama Site *</label>
            <input type="text" name="site_name" id="edit_site_name" class="form-control" placeholder="Contoh: Bandara Kualanamu Pole 2" required>
          </div>

          <div class="form-group">
            <label class="form-label">Site ID</label>
            <input type="text" name="site_id_str" id="edit_site_id_str" class="form-control" placeholder="Contoh: IASS-DSER-078-08">
          </div>

          <div class="form-group">
            <label class="form-label">Detail Lokasi / Area</label>
            <input type="text" name="location_detail" id="edit_location_detail" class="form-control" placeholder="Contoh: Server Domestik / Pole 2">
          </div>

          <div class="form-group">
            <label class="form-label">Region</label>
            <input type="text" name="region" id="edit_region" class="form-control" placeholder="Contoh: Sumbagut / Deli Serdang">
          </div>

          <div class="form-group">
            <label class="form-label">Provinsi</label>
            <input type="text" name="province" id="edit_province" class="form-control" value="Sumatra Utara">
          </div>

          <div class="form-group">
            <label class="form-label">Kota / Kabupaten</label>
            <input type="text" name="city" id="edit_city" class="form-control" value="Medan">
          </div>

          <div class="form-group" style="grid-column: span 2;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem; flex-wrap: wrap; gap: 0.5rem;">
              <label class="form-label" style="margin: 0; font-weight: 600;">Koordinat Lokasi GPS (Latitude & Longitude)</label>
              <div style="display: flex; gap: 0.4rem; align-items: center;">
                <button type="button" class="btn btn-secondary btn-sm btn-open-map" data-target="edit" style="padding: 0.25rem 0.65rem; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 4px;">
                  🗺️ Pilih dari Peta
                </button>
                <button type="button" class="btn btn-secondary btn-sm btn-get-gps" data-target="edit" style="padding: 0.25rem 0.65rem; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 4px;">
                  📍 GPS Saya
                </button>
              </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
              <div>
                <input type="text" name="latitude" id="edit_latitude" class="form-control" placeholder="Latitude">
              </div>
              <div>
                <input type="text" name="longitude" id="edit_longitude" class="form-control" placeholder="Longitude">
              </div>
            </div>
            <span style="font-size: 0.75rem; color: var(--text-subtle); display: block; margin-top: 4px;">
              Gunakan "Pilih dari Peta" untuk memilih lokasi presisi pada peta interaktif Leaflet.
            </span>
          </div>

          <div class="form-group">
            <label class="form-label">Tinggi Site (Meter)</label>
            <input type="text" name="site_height" id="edit_site_height" class="form-control" placeholder="Contoh: 18">
          </div>

          <div class="form-group">
            <label class="form-label">Tipe Tower</label>
            <input type="text" name="tower_type" id="edit_tower_type" class="form-control" placeholder="Contoh: Pole PJU 18 m, Non Pole">
          </div>

          <div class="form-group">
            <label class="form-label">Tipe Site</label>
            <select name="site_type" id="edit_site_type" class="form-control">
              <option value="Indoor">Indoor</option>
              <option value="Green Field">Green Field (Outdoor)</option>
              <option value="Rooftop">Rooftop</option>
              <option value="Outdoor">Outdoor</option>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Anchor Tenant</label>
            <input type="text" name="anchor_tenant" id="edit_anchor_tenant" class="form-control">
          </div>

          <div class="form-group">
            <label class="form-label">Total Tenant</label>
            <input type="number" name="total_tenant" id="edit_total_tenant" class="form-control" min="1" value="1">
          </div>
        </div>

        <div class="form-group" style="margin-bottom: 0.5rem;">
          <label class="form-label">Alamat Lengkap Site</label>
          <textarea name="address" id="edit_address" class="form-control" rows="2" placeholder="Jl. Bandara Kuala Namu..."></textarea>
        </div>
      </div>

      <div class="card-header" style="display: flex; justify-content: flex-end; gap: 0.75rem; border-top: 1px solid var(--border); position: sticky; bottom: 0; background: var(--bg-card); z-index: 10;">
        <button type="button" class="btn btn-secondary" id="btn_dismiss_edit_site">Batal</button>
        <button type="submit" class="btn btn-primary" style="font-weight: 700;">Simpan Perubahan</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Interaktif Map Picker (Leaflet) -->
<div id="map_picker_modal" class="qr-modal-overlay" style="display: none; z-index: 99995;">
  <div class="card" style="max-width: 840px; width: 95%; max-height: 94vh; display: flex; flex-direction: column; margin: auto; overflow: hidden; animation: fadeIn 0.2s ease;">
    <!-- Header -->
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding: 0.85rem 1.25rem;">
      <div>
        <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-main); margin: 0;">🗺️ Pilih Koordinat Lokasi Site di Peta</h3>
        <p style="margin: 2px 0 0; font-size: 0.8rem; color: var(--text-subtle);">Klik pada peta atau geser pin penanda untuk menentukan koordinat presisi.</p>
      </div>
      <button type="button" id="btn_close_map_picker" style="background: none; border: none; font-size: 1.5rem; color: var(--text-subtle); cursor: pointer; min-width: 40px; min-height: 40px;">&times;</button>
    </div>

    <!-- Toolbar: Presets & Live Coordinate Display -->
    <div style="padding: 0.65rem 1rem; background: var(--bg-alt); border-bottom: 1px solid var(--border); display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 0.5rem;">
      <div style="display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap;">
        <span style="font-size: 0.78rem; font-weight: 600; color: var(--text-subtle);">Preset Area:</span>
        <button type="button" class="btn btn-secondary btn-sm btn-map-preset" data-lat="3.642200" data-lng="98.885300" style="padding: 0.2rem 0.55rem; font-size: 0.75rem;">
          ✈️ Pusat KNO
        </button>
        <button type="button" class="btn btn-secondary btn-sm btn-map-preset" data-lat="3.640822" data-lng="98.883721" style="padding: 0.2rem 0.55rem; font-size: 0.75rem;">
          🏢 Terminal KNO
        </button>
        <button type="button" class="btn btn-secondary btn-sm btn-map-preset" data-lat="3.644150" data-lng="98.886510" style="padding: 0.2rem 0.55rem; font-size: 0.75rem;">
          🌐 Tower / SBU ICT
        </button>
        <button type="button" id="btn_map_my_location" class="btn btn-secondary btn-sm" style="padding: 0.2rem 0.55rem; font-size: 0.75rem; color: var(--primary); font-weight: 600;">
          📍 GPS Saya
        </button>
      </div>

      <div style="font-size: 0.8rem; font-family: monospace; background: var(--bg-card); padding: 0.25rem 0.65rem; border-radius: 4px; border: 1px solid var(--border);">
        Lat: <span id="map_preview_lat" style="font-weight: 700; color: var(--primary);">-</span>, 
        Lng: <span id="map_preview_lng" style="font-weight: 700; color: var(--primary);">-</span>
      </div>
    </div>

    <!-- Map Canvas Container -->
    <div style="position: relative; flex: 1; min-height: 380px; max-height: 55vh; background: #e2e8f0;">
      <div id="leaflet_picker_map" style="width: 100%; height: 100%; min-height: 380px;"></div>
    </div>

    <!-- Footer Action -->
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border); padding: 0.75rem 1.25rem; flex-wrap: wrap; gap: 0.5rem;">
      <span style="font-size: 0.75rem; color: var(--text-subtle);">
        💡 Geser pin merah atau klik lokasi di peta untuk memperbarui koordinat.
      </span>
      <div style="display: flex; gap: 0.5rem;">
        <button type="button" id="btn_cancel_map_picker" class="btn btn-secondary">Batal</button>
        <button type="button" id="btn_apply_map_coords" class="btn btn-primary" style="font-weight: 700;">
          ✅ Gunakan Koordinat Ini
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Cetak Stiker QR Code -->
<div id="sticker_modal" class="qr-modal-overlay no-print" style="display: none;">
  <div class="qr-scanner-card" style="max-width: 500px; background: #ffffff; color: #000000;">
    <div class="qr-scanner-header" style="background: var(--bg-alt); border-bottom: 1px solid var(--border);">
      <h4 style="color: var(--text-main); font-size: 1rem; margin: 0; font-weight: 700;">Pratinjau Stiker QR Code Site</h4>
      <button type="button" id="btn_close_sticker" style="color: var(--text-main); background: none; border: none; font-size: 1.5rem; cursor: pointer; min-width: 44px; min-height: 44px;">&times;</button>
    </div>
    
    <div style="padding: 1.5rem; background: #f8fafc; display: flex; justify-content: center;">
      <!-- Area yang dicetak (80mm x 50mm) -->
      <div id="qr_print_area" class="sticker-label-card">
        <div class="sticker-header">
          <div class="brand">IASS SBU ICT KNO</div>
          <div class="sub">BANDARA INTERNASIONAL KUALANAMU</div>
        </div>
        <div class="sticker-body">
          <div class="sticker-meta">
            <div class="sticker-meta-title" id="sticker_site_name">Bandara Kualanamu Pole 2</div>
            <div><strong>ID:</strong> <span id="sticker_site_code">IASS-DSER-078-08</span></div>
            <div><strong>Lokasi:</strong> <span id="sticker_location">Server Domestik</span></div>
            <div><strong>GPS:</strong> <span id="sticker_coords">3.618278, 98.861139</span></div>
            <div><strong>Tipe:</strong> <span id="sticker_type">Green Field</span></div>
          </div>
          <div class="sticker-qr">
            <canvas id="sticker_qr_canvas" style="width: 100%; height: 100%;"></canvas>
          </div>
        </div>
        <div class="sticker-footer">
          Pindai QR dengan kamera HP untuk langsung membuka Formulir PM Site ini
        </div>
      </div>
    </div>

    <!-- QR Format Selector & URL Target Box -->
    <div style="padding: 0.85rem 1.25rem; background: #f8fafc; border-top: 1px solid var(--border); font-size: 0.825rem;">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
        <strong style="color: var(--text-main);">Format Isi QR Code:</strong>
        <span style="font-size: 0.75rem; color: var(--primary); font-weight: 600;">Bisa langsung di-scan kamera smartphone</span>
      </div>
      <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
        <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; color: var(--primary); font-weight: 600;">
          <input type="radio" name="qr_payload_format" value="url" checked style="accent-color: var(--primary); width: 16px; height: 16px;">
          🌐 Tautan Web PM (Scan kamera HP langsung buka form)
        </label>
        <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; color: var(--text-subtle); font-weight: 600;">
          <input type="radio" name="qr_payload_format" value="raw" style="accent-color: var(--primary); width: 16px; height: 16px;">
          🏷️ Hanya Teks ID Site
        </label>
      </div>
      <div style="margin-top: 8px; display: flex; align-items: center; gap: 6px;">
        <span style="color: var(--text-subtle); font-size: 0.75rem; white-space: nowrap;">Target QR:</span>
        <code id="qr_payload_display" style="flex: 1; font-size: 0.75rem; background: #ffffff; padding: 4px 8px; border-radius: 4px; border: 1px solid var(--border); word-break: break-all; color: var(--text-main); font-family: monospace;">-</code>
        <button type="button" id="btn_copy_qr_url" class="btn btn-secondary btn-sm" style="padding: 3px 8px; font-size: 0.75rem; min-height: 30px; white-space: nowrap;" title="Salin Tautan">📋 Salin</button>
      </div>
    </div>
    
    <div style="padding: 1rem; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 0.5rem; flex-wrap: wrap;">
      <button type="button" id="btn_dismiss_sticker" class="btn btn-secondary">Tutup</button>
      <button type="button" id="btn_download_sticker_png" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
        🖼️ Unduh Gambar (PNG)
      </button>
      <button type="button" id="btn_download_sticker_pdf" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
        📄 Unduh PDF
      </button>
      <button type="button" id="btn_print_sticker_action" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
        🖨️ Cetak
      </button>
    </div>
  </div>
</div>

<script src="assets/js/leaflet.js"></script>
<script src="assets/js/qrious.min.js"></script>
<script src="assets/js/html2pdf.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  // ==========================================
  // 1. Table Search Filter
  // ==========================================
  const searchInput = document.getElementById('site_search_input');
  const countDisplay = document.getElementById('site_count_display');
  const rows = document.querySelectorAll('.site-table-row');

  if (searchInput) {
    searchInput.addEventListener('input', function () {
      const q = this.value.toLowerCase().trim();
      let visible = 0;
      rows.forEach(function (row) {
        const meta = row.getAttribute('data-search') || '';
        const match = !q || meta.includes(q);
        row.style.display = match ? '' : 'none';
        if (match) visible++;
      });
      if (countDisplay) {
        countDisplay.innerText = visible;
      }
    });
  }

  // ==========================================
  // 2. Modal Edit Master Site
  // ==========================================
  const editModal = document.getElementById('modal_edit_site');
  const btnCloseEdit = document.getElementById('btn_close_edit_site');
  const btnDismissEdit = document.getElementById('btn_dismiss_edit_site');

  function closeEditModal() {
    if (editModal) editModal.style.display = 'none';
  }

  if (btnCloseEdit) btnCloseEdit.addEventListener('click', closeEditModal);
  if (btnDismissEdit) btnDismissEdit.addEventListener('click', closeEditModal);

  document.querySelectorAll('.btn-edit-site').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const id = this.getAttribute('data-id') || '';
      const name = this.getAttribute('data-site_name') || '';
      const code = this.getAttribute('data-site_id_str') || '';
      const loc = this.getAttribute('data-location_detail') || '';
      const region = this.getAttribute('data-region') || '';
      const province = this.getAttribute('data-province') || '';
      const city = this.getAttribute('data-city') || '';
      const lat = this.getAttribute('data-latitude') || '';
      const lng = this.getAttribute('data-longitude') || '';
      const height = this.getAttribute('data-site_height') || '';
      const towerType = this.getAttribute('data-tower_type') || '';
      const siteType = this.getAttribute('data-site_type') || 'Indoor';
      const anchor = this.getAttribute('data-anchor_tenant') || '';
      const total = this.getAttribute('data-total_tenant') || '1';
      const address = this.getAttribute('data-address') || '';

      document.getElementById('edit_site_id').value = id;
      document.getElementById('edit_site_name').value = name;
      document.getElementById('edit_site_id_str').value = code;
      document.getElementById('edit_location_detail').value = loc;
      document.getElementById('edit_region').value = region;
      document.getElementById('edit_province').value = province;
      document.getElementById('edit_city').value = city;
      document.getElementById('edit_latitude').value = lat;
      document.getElementById('edit_longitude').value = lng;
      document.getElementById('edit_site_height').value = height;
      document.getElementById('edit_tower_type').value = towerType;
      document.getElementById('edit_site_type').value = siteType;
      document.getElementById('edit_anchor_tenant').value = anchor;
      document.getElementById('edit_total_tenant').value = total;
      document.getElementById('edit_address').value = address;

      if (editModal) editModal.style.display = 'flex';
    });
  });

  // ==========================================
  // 3. Interactive Map Picker (Leaflet)
  // ==========================================
  const mapModal = document.getElementById('map_picker_modal');
  const btnCloseMap = document.getElementById('btn_close_map_picker');
  const btnCancelMap = document.getElementById('btn_cancel_map_picker');
  const btnApplyCoords = document.getElementById('btn_apply_map_coords');
  const btnMapMyLocation = document.getElementById('btn_map_my_location');
  const previewLatEl = document.getElementById('map_preview_lat');
  const previewLngEl = document.getElementById('map_preview_lng');

  let pickerMap = null;
  let pickerMarker = null;
  let currentTarget = 'add'; // 'add' or 'edit'
  let currentPickedLat = '3.642200';
  let currentPickedLng = '98.885300';

  function closeMapModal() {
    if (mapModal) mapModal.style.display = 'none';
  }

  if (btnCloseMap) btnCloseMap.addEventListener('click', closeMapModal);
  if (btnCancelMap) btnCancelMap.addEventListener('click', closeMapModal);

  function setPickerCoordinates(lat, lng, panToView) {
    const pLat = parseFloat(lat);
    const pLng = parseFloat(lng);
    if (!isNaN(pLat) && !isNaN(pLng)) {
      currentPickedLat = pLat.toFixed(6);
      currentPickedLng = pLng.toFixed(6);
      if (previewLatEl) previewLatEl.innerText = currentPickedLat;
      if (previewLngEl) previewLngEl.innerText = currentPickedLng;
      if (pickerMarker) {
        pickerMarker.setLatLng([pLat, pLng]);
      }
      if (panToView && pickerMap) {
        pickerMap.panTo([pLat, pLng]);
      }
    }
  }

  function initPickerMap() {
    if (pickerMap) return;
    const mapEl = document.getElementById('leaflet_picker_map');
    if (!mapEl || !window.L) return;

    pickerMap = L.map('leaflet_picker_map', {
      zoomControl: true,
      attributionControl: true
    }).setView([parseFloat(currentPickedLat), parseFloat(currentPickedLng)], 15);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(pickerMap);

    const pinIcon = L.divIcon({
      className: 'map-picker-pin',
      html: `
        <div style="position: relative; width: 34px; height: 34px; transform: translate(-50%, -100%); cursor: grab;">
          <svg viewBox="0 0 24 24" width="34" height="34" style="filter: drop-shadow(0 3px 6px rgba(0,0,0,0.45));">
            <path fill="#008080" stroke="#ffffff" stroke-width="1.5" d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/>
            <circle cx="12" cy="9" r="3" fill="#ffffff"/>
          </svg>
        </div>
      `,
      iconSize: [0, 0],
      iconAnchor: [0, 0]
    });

    pickerMarker = L.marker([parseFloat(currentPickedLat), parseFloat(currentPickedLng)], {
      icon: pinIcon,
      draggable: true
    }).addTo(pickerMap);

    pickerMarker.on('dragend', function () {
      const pos = pickerMarker.getLatLng();
      setPickerCoordinates(pos.lat, pos.lng, false);
    });

    pickerMap.on('click', function (e) {
      setPickerCoordinates(e.latlng.lat, e.latlng.lng, false);
    });
  }

  function openMapPicker(target) {
    currentTarget = target;
    const latInput = document.getElementById(target + '_latitude');
    const lngInput = document.getElementById(target + '_longitude');

    let initLat = latInput ? parseFloat(latInput.value.trim()) : NaN;
    let initLng = lngInput ? parseFloat(lngInput.value.trim()) : NaN;

    if (isNaN(initLat) || isNaN(initLng) || initLat < -90 || initLat > 90 || initLng < -180 || initLng > 180) {
      initLat = 3.642200; // Bandara Kualanamu KNO default
      initLng = 98.885300;
    }

    if (mapModal) mapModal.style.display = 'flex';
    initPickerMap();
    setPickerCoordinates(initLat, initLng, true);

    setTimeout(function () {
      if (pickerMap) {
        pickerMap.invalidateSize();
        pickerMap.setView([initLat, initLng], 15);
      }
    }, 150);
  }

  document.querySelectorAll('.btn-open-map').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const target = this.getAttribute('data-target') || 'add';
      openMapPicker(target);
    });
  });

  // Preset buttons
  document.querySelectorAll('.btn-map-preset').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const pLat = parseFloat(this.getAttribute('data-lat'));
      const pLng = parseFloat(this.getAttribute('data-lng'));
      if (!isNaN(pLat) && !isNaN(pLng)) {
        setPickerCoordinates(pLat, pLng, true);
        if (pickerMap) pickerMap.setView([pLat, pLng], 16);
      }
    });
  });

  // GPS on Map Toolbar
  if (btnMapMyLocation) {
    btnMapMyLocation.addEventListener('click', function () {
      if (!navigator.geolocation) {
        alert('Browser tidak mendukung pendeteksi lokasi GPS.');
        return;
      }
      btnMapMyLocation.innerText = '⏳ Mencari...';
      navigator.geolocation.getCurrentPosition(
        function (pos) {
          btnMapMyLocation.innerText = '📍 GPS Saya';
          const glat = pos.coords.latitude;
          const glng = pos.coords.longitude;
          setPickerCoordinates(glat, glng, true);
          if (pickerMap) pickerMap.setView([glat, glng], 17);
        },
        function (err) {
          btnMapMyLocation.innerText = '📍 GPS Saya';
          alert('Tidak dapat mendeteksi GPS: ' + (err.message || 'Izin lokasi tidak diberikan.'));
        },
        { enableHighAccuracy: true, timeout: 10000 }
      );
    });
  }

  // Apply picked coordinates to calling form
  if (btnApplyCoords) {
    btnApplyCoords.addEventListener('click', function () {
      const latInput = document.getElementById(currentTarget + '_latitude');
      const lngInput = document.getElementById(currentTarget + '_longitude');

      if (latInput) latInput.value = currentPickedLat;
      if (lngInput) lngInput.value = currentPickedLng;

      [latInput, lngInput].forEach(function (inp) {
        if (inp) {
          inp.style.transition = 'box-shadow 0.3s ease, border-color 0.3s ease';
          inp.style.borderColor = '#10b981';
          inp.style.boxShadow = '0 0 0 3px rgba(16, 185, 129, 0.25)';
          setTimeout(function () {
            inp.style.borderColor = '';
            inp.style.boxShadow = '';
          }, 1500);
        }
      });

      closeMapModal();
    });
  }

  // Direct GPS button on form
  document.querySelectorAll('.btn-get-gps').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const target = this.getAttribute('data-target') || 'add';
      const latInput = document.getElementById(target + '_latitude');
      const lngInput = document.getElementById(target + '_longitude');

      if (!navigator.geolocation) {
        alert('Browser tidak mendukung geolokasi GPS.');
        return;
      }

      const origText = btn.innerText;
      btn.innerText = '⏳ Mencari...';

      navigator.geolocation.getCurrentPosition(
        function (pos) {
          btn.innerText = origText;
          if (latInput) latInput.value = pos.coords.latitude.toFixed(6);
          if (lngInput) lngInput.value = pos.coords.longitude.toFixed(6);

          [latInput, lngInput].forEach(function (inp) {
            if (inp) {
              inp.style.transition = 'box-shadow 0.3s ease, border-color 0.3s ease';
              inp.style.borderColor = '#10b981';
              inp.style.boxShadow = '0 0 0 3px rgba(16, 185, 129, 0.25)';
              setTimeout(function () {
                inp.style.borderColor = '';
                inp.style.boxShadow = '';
              }, 1500);
            }
          });
        },
        function (err) {
          btn.innerText = origText;
          alert('Gagal mendeteksi lokasi GPS: ' + (err.message || 'Izin akses lokasi ditolak.'));
        },
        { enableHighAccuracy: true, timeout: 10000 }
      );
    });
  });

  // ==========================================
  // 4. Modal Cetak Stiker QR Code
  // ==========================================
  const modal = document.getElementById('sticker_modal');
  const btnClose = document.getElementById('btn_close_sticker');
  const btnDismiss = document.getElementById('btn_dismiss_sticker');
  const btnPrint = document.getElementById('btn_print_sticker_action');
  const btnDownloadPng = document.getElementById('btn_download_sticker_png');
  const btnDownloadPdf = document.getElementById('btn_download_sticker_pdf');

  let activeSite = {
    id: '',
    name: 'Site KNO',
    code: 'KNO',
    loc: '-',
    coords: '-',
    type: '-',
    sitetype: ''
  };
  let activePayload = '';

  function closeModal() {
    if (modal) modal.style.display = 'none';
    document.body.classList.remove('printing-sticker');
  }

  if (btnClose) btnClose.onclick = closeModal;
  if (btnDismiss) btnDismiss.onclick = closeModal;

  function updateQrCode() {
    const isUrl = document.querySelector('input[name="qr_payload_format"]:checked')?.value === 'url';
    const isIndoor = (activeSite.type && activeSite.type.toLowerCase().includes('indoor')) ||
                     (activeSite.sitetype && activeSite.sitetype.toLowerCase().includes('indoor'));
    const targetForm = isIndoor ? 'form_indoor' : 'form_outdoor';
    const baseUrl = window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);

    const siteParam = activeSite.id ? ('site_id=' + encodeURIComponent(activeSite.id)) : ('code=' + encodeURIComponent(activeSite.code || activeSite.name));
    const fullUrl = baseUrl + targetForm + '?' + siteParam;
    const rawCode = activeSite.code || activeSite.name || 'KNO';

    activePayload = isUrl ? fullUrl : rawCode;

    const displayEl = document.getElementById('qr_payload_display');
    if (displayEl) {
      displayEl.innerText = activePayload;
    }

    const canvas = document.getElementById('sticker_qr_canvas');
    try {
      if (window.QRious) {
        new QRious({
          element: canvas,
          value: activePayload,
          size: 240,
          level: 'H'
        });
      }
    } catch (err) {
      console.error('Error rendering QRious:', err);
    }
  }

  // Format radio change handler
  document.querySelectorAll('input[name="qr_payload_format"]').forEach(radio => {
    radio.addEventListener('change', updateQrCode);
  });

  // Copy QR URL button
  const btnCopy = document.getElementById('btn_copy_qr_url');
  if (btnCopy) {
    btnCopy.addEventListener('click', function () {
      if (activePayload && navigator.clipboard) {
        navigator.clipboard.writeText(activePayload).then(() => {
          btnCopy.innerText = '✅ Tersalin';
          setTimeout(() => { btnCopy.innerText = '📋 Salin'; }, 1500);
        }).catch(() => {
          prompt('Salin link berikut:', activePayload);
        });
      } else if (activePayload) {
        prompt('Salin link berikut:', activePayload);
      }
    });
  }

  // Render whole sticker to high-res canvas (960x600 for sharp print quality)
  function renderStickerCanvas(data) {
    const c = document.createElement('canvas');
    c.width = 960;
    c.height = 600;
    const ctx = c.getContext('2d');

    // Background
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, c.width, c.height);

    // Outer border
    ctx.lineWidth = 4;
    ctx.strokeStyle = '#000000';
    ctx.strokeRect(12, 12, c.width - 24, c.height - 24);

    // Header bar
    ctx.fillStyle = '#0f172a';
    ctx.fillRect(12, 12, c.width - 24, 96);

    ctx.fillStyle = '#ffffff';
    ctx.font = 'bold 24px Arial, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('IASS SBU ICT KNO', c.width / 2, 52);

    ctx.fillStyle = '#94a3b8';
    ctx.font = '600 17px Arial, sans-serif';
    ctx.fillText('BANDARA INTERNASIONAL KUALANAMU', c.width / 2, 84);

    // Left metadata column
    ctx.textAlign = 'left';
    const leftX = 40;

    // Site Name
    ctx.fillStyle = '#0f172a';
    ctx.font = 'bold 26px Arial, sans-serif';
    const siteTitle = data.name || 'Site KNO';
    if (ctx.measureText(siteTitle).width > 480) {
      ctx.font = 'bold 21px Arial, sans-serif';
    }
    ctx.fillText(siteTitle, leftX, 155);

    function drawMeta(label, val, y) {
      ctx.font = 'bold 18px Arial, sans-serif';
      ctx.fillStyle = '#0f172a';
      ctx.fillText(label + ':', leftX, y);
      const lw = ctx.measureText(label + ': ').width;
      ctx.font = '18px Arial, sans-serif';
      ctx.fillStyle = '#334155';
      const cleanVal = (val && String(val).trim()) ? String(val).trim() : '-';
      ctx.fillText(cleanVal, leftX + lw, y);
    }

    drawMeta('ID Site', data.code, 210);
    drawMeta('Lokasi', data.loc, 265);
    drawMeta('GPS', data.coords, 320);
    drawMeta('Tipe', data.type, 375);

    // QR Code column (Right)
    const qrCanvas = document.getElementById('sticker_qr_canvas');
    if (qrCanvas) {
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(570, 130, 340, 340);
      ctx.lineWidth = 2;
      ctx.strokeStyle = '#e2e8f0';
      ctx.strokeRect(570, 130, 340, 340);
      ctx.drawImage(qrCanvas, 580, 140, 320, 320);
    }

    // Footer divider & note
    ctx.lineWidth = 1;
    ctx.strokeStyle = '#cbd5e1';
    ctx.beginPath();
    ctx.moveTo(30, 520);
    ctx.lineTo(c.width - 30, 520);
    ctx.stroke();

    ctx.fillStyle = '#64748b';
    ctx.font = '16px Arial, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('Pindai QR dengan kamera HP untuk langsung membuka Formulir PM Site ini', c.width / 2, 558);

    return c;
  }

  document.querySelectorAll('.btn-print-sticker').forEach(btn => {
    btn.addEventListener('click', function () {
      const id = this.getAttribute('data-id') || '';
      const name = this.getAttribute('data-name') || '';
      const code = this.getAttribute('data-code') || '';
      const loc = this.getAttribute('data-location') || '';
      const coords = this.getAttribute('data-coords') || '';
      const type = this.getAttribute('data-type') || '';
      const sitetype = this.getAttribute('data-sitetype') || '';

      activeSite = { id, name, code, loc, coords, type, sitetype };

      document.getElementById('sticker_site_name').innerText = name || 'Site KNO';
      document.getElementById('sticker_site_code').innerText = code || '-';
      document.getElementById('sticker_location').innerText = loc || '-';
      document.getElementById('sticker_coords').innerText = coords || '-';
      document.getElementById('sticker_type').innerText = type || '-';

      // Always reset to URL mode by default when opening modal
      const radioUrl = document.querySelector('input[name="qr_payload_format"][value="url"]');
      if (radioUrl) radioUrl.checked = true;

      updateQrCode();

      if (modal) modal.style.display = 'flex';
    });
  });

  // Print button
  if (btnPrint) {
    btnPrint.addEventListener('click', function () {
      document.body.classList.add('printing-sticker');
      window.print();
      setTimeout(() => {
        document.body.classList.remove('printing-sticker');
      }, 1000);
    });
  }

  // Download PNG button: renders crisp 960x600 image directly
  if (btnDownloadPng) {
    btnDownloadPng.addEventListener('click', function () {
      const canvas = renderStickerCanvas(activeSite);
      const safeCode = (activeSite.code || activeSite.name || 'Site').replace(/[^A-Za-z0-9_\-]/g, '_');
      const a = document.createElement('a');
      a.download = 'Stiker_' + safeCode + '.png';
      a.href = canvas.toDataURL('image/png');
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
    });
  }

  // Download PDF button
  if (btnDownloadPdf) {
    btnDownloadPdf.addEventListener('click', function () {
      const safeCode = (activeSite.code || activeSite.name || 'Site').replace(/[^A-Za-z0-9_\-]/g, '_');
      const area = document.getElementById('qr_print_area');
      if (window.html2pdf) {
        const opt = {
          margin: 0,
          filename: 'Stiker_' + safeCode + '.pdf',
          image: { type: 'jpeg', quality: 0.98 },
          html2canvas: { scale: 3, useCORS: true },
          jsPDF: { unit: 'mm', format: [80, 50], orientation: 'landscape' }
        };
        window.html2pdf().from(area).set(opt).save();
      } else {
        window.print();
      }
    });
  }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
