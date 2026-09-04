/* =========================================================
   FLUXNODE — seed.js
   Meaningful starter graphs. Each is a plain document object
   ({ name, nodes, edges }) that Graph.fromJSON can load. These
   are real, working pipelines — not blank canvases.
   ========================================================= */
'use strict';

const Seeds = (() => {

  /* helper to make an edge record */
  const E = (fn, fp, tn, tp) => ({ id: 'e_' + fn + fp + '_' + tn + tp, from: { node: fn, port: fp }, to: { node: tn, port: tp } });

  /* -------- DEFAULT: "Sunset Dunes" — gradient warped by noise, thresholded, output -------- */
  const sunset = {
    name: 'Sunset Dunes',
    nodes: [
      { id: 'n_grad', type: 'gradient', x: 60,  y: 120, params: { a: '#241640', b: '#ff7a59', angle: 70 } },
      { id: 'n_noise', type: 'noise',   x: 60,  y: 360, params: { scale: 7, oct: 5, seed: 21 } },
      { id: 'n_warp', type: 'warp',     x: 360, y: 130, params: { amount: 0.12, scale: 4, flow: 0.15 } },
      { id: 'n_mix',  type: 'mix',      x: 360, y: 360, params: { fac: 0.35 } },
      { id: 'n_thr',  type: 'threshold',x: 660, y: 300, params: { level: 0.52, soft: 0.08, lo: '#1a1030', hi: '#ffd07a' } },
      { id: 'n_tint', type: 'tint',     x: 660, y: 90,  params: { hue: 8, sat: 1.15, bri: 1.05 } },
      { id: 'n_out',  type: 'output',   x: 960, y: 200, params: { gamma: 1.05, vignette: 0.35 } },
    ],
    edges: [
      E('n_grad', 'out', 'n_warp', 'in'),
      E('n_warp', 'out', 'n_mix', 'a'),
      E('n_noise', 'out', 'n_mix', 'b'),
      E('n_warp', 'out', 'n_tint', 'in'),
      E('n_mix', 'out', 'n_thr', 'in'),
      E('n_tint', 'out', 'n_out', 'in'),
    ],
  };

  /* -------- "Circuit Weave" — checker × rings, posterized -------- */
  const circuit = {
    name: 'Circuit Weave',
    nodes: [
      { id: 'n_chk',  type: 'checker', x: 60,  y: 110, params: { tiles: 10, a: '#04131a', b: '#00d3a7' } },
      { id: 'n_ring', type: 'rings',   x: 60,  y: 360, params: { freq: 16, a: '#00d3a7', b: '#062b22' } },
      { id: 'n_mul',  type: 'multiply',x: 380, y: 230, params: { mode: 'screen' } },
      { id: 'n_post', type: 'posterize',x: 680, y: 230, params: { steps: 4 } },
      { id: 'n_out',  type: 'output',  x: 980, y: 230, params: { gamma: 1, vignette: 0.15 } },
    ],
    edges: [
      E('n_chk', 'out', 'n_mul', 'a'),
      E('n_ring', 'out', 'n_mul', 'b'),
      E('n_mul', 'out', 'n_post', 'in'),
      E('n_post', 'out', 'n_out', 'in'),
    ],
  };

  /* -------- "Aurora Flow" — animated warp + tint, driven by a Math sine node -------- */
  const aurora = {
    name: 'Aurora Flow',
    nodes: [
      { id: 'n_g1', type: 'gradient', x: 60,  y: 90,  params: { a: '#001b2e', b: '#3fb950', angle: 100 } },
      { id: 'n_g2', type: 'gradient', x: 60,  y: 330, params: { a: '#7c5cff', b: '#00d3a7', angle: 20 } },
      { id: 'n_val',type: 'value',    x: 60,  y: 560, params: { v: 0.4 } },
      { id: 'n_sin',type: 'math',     x: 300, y: 560, params: { op: 'sine', a: 0.6, b: 0 } },
      { id: 'n_mix',type: 'mix',      x: 360, y: 180, params: { fac: 0.5 } },
      { id: 'n_warp',type: 'warp',    x: 660, y: 180, params: { amount: 0.16, scale: 3, flow: 0.6 } },
      { id: 'n_tint',type: 'tint',    x: 660, y: 420, params: { hue: -20, sat: 1.3, bri: 1.1 } },
      { id: 'n_out',type: 'output',   x: 980, y: 280, params: { gamma: 1.1, vignette: 0.4 } },
    ],
    edges: [
      E('n_g1', 'out', 'n_mix', 'a'),
      E('n_g2', 'out', 'n_mix', 'b'),
      E('n_val', 'out', 'n_sin', 'a'),
      E('n_sin', 'out', 'n_mix', 'f'),
      E('n_mix', 'out', 'n_warp', 'in'),
      E('n_warp', 'out', 'n_tint', 'in'),
      E('n_tint', 'out', 'n_out', 'in'),
    ],
  };

  /* -------- "Topographic" — fbm noise → threshold contour lines -------- */
  const topo = {
    name: 'Topographic Map',
    nodes: [
      { id: 'n_n', type: 'noise',   x: 60,  y: 160, params: { scale: 5, oct: 5, seed: 44 } },
      { id: 'n_p', type: 'posterize',x: 360, y: 160, params: { steps: 8 } },
      { id: 'n_t', type: 'threshold',x: 360, y: 380, params: { level: 0.5, soft: 0.02, lo: '#0a1f1a', hi: '#9ef0c8' } },
      { id: 'n_m', type: 'multiply', x: 660, y: 260, params: { mode: 'add' } },
      { id: 'n_out',type: 'output',  x: 960, y: 260, params: { gamma: 0.95, vignette: 0.25 } },
    ],
    edges: [
      E('n_n', 'out', 'n_p', 'in'),
      E('n_n', 'out', 'n_t', 'in'),
      E('n_p', 'out', 'n_m', 'a'),
      E('n_t', 'out', 'n_m', 'b'),
      E('n_m', 'out', 'n_out', 'in'),
    ],
  };

  const ALL = { sunset, circuit, aurora, topo };

  /* deep clone so loads don't share references */
  function get(key) {
    const d = ALL[key];
    return d ? JSON.parse(JSON.stringify(d)) : null;
  }

  return { get, ALL, keys: Object.keys(ALL) };
})();

if (typeof window !== 'undefined') window.Seeds = Seeds;
