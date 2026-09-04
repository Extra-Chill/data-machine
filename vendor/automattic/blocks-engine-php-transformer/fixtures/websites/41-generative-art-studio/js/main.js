/* =========================================================
   NOISEWRIGHT STUDIO — UI wiring
   Cursor, header, nav, reveals, counters, hero, lab, forms.
   ========================================================= */
'use strict';

/* ── Custom cursor ──────────────────────────────────────── */
function initCursor() {
  if (NW.REDUCED) return;
  if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;

  const dot = document.createElement('div');
  dot.className = 'cursor';
  const ring = document.createElement('div');
  ring.className = 'cursor-ring';
  document.body.append(dot, ring);
  document.body.classList.add('has-cursor');

  let mx = innerWidth / 2, my = innerHeight / 2, rx = mx, ry = my;
  addEventListener('mousemove', e => {
    mx = e.clientX; my = e.clientY;
    dot.style.transform = `translate(${mx}px, ${my}px) translate(-50%, -50%)`;
  });
  (function trail() {
    rx += (mx - rx) * 0.16; ry += (my - ry) * 0.16;
    ring.style.transform = `translate(${rx}px, ${ry}px) translate(-50%, -50%)`;
    requestAnimationFrame(trail);
  })();

  const hot = 'a, button, input, textarea, select, .work-card, .member, .lab-swatch';
  document.querySelectorAll(hot).forEach(el => {
    el.addEventListener('mouseenter', () => document.body.classList.add('cursor-hover'));
    el.addEventListener('mouseleave', () => document.body.classList.remove('cursor-hover'));
  });
}

/* ── Sticky header ──────────────────────────────────────── */
function initHeader() {
  const header = document.querySelector('.site-header');
  if (!header) return;
  const onScroll = () => header.classList.toggle('scrolled', scrollY > 40);
  addEventListener('scroll', onScroll, { passive: true });
  onScroll();
}

/* ── Mobile nav ─────────────────────────────────────────── */
function initMobileNav() {
  const toggle = document.querySelector('.nav-toggle');
  const nav = document.querySelector('.site-nav');
  if (!toggle || !nav) return;
  const open = () => { toggle.classList.add('active'); toggle.setAttribute('aria-expanded', 'true'); nav.classList.add('open'); document.body.style.overflow = 'hidden'; };
  const close = () => { toggle.classList.remove('active'); toggle.setAttribute('aria-expanded', 'false'); nav.classList.remove('open'); document.body.style.overflow = ''; };
  toggle.addEventListener('click', () => nav.classList.contains('open') ? close() : open());
  nav.querySelectorAll('a').forEach(a => a.addEventListener('click', close));
}

/* ── Active nav ─────────────────────────────────────────── */
function initActiveNav() {
  const page = (location.pathname.split('/').pop() || 'index.html');
  document.querySelectorAll('.site-nav a').forEach(a => {
    const href = (a.getAttribute('href') || '').split('/').pop();
    if (href === page || ((href === 'index.html') && (page === '' || page === 'index.html'))) {
      a.classList.add('active');
    }
  });
}

/* ── Scroll reveals ─────────────────────────────────────── */
function initReveal() {
  const els = document.querySelectorAll('.reveal, .line-reveal');
  if (!els.length) return;
  const obs = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); }
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
  els.forEach(el => obs.observe(el));
}

/* ── Word-by-word manifesto light-up ────────────────────── */
function initManifesto() {
  const el = document.querySelector('.manifesto-body');
  if (!el || el.dataset.split) return;
  el.dataset.split = '1';
  // wrap each word in a span while preserving <em>
  const walk = node => {
    [...node.childNodes].forEach(child => {
      if (child.nodeType === 3) {
        const frag = document.createDocumentFragment();
        child.textContent.split(/(\s+)/).forEach(tok => {
          if (tok.trim()) {
            const s = document.createElement('span');
            s.className = 'word'; s.textContent = tok;
            frag.appendChild(s);
          } else { frag.appendChild(document.createTextNode(tok)); }
        });
        node.replaceChild(frag, child);
      } else if (child.nodeType === 1) { walk(child); }
    });
  };
  walk(el);
  const obs = new IntersectionObserver(entries => {
    entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('lit'); obs.unobserve(e.target); } });
  }, { threshold: 0.5 });
  obs.observe(el);
}

/* ── Counters ───────────────────────────────────────────── */
function initCounters() {
  const counters = document.querySelectorAll('[data-count]');
  if (!counters.length) return;
  const obs = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if (!e.isIntersecting) return;
      const el = e.target;
      const end = parseFloat(el.dataset.count);
      const suffix = el.dataset.suffix || '';
      if (NW.REDUCED) { el.textContent = end + suffix; obs.unobserve(el); return; }
      const dur = 1500; const start = performance.now();
      const tick = now => {
        const t = Math.min((now - start) / dur, 1);
        const eased = 1 - Math.pow(1 - t, 3);
        el.textContent = Math.round(end * eased) + suffix;
        if (t < 1) requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
      obs.unobserve(el);
    });
  }, { threshold: 0.5 });
  counters.forEach(el => obs.observe(el));
}

