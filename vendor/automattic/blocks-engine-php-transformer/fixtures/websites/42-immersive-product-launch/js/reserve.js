/* =========================================================
   VOLTA — reserve / pre-order page
   • variant + colourway + add-on selection
   • live price summary (recolours summary SVG)
   • client-side validation + success state
   ========================================================= */
'use strict';

(function () {
  const form = document.querySelector('.reserve-form');
  if (!form) return;

  const colorways = window.VOLTA_COLORWAYS || [];
  const fmt = (n) => '$' + n.toLocaleString('en-US');

  // ---- model packages ------------------------------------------
  const MODELS = {
    core: { name: 'AURA One Core', price: 14900, range: '142 mi', zero60: '3.4 s' },
    rs:   { name: 'AURA One RS',   price: 18900, range: '171 mi', zero60: '2.6 s' },
  };
  const ADDONS = {
    charger:  { name: 'AuraDock 7kW fast charger', price: 1200 },
    care:     { name: 'VoltaCare 4-yr coverage',   price: 1900 },
    luggage:  { name: 'Aero pannier system',       price:  640 },
    sound:    { name: 'Synthetic drive soundtrack',price:  290 },
  };
  const RESERVE_DEPOSIT = 500;

  const state = {
    model: 'core',
    colorway: colorways[0] ? colorways[0].id : 'midnight',
    addons: new Set(),
  };

  // ---- elements ------------------------------------------------
  const sumSvg = form.closest('.reserve-grid')?.querySelector('.sum-bike .config-bike');
  const lineModel = document.querySelector('[data-line="model"]');
  const lineColor = document.querySelector('[data-line="color"]');
  const lineAddons = document.querySelector('[data-sum-addons]');
  const totalEl = document.querySelector('[data-sum-total]');
  const depositEl = document.querySelector('[data-sum-deposit]');

  function cwById(id) { return colorways.find(c => c.id === id) || colorways[0]; }

  function recompute() {
    const model = MODELS[state.model];
    const cw = cwById(state.colorway);
    let total = model.price + (cw ? cw.priceAdj : 0);

    if (lineModel) lineModel.innerHTML = `<span>${model.name}</span><span>${fmt(model.price)}</span>`;
    if (lineColor) lineColor.innerHTML = `<span>${cw ? cw.name : '—'}${cw && cw.priceAdj ? '' : ''}</span><span>${cw && cw.priceAdj ? fmt(cw.priceAdj) : 'Included'}</span>`;

    if (lineAddons) {
      lineAddons.innerHTML = '';
      if (state.addons.size === 0) {
        const li = document.createElement('div');
        li.className = 'sum-line';
        li.innerHTML = '<span>No add-ons</span><span>—</span>';
        lineAddons.appendChild(li);
      } else {
        state.addons.forEach(id => {
          total += ADDONS[id].price;
          const li = document.createElement('div');
          li.className = 'sum-line';
          li.innerHTML = `<span>${ADDONS[id].name}</span><span>${fmt(ADDONS[id].price)}</span>`;
          lineAddons.appendChild(li);
        });
      }
    }
    if (totalEl) totalEl.textContent = fmt(total);
    if (depositEl) depositEl.textContent = fmt(RESERVE_DEPOSIT);

    // recolour summary bike
    if (sumSvg && cw && window.VOLTA_applyColorway) window.VOLTA_applyColorway(sumSvg, cw);
  }

  // ---- model option cards --------------------------------------
  document.querySelectorAll('.opt[data-model]').forEach(opt => {
    opt.addEventListener('click', () => {
      document.querySelectorAll('.opt[data-model]').forEach(o => { o.classList.remove('sel'); o.setAttribute('aria-pressed', 'false'); });
      opt.classList.add('sel'); opt.setAttribute('aria-pressed', 'true');
      state.model = opt.dataset.model;
      const radio = opt.querySelector('input'); if (radio) radio.checked = true;
      recompute();
    });
  });

  // ---- colourway swatches --------------------------------------
  const cwRow = document.querySelector('[data-reserve-swatches]');
  if (cwRow) {
    colorways.forEach((cw, i) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'swatch';
      b.style.background = `radial-gradient(circle at 32% 28%, ${cw.accent}, ${cw.body})`;
      b.setAttribute('aria-label', cw.name + (cw.priceAdj ? ` (+$${cw.priceAdj})` : ''));
      b.setAttribute('aria-pressed', i === 0 ? 'true' : 'false');
      b.addEventListener('click', () => {
        cwRow.querySelectorAll('.swatch').forEach(s => s.setAttribute('aria-pressed', 'false'));
        b.setAttribute('aria-pressed', 'true');
        state.colorway = cw.id;
        const nm = document.querySelector('[data-reserve-cwname]');
        if (nm) nm.innerHTML = `Finish &mdash; <b>${cw.name}</b>`;
        recompute();
      });
      cwRow.appendChild(b);
    });
    const nm = document.querySelector('[data-reserve-cwname]');
    if (nm && colorways[0]) nm.innerHTML = `Finish &mdash; <b>${colorways[0].name}</b>`;
  }

  // ---- add-ons -------------------------------------------------
  document.querySelectorAll('.opt[data-addon]').forEach(opt => {
    opt.addEventListener('click', () => {
      const id = opt.dataset.addon;
      const on = state.addons.has(id);
      if (on) { state.addons.delete(id); opt.classList.remove('sel'); opt.setAttribute('aria-pressed', 'false'); }
      else { state.addons.add(id); opt.classList.add('sel'); opt.setAttribute('aria-pressed', 'true'); }
      const cb = opt.querySelector('input'); if (cb) cb.checked = !on;
      recompute();
    });
  });

  // ---- validation ----------------------------------------------
  const validators = {
    name: v => v.trim().length >= 2 || 'Please enter your full name.',
    email: v => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim()) || 'Enter a valid email address.',
    zip: v => /^\d{5}(-\d{4})?$/.test(v.trim()) || 'Enter a valid US ZIP code.',
    phone: v => v.trim() === '' || /^[\d\s().+-]{7,}$/.test(v.trim()) || 'Enter a valid phone number.',
  };

  function validateField(field) {
    const input = field.querySelector('input, select');
    if (!input || !validators[input.name]) return true;
    const res = validators[input.name](input.value);
    if (res === true) { field.classList.remove('error'); return true; }
    field.classList.add('error');
    const msg = field.querySelector('.err-msg');
    if (msg) msg.textContent = res;
    return false;
  }

  form.querySelectorAll('.field input, .field select').forEach(input => {
    input.addEventListener('blur', () => validateField(input.closest('.field')));
    input.addEventListener('input', () => { const f = input.closest('.field'); if (f.classList.contains('error')) validateField(f); });
  });

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    let ok = true;
    form.querySelectorAll('.field').forEach(f => { if (!validateField(f)) ok = false; });
    if (!ok) {
      const firstErr = form.querySelector('.field.error');
      if (firstErr) firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    // success state
    const success = document.querySelector('.form-success');
    const cw = cwById(state.colorway);
    form.style.display = 'none';
    if (success) {
      const ref = 'VLT-' + Math.random().toString(36).slice(2, 7).toUpperCase();
      const span = success.querySelector('[data-ref]');
      if (span) span.textContent = ref;
      const dt = success.querySelector('[data-success-detail]');
      if (dt) dt.textContent = `${MODELS[state.model].name} · ${cw ? cw.name : ''}`;
      success.classList.add('show');
      success.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });

  recompute();
})();
