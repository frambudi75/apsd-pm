/**
 * APSD Preventive Maintenance System - Client Interactions
 * PT Angkasa Pura Sarana Digital
 */

document.addEventListener('DOMContentLoaded', function () {
  // 1. Site Selector Autofill
  const siteSelect = document.getElementById('site_id_select');
  if (siteSelect) {
    siteSelect.addEventListener('change', function () {
      const selectedOpt = siteSelect.options[siteSelect.selectedIndex];
      if (!selectedOpt || !selectedOpt.value) return;

      const setVal = (id, val) => {
        const el = document.getElementById(id);
        if (el && val !== undefined) el.value = val;
      };

      setVal('site_name', selectedOpt.getAttribute('data-name') || '');
      setVal('site_id_str', selectedOpt.getAttribute('data-code') || '');
      setVal('location_detail', selectedOpt.getAttribute('data-location') || '');
      setVal('region', selectedOpt.getAttribute('data-region') || '');
      setVal('province', selectedOpt.getAttribute('data-province') || '');
      setVal('city', selectedOpt.getAttribute('data-city') || '');
      setVal('address', selectedOpt.getAttribute('data-address') || '');
      setVal('latitude', selectedOpt.getAttribute('data-lat') || '');
      setVal('longitude', selectedOpt.getAttribute('data-long') || '');
      setVal('site_height', selectedOpt.getAttribute('data-height') || '');
      setVal('tower_type', selectedOpt.getAttribute('data-tower') || '');
      setVal('site_type', selectedOpt.getAttribute('data-sitetype') || '');
    });
  }

  // 2. Button "Set All OK"
  const btnSetAllOk = document.getElementById('btn_set_all_ok');
  if (btnSetAllOk) {
    btnSetAllOk.addEventListener('click', function () {
      const okRadios = document.querySelectorAll('input[type="radio"][value="OK"]');
      okRadios.forEach(radio => {
        radio.checked = true;
      });
      // Show subtle feedback
      const origText = btnSetAllOk.innerText;
      btnSetAllOk.innerText = 'Semua Berhasil Diset OK!';
      btnSetAllOk.classList.add('btn-primary');
      setTimeout(() => {
        btnSetAllOk.innerText = origText;
      }, 1500);
    });
  }

  // Helper: Client-side Image Compression without quality loss
  function compressImageClient(file, maxDim, quality, callback) {
    if (!file || !file.type.match(/image.*/)) {
      callback(file, null);
      return;
    }
    const reader = new FileReader();
    reader.onload = function (e) {
      const img = new Image();
      img.onload = function () {
        let width = img.width;
        let height = img.height;
        if (width > maxDim || height > maxDim) {
          if (width > height) {
            height = Math.round((height / width) * maxDim);
            width = maxDim;
          } else {
            width = Math.round((width / height) * maxDim);
            height = maxDim;
          }
        }
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, width, height);

        canvas.toBlob(function (blob) {
          if (blob && blob.size < file.size) {
            const compressedFile = new File([blob], file.name.replace(/\.[^/.]+$/, ".jpg"), {
              type: 'image/jpeg',
              lastModified: Date.now()
            });
            callback(compressedFile, canvas.toDataURL('image/jpeg', quality));
          } else {
            callback(file, e.target.result);
          }
        }, 'image/jpeg', quality);
      };
      img.src = e.target.result;
    };
    reader.readAsDataURL(file);
  }

  // 3. Photo Upload Slots & Preview with Auto-Compression
  const photoFileInputs = document.querySelectorAll('.photo-file-input');
  photoFileInputs.forEach(input => {
    input.addEventListener('change', function (e) {
      const slotCode = this.getAttribute('data-slot');
      const previewImg = document.getElementById('preview_img_' + slotCode);
      const naOverlay = document.getElementById('na_overlay_' + slotCode);
      const placeholder = document.getElementById('placeholder_' + slotCode);
      const isNaCheckbox = document.getElementById('isna_' + slotCode);
      const inputElem = this;

      if (this.files && this.files[0]) {
        const originalFile = this.files[0];
        compressImageClient(originalFile, 1600, 0.85, function (compressedFile, dataUrl) {
          if (previewImg) {
            previewImg.src = dataUrl;
            previewImg.style.display = 'block';
          }
          if (naOverlay) naOverlay.style.display = 'none';
          if (placeholder) placeholder.style.display = 'none';
          if (isNaCheckbox) isNaCheckbox.checked = false;

          try {
            const dt = new DataTransfer();
            dt.items.add(compressedFile);
            inputElem.files = dt.files;
          } catch (err) {
            // Fallback to server-side compression
          }
        });
      }
    });
  });

  // 4. N/A Toggle for Photos
  const naCheckboxes = document.querySelectorAll('.photo-na-toggle');
  naCheckboxes.forEach(chk => {
    chk.addEventListener('change', function () {
      const slotCode = this.getAttribute('data-slot');
      const fileInput = document.getElementById('file_' + slotCode);
      const previewImg = document.getElementById('preview_img_' + slotCode);
      const naOverlay = document.getElementById('na_overlay_' + slotCode);
      const placeholder = document.getElementById('placeholder_' + slotCode);

      if (this.checked) {
        if (fileInput) fileInput.value = '';
        if (previewImg) previewImg.style.display = 'none';
        if (placeholder) placeholder.style.display = 'none';
        if (naOverlay) naOverlay.style.display = 'block';
      } else {
        if (naOverlay) naOverlay.style.display = 'none';
        if (placeholder) placeholder.style.display = 'flex';
      }
    });
  });

  // 5. Form Submission Loading State
  const mainForm = document.getElementById('pm_report_form');
  if (mainForm) {
    mainForm.addEventListener('submit', function () {
      const submitBtn = document.getElementById('btn_submit_report');
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerText = 'Menyimpan & Memproses Laporan...';
      }
    });
  }

  // 6. Logo Provider Customizer
  const presetLogoSelect = document.getElementById('preset_logo_select');
  const operatorNameInput = document.getElementById('operator_name_input');
  const customLogoInput = document.getElementById('custom_logo_file');
  const logoPreviewImg = document.getElementById('logo_preview_img');

  if (presetLogoSelect && logoPreviewImg) {
    const logoMap = {
      'telkomsel': { src: 'assets/img/logo_telkomsel.png?v=2', name: 'TELKOMSEL' },
      'ias': { src: 'assets/img/logo_ias.png?v=2', name: 'APSD' },
      'indosat': { src: 'assets/img/logo_indosat.svg', name: 'INDOSAT OOREDOO' },
      'xl': { src: 'assets/img/logo_xl.svg', name: 'XL AXIATA' },
      'none': { src: '', name: '-' }
    };

    presetLogoSelect.addEventListener('change', function () {
      const val = this.value;
      if (val === 'custom') {
        if (customLogoInput) customLogoInput.click();
      } else if (logoMap[val]) {
        logoPreviewImg.src = logoMap[val].src;
        logoPreviewImg.style.display = logoMap[val].src ? 'block' : 'none';
        if (operatorNameInput && logoMap[val].name !== '-') {
          operatorNameInput.value = logoMap[val].name;
        }
      }
    });

    if (customLogoInput) {
      customLogoInput.addEventListener('change', function () {
        if (this.files && this.files[0]) {
          const reader = new FileReader();
          reader.onload = function (e) {
            logoPreviewImg.src = e.target.result;
            logoPreviewImg.style.display = 'block';
            if (presetLogoSelect) presetLogoSelect.value = 'custom';
          };
          reader.readAsDataURL(this.files[0]);
        }
      });
    }
  }

  // 7. PWA Installation Prompt & Offline Handler
  let deferredPrompt = null;
  const pwaInstallBanner = document.getElementById('pwa_install_banner');
  const btnInstallPwa = document.getElementById('btn_install_pwa');
  const btnDismissPwa = document.getElementById('btn_dismiss_pwa');
  const offlineNotice = document.getElementById('offline_notice');

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    if (pwaInstallBanner && !sessionStorage.getItem('pwa_prompt_dismissed')) {
      pwaInstallBanner.style.display = 'flex';
    }
  });

  if (btnInstallPwa) {
    btnInstallPwa.addEventListener('click', async function () {
      if (deferredPrompt) {
        deferredPrompt.prompt();
        const { outcome } = await deferredPrompt.userChoice;
        console.log('PWA installation response:', outcome);
        deferredPrompt = null;
        if (pwaInstallBanner) pwaInstallBanner.style.display = 'none';
      }
    });
  }

  if (btnDismissPwa) {
    btnDismissPwa.addEventListener('click', function () {
      if (pwaInstallBanner) pwaInstallBanner.style.display = 'none';
      sessionStorage.setItem('pwa_prompt_dismissed', '1');
    });
  }

  window.addEventListener('appinstalled', function () {
    console.log('APSD PM PWA installed successfully');
    if (pwaInstallBanner) pwaInstallBanner.style.display = 'none';
  });

  // Offline / Online network status monitor
  function updateOnlineStatus() {
    if (offlineNotice) {
      if (!navigator.onLine) {
        offlineNotice.style.display = 'block';
      } else {
        offlineNotice.style.display = 'none';
      }
    }
  }
  window.addEventListener('online', updateOnlineStatus);
  window.addEventListener('offline', updateOnlineStatus);
  updateOnlineStatus();
});

