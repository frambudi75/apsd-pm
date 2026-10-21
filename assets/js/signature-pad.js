/**
 * Responsive Digital Signature Pad (Touchscreen & Mouse Canvas)
 * PT Angkasa Pura Sarana Digital & IASS SBU ICT KNO
 */

(function (window) {
  'use strict';

  function SignaturePad(canvas, options) {
    if (!canvas) return;
    this.canvas = typeof canvas === 'string' ? document.getElementById(canvas) : canvas;
    if (!this.canvas) return;

    this.ctx = this.canvas.getContext('2d');
    this.options = Object.assign({
      strokeColor: '#0f172a',
      strokeWidth: 2.5,
      backgroundColor: 'transparent'
    }, options || {});

    this.isDrawing = false;
    this.hasDrawn = false;
    this.points = [];

    this._initCanvas();
    this._bindEvents();
  }

  SignaturePad.prototype._initCanvas = function () {
    var canvas = this.canvas;
    var rect = canvas.getBoundingClientRect();
    var dpr = window.devicePixelRatio || 1;

    // Use current styled width/height or parent width
    var width = rect.width || canvas.offsetWidth || 340;
    var height = rect.height || canvas.offsetHeight || 150;

    canvas.width = width * dpr;
    canvas.height = height * dpr;

    this.ctx.scale(dpr, dpr);
    this.ctx.lineCap = 'round';
    this.ctx.lineJoin = 'round';
    this.ctx.strokeStyle = this.options.strokeColor;
    this.ctx.lineWidth = this.options.strokeWidth;

    canvas.style.touchAction = 'none';
  };

  SignaturePad.prototype._getCoordinates = function (event) {
    var rect = this.canvas.getBoundingClientRect();
    var clientX = event.clientX;
    var clientY = event.clientY;

    if (event.touches && event.touches.length > 0) {
      clientX = event.touches[0].clientX;
      clientY = event.touches[0].clientY;
    }

    return {
      x: clientX - rect.left,
      y: clientY - rect.top
    };
  };

  SignaturePad.prototype._bindEvents = function () {
    var self = this;
    var canvas = this.canvas;

    function handleStart(e) {
      e.preventDefault();
      self.isDrawing = true;
      self.hasDrawn = true;
      var pos = self._getCoordinates(e);
      self.points = [pos];
      self.ctx.beginPath();
      self.ctx.moveTo(pos.x, pos.y);
      self.ctx.lineTo(pos.x + 0.1, pos.y + 0.1);
      self.ctx.stroke();
    }

    function handleMove(e) {
      if (!self.isDrawing) return;
      e.preventDefault();
      var pos = self._getCoordinates(e);
      self.points.push(pos);

      // Smooth curve drawing with quadratic curves if 3+ points
      if (self.points.length >= 3) {
        var p0 = self.points[self.points.length - 3];
        var p1 = self.points[self.points.length - 2];
        var p2 = self.points[self.points.length - 1];

        var mid1 = { x: (p0.x + p1.x) / 2, y: (p0.y + p1.y) / 2 };
        var mid2 = { x: (p1.x + p2.x) / 2, y: (p1.y + p2.y) / 2 };

        self.ctx.beginPath();
        self.ctx.moveTo(mid1.x, mid1.y);
        self.ctx.quadraticCurveTo(p1.x, p1.y, mid2.x, mid2.y);
        self.ctx.stroke();
      } else {
        self.ctx.lineTo(pos.x, pos.y);
        self.ctx.stroke();
      }
    }

    function handleEnd(e) {
      if (!self.isDrawing) return;
      e.preventDefault();
      self.isDrawing = false;
      self.points = [];
    }

    // Pointer events (modern touch & mouse)
    if (window.PointerEvent) {
      canvas.addEventListener('pointerdown', handleStart);
      window.addEventListener('pointermove', handleMove);
      window.addEventListener('pointerup', handleEnd);
      window.addEventListener('pointercancel', handleEnd);
    } else {
      // Fallback touch & mouse
      canvas.addEventListener('mousedown', handleStart);
      window.addEventListener('mousemove', handleMove);
      window.addEventListener('mouseup', handleEnd);

      canvas.addEventListener('touchstart', handleStart, { passive: false });
      window.addEventListener('touchmove', handleMove, { passive: false });
      window.addEventListener('touchend', handleEnd, { passive: false });
    }

    // Handle screen resize/orientation change gracefully
    var resizeTimer;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () {
        if (!self.hasDrawn) {
          self._initCanvas();
        }
      }, 250);
    });
  };

  SignaturePad.prototype.clear = function () {
    var dpr = window.devicePixelRatio || 1;
    this.ctx.clearRect(0, 0, this.canvas.width / dpr, this.canvas.height / dpr);
    this.hasDrawn = false;
    this.points = [];
  };

  SignaturePad.prototype.isEmpty = function () {
    return !this.hasDrawn;
  };

  SignaturePad.prototype.toDataURL = function (type) {
    if (this.isEmpty()) return '';
    return this.canvas.toDataURL(type || 'image/png');
  };

  window.SignaturePad = SignaturePad;
})(window);
