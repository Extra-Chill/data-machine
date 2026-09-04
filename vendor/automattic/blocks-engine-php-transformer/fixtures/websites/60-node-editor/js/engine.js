/* =========================================================
   FLUXNODE — engine.js
   The dataflow core. A graph of NODES connected by typed EDGES.
   Each node declares input/output ports and an `evaluate` function
   that turns its parameters + upstream input values into output
   values. For "color" outputs the value is a SAMPLER: a pure
   function (x, y, t) -> [r,g,b] in 0..1, so a whole texture pipeline
   is just composed closures. Number outputs are plain scalars.

   The engine topologically sorts the graph (Kahn's algorithm),
   detecting cycles, then evaluates every node once in order.

   No DOM here — this module is pure logic and is reused by the
   editor, the examples-page thumbnails, and the seed generator.
   ========================================================= */
'use strict';

/* ---------- small math helpers (deterministic, no Math.random in eval) ---------- */
const FX = {
  clamp(v, a, b) { return v < a ? a : v > b ? b : v; },
  clamp01(v) { return v < 0 ? 0 : v > 1 ? 1 : v; },
  lerp(a, b, t) { return a + (b - a) * t; },
  smooth(t) { return t * t * (3 - 2 * t); },
  frac(v) { return v - Math.floor(v); },
  // cheap deterministic hash noise -> 0..1
  hash(x, y) {
    let h = Math.sin(x * 127.1 + y * 311.7) * 43758.5453;
    return h - Math.floor(h);
  },
  // value noise with bilinear smoothing
  noise(x, y) {
    const xi = Math.floor(x), yi = Math.floor(y);
    const xf = x - xi, yf = y - yi;
    const u = this.smooth(xf), v = this.smooth(yf);
    const a = this.hash(xi, yi),     b = this.hash(xi + 1, yi);
    const c = this.hash(xi, yi + 1), d = this.hash(xi + 1, yi + 1);
    return this.lerp(this.lerp(a, b, u), this.lerp(c, d, u), v);
  },
  // fractal brownian motion
  fbm(x, y, oct) {
    let amp = 0.5, freq = 1, sum = 0, norm = 0;
    for (let i = 0; i < oct; i++) {
      sum += amp * this.noise(x * freq, y * freq);
      norm += amp; amp *= 0.5; freq *= 2;
    }
    return sum / norm;
  },
  hexToRgb(hex) {
    const h = hex.replace('#', '');
    return [
      parseInt(h.slice(0, 2), 16) / 255,
      parseInt(h.slice(2, 4), 16) / 255,
      parseInt(h.slice(4, 6), 16) / 255,
    ];
  },
  rgbToHex(rgb) {
    const c = v => Math.round(this.clamp01(v) * 255).toString(16).padStart(2, '0');
    return '#' + c(rgb[0]) + c(rgb[1]) + c(rgb[2]);
  },
  // hsl (0..1 each) -> rgb (0..1)
  hsl(h, s, l) {
    h = this.frac(h);
    const a = s * Math.min(l, 1 - l);
    const f = n => {
      const k = (n + h * 12) % 12;
      return l - a * Math.max(-1, Math.min(k - 3, Math.min(9 - k, 1)));
    };
    return [f(0), f(8), f(4)];
  },
};

/* A constant black sampler used as a safe fallback for unconnected color inputs. */
const BLACK = () => [0, 0, 0];
function asSampler(v) { return typeof v === 'function' ? v : BLACK; }
function asNumber(v, d) { return typeof v === 'number' && isFinite(v) ? v : d; }

/* =========================================================
   NODE TYPE REGISTRY
   Each type: { key, name, group, color, kind, inputs, outputs,
                params: [{key,label,type,min,max,step,default,options}],
                evaluate(node, ins) -> { portKey: value } }
   `color` is the node head accent. `kind` flags special UI (output).
   ========================================================= */
const PORT = { COLOR: 'color', NUMBER: 'number' };

