/* =========================================================
   STATIC//NOISE — Main JavaScript
   Modular, vanilla. Everything degrades with reduced-motion.
   ========================================================= */
'use strict';

const REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const COARSE  = window.matchMedia('(hover: none), (pointer: coarse)').matches;

/* ── Custom cursor + magnetic hover ─────────────────────── */
function initCursor() {
  if (COARSE || REDUCED) return;

  const dot  = document.createElement('div'); dot.className = 'cursor';
  const ring = document.createElement('div'); ring.className = 'cursor-ring';
  document.body.append(dot, ring);

  let mx = innerWidth / 2, my = innerHeight / 2;
  let rx = mx, ry = my;

  addEventListener('mousemove', e => {
    mx = e.clientX; my = e.clientY;
    dot.style.transform = `translate(${mx}px, ${my}px) translate(-50%,-50%)`;
  });
  (function loop() {
    rx += (mx - rx) * 0.16;
    ry += (my - ry) * 0.16;
    ring.style.transform = `translate(${rx}px, ${ry}px) translate(-50%,-50%)`;
    requestAnimationFrame(loop);
  })();

  const hot = 'a, button, .feat, .issue-card, .contributor, .sticker, .chip, input';
  document.querySelectorAll(hot).forEach(el => {
    el.addEventListener('mouseenter', () => document.body.classList.add('cursor-hover'));
    el.addEventListener('mouseleave', () => document.body.classList.remove('cursor-hover'));
  });
}

/* ── Magnetic buttons ───────────────────────────────────── */
function initMagnetic() {
  if (COARSE || REDUCED) return;
  document.querySelectorAll('.magnetic').forEach(el => {
    const strength = parseFloat(el.dataset.magnet || '0.4');
    el.addEventListener('mousemove', e => {
      const r = el.getBoundingClientRect();
      const x = e.clientX - (r.left + r.width / 2);
      const y = e.clientY - (r.top + r.height / 2);
      el.style.transform = `translate(${x * strength}px, ${y * strength}px)`;
    });
    el.addEventListener('mouseleave', () => { el.style.transform = ''; });
  });
}

/* ── Split-letter headlines (wrap each letter for hover) ── */
function initSplitLetters() {
  document.querySelectorAll('[data-split]').forEach(node => {
    node.querySelectorAll('span').forEach(span => {
      const text = span.textContent;
      span.textContent = '';
      [...text].forEach(ch => {
        const c = document.createElement('span');
        c.className = 'char';
        c.textContent = ch === ' ' ? ' ' : ch;
        span.appendChild(c);
      });
    });
  });
}

/* ── Mobile nav ─────────────────────────────────────────── */
function initNav() {
  const btn = document.querySelector('.nav-toggle');
  const nav = document.querySelector('.site-nav');
  if (!btn || !nav) return;
  btn.addEventListener('click', () => {
    const open = nav.classList.toggle('open');
    btn.setAttribute('aria-expanded', String(open));
  });
  nav.querySelectorAll('a').forEach(a => a.addEventListener('click', () => {
    nav.classList.remove('open'); btn.setAttribute('aria-expanded', 'false');
  }));
}

/* ── Hide-on-scroll header ──────────────────────────────── */
function initHeader() {
  const header = document.querySelector('.site-header');
  if (!header) return;
  let last = 0;
  addEventListener('scroll', () => {
    const y = scrollY;
    if (y > 220 && y > last) header.classList.add('hidden');
    else header.classList.remove('hidden');
    last = y;
  }, { passive: true });
}

/* ── Theme / GLITCH toggle — remixes palette ────────────── */
function initThemeToggle() {
  const btn = document.querySelector('.theme-toggle');
  if (!btn) return;
  const saved = localStorage.getItem('sn-theme');
  if (saved === 'glitch') document.body.classList.add('theme-glitch');

  btn.addEventListener('click', () => {
    const on = document.body.classList.toggle('theme-glitch');
    localStorage.setItem('sn-theme', on ? 'glitch' : 'paper');
    if (!REDUCED) glitchBurst();
  });
}

/* short visual glitch burst on toggle */
function glitchBurst() {
  const el = document.documentElement;
  let n = 0;
  const id = setInterval(() => {
    el.style.filter = n % 2 ? 'invert(1) hue-rotate(90deg)' : 'none';
    if (++n > 5) { clearInterval(id); el.style.filter = ''; }
  }, 55);
}

