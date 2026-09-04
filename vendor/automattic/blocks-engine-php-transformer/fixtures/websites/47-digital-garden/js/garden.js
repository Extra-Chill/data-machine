/* =========================================================
   Mara's Garden — client-side behaviors (vanilla JS)
   - theme toggle (persisted)
   - note search / filter
   - SVG graph view (force-ish layout)
   - hover-preview popovers for wikilinks
   ========================================================= */
'use strict';

/* ---------- shared note index ----------
   Hand-maintained metadata for every note in the garden.
   `links` = outgoing wikilinks (slugs). Backlinks are derived. */
const NOTES = [
  { slug: "index", title: "Start Here", ripe: "evergreen", tags: ["moc","meta","evergreen"],
    excerpt: "The map of content. A slow, public, perpetually-unfinished collection of interlinked notes — wander, don't read top to bottom.",
    links: ["on-tending-a-digital-garden","idempotency-is-a-superpower","the-two-generals-problem","why-i-stopped-trusting-wall-clocks","evergreen-notes","book-how-to-take-smart-notes","the-dichotomy-of-control","amor-fati","compost-is-a-distributed-system","three-sisters-planting"] },
  { slug: "on-tending-a-digital-garden", title: "On Tending a Digital Garden", ripe: "evergreen", tags: ["evergreen","meta","pkm","writing"],
    excerpt: "A garden is a topology, not a stream. Three rules: plant ugly, link before you file, tend on a schedule.",
    links: ["evergreen-notes","book-how-to-take-smart-notes","compost-is-a-distributed-system","the-dichotomy-of-control","index"] },
  { slug: "evergreen-notes", title: "Evergreen Notes", ripe: "evergreen", tags: ["evergreen","pkm","writing"],
    excerpt: "Notes written to be developed over time and across contexts: atomic, concept-oriented, densely linked, in your own words.",
    links: ["idempotency-is-a-superpower","book-how-to-take-smart-notes","on-tending-a-digital-garden","index"] },
  { slug: "book-how-to-take-smart-notes", title: "Book — How to Take Smart Notes", ripe: "budding", tags: ["budding","book","literature-note","pkm"],
    excerpt: "Literature note on Sönke Ahrens (2017). Don't take notes about what you read; take notes that argue with it.",
    links: ["evergreen-notes","on-tending-a-digital-garden","idempotency-is-a-superpower","compost-is-a-distributed-system","index"] },
  { slug: "idempotency-is-a-superpower", title: "Idempotency Is a Superpower", ripe: "evergreen", tags: ["evergreen","distributed-systems","engineering"],
    excerpt: "Doing it twice equals doing it once. The escape hatch from impossible exactly-once delivery: shift the burden from delivery to identity.",
    links: ["the-two-generals-problem","why-i-stopped-trusting-wall-clocks","compost-is-a-distributed-system","the-dichotomy-of-control","index"] },
  { slug: "the-two-generals-problem", title: "The Two Generals Problem", ripe: "budding", tags: ["budding","distributed-systems","theory"],
    excerpt: "You cannot guarantee coordinated action over an unreliable channel. Proven impossible. You route around it; you don't solve it.",
    links: ["idempotency-is-a-superpower","why-i-stopped-trusting-wall-clocks","the-dichotomy-of-control","index"] },
  { slug: "why-i-stopped-trusting-wall-clocks", title: "Why I Stopped Trusting Wall Clocks", ripe: "budding", tags: ["budding","distributed-systems","engineering"],
    excerpt: "Wall clocks across machines are liars. You want causal order, not time of day. Use logical clocks.",
    links: ["the-two-generals-problem","idempotency-is-a-superpower","index"] },
  { slug: "the-dichotomy-of-control", title: "The Dichotomy of Control", ripe: "evergreen", tags: ["evergreen","stoicism","philosophy"],
    excerpt: "Some things are up to us, others are not. The most useful sentence ever handed to me — and it hides inside every distributed-systems problem.",
    links: ["amor-fati","idempotency-is-a-superpower","the-two-generals-problem","on-tending-a-digital-garden","why-i-stopped-trusting-wall-clocks","index"] },
  { slug: "amor-fati", title: "Amor Fati", ripe: "budding", tags: ["budding","stoicism","philosophy"],
    excerpt: "Love of fate. Not resignation — the active embrace of what is, including the parts you'd never have chosen.",
    links: ["the-dichotomy-of-control","idempotency-is-a-superpower","three-sisters-planting","index"] },
  { slug: "compost-is-a-distributed-system", title: "Compost Is a Distributed System", ripe: "budding", tags: ["budding","gardening","distributed-systems"],
    excerpt: "A compost pile is self-organizing, fault-tolerant, eventually-consistent, and idempotent. The cheapest coordination is none.",
    links: ["idempotency-is-a-superpower","three-sisters-planting","on-tending-a-digital-garden","why-i-stopped-trusting-wall-clocks","the-two-generals-problem","amor-fati","book-how-to-take-smart-notes","index"] },
  { slug: "three-sisters-planting", title: "Three Sisters Planting", ripe: "seedling", tags: ["seedling","gardening"],
    excerpt: "Corn, beans, squash grown together — cooperation that removes the need for an external coordinator.",
    links: ["compost-is-a-distributed-system","index"] },
];

