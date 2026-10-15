<?php
/**
 * Form Input PM Indoor (Daily Check List IBC Telkomsel - 7 Halaman)
 * PT Angkasa Pura Sarana Digital
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/checklist_data.php';
$pdo = get_db_connection();

$sites = $pdo->query("SELECT * FROM sites ORDER BY site_name ASC")->fetchAll();
$definitions = get_indoor_checklist_definitions();
$photoGroups = get_indoor_photo_slots();

$page_title = 'Formulir PM Indoor (IBC Telkomsel)';
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
      <span style="font-size: 0.8rem; font-weight: 700; color: var(--primary); text-transform: uppercase;">Modul PM Indoor</span>
      <h2 style="font-size: 1.4rem; font-weight: 700; color: var(--text-main);">Daily Check List IBC Telkomsel (7 Halaman)</h2>
      <p style="color: var(--text-subtle); font-size: 0.9rem;">Inspeksi ruang server domestik, perangkat transmisi, daya listrik, dan dokumentasi foto.</p>
    </div>
    <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
      <span id="autosave_status" class="autosave-badge">💾 Auto-Save Siap</span>
      <button type="button" id="btn_set_all_ok" class="btn btn-secondary btn-sm" style="font-weight: 700; color: var(--status-ok); border-color: var(--status-ok); min-height: 40px;">
        ✓ Set Semua Checklist OK
      </button>
      <a href="index.php" class="btn btn-secondary btn-sm" style="min-height: 40px; display: inline-flex; align-items: center;">&larr; Batal / Kembali</a>
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

  <form id="pm_report_form" action="api/save_indoor.php" method="POST" enctype="multipart/form-data">
    <input type="hidden" id="report_type_hidden" value="indoor">

    <!-- Section 1: Informasi Form & Site -->
    <div class="card" id="sec_admin">
      <div class="card-header">
        <h3 class="card-title">1. Informasi Administrasi & Lokasi Site</h3>
      </div>
      <div class="card-body">
        
        <div class="form-group" style="margin-bottom: 1.25rem;">
          <label class="form-label" style="color: var(--primary); font-size: 0.9rem;">Pilih Master Site (Otomatis Lengkapi Data)</label>
          <div style="display: flex; gap: 0.5rem; align-items: stretch; flex-wrap: wrap;">
            <select id="site_id_select" name="site_id" class="form-control" style="flex: 1; min-width: 240px; font-weight: 600;">
              <option value="">-- Pilih Site Tersimpan atau Ketik Manual di Bawah --</option>
            <?php foreach ($sites as $s): ?>
              <option value="<?= $s['id'] ?>"
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
                <?= htmlspecialchars($s['site_name']) ?> (<?= htmlspecialchars($s['location_detail'] ?: $s['region']) ?>)
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
            <label class="form-label">Nama Site *</label>
            <input type="text" id="site_name" name="site_name" class="form-control" value="IBC Bandara International KUALANAMU" required>
          </div>

          <div class="form-group">
            <label class="form-label">Lokasi Pemeriksaan *</label>
            <input type="text" id="location_detail" name="location_detail" class="form-control" value="Server Domestik" required>
          </div>

          <div class="form-group">
            <label class="form-label">Tanggal Pemeriksaan *</label>
            <input type="date" name="report_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
          </div>

          <div class="form-group">
            <label class="form-label">Petugas Pemeriksa (PIC) *</label>
            <input type="text" name="inspector_name" class="form-control" placeholder="Nama petugas di lapangan" value="Juli" required>
          </div>

          <div class="form-group">
            <label class="form-label">Team Leader</label>
            <input type="text" name="team_leader" class="form-control" placeholder="Nama leader jika ada">
          </div>

          <div class="form-group">
            <label class="form-label">Periode Pemeriksaan</label>
            <input type="text" name="period" class="form-control" value="Harian / Daily">
          </div>

          <div class="form-group">
            <label class="form-label">Form No.</label>
            <input type="text" name="form_no" class="form-control" value="1">
          </div>

          <div class="form-group">
            <label class="form-label">Tanggal Efektif</label>
            <input type="text" name="effective_date" class="form-control" value="01-Jul-25">
          </div>

          <div class="form-group">
            <label class="form-label">Region</label>
            <input type="text" id="region" name="region" class="form-control" value="Deli Serdang">
          </div>

          <div class="form-group">
            <label class="form-label">Provinsi</label>
            <input type="text" id="province" name="province" class="form-control" value="Sumatra Utara">
          </div>

          <div class="form-group">
            <label class="form-label">Kota</label>
            <input type="text" id="city" name="city" class="form-control" value="Medan">
          </div>

          <div class="form-group">
            <label class="form-label">Tinggi Site</label>
            <input type="text" id="site_height" name="site_height" class="form-control" value="-">
          </div>

          <div class="form-group">
            <label class="form-label">Tipe Tower</label>
            <select id="tower_type" name="tower_type" class="form-control">
              <option value="Non Pole">Non Pole</option>
              <option value="Pole">Pole</option>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Tipe Site</label>
            <select id="site_type" name="site_type" class="form-control">
              <option value="Indoor" selected>Indoor</option>
              <option value="Outdoor">Outdoor</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Alamat Lengkap</label>
          <textarea id="address" name="address" class="form-control" rows="2">Jl. Bandara Kuala Namu, Ps. Enam Kuala Namu, Kec. Beringin, Kabupaten Deli Serdang, Sumatera Utara</textarea>
        </div>

        <!-- Logo Provider / Tenant Customizer -->
        <div style="margin-top: 1.25rem; padding: 1rem; background: var(--bg-alt); border-radius: var(--radius-sm); border: 1px solid var(--border);">
          <h4 style="font-size: 0.95rem; font-weight: 700; color: var(--primary); margin-bottom: 0.75rem;">Logo Provider / Operator (Kop Kanan Laporan)</h4>
          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Pilihan Logo Kop Kanan</label>
              <select id="preset_logo_select" name="preset_logo" class="form-control">
                <option value="telkomsel" selected>Telkomsel</option>
                <option value="ias">IAS Support / APSD</option>
                <option value="indosat">Indosat Ooredoo</option>
                <option value="xl">XL Axiata</option>
                <option value="custom">-- Upload Logo Custom / Provider Lain --</option>
                <option value="none">Tanpa Logo</option>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Nama Provider / Operator</label>
              <input type="text" id="operator_name_input" name="operator" class="form-control" value="TELKOMSEL" placeholder="Contoh: TELKOMSEL, INDOSAT, XL">
            </div>

            <div class="form-group" id="custom_logo_group">
              <label class="form-label">Upload File Logo Baru (PNG / JPG / SVG)</label>
              <input type="file" name="custom_logo_file" id="custom_logo_file" accept="image/*" class="form-control">
            </div>

            <div class="form-group" style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
              <span style="font-size: 0.75rem; color: var(--text-subtle); margin-bottom: 4px;">Pratinjau Logo Kanan:</span>
              <div style="border: 1px dashed var(--border-strong); padding: 4px; border-radius: 4px; background: #fff; width: 140px; height: 50px; display: flex; align-items: center; justify-content: center;">
                <img id="logo_preview_img" src="assets/img/logo_telkomsel.png?v=2" style="max-height: 42px; max-width: 130px; object-fit: contain;" alt="Logo Preview">
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- Section 2: Checklist Inspeksi (Bagian A s/d K) -->
    <div class="card" id="sec_checklist">
      <div class="card-header">
        <h3 class="card-title">2. Lembar Checklist Inspeksi Teknis (Halaman 1 s/d 3)</h3>
      </div>
      <div class="card-body" style="padding: 0;">

        <?php foreach ($definitions as $secKey => $section): ?>
          <div class="checklist-section-header">
            Bagian <?= $secKey ?>. <?= htmlspecialchars($section['name']) ?>
          </div>

          <table class="checklist-table">
            <thead>
              <tr>
                <th style="width: 45px; text-align: center;">No</th>
                <th>Item Pemeriksaan</th>
                <th style="width: 220px; text-align: center;">Hasil</th>
                <th style="width: 320px;">Referensi Standar</th>
                <th style="width: 220px;">Keterangan Tambahan</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($section['items'] as $itemNo => $item): ?>
                <?php $fieldKey = "chk_{$secKey}_{$itemNo}"; ?>
                <tr>
                  <td style="text-align: center; font-weight: bold;"><?= $itemNo ?></td>
                  <td>
                    <strong><?= htmlspecialchars($item['name']) ?></strong>
                  </td>
                  <td style="text-align: center;">
                    <div class="status-pill-group" style="justify-content: center;">
                      <label>
                        <input type="radio" class="status-radio" name="checklist[<?= $fieldKey ?>][status]" value="OK" checked>
                        <span class="status-label status-label-ok">OK</span>
                      </label>
                      <label>
                        <input type="radio" class="status-radio" name="checklist[<?= $fieldKey ?>][status]" value="NOK">
                        <span class="status-label status-label-nok">NOK</span>
                      </label>
                      <label>
                        <input type="radio" class="status-radio" name="checklist[<?= $fieldKey ?>][status]" value="NA">
                        <span class="status-label status-label-na">N/A</span>
                      </label>
                    </div>
                  </td>
                  <td style="font-size: 0.825rem; color: var(--text-muted);">
                    <?= htmlspecialchars($item['ref']) ?>
                    <input type="hidden" name="checklist[<?= $fieldKey ?>][ref]" value="<?= htmlspecialchars($item['ref']) ?>">
                    <input type="hidden" name="checklist[<?= $fieldKey ?>][name]" value="<?= htmlspecialchars($item['name']) ?>">
                  </td>
                  <td>
                    <input type="text" name="checklist[<?= $fieldKey ?>][note]" class="form-control" style="min-height: 36px; padding: 0.25rem 0.5rem; font-size: 0.85rem;" placeholder="Catatan bila ada">
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endforeach; ?>

        <!-- Catatan Umum -->
        <div style="padding: 1.25rem; background: var(--bg-alt); border-top: 1px solid var(--border);">
          <label class="form-label" style="font-weight: 700; margin-bottom: 0.5rem; display: block;">Catatan Tambahan (Box Catatan Halaman 3):</label>
          <textarea name="notes" class="form-control" rows="3" placeholder="Tuliskan catatan teknis atau rekomendasi tindak lanjut jika ada..."></textarea>
        </div>

      </div>
    </div>

    <!-- Section 3: Upload Dokumentasi Foto (24 Slot: Grup A, B, C, D) -->
    <div class="card" id="sec_photos">
      <div class="card-header">
        <div>
          <h3 class="card-title">3. Upload Dokumentasi Foto (Halaman 4 s/d 7)</h3>
          <p style="font-size: 0.8rem; color: var(--text-subtle); margin-top: 2px;">
            Total 24 slot foto (6 foto per halaman). Centang <strong>Tandai N/A</strong> jika slot tidak memiliki foto.
          </p>
        </div>
      </div>
      <div class="card-body">

        <!-- Photo Filter & Group Jump Toolbar -->
        <div class="photo-filter-toolbar no-print">
          <div class="photo-filter-tabs">
            <button type="button" class="btn-photo-filter active" data-filter="all">Semua Slot (<span id="count_all_slots">24</span>)</button>
            <button type="button" class="btn-photo-filter" data-filter="filled">📸 Hanya Terisi (<span id="count_filled_slots">0</span>)</button>
            <button type="button" class="btn-photo-filter" data-filter="empty">⏳ Belum Terisi (<span id="count_empty_slots">24</span>)</button>
          </div>
          <div class="photo-jump-group">
            <label for="jump_photo_group" style="font-size: 0.8rem; font-weight: 600; color: var(--text-subtle);">Lompat:</label>
            <select id="jump_photo_group" class="form-control" style="font-size: 0.825rem; padding: 0.35rem 0.6rem; min-height: 40px; width: auto;">
              <option value="">-- Ke Halaman --</option>
              <option value="grp_A">Halaman 4 (Grup A)</option>
              <option value="grp_B">Halaman 5 (Grup B)</option>
              <option value="grp_C">Halaman 6 (Grup C)</option>
              <option value="grp_D">Halaman 7 (Grup D)</option>
            </select>
          </div>
        </div>

        <!-- Watermark Controller Card -->
        <div class="watermark-controller-card">
          <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 38px; height: 38px; border-radius: 50%; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
              ⏱️
            </div>
            <div>
              <strong style="color: var(--text-main); font-size: 0.88rem;">Timestamp & GPS Watermark Otomatis</strong>
              <p style="font-size: 0.75rem; color: var(--text-subtle); margin-top: 1px;">Kamera live otomatis mencetak nama site, waktu terkini (WIB), dan koordinat GPS pada foto.</p>
            </div>
          </div>
          <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.85rem; font-weight: 600; color: var(--text-main);">
            <input type="checkbox" id="auto_watermark_toggle" checked style="width: 18px; height: 18px; accent-color: var(--primary);">
            Aktifkan Watermark Otomatis
          </label>
        </div>

        <?php foreach ($photoGroups as $grpKey => $grp): ?>
          <div id="grp_<?= $grpKey ?>" style="margin-bottom: 2rem;">
            <h4 style="font-size: 1.05rem; font-weight: 700; color: var(--primary); padding-bottom: 0.5rem; border-bottom: 2px solid var(--primary-subtle); margin-bottom: 1rem;">
              <?= htmlspecialchars($grp['page_title']) ?>
            </h4>

            <div class="photo-upload-grid">
              <?php foreach ($grp['slots'] as $slotCode => $slot): ?>
                <div class="photo-slot-card">
                  <div class="photo-slot-header">
                    <span class="photo-code-badge">Foto <?= $slotCode ?></span>
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
                    <label for="camera_<?= $slotCode ?>" class="btn btn-primary btn-open-camera" data-slot="<?= $slotCode ?>" style="cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 4px; min-height: 40px;">
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
        <strong style="color: var(--text-main); font-size: 0.95rem;">Siap Memproses Laporan?</strong>
        <div style="margin-top: 4px;">
          <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.825rem; font-weight: 600; color: var(--primary); cursor: pointer;">
            <input type="checkbox" id="compact_pdf" name="compact_pdf" value="1" checked style="width: 18px; height: 18px; accent-color: var(--primary); cursor: pointer;">
            📄 Cetak PDF Ringkas (Lewati slot foto kosong / N/A)
          </label>
        </div>
      </div>
      <div style="display: flex; gap: 0.75rem;">
        <a href="index.php" class="btn btn-secondary">Batal</a>
        <button type="submit" id="btn_submit_report" class="btn btn-primary" style="padding: 0.65rem 1.75rem; font-size: 0.95rem;">
          Simpan & Buat Laporan PM Indoor (PDF)
        </button>
      </div>
    </div>

  </form>

</main>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    if (window.DraftManager) {
      window.DraftManager.init('indoor');
    }
  });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
