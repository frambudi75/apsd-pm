/**
 * APSD Preventive Maintenance - QR Code Site Scanner
 * Native BarcodeDetector API with camera viewfinder and file fallback
 * Matches site codes/URLs and populates site metadata automatically
 * PT Angkasa Pura Sarana Digital
 */

(function () {
  'use strict';

  let qrStream = null;
  let qrScanInterval = null;
  let isScanning = false;

  const QrScanner = {
    modal: null,
    video: null,
    statusText: null,

    init() {
      const scanBtn = document.getElementById('btn_scan_qr');
      if (!scanBtn) return;

      this.modal = document.getElementById('qr_scanner_modal');
      this.video = document.getElementById('qr_video');
      this.statusText = document.getElementById('qr_scan_status');

      scanBtn.addEventListener('click', () => {
        this.openScanner();
      });

      const btnClose = document.getElementById('btn_close_qr');
      if (btnClose) {
        btnClose.addEventListener('click', () => {
          this.closeScanner();
        });
      }

      // File input fallback
      const fileInput = document.getElementById('qr_fallback_file');
      if (fileInput) {
        fileInput.addEventListener('change', (e) => {
          if (e.target.files && e.target.files[0]) {
            this.decodeFromImage(e.target.files[0]);
          }
        });
      }
    },

    async openScanner() {
      if (!this.modal) return;
      this.modal.style.display = 'flex';
      if (this.statusText) {
        this.statusText.innerText = 'Membuka kamera... Arahkan ke QR Code di tiang / panel.';
      }

      try {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
          throw new Error('Kamera tidak didukung pada browser ini.');
        }

        const constraints = {
          video: {
            facingMode: { ideal: 'environment' },
            width: { ideal: 1280 },
            height: { ideal: 720 }
          },
          audio: false
        };

        qrStream = await navigator.mediaDevices.getUserMedia(constraints);
        if (this.video) {
          this.video.srcObject = qrStream;
          await this.video.play();
          this.startDetectionLoop();
        }
      } catch (err) {
        console.warn('QR camera error:', err);
        if (this.statusText) {
          this.statusText.innerHTML = '<span style="color:#f87171;">Kamera langsung tidak tersedia. Gunakan tombol "Pilih Foto QR" di bawah.</span>';
        }
      }
    },

    closeScanner() {
      isScanning = false;
      if (qrScanInterval) {
        clearInterval(qrScanInterval);
        qrScanInterval = null;
      }
      if (qrStream) {
        qrStream.getTracks().forEach((t) => t.stop());
        qrStream = null;
      }
      if (this.modal) {
        this.modal.style.display = 'none';
      }
    },

    startDetectionLoop() {
      isScanning = true;
      const hasBarcodeDetector = ('BarcodeDetector' in window);
      let detector = null;

      if (hasBarcodeDetector) {
        try {
          detector = new window.BarcodeDetector({ formats: ['qr_code'] });
        } catch (e) {
          detector = null;
        }
      }

      const scanFrame = async () => {
        if (!isScanning || !this.video || !this.video.videoWidth) return;

        if (detector) {
          try {
            const barcodes = await detector.detect(this.video);
            if (barcodes && barcodes.length > 0) {
              const codeVal = barcodes[0].rawValue;
              this.handleDetectedCode(codeVal);
              return;
            }
          } catch (err) {
            // Frame detection pass-through
          }
        }
      };

      qrScanInterval = setInterval(scanFrame, 250);
    },

    async decodeFromImage(file) {
      if (!file) return;
      if (this.statusText) {
        this.statusText.innerText = 'Memindai gambar QR Code...';
      }

      if ('BarcodeDetector' in window) {
        try {
          const detector = new window.BarcodeDetector({ formats: ['qr_code'] });
          const img = new Image();
          const url = URL.createObjectURL(file);
          img.onload = async () => {
            try {
              const barcodes = await detector.detect(img);
              URL.revokeObjectURL(url);
              if (barcodes && barcodes.length > 0) {
                this.handleDetectedCode(barcodes[0].rawValue);
              } else {
                if (this.statusText) {
                  this.statusText.innerHTML = '<span style="color:#f87171;">Tidak ditemukan QR Code dalam gambar. Silakan coba lagi.</span>';
                }
              }
            } catch (e) {
              URL.revokeObjectURL(url);
              if (this.statusText) {
                this.statusText.innerText = 'Gagal membaca QR: ' + e.message;
              }
            }
          };
          img.src = url;
          return;
        } catch (err) {
          // fallback
        }
      }

      if (this.statusText) {
        this.statusText.innerText = 'Pemindaian gambar tidak didukung di browser ini. Mohon gunakan Chrome Android.';
      }
    },

    handleDetectedCode(rawText) {
      if (!rawText) return;
      const text = String(rawText).trim();
      this.closeScanner();

      // Haptic feedback if supported
      if (navigator.vibrate) {
        try { navigator.vibrate([100, 50, 100]); } catch (e) {}
      }

      // Parse payload (can be direct string or URL like ...site_id=1 or site_id_str=...)
      let siteParam = null;
      try {
        if (text.includes('?') || text.startsWith('http://') || text.startsWith('https://')) {
          const urlObj = new URL(text, window.location.origin);
          siteParam = urlObj.searchParams.get('site_id') || urlObj.searchParams.get('site') || urlObj.searchParams.get('id');
        }
      } catch (e) {}

      const targetValue = (siteParam || text).toLowerCase();

      // Look up in #site_id_select
      const siteSelect = document.getElementById('site_id_select');
      let matchedOption = null;

      if (siteSelect) {
        for (let i = 0; i < siteSelect.options.length; i++) {
          const opt = siteSelect.options[i];
          if (!opt.value) continue;

          const optVal = opt.value.toLowerCase();
          const optCode = (opt.getAttribute('data-code') || '').toLowerCase();
          const optName = (opt.getAttribute('data-name') || '').toLowerCase();
          const optText = opt.text.toLowerCase();

          if (
            optVal === targetValue ||
            (optCode && optCode === targetValue) ||
            (optName && optName === targetValue) ||
            optText.includes(targetValue)
          ) {
            matchedOption = opt;
            break;
          }
        }

        if (matchedOption) {
          siteSelect.value = matchedOption.value;
          siteSelect.dispatchEvent(new Event('change', { bubbles: true }));
          this.showToast('✅ QR Cocok! Site Terpilih: ' + (matchedOption.getAttribute('data-name') || matchedOption.text));
          return;
        }
      }

      // If not in select options, populate text input directly
      const siteIdStrInput = document.getElementById('site_id_str');
      const siteNameInput = document.getElementById('site_name');

      if (siteIdStrInput) {
        siteIdStrInput.value = text;
      } else if (siteNameInput) {
        siteNameInput.value = text;
      }

      this.showToast('ℹ️ QR Terbaca: ' + text);
    },

    showToast(msg) {
      let toast = document.getElementById('qr_toast_msg');
      if (!toast) {
        toast = document.createElement('div');
        toast.id = 'qr_toast_msg';
        toast.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);background:#0f172a;color:#ffffff;padding:0.75rem 1.25rem;border-radius:24px;font-size:0.88rem;font-weight:600;z-index:99999;box-shadow:0 8px 20px rgba(0,0,0,0.3);text-align:center;max-width:90%;';
        document.body.appendChild(toast);
      }
      toast.innerText = msg;
      toast.style.display = 'block';
      setTimeout(() => {
        if (toast) toast.style.display = 'none';
      }, 3500);
    }
  };

  document.addEventListener('DOMContentLoaded', () => {
    QrScanner.init();
  });

  window.QrScanner = QrScanner;
})();
