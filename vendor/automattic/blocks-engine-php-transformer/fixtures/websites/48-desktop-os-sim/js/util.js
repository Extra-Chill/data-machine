/* =========================================================
   AuroraOS 88 — shared utilities
   Global namespace, DOM helpers, localStorage, icon SVGs.
   ========================================================= */
'use strict';

window.AOS = window.AOS || {};

AOS.REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ── tiny DOM helpers ── */
AOS.el = function (tag, props, ...kids) {
  const node = document.createElement(tag);
  if (props) {
    for (const k in props) {
      if (k === 'class') node.className = props[k];
      else if (k === 'html') node.innerHTML = props[k];
      else if (k === 'text') node.textContent = props[k];
      else if (k === 'dataset') Object.assign(node.dataset, props[k]);
      else if (k.startsWith('on') && typeof props[k] === 'function') node.addEventListener(k.slice(2), props[k]);
      else if (props[k] != null && props[k] !== false) node.setAttribute(k, props[k]);
    }
  }
  for (const kid of kids.flat()) {
    if (kid == null || kid === false) continue;
    node.append(kid.nodeType ? kid : document.createTextNode(kid));
  }
  return node;
};
AOS.$  = (s, r = document) => r.querySelector(s);
AOS.$$ = (s, r = document) => Array.from(r.querySelectorAll(s));
AOS.clamp = (v, a, b) => Math.min(b, Math.max(a, v));

/* ── localStorage (namespaced, fault-tolerant for file://) ── */
const NS = 'auroraos88:';
AOS.store = {
  get(key, fallback) {
    try { const v = localStorage.getItem(NS + key); return v == null ? fallback : JSON.parse(v); }
    catch (e) { return fallback; }
  },
  set(key, val) {
    try { localStorage.setItem(NS + key, JSON.stringify(val)); return true; }
    catch (e) { return false; }
  },
  del(key) { try { localStorage.removeItem(NS + key); } catch (e) {} }
};

/* ── seeded RNG (mulberry32) for the wallpaper / login bg ── */
AOS.rng = function (seed) {
  let t = seed >>> 0;
  return function () {
    t += 0x6D2B79F5;
    let x = Math.imul(t ^ (t >>> 15), 1 | t);
    x ^= x + Math.imul(x ^ (x >>> 7), 61 | x);
    return ((x ^ (x >>> 14)) >>> 0) / 4294967296;
  };
};

