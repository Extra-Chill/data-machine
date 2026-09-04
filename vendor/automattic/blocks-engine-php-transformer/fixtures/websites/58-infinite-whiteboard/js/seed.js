/* =========================================================
   BOUNDLESS — seed.js
   Real, meaningful starter boards. The default board is a small
   product roadmap / system diagram so the canvas is never blank.
   Templates (kanban, mind map, flowchart) live here too and are
   loaded from templates.html.
   ========================================================= */
'use strict';

const Seed = (() => {

  const C = {
    ink:     '#1f2733',
    line:    '#cbd5e1',
    slate:   '#475569',
    sky:     '#dbeafe', skyB:   '#3b82f6',
    mint:    '#d1fae5', mintB:  '#10b981',
    amber:   '#fef3c7', amberB: '#f59e0b',
    rose:    '#ffe4e6', roseB:  '#f43f5e',
    violet:  '#ede9fe', violetB:'#8b5cf6',
    paperY:  '#fff8b8', paperP: '#ffd6e7', paperB: '#cdeafe', paperG: '#d7f5d0',
  };

  let n = 0;
  const id = p => `${p}_seed_${(++n).toString(36)}`;

  function rect(x, y, w, h, fill, stroke, label, extra = {}) {
    return Object.assign({
      id: id('rect'), type: 'rect', x, y, w, h,
      fill, stroke, strokeW: 2, radius: 12, label,
    }, extra);
  }
  function ellipse(x, y, w, h, fill, stroke, label, extra = {}) {
    return Object.assign({
      id: id('ell'), type: 'ellipse', x, y, w, h,
      fill, stroke, strokeW: 2, label,
    }, extra);
  }
  function sticky(x, y, text, fill = C.paperY) {
    return { id: id('note'), type: 'sticky', x, y, w: 190, h: 150, fill, text };
  }
  function text(x, y, t, fontSize = 30, extra = {}) {
    return Object.assign({
      id: id('txt'), type: 'text', x, y, w: 460, h: fontSize * 1.4,
      text: t, fontSize, fill: C.ink, bold: true,
    }, extra);
  }
  function conn(from, to, extra = {}) {
    return Object.assign({
      id: id('conn'), type: 'connector', from, to,
      stroke: C.slate, strokeW: 2.5, style: 'bezier', arrow: true,
    }, extra);
  }

  /* -----------------------------------------------------------
     DEFAULT BOARD — "Atlas Mobile · Q3 Release Plan"
     A believable product roadmap + a tiny system flow.
  ----------------------------------------------------------- */
  function defaultBoard() {
    const s = [];

    s.push(text(-140, -260, 'Atlas Mobile — Q3 Release Plan', 34));
    s.push(Object.assign(text(-140, -218, 'Owner: Priya N. · Updated this sprint · Status: on track', 16),
      { bold: false, fill: C.slate }));

    // --- Lane: Now / Next / Later swimlanes ---
    const lane = (x, label, color) =>
      Object.assign(rect(x, -160, 250, 470, '#ffffff', C.line, '', {}),
        { radius: 16, strokeW: 1.5, fill: 'rgba(255,255,255,0.65)' });
    s.push(lane(-160, 'Now', C.skyB));
    s.push(lane(120,  'Next', C.amberB));
    s.push(lane(400,  'Later', C.violetB));
    s.push(Object.assign(text(-150, -150, 'NOW', 18), { fill: C.skyB }));
    s.push(Object.assign(text(132, -150, 'NEXT', 18),  { fill: C.amberB }));
    s.push(Object.assign(text(412, -150, 'LATER', 18), { fill: C.violetB }));

    // NOW column cards
    const now1 = rect(-145, -110, 220, 64, C.sky, C.skyB, 'Offline sync engine');
    const now2 = rect(-145, -30,  220, 64, C.sky, C.skyB, 'New onboarding flow');
    const now3 = rect(-145, 50,   220, 64, C.mint, C.mintB, 'Crash-rate fixes ✓');
    s.push(now1, now2, now3);

    // NEXT column cards
    const next1 = rect(135, -110, 220, 64, C.amber, C.amberB, 'Push notifications v2');
    const next2 = rect(135, -30,  220, 64, C.amber, C.amberB, 'Dark mode');
    s.push(next1, next2);

    // LATER column cards
    const later1 = rect(415, -110, 220, 64, C.violet, C.violetB, 'Realtime collaboration');
    const later2 = rect(415, -30,  220, 64, C.violet, C.violetB, 'Plugin marketplace');
    s.push(later1, later2);

    // dependency arrows
    s.push(conn(now1.id, next1.id, { label: 'enables' }));
    s.push(conn(next1.id, later1.id));
    s.push(conn(now2.id, next2.id, { dashed: true }));

    // --- a tiny system diagram below the lanes ---
    s.push(Object.assign(text(-160, 360, 'Sync architecture (sketch)', 22), { fill: C.ink }));
    const app   = rect(-160, 410, 170, 70, '#ffffff', C.ink, 'Mobile App');
    const queue = ellipse(80, 412, 150, 66, C.amber, C.amberB, 'Local Queue');
    const api   = rect(310, 410, 160, 70, C.sky, C.skyB, 'Sync API');
    const db    = ellipse(540, 410, 160, 66, C.mint, C.mintB, 'Postgres');
    s.push(app, queue, api, db);
    s.push(conn(app.id, queue.id, { label: 'mutations' }));
    s.push(conn(queue.id, api.id, { label: 'flush' }));
    s.push(conn(api.id, db.id));
    s.push(conn(api.id, app.id, { dashed: true, style: 'bezier' }));

    // notes
    s.push(sticky(-420, -110, 'Decision: ship offline sync behind a flag for the beta cohort first. — eng sync 6/18', C.paperY));
    s.push(Object.assign(sticky(-420, 70, 'Risk: conflict resolution on multi-device edits. Need a merge strategy doc.', C.paperP), { w: 200 }));
    s.push(sticky(720, 380, 'Question: do we need a CDN in front of the Sync API for the EU region?', C.paperB));

    // a freehand circle around the risky note (hand annotation)
    s.push(freehandLoop(-440, 60, 230, 170));

    return { name: 'Atlas Mobile — Q3 Release Plan', shapes: s };
  }

  /* a hand-drawn-ish loop made of pen points */
  function freehandLoop(x, y, w, h) {
    const pts = [];
    const cx = x + w / 2, cy = y + h / 2, rx = w / 2, ry = h / 2;
    for (let a = 0; a <= Math.PI * 2 + 0.3; a += 0.25) {
      const wob = 1 + Math.sin(a * 3) * 0.03;
      pts.push({ x: cx + Math.cos(a) * rx * wob, y: cy + Math.sin(a) * ry * wob });
    }
    return { id: id('pen'), type: 'pen', points: pts, stroke: C.roseB, strokeW: 3 };
  }

  /* -----------------------------------------------------------
     TEMPLATES
  ----------------------------------------------------------- */
  function kanban() {
    const s = [];
    s.push(text(-200, -200, 'Sprint Board', 30));
    const cols = [
      ['Backlog', '#f1f5f9', '#94a3b8'],
      ['In Progress', C.sky, C.skyB],
      ['Review', C.amber, C.amberB],
      ['Done', C.mint, C.mintB],
    ];
    cols.forEach(([title, fill, stroke], i) => {
      const x = -200 + i * 250;
      s.push(Object.assign(rect(x, -150, 220, 420, 'rgba(255,255,255,0.6)', C.line, ''), { radius: 16, strokeW: 1.5 }));
      s.push(Object.assign(text(x + 12, -140, title, 18), { fill: stroke }));
    });
    const card = (col, row, t, fill) => sticky(-190 + col * 250, -90 + row * 95, t, fill);
    s.push(card(0, 0, 'Audit color contrast', C.paperB));
    s.push(card(0, 1, 'Write API docs', C.paperY));
    s.push(card(0, 2, 'Spike: search ranking', C.paperP));
    s.push(card(1, 0, 'Checkout redesign', C.paperG));
    s.push(card(1, 1, 'Fix iOS keyboard bug', C.paperY));
    s.push(card(2, 0, 'Settings page QA', C.paperB));
    s.push(card(3, 0, 'Empty states', C.paperG));
    s.push(card(3, 1, 'Analytics events', C.paperG));
    return { name: 'Sprint Board (Kanban)', shapes: s };
  }

  function mindMap() {
    const s = [];
    const core = ellipse(-90, -45, 200, 90, C.violet, C.violetB, 'Product Vision');
    s.push(core);
    const branches = [
      ['Users', C.sky, C.skyB,   -360, -220],
      ['Growth', C.mint, C.mintB, 220, -220],
      ['Platform', C.amber, C.amberB, -360, 180],
      ['Brand', C.rose, C.roseB,  220, 180],
    ];
    branches.forEach(([label, fill, stroke, x, y]) => {
      const b = rect(x, y, 170, 60, fill, stroke, label);
      s.push(b);
      s.push(conn(core.id, b.id, { style: 'bezier' }));
      // two leaves per branch
      for (let i = 0; i < 2; i++) {
        const lx = x + (x < 0 ? -10 : 180) ;
        const ly = y + (i === 0 ? -70 : 80);
        const leaf = Object.assign(sticky(lx, ly, leafText(label, i)), { w: 150, h: 90 });
        s.push(leaf);
        s.push(conn(b.id, leaf.id, { style: 'bezier', arrow: false, stroke: stroke }));
      }
    });
    return { name: 'Product Vision (Mind Map)', shapes: s };
  }
  function leafText(branch, i) {
    const map = {
      Users:    ['Personas', 'Jobs-to-be-done'],
      Growth:   ['Referral loop', 'Activation'],
      Platform: ['API', 'Webhooks'],
      Brand:    ['Voice & tone', 'Identity'],
    };
    return (map[branch] || ['Idea', 'Idea'])[i];
  }

  function flowchart() {
    const s = [];
    s.push(text(-120, -250, 'Login Flow', 30));
    const start = ellipse(-60, -180, 150, 64, C.mint, C.mintB, 'Start');
    const form  = rect(-80, -80, 190, 64, C.sky, C.skyB, 'Enter credentials');
    const check = Object.assign(rect(-70, 30, 170, 80, C.amber, C.amberB, 'Valid?'), { radius: 4 });
    const home  = rect(-260, 170, 170, 64, C.violet, C.violetB, 'Dashboard');
    const err   = rect(140, 170, 170, 64, C.rose, C.roseB, 'Show error');
    const end   = ellipse(-225, 280, 100, 56, '#e2e8f0', C.slate, 'End');
    s.push(start, form, check, home, err, end);
    s.push(conn(start.id, form.id));
    s.push(conn(form.id, check.id));
    s.push(conn(check.id, home.id, { label: 'yes' }));
    s.push(conn(check.id, err.id, { label: 'no', dashed: true }));
    s.push(conn(err.id, form.id, { style: 'bezier', label: 'retry' }));
    s.push(conn(home.id, end.id));
    return { name: 'Login Flow (Flowchart)', shapes: s };
  }

  const templates = {
    roadmap: { title: 'Product Roadmap', build: defaultBoard },
    kanban:  { title: 'Kanban Sprint Board', build: kanban },
    mindmap: { title: 'Mind Map', build: mindMap },
    flowchart:{ title: 'Flowchart', build: flowchart },
  };

  return { defaultBoard, kanban, mindMap, flowchart, templates, palette: C };
})();

if (typeof window !== 'undefined') window.Seed = Seed;