/* ── Scroll reveals (IntersectionObserver) ──────────────── */
function initReveals() {
  const els = document.querySelectorAll('.reveal');
  if (!els.length) return;
  if (REDUCED || !('IntersectionObserver' in window)) {
    els.forEach(e => e.classList.add('in')); return;
  }
  const io = new IntersectionObserver((entries) => {
    entries.forEach(en => {
      if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); }
    });
  }, { threshold: 0.18 });
  els.forEach(e => io.observe(e));
}

/* ── Scroll-velocity reactive skew on tagged elements ───── */
function initScrollSkew() {
  if (REDUCED) return;
  const els = document.querySelectorAll('[data-skew]');
  if (!els.length) return;
  let last = scrollY, vel = 0, raf = null;

  addEventListener('scroll', () => {
    vel = scrollY - last; last = scrollY;
    if (!raf) raf = requestAnimationFrame(apply);
  }, { passive: true });

  function apply() {
    const clamped = Math.max(-14, Math.min(14, vel * 0.4));
    els.forEach(el => {
      const f = parseFloat(el.dataset.skew || '1');
      el.style.transform = `skewY(${clamped * f * 0.5}deg) translateY(${clamped * f}px)`;
    });
    vel *= 0.85;
    if (Math.abs(vel) > 0.2) raf = requestAnimationFrame(apply);
    else { raf = null; els.forEach(el => el.style.transform = ''); }
  }
}

/* ── Parallax for hero blob/sticker ─────────────────────── */
function initParallax() {
  if (REDUCED) return;
  const layers = document.querySelectorAll('[data-parallax]');
  if (!layers.length) return;
  addEventListener('scroll', () => {
    const y = scrollY;
    layers.forEach(l => {
      const s = parseFloat(l.dataset.parallax);
      l.style.translate = `0 ${y * s}px`;
    });
  }, { passive: true });
}

/* ── Reading progress bar ───────────────────────────────── */
function initProgress() {
  const bar = document.querySelector('.progress');
  if (!bar) return;
  addEventListener('scroll', () => {
    const h = document.documentElement.scrollHeight - innerHeight;
    bar.style.width = (h > 0 ? (scrollY / h) * 100 : 0) + '%';
  }, { passive: true });
}

/* ── Count-up stats ─────────────────────────────────────── */
function initCounters() {
  const nums = document.querySelectorAll('[data-count]');
  if (!nums.length) return;
  if (REDUCED || !('IntersectionObserver' in window)) {
    nums.forEach(n => n.textContent = n.dataset.count + (n.dataset.suffix || '')); return;
  }
  const io = new IntersectionObserver(entries => {
    entries.forEach(en => {
      if (!en.isIntersecting) return;
      io.unobserve(en.target);
      const el = en.target;
      const target = parseFloat(el.dataset.count);
      const suffix = el.dataset.suffix || '';
      const dur = 1400; const t0 = performance.now();
      (function tick(now) {
        const p = Math.min(1, (now - t0) / dur);
        const e = 1 - Math.pow(1 - p, 3);
        const val = target % 1 === 0 ? Math.round(target * e) : (target * e).toFixed(1);
        el.textContent = val + suffix;
        if (p < 1) requestAnimationFrame(tick);
      })(t0);
    });
  }, { threshold: 0.4 });
  nums.forEach(n => io.observe(n));
}

/* ── Issues: filter + click-to-shuffle ──────────────────── */
function initIssues() {
  const grid = document.querySelector('.issue-grid');
  if (!grid) return;
  const cards = [...grid.children];

  document.querySelectorAll('.chip[data-filter]').forEach(chip => {
    chip.addEventListener('click', () => {
      document.querySelectorAll('.chip[data-filter]').forEach(c => c.setAttribute('aria-pressed', 'false'));
      chip.setAttribute('aria-pressed', 'true');
      const f = chip.dataset.filter;
      cards.forEach(c => {
        const show = f === 'all' || c.dataset.theme === f;
        c.classList.toggle('hide', !show);
      });
    });
  });

  const shuffle = document.querySelector('.shuffle-btn');
  if (shuffle) {
    shuffle.addEventListener('click', () => {
      const order = cards.map((_, i) => i);
      for (let i = order.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [order[i], order[j]] = [order[j], order[i]];
      }
      order.forEach(i => grid.appendChild(cards[i]));
      if (!REDUCED) { grid.style.transition = 'none'; grid.animate(
        [{ opacity: .3, filter: 'blur(4px)' }, { opacity: 1, filter: 'blur(0)' }],
        { duration: 350, easing: 'ease-out' }); }
    });
  }
}