/* ── reusable inline SVG icons (string -> used in innerHTML) ── */
AOS.icons = {
  terminal: `<svg viewBox="0 0 48 48" width="100%" height="100%"><rect x="4" y="7" width="40" height="34" rx="5" fill="#0a0420" stroke="#00eaff" stroke-width="2"/><path d="M12 18 L18 24 L12 30" fill="none" stroke="#8affc1" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M22 31 H34" stroke="#ff4fd6" stroke-width="2.4" stroke-linecap="round"/></svg>`,
  readme: `<svg viewBox="0 0 48 48" width="100%" height="100%"><path d="M11 5 H30 L38 13 V43 H11 Z" fill="#1a1136" stroke="#00eaff" stroke-width="2"/><path d="M30 5 V13 H38" fill="none" stroke="#00eaff" stroke-width="2"/><path d="M16 22 H32 M16 28 H32 M16 34 H26" stroke="#8affc1" stroke-width="2" stroke-linecap="round"/></svg>`,
  explorer: `<svg viewBox="0 0 48 48" width="100%" height="100%"><path d="M5 13 H20 L24 18 H43 V40 H5 Z" fill="#2a1d4d" stroke="#ff4fd6" stroke-width="2"/><path d="M5 18 H43" stroke="#ff4fd6" stroke-width="1.4" opacity=".6"/></svg>`,
  synth: `<svg viewBox="0 0 48 48" width="100%" height="100%"><rect x="5" y="14" width="38" height="22" rx="3" fill="#0a0420" stroke="#00eaff" stroke-width="2"/><rect x="9" y="26" width="3" height="8" fill="#fff"/><rect x="14" y="26" width="3" height="8" fill="#fff"/><rect x="19" y="26" width="3" height="8" fill="#fff"/><rect x="24" y="26" width="3" height="8" fill="#fff"/><rect x="11" y="26" width="2" height="5" fill="#ff4fd6"/><rect x="16" y="26" width="2" height="5" fill="#ff4fd6"/><circle cx="36" cy="20" r="2.4" fill="#ffd166"/><circle cx="31" cy="20" r="2.4" fill="#8affc1"/></svg>`,
  paint: `<svg viewBox="0 0 48 48" width="100%" height="100%"><path d="M24 6 C13 6 6 14 6 23 C6 30 11 33 16 33 C19 33 19 36 18 38 C17 41 19 43 24 43 C34 43 42 35 42 24 C42 13 34 6 24 6Z" fill="#1a1136" stroke="#ff4fd6" stroke-width="2"/><circle cx="16" cy="20" r="2.4" fill="#ff4fd6"/><circle cx="24" cy="15" r="2.4" fill="#00eaff"/><circle cx="32" cy="20" r="2.4" fill="#ffd166"/><circle cx="33" cy="29" r="2.4" fill="#8affc1"/></svg>`,
  guestbook: `<svg viewBox="0 0 48 48" width="100%" height="100%"><rect x="8" y="6" width="32" height="36" rx="3" fill="#1a1136" stroke="#00eaff" stroke-width="2"/><path d="M16 16 H32 M16 22 H32 M16 28 H28" stroke="#8affc1" stroke-width="2" stroke-linecap="round"/><path d="M26 33 L34 25 L38 29 L30 37 H26 Z" fill="#ffd166" stroke="#ff4fd6" stroke-width="1.6"/></svg>`,
  settings: `<svg viewBox="0 0 48 48" width="100%" height="100%"><circle cx="24" cy="24" r="7" fill="none" stroke="#00eaff" stroke-width="2.4"/><g stroke="#ff4fd6" stroke-width="2.6" stroke-linecap="round"><path d="M24 6 V12 M24 36 V42 M6 24 H12 M36 24 H42 M11 11 L15 15 M33 33 L37 37 M37 11 L33 15 M15 33 L11 37"/></g></svg>`,
  now: `<svg viewBox="0 0 48 48" width="100%" height="100%"><circle cx="24" cy="24" r="18" fill="#0a0420" stroke="#8affc1" stroke-width="2"/><path d="M24 14 V24 L31 28" fill="none" stroke="#ff4fd6" stroke-width="2.6" stroke-linecap="round"/></svg>`,
  trash: `<svg viewBox="0 0 48 48" width="100%" height="100%"><path d="M14 16 H34 L32 42 H16 Z" fill="#1a1136" stroke="#5d6f86" stroke-width="2"/><path d="M10 16 H38 M19 12 H29 M21 22 V36 M27 22 V36" stroke="#5d6f86" stroke-width="2" stroke-linecap="round"/></svg>`,
  folder: `<svg viewBox="0 0 48 48" width="100%" height="100%"><path d="M5 13 H20 L24 18 H43 V40 H5 Z" fill="#2a1d4d" stroke="#ff4fd6" stroke-width="2"/></svg>`,
  doc: `<svg viewBox="0 0 48 48" width="100%" height="100%"><path d="M13 5 H30 L38 13 V43 H13 Z" fill="#120a26" stroke="#00eaff" stroke-width="2"/><path d="M30 5 V13 H38" fill="none" stroke="#00eaff" stroke-width="2"/><path d="M18 24 H32 M18 30 H32 M18 36 H26" stroke="#8affc1" stroke-width="1.8" stroke-linecap="round"/></svg>`,
  audio: `<svg viewBox="0 0 48 48" width="100%" height="100%"><path d="M14 5 H30 L38 13 V43 H14 Z" fill="#120a26" stroke="#ff4fd6" stroke-width="2"/><circle cx="20" cy="32" r="4" fill="none" stroke="#00eaff" stroke-width="2"/><path d="M24 32 V18 L33 16 V28" fill="none" stroke="#00eaff" stroke-width="2"/><circle cx="29" cy="28" r="4" fill="none" stroke="#00eaff" stroke-width="2"/></svg>`,
  game: `<svg viewBox="0 0 48 48" width="100%" height="100%"><rect x="6" y="16" width="36" height="20" rx="9" fill="#120a26" stroke="#8affc1" stroke-width="2"/><path d="M15 23 V29 M12 26 H18" stroke="#00eaff" stroke-width="2.2" stroke-linecap="round"/><circle cx="31" cy="24" r="2" fill="#ff4fd6"/><circle cx="36" cy="29" r="2" fill="#ffd166"/></svg>`
};

/* short helper for inline svg in start menu / window title */
AOS.svg = (name, size = 20) => `<span style="display:inline-grid;width:${size}px;height:${size}px">${AOS.icons[name] || AOS.icons.doc}</span>`;