const NodeTypes = {};
function defType(t) { NodeTypes[t.key] = t; return t; }

/* ---- SOURCES ---- */

defType({
  key: 'gradient', name: 'Gradient', group: 'Sources', color: '#ffb454',
  inputs: [], outputs: [{ key: 'out', type: PORT.COLOR, label: 'Color' }],
  params: [
    { key: 'a', label: 'Start', type: 'color', default: '#1b2a4a' },
    { key: 'b', label: 'End',   type: 'color', default: '#ff7a59' },
    { key: 'angle', label: 'Angle', type: 'range', min: 0, max: 360, step: 1, default: 35, unit: '°' },
  ],
  evaluate(n) {
    const a = FX.hexToRgb(n.params.a), b = FX.hexToRgb(n.params.b);
    const rad = (n.params.angle * Math.PI) / 180;
    const dx = Math.cos(rad), dy = Math.sin(rad);
    return { out: (x, y) => {
      // x,y in 0..1 → projected onto gradient axis
      const t = FX.clamp01(((x - 0.5) * dx + (y - 0.5) * dy) + 0.5);
      return [FX.lerp(a[0], b[0], t), FX.lerp(a[1], b[1], t), FX.lerp(a[2], b[2], t)];
    }};
  },
});

defType({
  key: 'solid', name: 'Solid Color', group: 'Sources', color: '#ffb454',
  inputs: [], outputs: [{ key: 'out', type: PORT.COLOR, label: 'Color' }],
  params: [{ key: 'c', label: 'Color', type: 'color', default: '#7c5cff' }],
  evaluate(n) { const c = FX.hexToRgb(n.params.c); return { out: () => [c[0], c[1], c[2]] }; },
});

defType({
  key: 'noise', name: 'Noise Field', group: 'Sources', color: '#ffb454',
  inputs: [], outputs: [{ key: 'out', type: PORT.COLOR, label: 'Color' }],
  params: [
    { key: 'scale', label: 'Scale', type: 'range', min: 1, max: 24, step: 0.5, default: 6 },
    { key: 'oct', label: 'Octaves', type: 'range', min: 1, max: 6, step: 1, default: 4 },
    { key: 'seed', label: 'Seed', type: 'range', min: 0, max: 100, step: 1, default: 12 },
  ],
  evaluate(n) {
    const s = n.params.scale, oct = n.params.oct | 0, sd = n.params.seed * 13.3;
    return { out: (x, y) => {
      const v = FX.fbm(x * s + sd, y * s + sd, oct);
      return [v, v, v];
    }};
  },
});

defType({
  key: 'checker', name: 'Checker', group: 'Sources', color: '#ffb454',
  inputs: [], outputs: [{ key: 'out', type: PORT.COLOR, label: 'Color' }],
  params: [
    { key: 'tiles', label: 'Tiles', type: 'range', min: 1, max: 32, step: 1, default: 8 },
    { key: 'a', label: 'Color A', type: 'color', default: '#0d1117' },
    { key: 'b', label: 'Color B', type: 'color', default: '#e6edf3' },
  ],
  evaluate(n) {
    const t = n.params.tiles, a = FX.hexToRgb(n.params.a), b = FX.hexToRgb(n.params.b);
    return { out: (x, y) => {
      const cx = Math.floor(x * t), cy = Math.floor(y * t);
      return ((cx + cy) & 1) ? b : a;
    }};
  },
});