const RIPE_EMOJI = { seedling: "🌰", budding: "🌿", evergreen: "🌳" };
const BY_SLUG = Object.fromEntries(NOTES.map(n => [n.slug, n]));

/* derive backlinks once (used by graph dimming + popovers) */
NOTES.forEach(n => { n.backlinks = NOTES.filter(o => o.slug !== n.slug && o.links.includes(n.slug)).map(o => o.slug); });

/* ---------- theme ---------- */
function initTheme() {
  const KEY = 'mara-garden-theme';
  const root = document.documentElement;
  const saved = (() => { try { return localStorage.getItem(KEY); } catch { return null; } })();
  const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  root.setAttribute('data-theme', saved || (prefersDark ? 'dark' : 'light'));

  const btn = document.querySelector('.theme-toggle');
  if (!btn) return;
  const sync = () => {
    const dark = root.getAttribute('data-theme') === 'dark';
    btn.innerHTML = (dark ? '☀︎' : '☾') + ' <span>' + (dark ? 'Light' : 'Dark') + '</span>';
    btn.setAttribute('aria-pressed', String(dark));
  };
  sync();
  btn.addEventListener('click', () => {
    const dark = root.getAttribute('data-theme') === 'dark';
    const next = dark ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
    try { localStorage.setItem(KEY, next); } catch {}
    sync();
  });
}

/* ---------- search ---------- */
function initSearch() {
  const input = document.querySelector('#note-search');
  const list = document.querySelector('#search-results');
  if (!input || !list) return;

  const norm = s => s.toLowerCase();
  let activeIdx = -1, current = [];

  function render(items) {
    current = items;
    activeIdx = -1;
    if (!input.value.trim()) { list.innerHTML = ''; return; }
    if (!items.length) { list.innerHTML = '<li class="sr-none">No notes match. Try “systems”, “stoic”, or “compost”.</li>'; return; }
    list.innerHTML = items.slice(0, 8).map(n =>
      `<li><a href="${n.slug}.html">${RIPE_EMOJI[n.ripe]} ${n.title}` +
      `<span class="sr-tag">${n.tags.slice(0,3).map(t => '#'+t).join(' ')}</span></a></li>`
    ).join('');
  }

  input.addEventListener('input', () => {
    const q = norm(input.value.trim());
    if (!q) return render([]);
    const scored = NOTES.filter(n => n.slug !== 'index').map(n => {
      const hay = norm(n.title + ' ' + n.tags.join(' ') + ' ' + n.excerpt);
      let score = 0;
      if (norm(n.title).includes(q)) score += 5;
      if (n.tags.some(t => norm(t).includes(q))) score += 3;
      if (hay.includes(q)) score += 1;
      return { n, score };
    }).filter(x => x.score > 0).sort((a,b) => b.score - a.score).map(x => x.n);
    render(scored);
  });

  input.addEventListener('keydown', e => {
    const links = [...list.querySelectorAll('a')];
    if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = Math.min(activeIdx+1, links.length-1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = Math.max(activeIdx-1, 0); }
    else if (e.key === 'Enter' && links[activeIdx]) { e.preventDefault(); links[activeIdx].click(); return; }
    else if (e.key === 'Escape') { input.value = ''; render([]); return; }
    links.forEach((a,i) => a.classList.toggle('active', i === activeIdx));
  });

  // keyboard shortcut: "/" focuses search
  document.addEventListener('keydown', e => {
    if (e.key === '/' && document.activeElement !== input) { e.preventDefault(); input.focus(); }
  });
}

