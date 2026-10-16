<?php
/**
 * Form Input PM Outdoor / Pole (13 Halaman)
 * IASS SBU ICT KNO
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/checklist_data.php';
$pdo = get_db_connection();

$sites = $pdo->query("SELECT * FROM sites ORDER BY site_name ASC")->fetchAll();
$photoGroups = get_outdoor_photo_slots();

$page_title = 'Formulir PM Outdoor / Pole (13 Halaman)';
include __DIR__ . '/includes/header.php';
?>

<main class="container has-sticky-bar">

  <!-- Mobile Quick Jump Bar -->
  <div class="mobile-quick-jump no-print">
    <a href="#sec_admin" class="quick-jump-chip">1. Info Site</a>
    <a href="#sec_checklist" class="quick-jump-chip">2. Checklist Teknis</a>
    <a href="#sec_photos" class="quick-jump-chip">3. Foto Dokumentasi</a>
    <a href="#sec_submit" class="quick-jump-chip">4. Simpan Laporan</a>
  </div>

  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 1rem;">
    <div>
      <span style="font-size: 0.8rem; font-weight: 700; color: var(--secondary); text-transform: uppercase;">Modul PM Outdoor</span>
      <h2 style="font-size: 1.4rem; font-weight: 700; color: var(--text-main);">Checklist PM Mechanical Electrical - Pole & Site (13 Halaman)</h2>
      <p style="color: var(--text-subtle); font-size: 0.9rem;">Inspeksi tiang pole, grounding, struktur, pagar, kelistrikan PLN/genset, dan 48 slot foto dokumentasi.</p>
    </div>
    <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
      <span id="autosave_status" class="autosave-badge">💾 Auto-Save Siap</span>
      <button type="button" class="btn btn-secondary btn-sm" onclick="saveDraftManual('outdoor')" style="min-height: 40px; font-weight: 600;" title="Simpan draft data checklist & foto ke memori HP sekarang">
        💾 Simpan Draft
      </button>
      <button type="button" id="btn_set_all_ok" class="btn btn-secondary btn-sm" style="font-weight: 700; color: var(--status-ok); border-color: var(--status-ok); min-height: 40px;">
        ✓ Set Semua Checklist OK
      </button>
      <a href="./" class="btn btn-secondary btn-sm" style="min-height: 40px; display: inline-flex; align-items: center;">&larr; Batal / Kembali</a>
    </div>
  </div>

  <!-- Offline Draft Restore Banner -->
  <div id="draft_restore_banner" class="draft-banner no-print" style="display: none;">
    <div class="draft-banner-info">
      <span style="font-size: 1.4rem;">💾</span>
      <div>
        <strong>Ditemukan Draf Tersimpan di HP</strong>
        <p id="draft_banner_time">Tersimpan otomatis dari sesi sebelumnya. Ingin memulihkan data inspeksi?</p>
      </div>
    </div>
    <div class="draft-banner-actions">
      <button type="button" id="btn_restore_draft" class="btn btn-primary btn-sm" style="min-height: 44px; font-weight: 700; padding: 0.4rem 0.9rem;">
        🔄 Pulihkan Draft
      </button>
      <button type="button" id="btn_discard_draft" class="btn btn-secondary btn-sm" style="min-height: 44px; padding: 0.4rem 0.9rem;">
        🗑️ Buang
      </button>
    </div>
  </div>

  <form id="pm_report_form" action="api/save_outdoor.php" method="POST" enctype="multipart/form-data">
    <input type="hidden" id="report_type_hidden" value="outdoor">

    <!-- Section 1: Informasi Cover Site -->
    <div class="card" id="sec_admin">
      <div class="card-header">
        <h3 class="card-title">1. Site Information & Administrasi (Halaman 1)</h3>
      </div>
      <div class="card-body">
        
        <div class="form-group" style="margin-bottom: 1.25rem;">
          <label class="form-label" style="color: var(--secondary); font-size: 0.9rem;">Pilih Master Site (Otomatis Isi Data)</label>
          <div style="display: flex; gap: 0.5rem; align-items: stretch; flex-wrap: wrap;">
            <?php 
              $reqSiteId = trim($_GET['site_id'] ?? $_GET['site'] ?? '');
              $reqCode = trim($_GET['code'] ?? '');
            ?>
            <select id="site_id_select" name="site_id" class="form-control" style="flex: 1; min-width: 240px; font-weight: 600;">
              <option value="">-- Pilih Site Tersimpan atau Ketik Manual di Bawah --</option>
            <?php foreach ($sites as $s): ?>
              <?php 
                $isSelected = ($reqSiteId && ($reqSiteId == $s['id'] || strcasecmp($reqSiteId, $s['site_id_str']) === 0 || strcasecmp($reqSiteId, $s['site_name']) === 0)) ||
                              ($reqCode && strcasecmp($reqCode, $s['site_id_str']) === 0);
              ?>
              <option value="<?= $s['id'] ?>"
                <?= $isSelected ? 'selected' : '' ?>
                data-name="<?= htmlspecialchars($s['site_name']) ?>"
                data-code="<?= htmlspecialchars($s['site_id_str']) ?>"
                data-location="<?= htmlspecialchars($s['location_detail']) ?>"
                data-region="<?= htmlspecialchars($s['region']) ?>"
                data-province="<?= htmlspecialchars($s['province']) ?>"
                data-city="<?= htmlspecialchars($s['city']) ?>"
                data-address="<?= htmlspecialchars($s['address']) ?>"
                data-lat="<?= htmlspecialchars($s['latitude']) ?>"
                data-long="<?= htmlspecialchars($s['longitude']) ?>"
                data-height="<?= htmlspecialchars($s['site_height']) ?>"
                data-tower="<?= htmlspecialchars($s['tower_type']) ?>"
                data-sitetype="<?= htmlspecialchars($s['site_type']) ?>"
              >
                <?= htmlspecialchars($s['site_name']) ?> (<?= htmlspecialchars($s['site_id_str'] ?: $s['region']) ?>)
              </option>
            <?php endforeach; ?>
            </select>
            <button type="button" id="btn_scan_qr" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; font-weight: 600; min-height: 44px; padding: 0.5rem 1rem;">
              📷 Scan QR Site
            </button>
          </div>
        </div>

        <div class="form-grid" style="margin-bottom: 1rem;">
          <div class="form-group">
            <label class="form-label">IASS Site ID</label>
            <input type="text" id="site_id_str" name="site_id_str" class="form-control" value="IASS-DSER-078-08">
          </div>

          <div class="form-group">
            <label class="form-label">IASS Site Name *</label>
            <input type="text" id="site_name" name="site_name" class="form-control" value="Bandara Kualanamu Pole 2" required>
          </div>

          <div class="form-group">
            <label class="form-label">Tanggal Pemeriksaan *</label>
            <input type="date" name="report_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
          </div>

          <div class="form-group">
            <label class="form-label">Jam Pemeriksaan</label>
            <input type="text" name="inspection_time" class="form-control" value="10.25">
          </div>

          <div class="form-group">
            <label class="form-label">Zona Waktu</label>
            <select name="timezone" class="form-control">
              <option value="WIB" selected>WIB</option>
              <option value="WITA">WITA</option>
              <option value="WIT">WIT</option>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Nama Petugas Lapangan (PIC Field) *</label>
            <input type="text" name="inspector_name" class="form-control" value="Hamdani" required>
          </div>

          <div class="form-group">
            <label class="form-label">Nomor PIC Field</label>
            <input type="text" name="inspector_phone" class="form-control" placeholder="08xxxxxxxx">
          </div>

          <div class="form-group">
            <label class="form-label">Periode Laporan</label>
            <input type="text" name="period" class="form-control" value="Jul-25">
          </div>

          <div class="form-group">
            <label class="form-label">Region</label>
            <input type="text" id="region" name="region" class="form-control" value="Sumbagut">
          </div>

          <div class="form-group">
            <label class="form-label">Province</label>
            <input type="text" id="province" name="province" class="form-control" value="Sumatra Utara">
          </div>

          <div class="form-group">
            <label class="form-label">City</label>
            <input type="text" id="city" name="city" class="form-control" value="Medan">
          </div>

          <div class="form-group">
            <label class="form-label">Latitude</label>
            <input type="text" id="latitude" name="latitude" class="form-control" value="3.618278">
          </div>

          <div class="form-group">
            <label class="form-label">Longitude</label>
            <input type="text" id="longitude" name="longitude" class="form-control" value="98.861139">
          </div>

          <div class="form-group">
            <label class="form-label">Site Height (m)</label>
            <input type="text" id="site_height" name="site_height" class="form-control" value="18">
          </div>

          <div class="form-group">
            <label class="form-label">Tower Type</label>
            <input type="text" id="tower_type" name="tower_type" class="form-control" value="Pole PJU 18 m">
          </div>

          <div class="form-group">
            <label class="form-label">Site Type</label>
            <input type="text" id="site_type" name="site_type" class="form-control" value="Green Field">
          </div>

          <div class="form-group">
            <label class="form-label">Anchor Tenant</label>
            <input type="text" id="anchor_tenant" name="anchor_tenant" class="form-control" value="IASS SBU ICT KNO">
          </div>

          <div class="form-group">
            <label class="form-label">OM Vendor Name</label>
            <input type="text" name="om_vendor" class="form-control" value="PT. Naer Tunas Indonesia">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Address</label>
          <textarea id="address" name="address" class="form-control" rows="2">Jl. Bandara Kuala Namu, Ps. Enam Kuala Namu, Kec. Beringin, Kabupaten Deli Serdang, Sumatera Utara 20553</textarea>
        </div>

        <!-- Logo Provider / Tenant Customizer -->
        <div style="margin-top: 1.25rem; padding: 1rem; background: var(--bg-alt); border-radius: var(--radius-sm); border: 1px solid var(--border);">
          <h4 style="font-size: 0.95rem; font-weight: 700; color: var(--secondary); margin-bottom: 0.75rem;">Logo Provider / Tenant (Kop Kanan Laporan)</h4>
          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Pilihan Logo Kop Kanan</label>
              <select id="preset_logo_select" name="preset_logo" class="form-control">
                <option value="ias" selected>IAS Support / IASS SBU ICT KNO</option>
                <option value="telkomsel">Telkomsel</option>
                <option value="indosat">Indosat Ooredoo</option>
                <option value="xl">XL Axiata</option>
                <option value="custom">-- Upload Logo Custom / Provider Lain --</option>
                <option value="none">Tanpa Logo</option>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Nama Provider / Operator</label>
              <input type="text" id="operator_name_input" name="operator" class="form-control" value="IASS SBU ICT KNO" placeholder="Contoh: IASS SBU ICT KNO, TELKOMSEL, INDOSAT, XL">
            </div>

            <div class="form-group" id="custom_logo_group">
              <label class="form-label">Upload File Logo Baru (PNG / JPG / SVG)</label>
              <input type="file" name="custom_logo_file" id="custom_logo_file" accept="image/*" class="form-control">
            </div>

            <div class="form-group" style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
              <span style="font-size: 0.75rem; color: var(--text-subtle); margin-bottom: 4px;">Pratinjau Logo Kanan:</span>
              <div style="border: 1px dashed var(--border-strong); padding: 4px; border-radius: 4px; background: #fff; width: 140px; height: 50px; display: flex; align-items: center; justify-content: center;">
                <img id="logo_preview_img" src="assets/img/logo_ias.png?v=2" style="max-height: 42px; max-width: 130px; object-fit: contain;" alt="Logo Preview">
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- Section 2: Grounding, Tower, Lighting, Fence (Halaman 2) -->
    <div class="card" id="sec_checklist">
      <div class="card-header">
        <h3 class="card-title">2. Checklist Site: Grounding, Tower, Lighting & Fence (Halaman 2)</h3>
      </div>
      <div class="card-body">
        
        <h4 style="font-weight: 700; color: var(--secondary); margin-bottom: 0.75rem;">A. Pengukuran Grounding (Nilai Ohm Standar &lt; 1 Ohm)</h4>
        <div class="form-grid" style="margin-bottom: 1.25rem;">
          <div class="form-group">
            <label class="form-label">Grounding Control Box (Ohm)</label>
            <input type="text" name="grounding_meas[control_box]" class="form-control" value="0.24">
          </div>
          <div class="form-group">
            <label class="form-label">Tower Leg (Ohm)</label>
            <input type="text" name="grounding_meas[tower_leg]" class="form-control" value="0.26">
          </div>
          <div class="form-group">
            <label class="form-label">Fence (Ohm)</label>
            <input type="text" name="grounding_meas[fence]" class="form-control" value="0">
          </div>
          <div class="form-group">
            <label class="form-label">Grounding EGB (Ohm)</label>
            <input type="text" name="grounding_meas[egb]" class="form-control" value="0.20">
          </div>
        </div>

        <h4 style="font-weight: 700; color: var(--secondary); margin-bottom: 0.75rem;">B. Grounding Status</h4>
        <table class="checklist-table" style="margin-bottom: 1.5rem;">
          <thead>
            <tr>
              <th>Item</th>
              <th style="width: 220px; text-align: center;">Status</th>
              <th>Remarks / Catatan</th>
            </tr>
          </thead>
          <tbody>
            <?php 
              $gItems = [
                'lightening_rod' => 'Lightening Rod',
                'bar_grounding_tower' => 'Bar Grounding Tower',
                'bar_grounding_control_box' => 'Bar Grounding Control Box',
                'down_conductor_cable' => 'Down Conductor Cable',
                'bolt_connection' => 'Bolt Connection'
              ];
              foreach ($gItems as $k => $label): 
            ?>
              <tr>
                <td><strong><?= $label ?></strong></td>
                <td style="text-align: center;">
                  <div class="status-pill-group" style="justify-content: center;">
                    <label>
                      <input type="radio" class="status-radio" name="grounding_status[<?= $k ?>][status]" value="OK" checked>
                      <span class="status-label status-label-ok">OK</span>
                    </label>
                    <label>
                      <input type="radio" class="status-radio" name="grounding_status[<?= $k ?>][status]" value="NOK">
                      <span class="status-label status-label-nok">NOK</span>
                    </label>
                    <label>
                      <input type="radio" class="status-radio" name="grounding_status[<?= $k ?>][status]" value="NA">
                      <span class="status-label status-label-na">N/A</span>
                    </label>
                  </div>
                </td>
                <td>
                  <input type="text" name="grounding_status[<?= $k ?>][remarks]" class="form-control" style="min-height: 36px;" placeholder="Remarks jika ada">
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <h4 style="font-weight: 700; color: var(--secondary); margin-bottom: 0.75rem;">C. Tower, Lighting & Fence Condition</h4>
        <table class="checklist-table">
          <thead>
            <tr>
              <th>Item Pemeriksaan</th>
              <th style="width: 220px; text-align: center;">Status</th>
              <th>Remarks / Keterangan</th>
            </tr>
          </thead>
          <tbody>
            <?php 
              $siteItems = [
                'painting' => ['Painting Condition', 'tower loosen paint 70%'],
                'construction' => ['Construction Condition', 'paving down / tower leg cracked'],
                'cleaning' => ['Tower Cleaning', 'cleaning from spider nest/bee/etc'],
                'tower_lamp' => ['Tower Lamp / OBL Condition', 'broken, cracked, unshielded'],
                'photo_sensor' => ['Photo Sensor', 'broken, cracked, unshielded'],
                'verticality' => ['Tower Verticality', ''],
                'pole_sticker' => ['Pole Sticker', ''],
                'baseplate_painting' => ['Baseplate Leg Painting', ''],
                'fence_condition' => ['Fence Condition', 'collapsed, broken'],
                'site_cleaning' => ['Site Cleaning', 'cleaning from grass/garbage'],
                'paving_block' => ['Paving Block / Gravel', ''],
                'barbed_wire' => ['Barbed Wire', ''],
                'fence_door' => ['Fence Door', ''],
                'safety_sign' => ['Safety Sign Board', 'uninstalled, undetected'],
                'warning_sign' => ['Warning Sign Board', 'uninstalled, undetected'],
                'combination_lock' => ['Combination Lock / Padlock', 'uninstalled, undetected'],
                'site_keeper' => ['Site Keeper Status', ''],
                'notification_sticker' => ['Notification Sticker', '']
              ];
              foreach ($siteItems as $k => $info):
            ?>
              <tr>
                <td><strong><?= htmlspecialchars($info[0]) ?></strong></td>
                <td style="text-align: center;">
                  <div class="status-pill-group" style="justify-content: center;">
                    <label>
                      <input type="radio" class="status-radio" name="site_condition[<?= $k ?>][status]" value="OK" checked>
                      <span class="status-label status-label-ok">OK</span>
                    </label>
                    <label>
                      <input type="radio" class="status-radio" name="site_condition[<?= $k ?>][status]" value="NOK">
                      <span class="status-label status-label-nok">NOK</span>
                    </label>
                    <label>
                      <input type="radio" class="status-radio" name="site_condition[<?= $k ?>][status]" value="NA">
                      <span class="status-label status-label-na">N/A</span>
                    </label>
                  </div>
                </td>
                <td>
                  <input type="text" name="site_condition[<?= $k ?>][remarks]" class="form-control" style="min-height: 36px;" placeholder="<?= htmlspecialchars($info[1] ? "Contoh: {$info[1]}" : "Remarks") ?>">
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

      </div>
    </div>

    <!-- Section 3: Base Service - Structural & Inspection (Halaman 3) -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">3. Base Service - Structural & Kondisi Pondasi (Halaman 3)</h3>
      </div>
      <div class="card-body">
        
        <table class="checklist-table">
          <thead>
            <tr>
              <th>Komponen Struktur</th>
              <th>Pilihan Kondisi</th>
              <th style="width: 200px;">Butuh Investigasi Lanjut?</th>
              <th>Keterangan Tambahan</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><strong>Members Completeness</strong></td>
              <td>
                <select name="structural[members][val]" class="form-control">
                  <option value="Complete/lengkap" selected>Complete / Lengkap</option>
                  <option value="Not complete/tidak lengkap">Not Complete / Tidak Lengkap</option>
                </select>
              </td>
              <td>
                <select name="structural[members][need_inv]" class="form-control">
                  <option value="No" selected>No (Tidak)</option>
                  <option value="Yes">Yes (Ya)</option>
                </select>
              </td>
              <td><input type="text" name="structural[members][remarks]" class="form-control" placeholder="Remarks"></td>
            </tr>

            <tr>
              <td><strong>Tower Corrosion (Korosi)</strong></td>
              <td>
                <select name="structural[corrosion][val]" class="form-control">
                  <option value="No" selected>No (Tidak Ada Korosi)</option>
                  <option value="Yes,minor">Yes, Minor</option>
                  <option value="Yes,major">Yes, Major</option>
                </select>
              </td>
              <td>
                <select name="structural[corrosion][need_inv]" class="form-control">
                  <option value="No" selected>No (Tidak)</option>
                  <option value="Yes">Yes (Ya)</option>
                </select>
              </td>
              <td><input type="text" name="structural[corrosion][remarks]" class="form-control" placeholder="Remarks"></td>
            </tr>

            <tr>
              <td><strong>Tower Alignment (Ketegakan)</strong></td>
              <td>
                <select name="structural[alignment][val]" class="form-control">
                  <option value="Align and vertical/simetris & tegak lurus" selected>Align & Vertical (Simetris & Tegak)</option>
                  <option value="Not Align & not vertical/tidak tegak lurus">Not Align & Not Vertical</option>
                </select>
              </td>
              <td>
                <select name="structural[alignment][need_inv]" class="form-control">
                  <option value="No" selected>No (Tidak)</option>
                  <option value="Yes">Yes (Ya)</option>
                </select>
              </td>
              <td><input type="text" name="structural[alignment][remarks]" class="form-control" placeholder="Remarks"></td>
            </tr>

            <tr>
              <td><strong>Pedestal & Baseplate</strong></td>
              <td>
                <select name="structural[pedestal][val]" class="form-control">
                  <option value="Good/bagus" selected>Good / Bagus</option>
                  <option value="Cracked/retak">Cracked / Retak</option>
                  <option value="Corrosion/Baseplate berkarat">Corrosion / Berkarat</option>
                </select>
              </td>
              <td>
                <select name="structural[pedestal][need_inv]" class="form-control">
                  <option value="No" selected>No (Tidak)</option>
                  <option value="Yes">Yes (Ya)</option>
                </select>
              </td>
              <td><input type="text" name="structural[pedestal][remarks]" class="form-control" placeholder="Remarks"></td>
            </tr>

            <tr>
              <td><strong>Soil & Gravel Condition</strong></td>
              <td>
                <select name="structural[soil][val]" class="form-control">
                  <option value="Normal" selected>Normal</option>
                  <option value="Landslide/penurunan">Landslide / Penurunan</option>
                  <option value="Flooded/tergenang">Flooded / Tergenang</option>
                  <option value="Washed away/tergerus">Washed Away / Tergerus</option>
                </select>
              </td>
              <td>
                <select name="structural[soil][need_inv]" class="form-control">
                  <option value="No" selected>No (Tidak)</option>
                  <option value="Yes">Yes (Ya)</option>
                </select>
              </td>
              <td><input type="text" name="structural[soil][remarks]" class="form-control" placeholder="Remarks"></td>
            </tr>

            <tr>
              <td><strong>Building Existing Condition</strong></td>
              <td>
                <select name="structural[building][val]" class="form-control">
                  <option value="Normal">Normal</option>
                  <option value="Cracked/retak">Cracked / Retak</option>
                  <option value="Leakage/Bocor">Leakage / Bocor</option>
                  <option value="Other/lainya : Site GF" selected>Other: Site GF</option>
                </select>
              </td>
              <td>
                <select name="structural[building][need_inv]" class="form-control">
                  <option value="No" selected>No (Tidak)</option>
                  <option value="Yes">Yes (Ya)</option>
                </select>
              </td>
              <td><input type="text" name="structural[building][remarks]" class="form-control" placeholder="Remarks"></td>
            </tr>
          </tbody>
        </table>

        <div class="form-group" style="margin-top: 1rem;">
          <label class="form-label">Inspeksi Oleh (Tanda Tangan Halaman 3):</label>
          <div style="display: flex; gap: 1rem;">
            <input type="text" name="inspection_company" class="form-control" value="PT.NAER" style="width: 200px;">
            <input type="text" name="inspection_person" class="form-control" value="HAMDHANI" style="width: 250px;">
          </div>
        </div>

      </div>
    </div>

    <!-- Section 4: Upload Dokumentasi Foto (48 Slot: Grup A s/d H) -->
    <div class="card" id="sec_photos">
      <div class="card-header">
        <div>
          <h3 class="card-title">4. Upload Dokumentasi Foto PM Outdoor (Halaman 6 s/d 13)</h3>
          <p style="font-size: 0.8rem; color: var(--text-subtle); margin-top: 2px;">
            Total 48 slot foto (8 halaman, 6 foto per halaman). Centang <strong>Tandai N/A</strong> jika slot foto kosong.
          </p>
        </div>
      </div>
      <div class="card-body">

        <!-- Photo Filter & Group Jump Toolbar -->
        <div class="photo-filter-toolbar no-print">
          <div class="photo-filter-tabs">
            <button type="button" class="btn-photo-filter active" data-filter="all">Semua Slot (<span id="count_all_slots">48</span>)</button>
            <button type="button" class="btn-photo-filter" data-filter="filled">📸 Hanya Terisi (<span id="count_filled_slots">0</span>)</button>
            <button type="button" class="btn-photo-filter" data-filter="empty">⏳ Belum Terisi (<span id="count_empty_slots">48</span>)</button>
          </div>
          <div class="photo-jump-group">
            <label for="jump_photo_group" style="font-size: 0.8rem; font-weight: 600; color: var(--text-subtle);">Lompat:</label>
            <select id="jump_photo_group" class="form-control" style="font-size: 0.825rem; padding: 0.35rem 0.6rem; min-height: 40px; width: auto;">
              <option value="">-- Ke Halaman Foto --</option>
              <option value="grp_A">Halaman 6 (Grup A)</option>
              <option value="grp_B">Halaman 7 (Grup B)</option>
              <option value="grp_C">Halaman 8 (Grup C)</option>
              <option value="grp_D">Halaman 9 (Grup D)</option>
              <option value="grp_E">Halaman 10 (Grup E)</option>
              <option value="grp_F">Halaman 11 (Grup F)</option>
              <option value="grp_G">Halaman 12 (Grup G)</option>
              <option value="grp_H">Halaman 13 (Grup H)</option>
            </select>
          </div>
        </div>

        <!-- Watermark Controller Card -->
        <div class="watermark-controller-card" style="border-color: #bae6fd; background: #f0f9ff;">
          <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 38px; height: 38px; border-radius: 50%; background: var(--secondary); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
              ⏱️
            </div>
            <div>
              <strong style="color: var(--text-main); font-size: 0.88rem;">Timestamp & GPS Watermark Otomatis</strong>
              <p style="font-size: 0.75rem; color: var(--text-subtle); margin-top: 1px;">Kamera live otomatis mencetak nama site, tanggal & jam inspeksi (WIB), dan koordinat GPS.</p>
            </div>
          </div>
          <div style="display: flex; flex-direction: column; gap: 8px; align-items: flex-start;">
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.85rem; font-weight: 600; color: var(--text-main);">
              <input type="checkbox" id="auto_watermark_toggle" checked style="width: 18px; height: 18px; accent-color: var(--secondary);">
              Aktifkan Watermark Otomatis
            </label>
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.85rem; font-weight: 600; color: var(--secondary);">
              <input type="checkbox" id="compact_pdf" name="compact_pdf" value="1" checked style="width: 18px; height: 18px; accent-color: var(--secondary);">
              Lewati slot foto kosong (N/A) saat cetak PDF
            </label>
          </div>
        </div>

        <?php foreach ($photoGroups as $grpKey => $grp): ?>
          <div id="grp_<?= $grpKey ?>" style="margin-bottom: 2rem;">
            <h4 style="font-size: 1.05rem; font-weight: 700; color: var(--secondary); padding-bottom: 0.5rem; border-bottom: 2px solid #e0f2fe; margin-bottom: 1rem;">
              <?= htmlspecialchars($grp['page_title']) ?>
            </h4>

            <div class="photo-upload-grid">
              <?php foreach ($grp['slots'] as $slotCode => $slot): ?>
                <div class="photo-slot-card">
                  <div class="photo-slot-header">
                    <span class="photo-code-badge" style="background: var(--secondary);">Foto <?= $slotCode ?></span>
                    <label style="font-size: 0.8rem; color: var(--text-subtle); display: flex; align-items: center; gap: 4px; cursor: pointer;">
                      <input type="checkbox" id="isna_<?= $slotCode ?>" name="photos[<?= $slotCode ?>][is_na]" value="1" class="photo-na-toggle" data-slot="<?= $slotCode ?>">
                      Tandai N/A
                    </label>
                  </div>

                  <div style="margin-bottom: 0.35rem;">
                    <label class="form-label" style="font-size: 0.75rem; color: var(--text-subtle); margin-bottom: 2px;">Keterangan Foto</label>
                    <input type="text" id="title_<?= $slotCode ?>" name="photos[<?= $slotCode ?>][title]" class="form-control" style="min-height: 36px; padding: 0.35rem 0.6rem; font-size: 0.85rem; font-weight: 600;" value="<?= htmlspecialchars($slot['title']) ?>" placeholder="Keterangan foto bebas...">
                  </div>

                  <!-- Image Preview Box -->
                  <div class="photo-preview-box" id="preview_box_<?= $slotCode ?>" data-slot="<?= $slotCode ?>">
                    <img id="preview_img_<?= $slotCode ?>" class="photo-preview-img" alt="Preview Foto">
                    <div id="na_overlay_<?= $slotCode ?>" class="photo-na-overlay">N/A</div>
                    <div id="placeholder_<?= $slotCode ?>" style="display: flex; flex-direction: column; align-items: center; gap: 6px; color: var(--text-subtle);">
                      <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                      <span style="font-size: 0.75rem; font-weight: 600;">Ambil Kamera / Pilih Foto</span>
                    </div>
                  </div>

                  <!-- Dual Action Buttons: Live Camera & Gallery -->
                  <div class="photo-btn-row">
                    <label for="camera_<?= $slotCode ?>" class="btn btn-primary btn-open-camera" data-slot="<?= $slotCode ?>" style="background-color: var(--secondary); border-color: var(--secondary); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 4px; min-height: 40px;">
                      📷 Kamera Live
                    </label>
                    <label for="file_<?= $slotCode ?>" class="btn btn-secondary btn-pick-file" data-slot="<?= $slotCode ?>" style="cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 4px; min-height: 40px;">
                      📁 Galeri
                    </label>
                  </div>

                  <input type="file" id="camera_<?= $slotCode ?>" name="camera_files[<?= $slotCode ?>]" accept="image/*" capture="environment" class="photo-file-input photo-hidden-input" data-slot="<?= $slotCode ?>">
                  <input type="file" id="file_<?= $slotCode ?>" name="photo_files[<?= $slotCode ?>]" accept="image/*" class="photo-file-input photo-hidden-input" data-slot="<?= $slotCode ?>">
                  <input type="hidden" id="base64_<?= $slotCode ?>" name="photo_base64[<?= $slotCode ?>]">
                  <input type="hidden" id="watermarked_<?= $slotCode ?>" name="photo_watermarked[<?= $slotCode ?>]" value="0">

                  <?php if ($slot['has_measurement']): ?>
                    <div class="form-group" style="margin-top: 0.35rem;">
                      <label class="form-label" style="font-size: 0.75rem;">Nilai Pengukuran</label>
                      <input type="text" name="photos[<?= $slotCode ?>][measurement]" class="form-control" style="min-height: 36px; font-size: 0.85rem;" placeholder="Contoh: <?= htmlspecialchars($slot['default_label'] ?? '') ?>" value="<?= htmlspecialchars($slot['default_label'] ?? '') ?>">
                    </div>
                  <?php endif; ?>

                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>

      </div>
    </div>

    <!-- Sticky Bottom Submit Bar -->
    <div class="sticky-footer-bar" id="sec_submit">
      <div>
        <strong style="color: var(--text-main); font-size: 0.95rem;">Siap Memproses Laporan Outdoor?</strong>
        <p style="font-size: 0.8rem; color: var(--text-subtle); margin: 2px 0 0 0;">Pastikan data inspeksi dan dokumentasi foto telah diperiksa.</p>
      </div>
      <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
        <button type="button" class="btn btn-secondary" onclick="saveDraftManual('outdoor')" style="min-height: 44px; display: inline-flex; align-items: center; gap: 6px;">
          💾 Simpan ke Draft
        </button>
        <button type="submit" id="btn_submit_report" class="btn btn-primary" style="background-color: var(--secondary); border-color: var(--secondary); padding: 0.65rem 1.75rem; font-size: 0.95rem; min-height: 44px;">
          Simpan & Buat Laporan PM Outdoor (PDF)
        </button>
      </div>
    </div>

  </form>

</main>

<script>
  function saveDraftManual(type) {
    if (window.DraftManager) {
      window.DraftManager.saveDraft(type);
      alert('✅ Draft tersimpan aman di perangkat! Anda dapat melanjutkan pengisian kapan saja.');
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (window.DraftManager) {
      window.DraftManager.init('outdoor');
    }
  });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