defType({
  key: 'rings', name: 'Radial Rings', group: 'Sources', color: '#ffb454',
  inputs: [], outputs: [{ key: 'out', type: PORT.COLOR, label: 'Color' }],
  params: [
    { key: 'freq', label: 'Frequency', type: 'range', min: 1, max: 40, step: 0.5, default: 12 },
    { key: 'a', label: 'Inner', type: 'color', default: '#00d3a7' },
    { key: 'b', label: 'Outer', type: 'color', default: '#0d1117' },
  ],
  evaluate(n) {
    const f = n.params.freq, a = FX.hexToRgb(n.params.a), b = FX.hexToRgb(n.params.b);
    return { out: (x, y) => {
      const d = Math.hypot(x - 0.5, y - 0.5);
      const t = 0.5 + 0.5 * Math.sin(d * f * Math.PI * 2);
      return [FX.lerp(a[0], b[0], t), FX.lerp(a[1], b[1], t), FX.lerp(a[2], b[2], t)];
    }};
  },
});

/* ---- NUMBER source ---- */
defType({
  key: 'value', name: 'Value', group: 'Numbers', color: '#4cc9f0',
  inputs: [], outputs: [{ key: 'out', type: PORT.NUMBER, label: 'n' }],
  params: [{ key: 'v', label: 'Value', type: 'number', min: -10, max: 10, step: 0.01, default: 0.5 }],
  evaluate(n) { return { out: asNumber(n.params.v, 0) }; },
});

/* ---- OPERATORS (color in → color out) ---- */

defType({
  key: 'mix', name: 'Mix', group: 'Operators', color: '#7c5cff',
  inputs: [
    { key: 'a', type: PORT.COLOR, label: 'A' },
    { key: 'b', type: PORT.COLOR, label: 'B' },
    { key: 'f', type: PORT.NUMBER, label: 'Fac' },
  ],
  outputs: [{ key: 'out', type: PORT.COLOR, label: 'Color' }],
  params: [{ key: 'fac', label: 'Factor', type: 'range', min: 0, max: 1, step: 0.01, default: 0.5 }],
  evaluate(n, ins) {
    const A = asSampler(ins.a), B = asSampler(ins.b);
    const fixed = n.params.fac, f = ins.f;
    return { out: (x, y, t) => {
      const m = FX.clamp01(asNumber(f, fixed));
      const ca = A(x, y, t), cb = B(x, y, t);
      return [FX.lerp(ca[0], cb[0], m), FX.lerp(ca[1], cb[1], m), FX.lerp(ca[2], cb[2], m)];
    }};
  },
});

defType({
  key: 'multiply', name: 'Multiply', group: 'Operators', color: '#7c5cff',
  inputs: [
    { key: 'a', type: PORT.COLOR, label: 'A' },
    { key: 'b', type: PORT.COLOR, label: 'B' },
  ],
  outputs: [{ key: 'out', type: PORT.COLOR, label: 'Color' }],
  params: [{ key: 'mode', label: 'Blend', type: 'select', options: ['multiply', 'screen', 'add', 'difference'], default: 'multiply' }],
  evaluate(n, ins) {
    const A = asSampler(ins.a), B = asSampler(ins.b), mode = n.params.mode;
    const blend = (p, q) => {
      switch (mode) {
        case 'screen': return 1 - (1 - p) * (1 - q);
        case 'add': return FX.clamp01(p + q);
        case 'difference': return Math.abs(p - q);
        default: return p * q;
      }
    };
    return { out: (x, y, t) => {
      const ca = A(x, y, t), cb = B(x, y, t);
      return [blend(ca[0], cb[0]), blend(ca[1], cb[1]), blend(ca[2], cb[2])];
    }};
  },
});

