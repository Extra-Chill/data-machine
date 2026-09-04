/* ============================================================
   Keystroke — on-screen virtual keyboard with finger zones and
   a live problem-key heatmap.
   ============================================================ */
(function (global) {
  'use strict';

  var ROWS = [
    ['`', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '-', '='],
    ['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p', '[', ']', '\\'],
    ['a', 's', 'd', 'f', 'g', 'h', 'j', 'k', 'l', ';', '\''],
    ['z', 'x', 'c', 'v', 'b', 'n', 'm', ',', '.', '/']
  ];

  // Finger zone per key (left/right hand, finger index) → a color class.
  var ZONE = {};
  function zone(keys, cls) { keys.forEach(function (k) { ZONE[k] = cls; }); }
  zone(['`', '1', 'q', 'a', 'z'], 'z-lp');                 // left pinky
  zone(['2', 'w', 's', 'x'], 'z-lr');                      // left ring
  zone(['3', 'e', 'd', 'c'], 'z-lm');                      // left middle
  zone(['4', '5', 'r', 't', 'f', 'g', 'v', 'b'], 'z-li');  // left index
  zone(['6', '7', 'y', 'u', 'h', 'j', 'n', 'm'], 'z-ri');  // right index
  zone(['8', 'i', 'k', ','], 'z-rm');                      // right middle
  zone(['9', 'o', 'l', '.'], 'z-rr');                      // right ring
  zone(['0', '-', '=', 'p', '[', ']', '\\', ';', '\'', '/'], 'z-rp'); // right pinky

  // Shift pairs so an uppercase / symbol char highlights its base key.
  var SHIFTED = {
    '~': '`', '!': '1', '@': '2', '#': '3', '$': '4', '%': '5', '^': '6',
    '&': '7', '*': '8', '(': '9', ')': '0', '_': '-', '+': '=',
    '{': '[', '}': ']', '|': '\\', ':': ';', '"': '\'', '<': ',', '>': '.', '?': '/'
  };

  function baseKey(ch) {
    if (ch === ' ') return 'space';
    var low = ch.toLowerCase();
    if (SHIFTED[ch]) return SHIFTED[ch];
    return low;
  }

  function Keyboard(mount) {
    this.mount = mount;
    this.cells = {};
    this.render();
  }

  Keyboard.prototype.render = function () {
    var self = this;
    this.mount.innerHTML = '';
    ROWS.forEach(function (row) {
      var rEl = document.createElement('div');
      rEl.className = 'kb-row';
      row.forEach(function (k) {
        var cell = document.createElement('div');
        cell.className = 'kb-key ' + (ZONE[k] || '');
        cell.textContent = k === '`' ? '`' : k;
        cell.setAttribute('data-key', k);
        self.cells[k] = cell;
        rEl.appendChild(cell);
      });
      self.mount.appendChild(rEl);
    });
    var sRow = document.createElement('div');
    sRow.className = 'kb-row';
    var space = document.createElement('div');
    space.className = 'kb-key kb-space z-thumb';
    space.textContent = 'space';
    space.setAttribute('data-key', 'space');
    this.cells.space = space;
    sRow.appendChild(space);
    this.mount.appendChild(sRow);
  };

  Keyboard.prototype.setNext = function (ch) {
    for (var k in this.cells) this.cells[k].classList.remove('kb-next');
    if (ch == null) return;
    var cell = this.cells[baseKey(ch)];
    if (cell) cell.classList.add('kb-next');
  };

  Keyboard.prototype.flash = function (ch, ok) {
    var cell = this.cells[baseKey(ch)];
    if (!cell) return;
    var cls = ok ? 'kb-hit' : 'kb-miss';
    cell.classList.add(cls);
    setTimeout(function () { cell.classList.remove(cls); }, 120);
  };

  // Heatmap: tint keys by error rate from stored key stats.
  Keyboard.prototype.applyHeatmap = function (keyStats) {
    for (var k in this.cells) {
      var cell = this.cells[k];
      cell.classList.remove('kb-heat');
      cell.style.removeProperty('--heat');
      var st = keyStats[k];
      if (st && st.total >= 4) {
        var rate = st.errors / st.total;
        if (rate > 0.04) {
          cell.classList.add('kb-heat');
          cell.style.setProperty('--heat', Math.min(1, rate * 3).toFixed(2));
        }
      }
    }
  };

  global.Keyboard = Keyboard;
})(window);