/* ── Hero ───────────────────────────────────────────────── */
function initHero() {
  const hero = document.querySelector('.hero');
  if (hero) requestAnimationFrame(() => hero.classList.add('in'));
  NW.heroFlowField(document.getElementById('hero-canvas'));
}

/* ── Regenerable work thumbnails ────────────────────────── */
function initThumbs() {
  document.querySelectorAll('[data-thumb]').forEach(canvas => {
    const kind = canvas.dataset.thumb;
    let seed = parseInt(canvas.dataset.seed, 10) || (Math.random() * 1e9 | 0);
    NW.renderThumb(canvas, kind, seed);
    const btn = canvas.closest('.work-thumb')?.querySelector('.work-regen');
    if (btn) {
      btn.addEventListener('click', e => {
        e.preventDefault();
        seed = (Math.random() * 1e9) | 0;
        NW.renderThumb(canvas, kind, seed);
      });
    }
  });
}

/* ── Interactive lab ────────────────────────────────────── */
function initLab() {
  const canvas = document.getElementById('lab-canvas');
  if (!canvas) return;
  const density = document.getElementById('lab-density');
  const twist = document.getElementById('lab-twist');
  const scale = document.getElementById('lab-scale');
  const dVal = document.getElementById('lab-density-val');
  const tVal = document.getElementById('lab-twist-val');
  const sVal = document.getElementById('lab-scale-val');
  let color = 0;
  let seed = 4242;

  const lab = NW.makeLab(canvas, () => ({
    density: parseInt(density.value, 10),
    twist: parseInt(twist.value, 10),
    scale: parseFloat(scale.value),
    color, seed
  }));

  function sync() {
    dVal.textContent = density.value;
    tVal.textContent = twist.value;
    sVal.textContent = parseFloat(scale.value).toFixed(1);
    lab.render();
  }
  [density, twist, scale].forEach(el => el.addEventListener('input', sync));

  document.querySelectorAll('.lab-swatch').forEach((sw, i) => {
    sw.addEventListener('click', () => {
      document.querySelectorAll('.lab-swatch').forEach(s => s.classList.remove('active'));
      sw.classList.add('active');
      color = i; sync();
    });
  });
  const regen = document.getElementById('lab-regen');
  if (regen) regen.addEventListener('click', () => { seed = (Math.random() * 1e9) | 0; sync(); });

  sync();
}

/* ── Ambient fields (footer + CTA) ──────────────────────── */
function initAmbient() {
  NW.ambientField(document.getElementById('footer-canvas'), { seed: 1212, color: '#6cf2c8' });
  NW.ambientField(document.getElementById('cta-canvas'), { seed: 9090, color: '#8a7bff' });
}

/* ── Contact form validation ────────────────────────────── */
function initForm() {
  const form = document.querySelector('.contact-form');
  if (!form) return;

  // budget chips -> hidden field
  const chips = form.querySelectorAll('.budget-chip');
  const budgetField = form.querySelector('#budget');
  chips.forEach(chip => chip.addEventListener('click', () => {
    chips.forEach(c => c.classList.remove('active'));
    chip.classList.add('active');
    if (budgetField) budgetField.value = chip.dataset.value;
  }));

  const rules = {
    name: v => v.trim().length >= 2 || 'Please enter your name.',
    email: v => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim()) || 'Enter a valid email address.',
    org: () => true,
    type: v => v !== '' || 'Choose a commission type.',
    message: v => v.trim().length >= 20 || 'Tell us a little more (20+ characters).'
  };

  function validateField(field) {
    const rule = rules[field.name];
    if (!rule) return true;
    const res = rule(field.value);
    const err = form.querySelector(`[data-error="${field.name}"]`);
    if (res === true) {
      field.classList.remove('invalid');
      if (err) { err.classList.remove('show'); err.textContent = ''; }
      return true;
    }
    field.classList.add('invalid');
    if (err) { err.textContent = res; err.classList.add('show'); }
    return false;
  }

  form.querySelectorAll('.form-control[name]').forEach(field => {
    field.addEventListener('blur', () => validateField(field));
    field.addEventListener('input', () => { if (field.classList.contains('invalid')) validateField(field); });
  });

  form.addEventListener('submit', e => {
    e.preventDefault();
    let ok = true;
    form.querySelectorAll('.form-control[name]').forEach(field => {
      if (!validateField(field)) ok = false;
    });
    if (!ok) {
      form.querySelector('.invalid')?.focus();
      return;
    }
    const success = document.querySelector('.form-success');
    form.style.display = 'none';
    if (success) {
      success.classList.add('show');
      const nameVal = form.querySelector('#name')?.value.trim().split(' ')[0] || 'there';
      const greet = success.querySelector('[data-greet]');
      if (greet) greet.textContent = nameVal;
    }
  });
}

/* ── Year stamp ─────────────────────────────────────────── */
function initYear() {
  document.querySelectorAll('[data-year]').forEach(el => el.textContent = new Date().getFullYear());
}

/* ── Boot ───────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  initCursor();
  initHeader();
  initMobileNav();
  initActiveNav();
  initReveal();
  initManifesto();
  initCounters();
  initHero();
  initThumbs();
  initLab();
  initAmbient();
  initForm();
  initYear();
});
