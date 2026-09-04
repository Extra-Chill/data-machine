/* ============================================================
   Inkwell — presentation / slides mode (reveal-style, hand-built)
   Splits the document on top-level `---` into slides, presents
   them fullscreen with arrow-key navigation and a counter.
   ============================================================ */
(function (global) {
  'use strict';

  function Slides(rootEl) {
    this.root = rootEl;            // .slides-overlay element
    this.stage = rootEl.querySelector('[data-slide-stage]');
    this.counter = rootEl.querySelector('[data-slide-counter]');
    this.progress = rootEl.querySelector('[data-slide-progress]');
    this.slides = [];
    this.index = 0;
    this.active = false;
    this._bind();
  }

  /* Split raw markdown on lines that are exactly a horizontal rule. */
  Slides.splitSlides = function (markdown) {
    var lines = String(markdown || '').replace(/\r\n?/g, '\n').split('\n');
    var slides = [];
    var cur = [];
    var inFence = false;
    for (var i = 0; i < lines.length; i++) {
      var l = lines[i];
      if (/^\s*(```|~~~)/.test(l)) inFence = !inFence;
      if (!inFence && /^ {0,3}(-{3,}|\*{3,}|_{3,})\s*$/.test(l)) {
        slides.push(cur.join('\n'));
        cur = [];
        continue;
      }
      cur.push(l);
    }
    slides.push(cur.join('\n'));
    // drop empty leading/trailing slides
    return slides.map(function (s) { return s.trim(); }).filter(function (s, idx, arr) {
      return s.length > 0 || arr.length === 1;
    });
  };

  Slides.prototype.open = function (markdown) {
    var parts = Slides.splitSlides(markdown);
    if (!parts.length) parts = ['# (empty document)'];
    this.slides = parts.map(function (md) { return global.Inkwell.render(md).html; });
    this.index = 0;
    this.active = true;
    this.root.hidden = false;
    this.root.classList.add('is-open');
    document.body.classList.add('presenting');
    this._render();
    // try real fullscreen, ignore if blocked
    if (this.root.requestFullscreen) {
      this.root.requestFullscreen().catch(function () {});
    }
    this.root.focus();
  };

  Slides.prototype.close = function () {
    this.active = false;
    this.root.classList.remove('is-open');
    this.root.hidden = true;
    document.body.classList.remove('presenting');
    if (document.fullscreenElement) {
      document.exitFullscreen().catch(function () {});
    }
  };

  Slides.prototype.go = function (delta) {
    var n = this.index + delta;
    if (n < 0) n = 0;
    if (n > this.slides.length - 1) n = this.slides.length - 1;
    if (n === this.index) return;
    this.index = n;
    this._render(delta);
  };

  Slides.prototype._render = function (dir) {
    var cls = 'slide-deck';
    this.stage.innerHTML =
      '<article class="slide ' + (dir < 0 ? 'from-left' : 'from-right') + '">' +
        '<div class="slide-inner">' + this.slides[this.index] + '</div>' +
      '</article>';
    this.counter.textContent = (this.index + 1) + ' / ' + this.slides.length;
    var pct = this.slides.length <= 1 ? 100 :
      Math.round((this.index) / (this.slides.length - 1) * 100);
    this.progress.style.width = pct + '%';
  };

  Slides.prototype._bind = function () {
    var self = this;
    document.addEventListener('keydown', function (ev) {
      if (!self.active) return;
      switch (ev.key) {
        case 'ArrowRight':
        case 'PageDown':
        case ' ':
          ev.preventDefault(); self.go(1); break;
        case 'ArrowLeft':
        case 'PageUp':
          ev.preventDefault(); self.go(-1); break;
        case 'Home':
          ev.preventDefault(); self.index = 0; self._render(-1); break;
        case 'End':
          ev.preventDefault(); self.index = self.slides.length - 1; self._render(1); break;
        case 'Escape':
          ev.preventDefault(); self.close(); break;
        case 'f':
        case 'F':
          if (self.root.requestFullscreen && !document.fullscreenElement) self.root.requestFullscreen().catch(function(){});
          break;
      }
    });

    // click navigation (right half = next, left = prev)
    this.stage.addEventListener('click', function (ev) {
      if (ev.target.closest('a')) return;
      var rect = self.stage.getBoundingClientRect();
      if (ev.clientX - rect.left < rect.width * 0.3) self.go(-1);
      else self.go(1);
    });

    // explicit nav buttons in the chrome
    this.root.querySelectorAll('[data-slide-nav]').forEach(function (btn) {
      btn.addEventListener('click', function () { self.go(+btn.getAttribute('data-slide-nav')); });
    });
    var closeBtn = this.root.querySelector('[data-slide-close]');
    if (closeBtn) closeBtn.addEventListener('click', function () { self.close(); });

    document.addEventListener('fullscreenchange', function () {
      // if user exits browser fullscreen while presenting, keep overlay but in-window
    });
  };

  global.InkwellSlides = Slides;

})(typeof window !== 'undefined' ? window : this);