/* ── Draggable sticker toy ──────────────────────────────── */
function initDrag() {
  document.querySelectorAll('.sticker').forEach(st => {
    let sx, sy, ox, oy, dragging = false;
    const down = e => {
      dragging = true; st.classList.add('dragging');
      st.style.zIndex = ++initDrag.z;
      const p = point(e);
      sx = p.x; sy = p.y;
      const r = st.getBoundingClientRect();
      const pr = st.offsetParent.getBoundingClientRect();
      ox = r.left - pr.left; oy = r.top - pr.top;
      st.style.left = ox + 'px'; st.style.top = oy + 'px';
      st.style.right = 'auto'; st.style.bottom = 'auto';
      e.preventDefault();
    };
    const move = e => {
      if (!dragging) return;
      const p = point(e);
      st.style.left = (ox + p.x - sx) + 'px';
      st.style.top  = (oy + p.y - sy) + 'px';
    };
    const up = () => { dragging = false; st.classList.remove('dragging'); };
    st.addEventListener('mousedown', down);
    st.addEventListener('touchstart', down, { passive: false });
    addEventListener('mousemove', move);
    addEventListener('touchmove', move, { passive: false });
    addEventListener('mouseup', up);
    addEventListener('touchend', up);
  });
  function point(e) {
    const t = e.touches ? e.touches[0] : e;
    return { x: t.clientX, y: t.clientY };
  }
}
initDrag.z = 50;

/* ── Konami / keyboard easter egg → confetti of letters ─── */
function initEasterEgg() {
  const seq = ['n','o','i','s','e'];
  let buf = [];
  addEventListener('keydown', e => {
    buf.push(e.key.toLowerCase()); buf = buf.slice(-seq.length);
    if (seq.every((k, i) => buf[i] === k)) letterRain();
  });
  function letterRain() {
    if (REDUCED) { document.body.classList.toggle('theme-glitch'); return; }
    const chars = 'STATICNOISE✲◆▲●';
    const colors = ['#d6ff2e','#ff2e63','#2e5bff','#ff7a00','#b026ff','#00e5d4'];
    for (let i = 0; i < 60; i++) {
      const s = document.createElement('div');
      s.textContent = chars[Math.floor(Math.random() * chars.length)];
      Object.assign(s.style, {
        position: 'fixed', top: '-40px',
        left: Math.random() * 100 + 'vw', zIndex: 9500,
        fontFamily: 'Archivo Black, sans-serif',
        fontSize: (16 + Math.random() * 40) + 'px',
        color: colors[Math.floor(Math.random() * colors.length)],
        pointerEvents: 'none'
      });
      document.body.appendChild(s);
      const dur = 2200 + Math.random() * 1800;
      s.animate([
        { transform: `translateY(0) rotate(0deg)`, opacity: 1 },
        { transform: `translateY(${innerHeight + 80}px) rotate(${(Math.random()*2-1)*720}deg)`, opacity: 0 }
      ], { duration: dur, easing: 'cubic-bezier(.3,.1,.6,1)' }).onfinish = () => s.remove();
    }
  }
}

/* ── Year stamp ─────────────────────────────────────────── */
function initYear() {
  document.querySelectorAll('[data-year]').forEach(el => el.textContent = new Date().getFullYear());
}

/* ── Newsletter fake submit ─────────────────────────────── */
function initForms() {
  document.querySelectorAll('.footer-sub').forEach(form => {
    form.addEventListener('submit', e => {
      e.preventDefault();
      const btn = form.querySelector('button');
      const old = btn.textContent;
      btn.textContent = 'SENT ✲';
      form.querySelector('input').value = '';
      setTimeout(() => btn.textContent = old, 2200);
    });
  });
}

/* ── Boot ───────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  initSplitLetters();
  initCursor();
  initMagnetic();
  initNav();
  initHeader();
  initThemeToggle();
  initReveals();
  initScrollSkew();
  initParallax();
  initProgress();
  initCounters();
  initIssues();
  initDrag();
  initEasterEgg();
  initYear();
  initForms();
});
