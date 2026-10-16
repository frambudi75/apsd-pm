/**
 * APSD Preventive Maintenance - Offline Draft Manager (IndexedDB)
 * Persists form inputs, radios, checkboxes, and watermarked photo Base64
 * Zero data loss on battery drain, accidental reload, or network interruption.
 * PT Angkasa Pura Sarana Digital
 */

(function () {
  'use strict';

  const DB_NAME = 'apsd_pm_drafts_db';
  const DB_VERSION = 1;
  const STORE_NAME = 'drafts';

  let dbInstance = null;
  let autoSaveTimeout = null;
  let isRestoring = false;

  function openDB() {
    return new Promise((resolve, reject) => {
      if (dbInstance) {
        return resolve(dbInstance);
      }
      const request = indexedDB.open(DB_NAME, DB_VERSION);
      request.onupgradeneeded = function (e) {
        const db = e.target.result;
        if (!db.objectStoreNames.contains(STORE_NAME)) {
          db.createObjectStore(STORE_NAME, { keyPath: 'formType' });
        }
      };
      request.onsuccess = function (e) {
        dbInstance = e.target.result;
        resolve(dbInstance);
      };
      request.onerror = function (e) {
        console.error('IndexedDB error:', e.target.error);
        reject(e.target.error);
      };
    });
  }

  const DraftManager = {
    /**
     * Save draft for a specific form type ('indoor' or 'outdoor')
     */
    async saveDraft(formType) {
      if (isRestoring) return;
      try {
        const form = document.getElementById('pm_report_form');
        if (!form) return;

        const db = await openDB();
        const tx = db.transaction(STORE_NAME, 'readwrite');
        const store = tx.objectStore(STORE_NAME);

        const formDataObj = {};
        const elements = form.querySelectorAll('input, select, textarea');
        elements.forEach((el) => {
          if (!el.name || el.type === 'file') return;
          if (el.type === 'radio') {
            if (el.checked) {
              formDataObj[el.name] = el.value;
            }
          } else if (el.type === 'checkbox') {
            formDataObj[el.name] = el.checked ? (el.value || '1') : '';
          } else {
            formDataObj[el.name] = el.value;
          }
        });

        // Collect photo previews & base64
        const photosObj = {};
        const photoSlots = form.querySelectorAll('.photo-slot-card');
        photoSlots.forEach((card) => {
          const previewImg = card.querySelector('.photo-preview-img');
          const base64Input = card.querySelector('input[type="hidden"][name^="photo_base64"]');
          const watermarkedInput = card.querySelector('input[type="hidden"][name^="photo_watermarked"]');
          const naCheck = card.querySelector('.photo-na-toggle');
          const titleInput = card.querySelector('input[name*="[title]"]');
          const measInput = card.querySelector('input[name*="[measurement]"]');

          const slotCode = naCheck ? naCheck.dataset.slot : null;
          if (!slotCode) return;

          const base64Val = (base64Input && base64Input.value) || (previewImg && previewImg.src && previewImg.src.startsWith('data:image/') ? previewImg.src : '');

          if (base64Val || (naCheck && naCheck.checked)) {
            photosObj[slotCode] = {
              base64: base64Val,
              watermarked: watermarkedInput ? watermarkedInput.value : '0',
              is_na: naCheck ? naCheck.checked : false,
              title: titleInput ? titleInput.value : '',
              measurement: measInput ? measInput.value : ''
            };
          }
        });

        const siteSelect = document.getElementById('site_id_select');
        const siteNameInput = document.getElementById('site_name');
        const siteName = siteNameInput ? siteNameInput.value : (siteSelect && siteSelect.selectedOptions[0] ? siteSelect.selectedOptions[0].text : '');

        const now = new Date();
        const timeFormatted = now.toLocaleDateString('id-ID', {
          day: '2-digit',
          month: 'short',
          year: 'numeric'
        }) + ' ' + now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });

        const draftRecord = {
          formType: formType,
          siteName: siteName,
          timestamp: now.getTime(),
          timeFormatted: timeFormatted,
          fields: formDataObj,
          photos: photosObj
        };

        store.put(draftRecord);

        // Update UI Badge
        const statusBadge = document.getElementById('autosave_status');
        if (statusBadge) {
          statusBadge.innerHTML = '💾 Draft tersimpan (' + now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) + ')';
          statusBadge.classList.add('saved');
        }
      } catch (err) {
        console.warn('Auto-save error:', err);
      }
    },

    /**
     * Check if a draft exists for formType
     */
    async getDraft(formType) {
      try {
        const db = await openDB();
        return new Promise((resolve) => {
          const tx = db.transaction(STORE_NAME, 'readonly');
          const store = tx.objectStore(STORE_NAME);
          const req = store.get(formType);
          req.onsuccess = () => resolve(req.result || null);
          req.onerror = () => resolve(null);
        });
      } catch (e) {
        return null;
      }
    },

    /**
     * Restore saved draft into the current form
     */
    async restoreDraft(formType) {
      const draft = await this.getDraft(formType);
      if (!draft) return false;

      isRestoring = true;
      const form = document.getElementById('pm_report_form');
      if (!form) {
        isRestoring = false;
        return false;
      }

      try {
        // 1. Restore standard inputs, selects, textareas
        if (draft.fields) {
          for (const [name, val] of Object.entries(draft.fields)) {
            const inputs = form.querySelectorAll(`[name="${CSS.escape(name)}"]`);
            if (!inputs || inputs.length === 0) continue;

            if (inputs[0].type === 'radio') {
              inputs.forEach((r) => {
                r.checked = (r.value === val);
              });
            } else if (inputs[0].type === 'checkbox') {
              inputs[0].checked = (val === '1' || val === inputs[0].value || val === true);
            } else {
              inputs[0].value = val;
            }
          }

          // Trigger change on site selector if present to update metadata if needed
          const siteSelect = document.getElementById('site_id_select');
          if (siteSelect && siteSelect.value) {
            siteSelect.dispatchEvent(new Event('change', { bubbles: true }));
          }

          // Trigger change on preset logo selector if present
          const presetLogo = document.getElementById('preset_logo_select');
          if (presetLogo && presetLogo.value) {
            presetLogo.dispatchEvent(new Event('change', { bubbles: true }));
          }
        }

        // 2. Restore photo previews and Base64 values
        if (draft.photos) {
          for (const [slotCode, photoData] of Object.entries(draft.photos)) {
            const previewBox = document.getElementById(`preview_box_${slotCode}`);
            const previewImg = document.getElementById(`preview_img_${slotCode}`);
            const base64Input = document.getElementById(`base64_${slotCode}`);
            const watermarkedInput = document.getElementById(`watermarked_${slotCode}`);
            const naCheck = document.getElementById(`isna_${slotCode}`);
            const naOverlay = document.getElementById(`na_overlay_${slotCode}`);
            const placeholder = document.getElementById(`placeholder_${slotCode}`);
            const titleInput = document.getElementById(`title_${slotCode}`);

            if (photoData.title && titleInput) {
              titleInput.value = photoData.title;
            }

            if (photoData.is_na && naCheck) {
              naCheck.checked = true;
              if (naOverlay) naOverlay.style.display = 'flex';
              if (previewImg) previewImg.style.display = 'none';
              if (placeholder) placeholder.style.display = 'none';
            } else if (photoData.base64) {
              if (previewImg) {
                previewImg.src = photoData.base64;
                previewImg.style.display = 'block';
              }
              if (base64Input) {
                base64Input.value = photoData.base64;
              }
              if (watermarkedInput) {
                watermarkedInput.value = photoData.watermarked || '1';
              }
              if (placeholder) {
                placeholder.style.display = 'none';
              }
              if (naOverlay) {
                naOverlay.style.display = 'none';
              }
              if (naCheck) {
                naCheck.checked = false;
              }
            }
          }
        }

        // Update photo filter counts if the function exists
        if (typeof window.updatePhotoFilterCounts === 'function') {
          window.updatePhotoFilterCounts();
        }

        // Hide restore banner
        const banner = document.getElementById('draft_restore_banner');
        if (banner) {
          banner.style.display = 'none';
        }

        // Update status badge
        const statusBadge = document.getElementById('autosave_status');
        if (statusBadge) {
          statusBadge.innerHTML = '✅ Draft dipulihkan (' + draft.timeFormatted + ')';
        }

        return true;
      } finally {
        isRestoring = false;
      }
    },

    /**
     * Clear draft from IndexedDB
     */
    async clearDraft(formType) {
      try {
        const db = await openDB();
        const tx = db.transaction(STORE_NAME, 'readwrite');
        tx.objectStore(STORE_NAME).delete(formType);
        const banner = document.getElementById('draft_restore_banner');
        if (banner) {
          banner.style.display = 'none';
        }
        const statusBadge = document.getElementById('autosave_status');
        if (statusBadge) {
          statusBadge.innerHTML = '💾 Draft dibersihkan';
        }
      } catch (e) {
        console.warn('Failed to clear draft:', e);
      }
    },

    /**
     * Initialize Draft Manager on page load
     */
    async init(formType) {
      const form = document.getElementById('pm_report_form');
      if (!form) return;

      // 1. Check if an existing draft is available
      const draft = await this.getDraft(formType);
      const banner = document.getElementById('draft_restore_banner');
      const bannerTime = document.getElementById('draft_banner_time');

      if (draft && banner) {
        const photoCount = Object.keys(draft.photos || {}).length;
        if (bannerTime) {
          bannerTime.innerHTML = `Tersimpan pada <strong>${draft.timeFormatted}</strong> (${draft.siteName || 'Site tanpa nama'}, ${photoCount} foto terlampir).`;
        }
        banner.style.display = 'flex';

        const btnRestore = document.getElementById('btn_restore_draft');
        if (btnRestore) {
          btnRestore.onclick = () => this.restoreDraft(formType);
        }

        const btnDiscard = document.getElementById('btn_discard_draft');
        if (btnDiscard) {
          btnDiscard.onclick = () => {
            if (confirm('Buang draft tersimpan ini? Tindakan ini tidak dapat dibatalkan.')) {
              this.clearDraft(formType);
            }
          };
        }
      }

      // 2. Debounced auto-save listener on form changes
      const scheduleAutoSave = () => {
        clearTimeout(autoSaveTimeout);
        const statusBadge = document.getElementById('autosave_status');
        if (statusBadge) {
          statusBadge.innerHTML = '⏳ Menyimpan...';
        }
        autoSaveTimeout = setTimeout(() => {
          this.saveDraft(formType);
        }, 1200);
      };

      form.addEventListener('input', scheduleAutoSave);
      form.addEventListener('change', scheduleAutoSave);

      // 3. Clear draft when form is successfully submitted
      form.addEventListener('submit', () => {
        this.clearDraft(formType);
      });
    }
  };

  window.DraftManager = DraftManager;
})();