defType({
  key: 'tint', name: 'Tint / HSV', group: 'Operators', color: '#7c5cff',
  inputs: [{ key: 'in', type: PORT.COLOR, label: 'Color' }],
  outputs: [{ key: 'out', type: PORT.COLOR, label: 'Color' }],
  params: [
    { key: 'hue', label: 'Hue shift', type: 'range', min: -180, max: 180, step: 1, default: 0, unit: '°' },
    { key: 'sat', label: 'Saturation', type: 'range', min: 0, max: 2, step: 0.01, default: 1 },
    { key: 'bri', label: 'Brightness', type: 'range', min: 0, max: 2, step: 0.01, default: 1 },
  ],
  evaluate(n, ins) {
    const A = asSampler(ins.in);
    const hueShift = n.params.hue / 360, sat = n.params.sat, bri = n.params.bri;
    return { out: (x, y, t) => {
      let [r, g, b] = A(x, y, t);
      // rgb → hsl
      const mx = Math.max(r, g, b), mn = Math.min(r, g, b), l = (mx + mn) / 2;
      let h = 0, s = 0;
      if (mx !== mn) {
        const d = mx - mn;
        s = l > 0.5 ? d / (2 - mx - mn) : d / (mx + mn);
        if (mx === r) h = (g - b) / d + (g < b ? 6 : 0);
        else if (mx === g) h = (b - r) / d + 2;
        else h = (r - g) / d + 4;
        h /= 6;
      }
      const out = FX.hsl(h + hueShift, FX.clamp01(s * sat), FX.clamp01(l * bri));
      return out;
    }};
  },
});

defType({
  key: 'threshold', name: 'Threshold', group: 'Operators', color: '#7c5cff',
  inputs: [
    { key: 'in', type: PORT.COLOR, label: 'Color' },
    { key: 'lvl', type: PORT.NUMBER, label: 'Level' },
  ],
  outputs: [{ key: 'out', type: PORT.COLOR, label: 'Color' }],
  params: [
    { key: 'level', label: 'Level', type: 'range', min: 0, max: 1, step: 0.01, default: 0.5 },
    { key: 'soft', label: 'Softness', type: 'range', min: 0, max: 0.5, step: 0.01, default: 0.04 },
    { key: 'lo', label: 'Low', type: 'color', default: '#0d1117' },
    { key: 'hi', label: 'High', type: 'color', default: '#ffb454' },
  ],
  evaluate(n, ins) {
    const A = asSampler(ins.in), lo = FX.hexToRgb(n.params.lo), hi = FX.hexToRgb(n.params.hi);
    const lvlIn = ins.lvl, fixed = n.params.level, soft = Math.max(0.0001, n.params.soft);
    return { out: (x, y, t) => {
      const c = A(x, y, t);
      const lum = 0.299 * c[0] + 0.587 * c[1] + 0.114 * c[2];
      const lvl = FX.clamp01(asNumber(lvlIn, fixed));
      let f = FX.clamp01((lum - (lvl - soft)) / (2 * soft));
      f = FX.smooth(f);
      return [FX.lerp(lo[0], hi[0], f), FX.lerp(lo[1], hi[1], f), FX.lerp(lo[2], hi[2], f)];
    }};
  },
});

defType({
  key: 'warp', name: 'Warp', group: 'Operators', color: '#7c5cff',
  inputs: [{ key: 'in', type: PORT.COLOR, label: 'Color' }],
  outputs: [{ key: 'out', type: PORT.COLOR, label: 'Color' }],
  params: [
    { key: 'amount', label: 'Amount', type: 'range', min: 0, max: 0.4, step: 0.005, default: 0.08 },
    { key: 'scale', label: 'Scale', type: 'range', min: 1, max: 16, step: 0.5, default: 5 },
    { key: 'flow', label: 'Animate', type: 'range', min: 0, max: 1, step: 0.05, default: 0 },
  ],
  evaluate(n, ins) {
    const A = asSampler(ins.in), amt = n.params.amount, s = n.params.scale, flow = n.params.flow;
    return { out: (x, y, t) => {
      const ph = (t || 0) * flow;
      const ox = (FX.noise(x * s + ph, y * s) - 0.5) * 2 * amt;
      const oy = (FX.noise(x * s + 31.4, y * s + ph + 17.2) - 0.5) * 2 * amt;
      return A(x + ox, y + oy, t);
    }};
  },
});

