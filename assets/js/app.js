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

    // Auto-select site if passed in URL query param (?site_id=... or ?code=...)
    try {
      const urlParams = new URLSearchParams(window.location.search);
      const qSiteId = urlParams.get('site_id') || urlParams.get('site') || urlParams.get('id');
      const qCode = urlParams.get('code');

      if (qSiteId || qCode) {
        for (let i = 0; i < siteSelect.options.length; i++) {
          const opt = siteSelect.options[i];
          const optVal = (opt.value || '').toLowerCase();
          const optCode = (opt.getAttribute('data-code') || '').toLowerCase();
          const optName = (opt.getAttribute('data-name') || '').toLowerCase();

          if (
            (qSiteId && (optVal === qSiteId.toLowerCase() || optCode === qSiteId.toLowerCase() || optName === qSiteId.toLowerCase())) ||
            (qCode && (optCode === qCode.toLowerCase() || optName === qCode.toLowerCase()))
          ) {
            siteSelect.selectedIndex = i;
            siteSelect.dispatchEvent(new Event('change'));
            break;
          }
        }
      } else if (siteSelect.value) {
        siteSelect.dispatchEvent(new Event('change'));
      }
    } catch (e) {
      console.warn('Auto-select site error:', e);
    }
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
    const name = siteInp && siteInp.value ? siteInp.value.trim() : 'Site KNO';
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

  // Stamp official inspection watermark on canvas (adaptive for both Landscape & Portrait)
  function stampWatermark(canvas, slotCode) {
    const autoWatermarkToggle = document.getElementById('auto_watermark_toggle');
    if (autoWatermarkToggle && !autoWatermarkToggle.checked) {
      return;
    }

    const ctx = canvas.getContext('2d');
    const w = canvas.width;
    const h = canvas.height;
    const isPortrait = (h > w);

    // Scale dynamically by canvas width to prevent horizontal text overflow on portrait photos
    const baseWidth = isPortrait ? 760 : 1050;
    const scale = Math.max(0.65, w / baseWidth);
    const boxH = Math.round((isPortrait ? 134 : 118) * scale);
    const pad = Math.round(14 * scale);

    ctx.save();

    // High-contrast gradient bar at bottom
    const grad = ctx.createLinearGradient(0, h - boxH - (18 * scale), 0, h);
    grad.addColorStop(0, 'rgba(0, 0, 0, 0)');
    grad.addColorStop(0.25, 'rgba(15, 23, 42, 0.90)');
    grad.addColorStop(1, 'rgba(15, 23, 42, 0.98)');
    ctx.fillStyle = grad;
    ctx.fillRect(0, h - boxH - (18 * scale), w, boxH + (18 * scale));

    // Teal vertical accent line
    ctx.fillStyle = '#008080';
    ctx.fillRect(pad, h - boxH + (4 * scale), 4 * scale, boxH - (12 * scale));

    const startX = pad + (12 * scale);
    const maxTextW = w - startX - pad;

    // Helper to guarantee text never runs off canvas edge
    function fitText(text, font) {
      ctx.font = font;
      if (ctx.measureText(text).width <= maxTextW) return text;
      let trimmed = text;
      while (trimmed.length > 5 && ctx.measureText(trimmed + '...').width > maxTextW) {
        trimmed = trimmed.substring(0, trimmed.length - 1);
      }
      return trimmed + '...';
    }

    // Text shadow for high contrast on bright/dark backgrounds
    ctx.shadowColor = 'rgba(0, 0, 0, 0.95)';
    ctx.shadowBlur = 4;
    ctx.shadowOffsetX = 1;
    ctx.shadowOffsetY = 1;

    // Header Tag
    const headerFont = 'bold ' + Math.round((isPortrait ? 11.5 : 11) * scale) + 'px sans-serif';
    ctx.fillStyle = '#38bdf8';
    ctx.fillText(fitText('IASS SBU ICT KNO | PM INSPECTION', headerFont), startX, h - boxH + (18 * scale));

    // Line 1: Timestamp
    const timeFont = 'bold ' + Math.round((isPortrait ? 13.5 : 13) * scale) + 'px sans-serif';
    ctx.fillStyle = '#ffffff';
    ctx.fillText(fitText('WAKTU : ' + getFormattedTimestamp(), timeFont), startX, h - boxH + (38 * scale));

    // Line 2: Site & Location
    const siteFont = Math.round((isPortrait ? 12 : 12) * scale) + 'px sans-serif';
    ctx.fillStyle = '#f1f5f9';
    let siteText = 'SITE  : ' + getActiveSiteInfo();
    ctx.fillText(fitText(siteText, siteFont), startX, h - boxH + (57 * scale));

    // Line 3: GPS
    const gpsFont = Math.round((isPortrait ? 12 : 12) * scale) + 'px sans-serif';
    ctx.fillStyle = '#fde047';
    ctx.fillText(fitText('GPS   : ' + getActiveCoords(), gpsFont), startX, h - boxH + (76 * scale));

    // Line 4: Slot Code & Title
    const titleInp = document.getElementById('title_' + slotCode);
    let titleText = titleInp && titleInp.value ? titleInp.value.trim() : '';
    let itemText = 'FOTO  : Slot ' + slotCode + (titleText ? (' (' + titleText + ')') : '');
    const slotFont = Math.round((isPortrait ? 12 : 12) * scale) + 'px sans-serif';
    ctx.fillStyle = '#e2e8f0';
    ctx.fillText(fitText(itemText, slotFont), startX, h - boxH + (95 * scale));

    ctx.restore();
  }

  function assignFileToSlot(slotCode, file, dataUrl, isWatermarked = false) {
    const fileInput = document.getElementById('file_' + slotCode);
    const cameraInput = document.getElementById('camera_' + slotCode);
    const base64Input = document.getElementById('base64_' + slotCode);
    const watermarkedInput = document.getElementById('watermarked_' + slotCode);
    const previewImg = document.getElementById('preview_img_' + slotCode);
    const naOverlay = document.getElementById('na_overlay_' + slotCode);
    const placeholder = document.getElementById('placeholder_' + slotCode);
    const isNaCheckbox = document.getElementById('isna_' + slotCode);

    if (previewImg && dataUrl) {
      previewImg.src = dataUrl;
      previewImg.style.display = 'block';
    }
    if (naOverlay) naOverlay.style.display = 'none';
    if (placeholder) placeholder.style.display = 'none';
    if (isNaCheckbox) isNaCheckbox.checked = false;

    // Only store Base64 if it has been watermarked
    if (base64Input) {
      base64Input.value = isWatermarked ? (dataUrl || '') : '';
    }
    if (watermarkedInput) {
      watermarkedInput.value = isWatermarked ? '1' : '0';
    }

    if (fileInput && file) {
      try {
        const dt = new DataTransfer();
        dt.items.add(file);
        fileInput.files = dt.files;
      } catch (err) {
        console.warn('DataTransfer not supported:', err);
      }
    }

    if (typeof window.updatePhotoFilterCounts === 'function') {
      window.updatePhotoFilterCounts();
    }
  }

  function compressAndWatermarkImage(file, slotCode, callback) {
    if (!file) {
      if (callback) callback(file, null, false);
      return;
    }

    const fileName = file.name || '';
    let objUrl = null;
    try {
      objUrl = URL.createObjectURL(file);
    } catch (e) {
      console.warn('createObjectURL failed:', e);
    }

    const img = new Image();

    function drawAndStamp(imgSource, naturalW, naturalH) {
      try {
        const maxDim = 1600;
        let targetW = naturalW;
        let targetH = naturalH;
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
        ctx.drawImage(imgSource, 0, 0, targetW, targetH);

        // Revoke ObjectURL after canvas draw
        if (objUrl) {
          URL.revokeObjectURL(objUrl);
          objUrl = null;
        }

        // Stamp official inspection watermark
        stampWatermark(canvas, slotCode);

        const dataUrl = canvas.toDataURL('image/jpeg', 0.85);

        if (canvas.toBlob) {
          canvas.toBlob(function (blob) {
            if (blob) {
              const outName = (fileName ? fileName.replace(/\.[^/.]+$/, "") : ('photo_' + slotCode)) + '.jpg';
              const stampedFile = new File([blob], outName, {
                type: 'image/jpeg',
                lastModified: Date.now()
              });
              callback(stampedFile, dataUrl, true);
            } else {
              callback(file, dataUrl, true);
            }
          }, 'image/jpeg', 0.85);
        } else {
          callback(file, dataUrl, true);
        }
      } catch (err) {
        console.error('drawAndStamp error:', err);
        if (objUrl) {
          URL.revokeObjectURL(objUrl);
          objUrl = null;
        }
        callback(file, null, false);
      }
    }

    if (objUrl) {
      img.onload = function () {
        drawAndStamp(img, img.naturalWidth || img.width, img.naturalHeight || img.height);
      };
      img.onerror = function () {
        if (objUrl) {
          URL.revokeObjectURL(objUrl);
          objUrl = null;
        }
        // Fallback to FileReader
        const reader = new FileReader();
        reader.onload = function (e) {
          const fallbackImg = new Image();
          fallbackImg.onload = function () {
            drawAndStamp(fallbackImg, fallbackImg.naturalWidth || fallbackImg.width, fallbackImg.naturalHeight || fallbackImg.height);
          };
          fallbackImg.onerror = function () {
            callback(file, null, false);
          };
          fallbackImg.src = e.target.result;
        };
        reader.onerror = function () {
          callback(file, null, false);
        };
        reader.readAsDataURL(file);
      };
      img.src = objUrl;
    } else {
      const reader = new FileReader();
      reader.onload = function (e) {
        const fallbackImg = new Image();
        fallbackImg.onload = function () {
          drawAndStamp(fallbackImg, fallbackImg.naturalWidth || fallbackImg.width, fallbackImg.naturalHeight || fallbackImg.height);
        };
        fallbackImg.onerror = function () {
          callback(file, null, false);
        };
        fallbackImg.src = e.target.result;
      };
      reader.onerror = function () {
        callback(file, null, false);
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
          assignFileToSlot(activeSlotCode, file, canvas.toDataURL('image/jpeg', 0.85), true);
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
        compressAndWatermarkImage(rawFile, slotCode, function (stampedFile, dataUrl, isWatermarked) {
          assignFileToSlot(slotCode, stampedFile, dataUrl, isWatermarked);
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

      if (typeof window.updatePhotoFilterCounts === 'function') {
        window.updatePhotoFilterCounts();
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
      'ias': { src: 'assets/img/logo_ias.png?v=2', name: 'IASS SBU ICT KNO' },
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
    console.log('IASS SBU ICT KNO PWA installed successfully');
    if (pwaInstallBanner) pwaInstallBanner.style.display = 'none';
  });

  // 8. Photo Slot Filter Toolbar
  function initPhotoFilterToolbar() {
    const filterBtns = document.querySelectorAll('.btn-photo-filter');
    const jumpSelect = document.getElementById('jump_photo_group');

    function updateCounts() {
      const cards = document.querySelectorAll('.photo-slot-card');
      if (!cards.length) return;

      let filled = 0;
      let empty = 0;

      cards.forEach(card => {
        const previewImg = card.querySelector('.photo-preview-img');
        const base64Input = card.querySelector('input[type="hidden"][name^="photo_base64"]');
        const naCheck = card.querySelector('.photo-na-toggle');
        const isFilled = (previewImg && previewImg.style.display !== 'none' && previewImg.src && !previewImg.src.endsWith('/')) ||
                         (base64Input && base64Input.value) ||
                         (naCheck && naCheck.checked);

        card.setAttribute('data-filled', isFilled ? 'true' : 'false');
        if (isFilled) filled++;
        else empty++;
      });

      const cAll = document.getElementById('count_all_slots');
      const cFilled = document.getElementById('count_filled_slots');
      const cEmpty = document.getElementById('count_empty_slots');

      if (cAll) cAll.innerText = cards.length;
      if (cFilled) cFilled.innerText = filled;
      if (cEmpty) cEmpty.innerText = empty;
    }

    window.updatePhotoFilterCounts = updateCounts;
    updateCounts();

    filterBtns.forEach(btn => {
      btn.addEventListener('click', function () {
        filterBtns.forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        const filter = this.getAttribute('data-filter');

        const cards = document.querySelectorAll('.photo-slot-card');
        cards.forEach(card => {
          const isFilled = card.getAttribute('data-filled') === 'true';
          if (filter === 'all') {
            card.style.display = '';
          } else if (filter === 'filled') {
            card.style.display = isFilled ? '' : 'none';
          } else if (filter === 'empty') {
            card.style.display = !isFilled ? '' : 'none';
          }
        });
      });
    });

    if (jumpSelect) {
      jumpSelect.addEventListener('change', function () {
        const targetId = this.value;
        if (targetId) {
          const el = document.getElementById(targetId);
          if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }
      });
    }
  }
  initPhotoFilterToolbar();

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

