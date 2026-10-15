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

  // 3. Geolocation & Timestamp Watermark Engine
  let liveGpsCoords = null;

  function initGeolocation() {
    try {
      if ('geolocation' in navigator) {
        navigator.geolocation.getCurrentPosition(
          function (pos) {
            liveGpsCoords = {
              lat: pos.coords.latitude.toFixed(6),
              long: pos.coords.longitude.toFixed(6)
            };
            updateHudText();
          },
          function (err) {
            console.warn('Geolocation info:', err.message);
          },
          { enableHighAccuracy: true, timeout: 6000 }
        );
      }
    } catch (err) {
      console.warn('Geolocation exception:', err);
    }
  }
  initGeolocation();

  function getActiveCoords() {
    if (liveGpsCoords) {
      return 'Lat: ' + liveGpsCoords.lat + ', Long: ' + liveGpsCoords.long;
    }
    const latInp = document.getElementById('latitude');
    const longInp = document.getElementById('longitude');
    if (latInp && longInp && latInp.value && longInp.value) {
      return 'Lat: ' + latInp.value + ', Long: ' + longInp.value;
    }
    return 'GPS: Tidak Tersedia';
  }

  function getActiveSiteInfo() {
    const siteInp = document.getElementById('site_name');
    const locInp = document.getElementById('location_detail') || document.getElementById('address') || document.getElementById('city');
    const name = siteInp && siteInp.value ? siteInp.value.trim() : 'Site APSD';
    const loc = locInp && locInp.value ? locInp.value.trim() : '';
    return loc ? (name + ' (' + loc + ')') : name;
  }

  function getFormattedTimestamp() {
    const now = new Date();
    const pad = n => String(n).padStart(2, '0');
    const d = pad(now.getDate());
    const m = pad(now.getMonth() + 1);
    const y = now.getFullYear();
    const h = pad(now.getHours());
    const min = pad(now.getMinutes());
    const s = pad(now.getSeconds());
    return `${d}/${m}/${y} ${h}:${min}:${s} WIB`;
  }

  // Stamp official inspection watermark on canvas
  function stampWatermark(canvas, slotCode) {
    const autoWatermarkToggle = document.getElementById('auto_watermark_toggle');
    if (autoWatermarkToggle && !autoWatermarkToggle.checked) {
      return;
    }

    const ctx = canvas.getContext('2d');
    const w = canvas.width;
    const h = canvas.height;
    const maxDim = Math.max(w, h);
    const scale = Math.max(1, maxDim / 1050);
    const boxH = Math.round(118 * scale);
    const pad = Math.round(14 * scale);

    ctx.save();

    // High-contrast gradient bar at bottom
    const grad = ctx.createLinearGradient(0, h - boxH - (18 * scale), 0, h);
    grad.addColorStop(0, 'rgba(0, 0, 0, 0)');
    grad.addColorStop(0.25, 'rgba(15, 23, 42, 0.88)');
    grad.addColorStop(1, 'rgba(15, 23, 42, 0.98)');
    ctx.fillStyle = grad;
    ctx.fillRect(0, h - boxH - (18 * scale), w, boxH + (18 * scale));

    // Teal vertical accent line
    ctx.fillStyle = '#008080';
    ctx.fillRect(pad, h - boxH + (4 * scale), 4 * scale, boxH - (12 * scale));

    const startX = pad + (12 * scale);

    // Text shadow for high contrast on bright/dark backgrounds
    ctx.shadowColor = 'rgba(0, 0, 0, 0.95)';
    ctx.shadowBlur = 4;
    ctx.shadowOffsetX = 1;
    ctx.shadowOffsetY = 1;

    // Header Tag
    ctx.font = 'bold ' + Math.round(11 * scale) + 'px sans-serif';
    ctx.fillStyle = '#38bdf8';
    ctx.fillText('PT ANGKASA PURA SARANA DIGITAL | PM INSPECTION', startX, h - boxH + (18 * scale));

    // Line 1: Timestamp
    ctx.font = 'bold ' + Math.round(13 * scale) + 'px sans-serif';
    ctx.fillStyle = '#ffffff';
    ctx.fillText('WAKTU : ' + getFormattedTimestamp(), startX, h - boxH + (38 * scale));

    // Line 2: Site & Location
    ctx.font = Math.round(12 * scale) + 'px sans-serif';
    ctx.fillStyle = '#f1f5f9';
    let siteText = 'SITE  : ' + getActiveSiteInfo();
    if (siteText.length > 55) siteText = siteText.substring(0, 52) + '...';
    ctx.fillText(siteText, startX, h - boxH + (56 * scale));

    // Line 3: GPS
    ctx.font = Math.round(12 * scale) + 'px sans-serif';
    ctx.fillStyle = '#fde047';
    ctx.fillText('GPS   : ' + getActiveCoords(), startX, h - boxH + (74 * scale));

    // Line 4: Slot Code & Title
    const titleInp = document.getElementById('title_' + slotCode);
    let titleText = titleInp && titleInp.value ? titleInp.value.trim() : '';
    let itemText = 'FOTO  : Slot ' + slotCode + (titleText ? (' (' + titleText + ')') : '');
    if (itemText.length > 55) itemText = itemText.substring(0, 52) + '...';
    ctx.font = Math.round(12 * scale) + 'px sans-serif';
    ctx.fillStyle = '#e2e8f0';
    ctx.fillText(itemText, startX, h - boxH + (92 * scale));

    ctx.restore();
  }

  function assignFileToSlot(slotCode, file, dataUrl) {
    const fileInput = document.getElementById('file_' + slotCode);
    const cameraInput = document.getElementById('camera_' + slotCode);
    const base64Input = document.getElementById('base64_' + slotCode);
    const previewImg = document.getElementById('preview_img_' + slotCode);
    const naOverlay = document.getElementById('na_overlay_' + slotCode);
    const placeholder = document.getElementById('placeholder_' + slotCode);
    const isNaCheckbox = document.getElementById('isna_' + slotCode);

    if (previewImg) {
      previewImg.src = dataUrl;
      previewImg.style.display = 'block';
    }
    if (naOverlay) naOverlay.style.display = 'none';
    if (placeholder) placeholder.style.display = 'none';
    if (isNaCheckbox) isNaCheckbox.checked = false;

    // Save base64 string to hidden input (works on 100% of mobile browsers)
    if (base64Input) {
      base64Input.value = dataUrl;
    }

    if (fileInput) {
      try {
        const dt = new DataTransfer();
        dt.items.add(file);
        fileInput.files = dt.files;
      } catch (err) {
        console.warn('DataTransfer not supported:', err);
      }
    }
  }

  async function compressAndWatermarkImage(file, slotCode, callback) {
    if (!file) {
      if (callback) callback(file, null);
      return;
    }

    // Flexible image detection (Android camera files often have empty file.type or generic octet-stream)
    const fileName = file.name || '';
    const isImage = (file.type && file.type.startsWith('image/')) ||
                    (/\.(jpe?g|png|webp|bmp|heic|heif|gif)$/i.test(fileName)) ||
                    (!file.type && file.size > 0);

    if (!isImage) {
      if (callback) callback(file, null);
      return;
    }

    try {
      let sourceWidth = 0;
      let sourceHeight = 0;
      let drawSource = null;

      // Primary engine: modern createImageBitmap with hardware EXIF orientation handling
      if (window.createImageBitmap) {
        try {
          drawSource = await createImageBitmap(file, { imageOrientation: 'from-image' });
          sourceWidth = drawSource.width;
          sourceHeight = drawSource.height;
        } catch (bErr) {
          console.warn('createImageBitmap fallback:', bErr);
          drawSource = null;
        }
      }

      // Secondary engine: HTMLImageElement via lightweight ObjectURL
      if (!drawSource) {
        const objUrl = URL.createObjectURL(file);
        const img = new Image();
        await new Promise((resolve, reject) => {
          img.onload = () => resolve();
          img.onerror = (e) => reject(e);
          img.src = objUrl;
        });
        URL.revokeObjectURL(objUrl);
        drawSource = img;
        sourceWidth = img.naturalWidth || img.width;
        sourceHeight = img.naturalHeight || img.height;
      }

      if (!sourceWidth || !sourceHeight) {
        throw new Error('Dimensi gambar tidak valid');
      }

      // Scale down proportionally to max 1600px
      const maxDim = 1600;
      let targetW = sourceWidth;
      let targetH = sourceHeight;
      if (targetW > maxDim || targetH > maxDim) {
        if (targetW > targetH) {
          targetH = Math.round((targetH / targetW) * maxDim);
          targetW = maxDim;
        } else {
          targetW = Math.round((targetW / targetH) * maxDim);
          targetH = maxDim;
        }
      }

      const canvas = document.createElement('canvas');
      canvas.width = targetW;
      canvas.height = targetH;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(drawSource, 0, 0, targetW, targetH);

      // Clean up bitmap memory if available
      if (drawSource && typeof drawSource.close === 'function') {
        drawSource.close();
      }

      // Stamp official inspection watermark
      stampWatermark(canvas, slotCode);

      const dataUrl = canvas.toDataURL('image/jpeg', 0.85);

      canvas.toBlob(function (blob) {
        if (blob) {
          const outName = (fileName ? fileName.replace(/\.[^/.]+$/, "") : ('photo_' + slotCode)) + '.jpg';
          const stampedFile = new File([blob], outName, {
            type: 'image/jpeg',
            lastModified: Date.now()
          });
          callback(stampedFile, dataUrl);
        } else {
          callback(file, dataUrl);
        }
      }, 'image/jpeg', 0.85);

    } catch (err) {
      console.error('Gagal kompresi watermark client:', err);
      // Fallback via FileReader
      const reader = new FileReader();
      reader.onload = function (e) {
        callback(file, e.target.result);
      };
      reader.onerror = function () {
        callback(file, null);
      };
      reader.readAsDataURL(file);
    }
  }

  // 4. Live In-App Camera Controller
  let cameraStream = null;
  let currentFacingMode = 'environment'; // Back camera by default
  let activeSlotCode = null;
  let hudInterval = null;

  function updateHudText() {
    const hudTime = document.getElementById('hud_time');
    const hudSite = document.getElementById('hud_site_name');
    const hudCoords = document.getElementById('hud_coords');
    const hudSlot = document.getElementById('hud_slot_info');

    if (hudTime) hudTime.innerText = 'WAKTU: ' + getFormattedTimestamp();
    if (hudSite) hudSite.innerText = 'SITE : ' + getActiveSiteInfo();
    if (hudCoords) hudCoords.innerText = 'GPS  : ' + getActiveCoords();
    if (hudSlot && activeSlotCode) {
      const titleInp = document.getElementById('title_' + activeSlotCode);
      const title = titleInp && titleInp.value ? titleInp.value.trim() : '';
      hudSlot.innerText = 'FOTO : Slot ' + activeSlotCode + (title ? (' (' + title + ')') : '');
    }
  }

  const isWebcamSupported = !!(window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia);

  async function openLiveCamera(slotCode) {
    activeSlotCode = slotCode;
    const modal = document.getElementById('camera_modal');
    const badge = document.getElementById('camera_slot_badge');
    if (badge) badge.innerText = 'Foto ' + slotCode;
    if (modal) modal.style.display = 'flex';

    updateHudText();
    if (hudInterval) clearInterval(hudInterval);
    hudInterval = setInterval(updateHudText, 1000);

    const started = await startCameraStream(currentFacingMode);
    if (!started) {
      closeLiveCamera();
      // Fallback directly to native device camera or file input
      const camInp = document.getElementById('camera_' + slotCode);
      const fileInp = document.getElementById('file_' + slotCode);
      if (camInp) camInp.click();
      else if (fileInp) fileInp.click();
    }
  }

  function closeLiveCamera() {
    const modal = document.getElementById('camera_modal');
    if (modal) modal.style.display = 'none';
    if (hudInterval) {
      clearInterval(hudInterval);
      hudInterval = null;
    }
    if (cameraStream) {
      cameraStream.getTracks().forEach(track => track.stop());
      cameraStream = null;
    }
  }

  async function startCameraStream(facingMode) {
    const video = document.getElementById('camera_video');
    if (cameraStream) {
      cameraStream.getTracks().forEach(track => track.stop());
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      return false;
    }
    try {
      const constraints = {
        video: {
          facingMode: { ideal: facingMode },
          width: { ideal: 1920 },
          height: { ideal: 1080 }
        },
        audio: false
      };
      cameraStream = await navigator.mediaDevices.getUserMedia(constraints);
      if (video) {
        video.srcObject = cameraStream;
        await video.play();
      }
      return true;
    } catch (err) {
      console.warn('getUserMedia unavailable, falling back:', err);
      return false;
    }
  }

  // Bind Camera & Gallery Trigger Buttons
  document.querySelectorAll('.btn-open-camera').forEach(btn => {
    btn.addEventListener('click', function (e) {
      const slot = this.getAttribute('data-slot');
      if (isWebcamSupported) {
        e.preventDefault();
        openLiveCamera(slot);
      }
      // If WebRTC is unavailable (e.g. HTTP context), native <label for="camera_..."> opens device camera directly
    });
  });

  document.querySelectorAll('.btn-pick-file').forEach(btn => {
    btn.addEventListener('click', function () {
      const slot = this.getAttribute('data-slot');
      const fileInp = document.getElementById('file_' + slot);
      if (fileInp && !this.htmlFor) fileInp.click();
    });
  });

  // Preview box click: open camera directly
  document.querySelectorAll('.photo-preview-box').forEach(box => {
    box.addEventListener('click', function () {
      const slot = this.getAttribute('data-slot');
      if (!slot) return;
      if (isWebcamSupported) {
        openLiveCamera(slot);
      } else {
        const camInp = document.getElementById('camera_' + slot);
        const fileInp = document.getElementById('file_' + slot);
        if (camInp) camInp.click();
        else if (fileInp) fileInp.click();
      }
    });
  });

  // Global helper fallback
  window.triggerPhotoChoice = function (slotCode) {
    if (!slotCode) return;
    if (isWebcamSupported) {
      openLiveCamera(slotCode);
    } else {
      const camInp = document.getElementById('camera_' + slotCode);
      const fileInp = document.getElementById('file_' + slotCode);
      if (camInp) camInp.click();
      else if (fileInp) fileInp.click();
    }
  };

  // Camera modal controls
  const btnCloseCam = document.getElementById('btn_close_camera');
  if (btnCloseCam) {
    btnCloseCam.addEventListener('click', closeLiveCamera);
  }

  const btnSwitchCam = document.getElementById('btn_switch_camera');
  if (btnSwitchCam) {
    btnSwitchCam.addEventListener('click', function () {
      currentFacingMode = (currentFacingMode === 'environment') ? 'user' : 'environment';
      startCameraStream(currentFacingMode);
    });
  }

  const btnGalleryFallback = document.getElementById('btn_camera_gallery_fallback');
  if (btnGalleryFallback) {
    btnGalleryFallback.addEventListener('click', function () {
      const slot = activeSlotCode;
      closeLiveCamera();
      if (slot) {
        const fileInp = document.getElementById('file_' + slot);
        if (fileInp) fileInp.click();
      }
    });
  }

  // Shutter button capture
  const btnCapture = document.getElementById('btn_capture_photo');
  if (btnCapture) {
    btnCapture.addEventListener('click', function () {
      const video = document.getElementById('camera_video');
      if (!video || !video.videoWidth || !activeSlotCode) return;

      const canvas = document.createElement('canvas');
      canvas.width = video.videoWidth;
      canvas.height = video.videoHeight;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

      // Stamp official inspection watermark
      stampWatermark(canvas, activeSlotCode);

      canvas.toBlob(function (blob) {
        if (blob) {
          const fileName = 'photo_' + activeSlotCode + '_' + Date.now() + '.jpg';
          const file = new File([blob], fileName, { type: 'image/jpeg', lastModified: Date.now() });
          assignFileToSlot(activeSlotCode, file, canvas.toDataURL('image/jpeg', 0.85));
        }
        closeLiveCamera();
      }, 'image/jpeg', 0.85);
    });
  }

  // File upload change listener
  document.querySelectorAll('.photo-file-input').forEach(input => {
    input.addEventListener('change', function () {
      const slotCode = this.getAttribute('data-slot');
      if (this.files && this.files[0]) {
        const rawFile = this.files[0];
        const placeholder = document.getElementById('placeholder_' + slotCode);
        const naOverlay = document.getElementById('na_overlay_' + slotCode);
        if (naOverlay) naOverlay.style.display = 'none';
        if (placeholder) {
          placeholder.innerHTML = '<span style="font-size: 0.8rem; font-weight: 700; color: var(--primary, #008080); padding: 8px;">⏳ Memproses Watermark...</span>';
          placeholder.style.display = 'flex';
        }
        compressAndWatermarkImage(rawFile, slotCode, function (stampedFile, dataUrl) {
          assignFileToSlot(slotCode, stampedFile, dataUrl);
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