defType({
  key: 'invert', name: 'Invert', group: 'Operators', color: '#7c5cff',
  inputs: [{ key: 'in', type: PORT.COLOR, label: 'Color' }],
  outputs: [{ key: 'out', type: PORT.COLOR, label: 'Color' }],
  params: [{ key: 'amt', label: 'Amount', type: 'range', min: 0, max: 1, step: 0.01, default: 1 }],
  evaluate(n, ins) {
    const A = asSampler(ins.in), amt = n.params.amt;
    return { out: (x, y, t) => {
      const c = A(x, y, t);
      return [FX.lerp(c[0], 1 - c[0], amt), FX.lerp(c[1], 1 - c[1], amt), FX.lerp(c[2], 1 - c[2], amt)];
    }};
  },
});

defType({
  key: 'posterize', name: 'Posterize', group: 'Operators', color: '#7c5cff',
  inputs: [{ key: 'in', type: PORT.COLOR, label: 'Color' }],
  outputs: [{ key: 'out', type: PORT.COLOR, label: 'Color' }],
  params: [{ key: 'steps', label: 'Steps', type: 'range', min: 2, max: 16, step: 1, default: 5 }],
  evaluate(n, ins) {
    const A = asSampler(ins.in), st = Math.max(2, n.params.steps | 0);
    const q = v => Math.round(v * (st - 1)) / (st - 1);
    return { out: (x, y, t) => { const c = A(x, y, t); return [q(c[0]), q(c[1]), q(c[2])]; } };
  },
});

/* ---- NUMBER math (number in → number out), great for driving Fac inputs ---- */
defType({
  key: 'math', name: 'Math', group: 'Numbers', color: '#4cc9f0',
  inputs: [
    { key: 'a', type: PORT.NUMBER, label: 'A' },
    { key: 'b', type: PORT.NUMBER, label: 'B' },
  ],
  outputs: [{ key: 'out', type: PORT.NUMBER, label: 'n' }],
  params: [
    { key: 'op', label: 'Op', type: 'select', options: ['add', 'subtract', 'multiply', 'min', 'max', 'sine'], default: 'multiply' },
    { key: 'a', label: 'A (if unwired)', type: 'number', min: -10, max: 10, step: 0.01, default: 0.5 },
    { key: 'b', label: 'B (if unwired)', type: 'number', min: -10, max: 10, step: 0.01, default: 0.5 },
  ],
  evaluate(n, ins) {
    const a = asNumber(ins.a, n.params.a), b = asNumber(ins.b, n.params.b);
    let r;
    switch (n.params.op) {
      case 'add': r = a + b; break;
      case 'subtract': r = a - b; break;
      case 'min': r = Math.min(a, b); break;
      case 'max': r = Math.max(a, b); break;
      case 'sine': r = 0.5 + 0.5 * Math.sin(a * Math.PI * 2 + b); break;
      default: r = a * b;
    }
    return { out: r };
  },
});

/* ---- OUTPUT ---- */
defType({
  key: 'output', name: 'Output', group: 'Output', color: '#00d3a7', kind: 'output',
  inputs: [{ key: 'in', type: PORT.COLOR, label: 'Image' }],
  outputs: [],
  params: [
    { key: 'gamma', label: 'Gamma', type: 'range', min: 0.4, max: 2.4, step: 0.05, default: 1 },
    { key: 'vignette', label: 'Vignette', type: 'range', min: 0, max: 1, step: 0.01, default: 0.2 },
  ],
  evaluate(n, ins) {
    const A = asSampler(ins.in), g = 1 / n.params.gamma, vig = n.params.vignette;
    // expose the final composed sampler so previews can render it
    n._sampler = (x, y, t) => {
      let c = A(x, y, t);
      const r = Math.pow(FX.clamp01(c[0]), g);
      const gg = Math.pow(FX.clamp01(c[1]), g);
      const b = Math.pow(FX.clamp01(c[2]), g);
      if (vig > 0) {
        const d = Math.hypot(x - 0.5, y - 0.5) * 1.414;
        const v = 1 - vig * d * d;
        return [r * v, gg * v, b * v];
      }
      return [r, gg, b];
    };
    return {};
  },
});

