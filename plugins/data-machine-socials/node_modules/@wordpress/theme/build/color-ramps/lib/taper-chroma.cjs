var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __getOwnPropNames = Object.getOwnPropertyNames;
var __hasOwnProp = Object.prototype.hasOwnProperty;
var __export = (target, all) => {
  for (var name in all)
    __defProp(target, name, { get: all[name], enumerable: true });
};
var __copyProps = (to, from, except, desc) => {
  if (from && typeof from === "object" || typeof from === "function") {
    for (let key of __getOwnPropNames(from))
      if (!__hasOwnProp.call(to, key) && key !== except)
        __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
  }
  return to;
};
var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);

// packages/theme/src/color-ramps/lib/taper-chroma.ts
var taper_chroma_exports = {};
__export(taper_chroma_exports, {
  taperChroma: () => taperChroma
});
module.exports = __toCommonJS(taper_chroma_exports);
var import_fn = require("colorjs.io/fn");
var import_register_color_spaces = require("./register-color-spaces.cjs");
function taperChroma(seed, lTarget, options = {}) {
  const gamut = options.gamut ?? import_fn.sRGB;
  const alpha = options.alpha ?? 0.65;
  const carry = options.carry ?? 0.5;
  const cUpperBound = options.cUpperBound ?? 0.45;
  const radiusLight = options.radiusLight ?? 0.2;
  const radiusDark = options.radiusDark ?? 0.2;
  const kLight = options.kLight ?? 0.85;
  const kDark = options.kDark ?? 0.85;
  const achromaEpsilon = options.achromaEpsilon ?? 5e-3;
  const cSeed = Math.max(0, (0, import_fn.get)(seed, [import_fn.OKLCH, "c"]));
  let hSeed = (0, import_fn.get)(seed, [import_fn.OKLCH, "h"]);
  const chromaIsTiny = cSeed < achromaEpsilon;
  const hueIsInvalid = hSeed === null || !Number.isFinite(hSeed);
  if (chromaIsTiny || hueIsInvalid) {
    if (typeof options.hueFallback === "number") {
      hSeed = normalizeHue(options.hueFallback);
    } else {
      return {
        space: import_fn.OKLCH,
        coords: [clamp01(lTarget), 0, 0],
        alpha: 1
      };
    }
  }
  const lSeed = clamp01((0, import_fn.get)(seed, [import_fn.OKLCH, "l"]));
  const cmaxSeed = getCachedMaxChromaAtLH(lSeed, hSeed, gamut, cUpperBound);
  const cmaxTarget = getCachedMaxChromaAtLH(
    clamp01(lTarget),
    hSeed,
    gamut,
    cUpperBound
  );
  let seedRelative = 0;
  const denom = cmaxSeed > 0 ? cmaxSeed : 1e-6;
  seedRelative = clamp01(cSeed / denom);
  const cIntendedBase = alpha * cmaxTarget;
  const cWithCarry = cIntendedBase * Math.pow(seedRelative, clamp01(carry));
  const t = continuousTaper(lSeed, lTarget, {
    radiusLight,
    radiusDark,
    kLight,
    kDark
  });
  const cPlanned = cWithCarry * t;
  const lOut = clamp01(lTarget);
  return { l: lOut, c: cPlanned };
}
function clamp01(x) {
  if (x < 0) {
    return 0;
  }
  if (x > 1) {
    return 1;
  }
  return x;
}
function normalizeHue(h) {
  let hue = h % 360;
  if (hue < 0) {
    hue += 360;
  }
  return hue;
}
function raisedCosine(u) {
  const x = clamp01(u);
  return 0.5 - 0.5 * Math.cos(Math.PI * x);
}
function continuousTaper(seedL, targetL, opts) {
  const d = targetL - seedL;
  if (d >= 0) {
    const u2 = opts.radiusLight > 0 ? Math.abs(d) / opts.radiusLight : 1;
    const w2 = raisedCosine(u2 > 1 ? 1 : u2);
    return 1 - (1 - opts.kLight) * w2;
  }
  const u = opts.radiusDark > 0 ? Math.abs(d) / opts.radiusDark : 1;
  const w = raisedCosine(u > 1 ? 1 : u);
  return 1 - (1 - opts.kDark) * w;
}
var maxChromaCache = /* @__PURE__ */ new Map();
function keyMax(l, h, gamut, cap) {
  const lq = quantize(l, 0.05);
  const hq = quantize(normalizeHue(h), 10);
  const cq = quantize(cap, 0.05);
  return `${gamut}|L:${lq}|H:${hq}|cap:${cq}`;
}
function quantize(x, step) {
  const k = Math.round(x / step);
  return k * step;
}
function getCachedMaxChromaAtLH(l, h, gamutSpace, cap) {
  const gamut = gamutSpace.id;
  const key = keyMax(l, h, gamut, cap);
  const hit = maxChromaCache.get(key);
  if (typeof hit === "number") {
    return hit;
  }
  const computed = maxInGamutChromaAtLH(l, h, gamutSpace, cap);
  maxChromaCache.set(key, computed);
  return computed;
}
function maxInGamutChromaAtLH(l, h, gamutSpace, cap) {
  const probe = {
    space: import_fn.OKLCH,
    coords: [l, cap, h],
    alpha: 1
  };
  const clamped = (0, import_fn.toGamut)(probe, { space: gamutSpace, method: "css" });
  return (0, import_fn.get)(clamped, [import_fn.OKLCH, "c"]);
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  taperChroma
});
//# sourceMappingURL=taper-chroma.cjs.map