/* ---------- hover-preview popovers for wikilinks ---------- */
function initPopovers() {
  let pop, timer;
  function ensure() {
    if (pop) return pop;
    pop = document.createElement('div');
    pop.className = 'popover';
    pop.setAttribute('role', 'tooltip');
    document.body.appendChild(pop);
    return pop;
  }
  function show(a) {
    const slug = a.dataset.slug;
    const n = BY_SLUG[slug];
    if (!n) return;
    const p = ensure();
    p.innerHTML =
      `<h4>${RIPE_EMOJI[n.ripe]} ${n.title}</h4>` +
      `<div class="pp-meta">${n.ripe} · ${n.tags.slice(0,3).map(t=>'#'+t).join(' ')}</div>` +
      `<div>${n.excerpt}</div>`;
    const r = a.getBoundingClientRect();
    const top = window.scrollY + r.bottom + 8;
    let left = window.scrollX + r.left;
    left = Math.min(left, window.scrollX + document.documentElement.clientWidth - 332);
    p.style.top = top + 'px';
    p.style.left = Math.max(8, left) + 'px';
    requestAnimationFrame(() => p.classList.add('show'));
  }
  function hide() { if (pop) pop.classList.remove('show'); }

  document.querySelectorAll('a.internal[data-slug]').forEach(a => {
    a.addEventListener('mouseenter', () => { clearTimeout(timer); timer = setTimeout(() => show(a), 220); });
    a.addEventListener('mouseleave', () => { clearTimeout(timer); hide(); });
    a.addEventListener('focus', () => show(a));
    a.addEventListener('blur', hide);
  });
}