/* =========================================================
   GRAPH MODEL
   ========================================================= */
let _uid = 1;
function uid(prefix) { return prefix + (_uid++) + '_' + Math.floor(Math.random() * 1e4).toString(36); }

class Graph {
  constructor() {
    this.nodes = new Map();   // id -> node
    this.edges = new Map();   // id -> { id, from:{node,port}, to:{node,port} }
  }

  addNode(typeKey, x = 0, y = 0, params = null, id = null) {
    const T = NodeTypes[typeKey];
    if (!T) throw new Error('Unknown node type: ' + typeKey);
    const p = {};
    T.params.forEach(pm => { p[pm.key] = pm.default; });
    if (params) Object.assign(p, params);
    const node = { id: id || uid('n'), type: typeKey, x, y, params: p };
    this.nodes.set(node.id, node);
    return node;
  }

  removeNode(id) {
    this.nodes.delete(id);
    for (const [eid, e] of this.edges) {
      if (e.from.node === id || e.to.node === id) this.edges.delete(eid);
    }
  }

  /* Is there already an edge into this input port? (inputs accept one wire) */
  edgeInto(nodeId, portKey) {
    for (const e of this.edges.values()) {
      if (e.to.node === nodeId && e.to.port === portKey) return e;
    }
    return null;
  }

  /* Validate a proposed connection: type match + no duplicate + no cycle. */
  canConnect(from, to) {
    if (!from || !to) return { ok: false, reason: 'incomplete' };
    if (from.node === to.node) return { ok: false, reason: 'self' };
    const Tf = NodeTypes[this.nodes.get(from.node)?.type];
    const Tt = NodeTypes[this.nodes.get(to.node)?.type];
    if (!Tf || !Tt) return { ok: false, reason: 'missing' };
    const op = Tf.outputs.find(o => o.key === from.port);
    const ip = Tt.inputs.find(i => i.key === to.port);
    if (!op || !ip) return { ok: false, reason: 'no-port' };
    if (op.type !== ip.type) return { ok: false, reason: 'type' };
    if (this.wouldCycle(from.node, to.node)) return { ok: false, reason: 'cycle' };
    return { ok: true };
  }

  /* Would connecting source→target create a cycle? i.e. is `source`
     reachable from `target` following edges forward (target→…→source)? */
  wouldCycle(sourceNode, targetNode) {
    const stack = [targetNode];
    const seen = new Set();
    while (stack.length) {
      const cur = stack.pop();
      if (cur === sourceNode) return true;
      if (seen.has(cur)) continue;
      seen.add(cur);
      for (const e of this.edges.values()) {
        if (e.from.node === cur) stack.push(e.to.node);
      }
    }
    return false;
  }

  connect(from, to) {
    const check = this.canConnect(from, to);
    if (!check.ok) return { ok: false, reason: check.reason };
    // replace any existing wire into that input
    const existing = this.edgeInto(to.node, to.port);
    if (existing) this.edges.delete(existing.id);
    const edge = { id: uid('e'), from: { ...from }, to: { ...to } };
    this.edges.set(edge.id, edge);
    return { ok: true, edge };
  }

  disconnect(edgeId) { this.edges.delete(edgeId); }

  /* Kahn topological sort. Returns { order:[ids], cyclic:bool } */
  topoSort() {
    const indeg = new Map();
    const adj = new Map();
    for (const id of this.nodes.keys()) { indeg.set(id, 0); adj.set(id, []); }
    for (const e of this.edges.values()) {
      if (!this.nodes.has(e.from.node) || !this.nodes.has(e.to.node)) continue;
      adj.get(e.from.node).push(e.to.node);
      indeg.set(e.to.node, indeg.get(e.to.node) + 1);
    }
    const queue = [];
    for (const [id, d] of indeg) if (d === 0) queue.push(id);
    const order = [];
    while (queue.length) {
      const cur = queue.shift();
      order.push(cur);
      for (const nxt of adj.get(cur)) {
        indeg.set(nxt, indeg.get(nxt) - 1);
        if (indeg.get(nxt) === 0) queue.push(nxt);
      }
    }
    return { order, cyclic: order.length !== this.nodes.size };
  }

