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
                  <a href="sites.php?delete=<?= $s['id'] ?>" onclick="return confirm('Hapus site ini?');" class="btn btn-danger btn-sm">
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
          <div class="brand">PT ANGKASA PURA SARANA DIGITAL</div>
          <div class="sub">BANDARA INTERNASIONAL KUALANAMU</div>
        </div>
        <div class="sticker-body">
          <div class="sticker-meta">
            <div class="sticker-meta-title" id="sticker_site_name">Bandara Kualanamu Pole 2</div>
            <div><strong>ID:</strong> <span id="sticker_site_code">APSD-DSER-078-08</span></div>
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

<script src="assets/js/qrious.min.js"></script>
<script src="assets/js/html2pdf.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const modal = document.getElementById('sticker_modal');
  const btnClose = document.getElementById('btn_close_sticker');
  const btnDismiss = document.getElementById('btn_dismiss_sticker');
  const btnPrint = document.getElementById('btn_print_sticker_action');
  const btnDownloadPng = document.getElementById('btn_download_sticker_png');
  const btnDownloadPdf = document.getElementById('btn_download_sticker_pdf');

  let activeSite = {
    id: '',
    name: 'Site APSD',
    code: 'APSD',
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
    const targetForm = isIndoor ? 'form_indoor.php' : 'form_outdoor.php';
    const baseUrl = window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);

    const siteParam = activeSite.id ? ('site_id=' + encodeURIComponent(activeSite.id)) : ('code=' + encodeURIComponent(activeSite.code || activeSite.name));
    const fullUrl = baseUrl + targetForm + '?' + siteParam;
    const rawCode = activeSite.code || activeSite.name || 'APSD';

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
    ctx.fillText('PT ANGKASA PURA SARANA DIGITAL', c.width / 2, 52);

    ctx.fillStyle = '#94a3b8';
    ctx.font = '600 17px Arial, sans-serif';
    ctx.fillText('BANDARA INTERNASIONAL KUALANAMU', c.width / 2, 84);

    // Left metadata column
    ctx.textAlign = 'left';
    const leftX = 40;

    // Site Name
    ctx.fillStyle = '#0f172a';
    ctx.font = 'bold 26px Arial, sans-serif';
    const siteTitle = data.name || 'Site APSD';
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

      document.getElementById('sticker_site_name').innerText = name || 'Site APSD';
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
