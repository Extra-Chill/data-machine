/* =========================================================
   THE BLOCK PARTY — Main JavaScript
   Vanilla JS. Theme toggle, mobile nav, scroll reveal,
   animated counters, accordion FAQ, sticky header.
   ========================================================= */
'use strict';

/* ── Theme toggle (persisted, respects OS preference) ───── */
function initTheme() {
  const root = document.documentElement;
  const KEY = 'blockparty-theme';
  const saved = localStorage.getItem(KEY);
  const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  const theme = saved || (prefersDark ? 'dark' : 'light');
  root.setAttribute('data-theme', theme);

  document.querySelectorAll('.theme-toggle').forEach(btn => {
    const sync = () => btn.setAttribute('aria-label',
      root.getAttribute('data-theme') === 'dark' ? 'Switch to light theme' : 'Switch to dark theme');
    sync();
    btn.addEventListener('click', () => {
      const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      localStorage.setItem(KEY, next);
      sync();
    });
  });
}

/* ── Sticky header shadow ───────────────────────────────── */
function initHeader() {
  const header = document.querySelector('.site-header');
  if (!header) return;
  const onScroll = () => header.classList.toggle('scrolled', window.scrollY > 12);
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
}

/* ── Mobile nav ─────────────────────────────────────────── */
function initMobileNav() {
  const toggle = document.querySelector('.nav-toggle');
  const nav = document.querySelector('.site-nav');
  if (!toggle || !nav) return;
  const close = () => { toggle.classList.remove('active'); nav.classList.remove('open'); toggle.setAttribute('aria-expanded', 'false'); };
  const open = () => { toggle.classList.add('active'); nav.classList.add('open'); toggle.setAttribute('aria-expanded', 'true'); };
  toggle.addEventListener('click', () => nav.classList.contains('open') ? close() : open());
  nav.querySelectorAll('a').forEach(a => a.addEventListener('click', close));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
}

/* ── Active nav highlighting ────────────────────────────── */
function initActiveNav() {
  const pname = (window.location.pathname.split('/').pop() || 'index.html');
  document.querySelectorAll('.site-nav a').forEach(a => {
    const fname = (a.getAttribute('href') || '').split('/').pop();
    if (fname === pname || (pname === '' && fname === 'index.html')) a.classList.add('active');
  });
}

/* ── Scroll reveal ──────────────────────────────────────── */
function initReveal() {
  const els = document.querySelectorAll('.reveal');
  if (!els.length) return;
  if (!('IntersectionObserver' in window)) { els.forEach(el => el.classList.add('visible')); return; }
  const obs = new IntersectionObserver(entries => {
    entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); } });
  }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
  els.forEach(el => obs.observe(el));
}

/* ── Animated stat counters ─────────────────────────────── */
function initCounters() {
  const counters = document.querySelectorAll('[data-count]');
  if (!counters.length) return;
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const fmt = (n, dec) => dec ? n.toFixed(dec) : Math.floor(n).toLocaleString('en-US');
  const run = el => {
    const end = parseFloat(el.dataset.count);
    const dec = (el.dataset.decimals ? parseInt(el.dataset.decimals, 10) : 0);
    if (reduce) { el.textContent = fmt(end, dec); return; }
    const dur = 1700; const t0 = performance.now();
    const tick = now => {
      const p = Math.min((now - t0) / dur, 1);
      const eased = 1 - Math.pow(1 - p, 3);
      el.textContent = fmt(end * eased, dec);
      if (p < 1) requestAnimationFrame(tick); else el.textContent = fmt(end, dec);
    };
    requestAnimationFrame(tick);
  };
  const obs = new IntersectionObserver(entries => {
    entries.forEach(e => { if (e.isIntersecting) { run(e.target); obs.unobserve(e.target); } });
  }, { threshold: 0.5 });
  counters.forEach(el => obs.observe(el));
}

/* ── Accordion FAQ ──────────────────────────────────────── */
function initAccordion() {
  document.querySelectorAll('.acc-item').forEach(item => {
    const btn = item.querySelector('.acc-q');
    const panel = item.querySelector('.acc-a');
    if (!btn || !panel) return;
    btn.setAttribute('aria-expanded', 'false');
    btn.addEventListener('click', () => {
      const isOpen = item.classList.contains('open');
      // close siblings within same accordion
      const group = item.closest('.accordion');
      if (group) group.querySelectorAll('.acc-item.open').forEach(other => {
        if (other !== item) { other.classList.remove('open'); other.querySelector('.acc-q').setAttribute('aria-expanded', 'false'); other.querySelector('.acc-a').style.maxHeight = null; }
      });
      if (isOpen) { item.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); panel.style.maxHeight = null; }
      else { item.classList.add('open'); btn.setAttribute('aria-expanded', 'true'); panel.style.maxHeight = panel.scrollHeight + 'px'; }
    });
  });
}