  /* Evaluate the whole graph. Populates a Map id -> {outputs, error}.
     Returns { values, order, cyclic, output } where output is the
     Output node's composed sampler (or null). */
  evaluate() {
    const { order, cyclic } = this.topoSort();
    const values = new Map();   // nodeId -> { port: value }
    let outputSampler = null;
    let outputNode = null;
    let errors = 0;

    for (const id of order) {
      const node = this.nodes.get(id);
      const T = NodeTypes[node.type];
      // gather inputs from incoming edges
      const ins = {};
      for (const e of this.edges.values()) {
        if (e.to.node !== id) continue;
        const up = values.get(e.from.node);
        if (up && up[e.from.port] !== undefined) ins[e.to.port] = up[e.from.port];
      }
      try {
        node._error = null;
        const out = T.evaluate(node, ins) || {};
        values.set(id, out);
        if (T.kind === 'output') { outputSampler = node._sampler; outputNode = node; }
      } catch (err) {
        node._error = err.message || 'eval error';
        errors++;
        values.set(id, {});
      }
    }
    return { values, order, cyclic, output: outputSampler, outputNode, errors };
  }

  /* ---------- serialization ---------- */
  toJSON() {
    return {
      nodes: [...this.nodes.values()].map(n => ({
        id: n.id, type: n.type, x: n.x, y: n.y, params: n.params,
      })),
      edges: [...this.edges.values()].map(e => ({
        id: e.id, from: e.from, to: e.to,
      })),
    };
  }

  static fromJSON(data) {
    const g = new Graph();
    if (!data || !Array.isArray(data.nodes)) return g;
    let maxN = 0;
    data.nodes.forEach(n => {
      if (!NodeTypes[n.type]) return;
      const T = NodeTypes[n.type];
      const params = {};
      T.params.forEach(pm => { params[pm.key] = pm.default; });
      Object.assign(params, n.params || {});
      g.nodes.set(n.id, { id: n.id, type: n.type, x: n.x || 0, y: n.y || 0, params });
      const m = /(\d+)/.exec(n.id); if (m) maxN = Math.max(maxN, +m[1]);
    });
    (data.edges || []).forEach(e => {
      if (!e.from || !e.to) return;
      if (!g.nodes.has(e.from.node) || !g.nodes.has(e.to.node)) return;
      g.edges.set(e.id || uid('e'), { id: e.id || uid('e'), from: e.from, to: e.to });
    });
    _uid = Math.max(_uid, maxN + 1);
    return g;
  }
}

/* render a sampler to an ImageData on a context — shared by previews + export */
function renderSampler(ctx, sampler, w, h, t = 0) {
  const img = ctx.createImageData(w, h);
  const d = img.data;
  let i = 0;
  for (let y = 0; y < h; y++) {
    const fy = (y + 0.5) / h;
    for (let x = 0; x < w; x++) {
      const fx = (x + 0.5) / w;
      const c = sampler ? sampler(fx, fy, t) : [0, 0, 0];
      d[i++] = FX.clamp01(c[0]) * 255;
      d[i++] = FX.clamp01(c[1]) * 255;
      d[i++] = FX.clamp01(c[2]) * 255;
      d[i++] = 255;
    }
  }
  ctx.putImageData(img, 0, 0);
}

if (typeof window !== 'undefined') {
  window.FX = FX;
  window.NodeTypes = NodeTypes;
  window.PORT = PORT;
  window.Graph = Graph;
  window.renderSampler = renderSampler;
  window.uid = uid;
}