/* ---------- SVG graph view ---------- */
function initGraph() {
  const svg = document.querySelector('#graph');
  if (!svg) return;
  const W = 280, H = 280;
  svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
  const currentSlug = svg.dataset.current || '';

  // build node + edge sets
  const nodes = NOTES.map(n => ({ ...n }));
  const idx = Object.fromEntries(nodes.map((n,i) => [n.slug, i]));
  const edges = [];
  const seen = new Set();
  nodes.forEach(n => n.links.forEach(t => {
    if (!idx.hasOwnProperty(t)) return;
    const key = [n.slug, t].sort().join('::');
    if (seen.has(key)) return; seen.add(key);
    edges.push([idx[n.slug], idx[t]]);
  }));

  // deterministic seeded init (circle) then relax with a tiny force sim
  nodes.forEach((n,i) => {
    const ang = (i / nodes.length) * Math.PI * 2;
    n.x = W/2 + Math.cos(ang) * 95;
    n.y = H/2 + Math.sin(ang) * 95;
    n.vx = 0; n.vy = 0;
    n.deg = 0;
  });
  edges.forEach(([a,b]) => { nodes[a].deg++; nodes[b].deg++; });

  for (let iter = 0; iter < 220; iter++) {
    // repulsion
    for (let i=0;i<nodes.length;i++) for (let j=i+1;j<nodes.length;j++) {
      const a=nodes[i], b=nodes[j];
      let dx=a.x-b.x, dy=a.y-b.y, d2=dx*dx+dy*dy || 0.01, d=Math.sqrt(d2);
      const f = 900 / d2;
      const ux=dx/d, uy=dy/d;
      a.vx += ux*f; a.vy += uy*f; b.vx -= ux*f; b.vy -= uy*f;
    }
    // spring on edges
    edges.forEach(([ai,bi]) => {
      const a=nodes[ai], b=nodes[bi];
      let dx=b.x-a.x, dy=b.y-a.y, d=Math.sqrt(dx*dx+dy*dy)||0.01;
      const f=(d-58)*0.02, ux=dx/d, uy=dy/d;
      a.vx+=ux*f; a.vy+=uy*f; b.vx-=ux*f; b.vy-=uy*f;
    });
    // center gravity + integrate
    nodes.forEach(n => {
      n.vx += (W/2 - n.x)*0.005; n.vy += (H/2 - n.y)*0.005;
      n.x += n.vx*0.85; n.y += n.vy*0.85; n.vx*=0.82; n.vy*=0.82;
      n.x = Math.max(16, Math.min(W-16, n.x));
      n.y = Math.max(16, Math.min(H-16, n.y));
    });
  }

  const NS = 'http://www.w3.org/2000/svg';
  const gEdges = document.createElementNS(NS,'g');
  const gNodes = document.createElementNS(NS,'g');
  edges.forEach(([ai,bi]) => {
    const l = document.createElementNS(NS,'line');
    l.setAttribute('class','edge');
    l.setAttribute('x1',nodes[ai].x); l.setAttribute('y1',nodes[ai].y);
    l.setAttribute('x2',nodes[bi].x); l.setAttribute('y2',nodes[bi].y);
    l.dataset.a = nodes[ai].slug; l.dataset.b = nodes[bi].slug;
    gEdges.appendChild(l);
  });
  nodes.forEach(n => {
    const g = document.createElementNS(NS,'g');
    g.setAttribute('class','node' + (n.slug===currentSlug ? ' current' : ''));
    g.dataset.slug = n.slug;
    const c = document.createElementNS(NS,'circle');
    c.setAttribute('cx',n.x); c.setAttribute('cy',n.y);
    c.setAttribute('r', Math.max(4, Math.min(9, 3 + n.deg*0.9)));
    const t = document.createElementNS(NS,'text');
    t.setAttribute('x',n.x); t.setAttribute('y', n.y - (3 + n.deg*0.9) - 3);
    t.setAttribute('text-anchor','middle');
    t.textContent = n.slug === 'index' ? '🌱 home' : n.title.replace(/^(Book — |Why I Stopped Trusting )/,'').slice(0,16);
    g.append(c,t);
    const a = document.createElementNS(NS,'a');
    a.setAttributeNS('http://www.w3.org/1999/xlink','href', n.slug + '.html');
    a.setAttribute('href', n.slug + '.html');
    a.appendChild(g);
    gNodes.appendChild(a);

    // highlight neighborhood on hover
    g.addEventListener('mouseenter', () => highlight(n.slug));
    g.addEventListener('mouseleave', clearHighlight);
  });
  svg.append(gEdges, gNodes);

  function neighbors(slug) {
    const set = new Set([slug]);
    const n = BY_SLUG[slug];
    n.links.forEach(s => set.add(s));
    n.backlinks.forEach(s => set.add(s));
    return set;
  }
  function highlight(slug) {
    const keep = neighbors(slug);
    gNodes.querySelectorAll('.node').forEach(g => g.classList.toggle('dim', !keep.has(g.dataset.slug)));
    gEdges.querySelectorAll('.edge').forEach(e => e.classList.toggle('dim', !(keep.has(e.dataset.a) && keep.has(e.dataset.b))));
  }
  function clearHighlight() {
    gNodes.querySelectorAll('.node').forEach(g => g.classList.remove('dim'));
    gEdges.querySelectorAll('.edge').forEach(e => e.classList.remove('dim'));
  }
  if (currentSlug) highlight(currentSlug);
}

document.addEventListener('DOMContentLoaded', () => {
  initTheme();
  initSearch();
  initPopovers();
  initGraph();
});