/* ── Toast helper ───────────────────────────────────────── */
let toastTimer;
function showToast(msg) {
  let toast = document.querySelector('.toast');
  if (!toast) {
    toast = document.createElement('div');
    toast.className = 'toast';
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
    document.body.appendChild(toast);
  }
  toast.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><span></span>';
  toast.querySelector('span').textContent = msg;
  requestAnimationFrame(() => toast.classList.add('show'));
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toast.classList.remove('show'), 2200);
}

/* ── Block inserter demo ────────────────────────────────── */
const BLOCK_TEMPLATES = {
  heading:  () => '<div class="pb-heading">A joyful headline</div>',
  paragraph:() => '<p class="pb-para">Write freely. The block editor gets out of your way, so the words come first and the layout follows.</p>',
  image:    () => '<div class="pb-image"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg></div>',
  quote:    () => '<blockquote class="pb-quote">Blocks are the LEGO bricks of the open web.</blockquote>',
  button:   () => '<span class="pb-button">Get started →</span>',
  columns:  () => '<div class="pb-columns"><div>Two tidy columns, no CSS wrangling required.</div><div>Drag, drop, done. Responsive by default.</div></div>',
  gallery:  () => '<div class="pb-gallery"><span style="background:var(--grad-cool)"></span><span style="background:var(--grad-warm)"></span><span style="background:var(--grad-hero)"></span></div>',
  list:     () => '<ul class="pb-list"><li>Reusable patterns</li><li>Block themes &amp; theme.json</li><li>Full-site editing</li></ul>',
  code:     () => '<pre class="pb-code">add_filter( \'the_content\', \'spark_joy\' );</pre>'
};

function initInserter() {
  const inserter = document.querySelector('.inserter-demo');
  if (!inserter) return;
  const canvas = inserter.querySelector('.canvas-stack');
  const empty = inserter.querySelector('.canvas-empty');
  const clearBtn = inserter.querySelector('.canvas-clear');
  const search = inserter.querySelector('.inserter-search input');
  const blocks = Array.from(inserter.querySelectorAll('.ins-block'));

  const refreshEmpty = () => {
    const has = canvas.children.length > 0;
    if (empty) empty.style.display = has ? 'none' : '';
    if (clearBtn) clearBtn.style.display = has ? '' : 'none';
  };

  const addBlock = type => {
    const tpl = BLOCK_TEMPLATES[type];
    if (!tpl) return;
    const wrap = document.createElement('div');
    wrap.className = 'placed-block';
    wrap.innerHTML = tpl() + '<button class="pb-remove" aria-label="Remove block">×</button>';
    wrap.querySelector('.pb-remove').addEventListener('click', () => { wrap.remove(); refreshEmpty(); });
    canvas.appendChild(wrap);
    refreshEmpty();
    showToast(type.charAt(0).toUpperCase() + type.slice(1) + ' block added');
    canvas.scrollTop = canvas.scrollHeight;
  };

  blocks.forEach(b => b.addEventListener('click', () => addBlock(b.dataset.block)));

  if (clearBtn) clearBtn.addEventListener('click', () => { canvas.innerHTML = ''; refreshEmpty(); showToast('Canvas cleared'); });

  if (search) search.addEventListener('input', () => {
    const q = search.value.trim().toLowerCase();
    blocks.forEach(b => {
      const name = (b.dataset.block + ' ' + b.textContent).toLowerCase();
      b.style.display = (!q || name.includes(q)) ? '' : 'none';
    });
  });

  // seed with a friendly starter layout
  addBlockSilently('heading');
  addBlockSilently('paragraph');
  function addBlockSilently(type) {
    const tpl = BLOCK_TEMPLATES[type];
    const wrap = document.createElement('div');
    wrap.className = 'placed-block';
    wrap.innerHTML = tpl() + '<button class="pb-remove" aria-label="Remove block">×</button>';
    wrap.querySelector('.pb-remove').addEventListener('click', () => { wrap.remove(); refreshEmpty(); });
    canvas.appendChild(wrap);
  }
  refreshEmpty();
}

/* ── theme.json palette swatch copy ─────────────────────── */
function initPalette() {
  document.querySelectorAll('.swatch').forEach(sw => {
    sw.setAttribute('role', 'button');
    sw.setAttribute('tabindex', '0');
    const copy = () => {
      const hex = sw.dataset.hex || '';
      if (navigator.clipboard) navigator.clipboard.writeText(hex).catch(() => {});
      showToast('Copied ' + hex);
    };
    sw.addEventListener('click', copy);
    sw.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); copy(); } });
  });
}

/* ── Newsletter / pledge form ───────────────────────────── */
function initForms() {
  document.querySelectorAll('form[data-faux]').forEach(form => {
    form.addEventListener('submit', e => {
      e.preventDefault();
      const btn = form.querySelector('button[type="submit"]');
      const orig = btn.textContent;
      btn.textContent = form.dataset.faux || 'Thank you!';
      btn.disabled = true;
      form.reset();
      setTimeout(() => { btn.textContent = orig; btn.disabled = false; }, 3500);
    });
  });
}

/* ── Init ───────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  initTheme();
  initHeader();
  initMobileNav();
  initActiveNav();
  initReveal();
  initCounters();
  initAccordion();
  initInserter();
  initPalette();
  initForms();
});
