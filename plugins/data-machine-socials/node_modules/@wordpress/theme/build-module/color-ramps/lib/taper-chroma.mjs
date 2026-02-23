// packages/theme/src/color-ramps/lib/taper-chroma.ts
import {
  get,
  toGamut,
  OKLCH,
  sRGB
} from "colorjs.io/fn";
import "./register-color-spaces.mjs";
function taperChroma(seed, lTarget, options = {}) {
  const gamut = options.gamut ?? sRGB;
  const alpha = options.alpha ?? 0.65;
  const carry = options.carry ?? 0.5;
  const cUpperBound = options.cUpperBound ?? 0.45;
  const radiusLight = options.radiusLight ?? 0.2;
  const radiusDark = options.radiusDark ?? 0.2;
  const kLight = options.kLight ?? 0.85;
  const kDark = options.kDark ?? 0.85;
  const achromaEpsilon = options.achromaEpsilon ?? 5e-3;
  const cSeed = Math.max(0, get(seed, [OKLCH, "c"]));
  let hSeed = get(seed, [OKLCH, "h"]);
  const chromaIsTiny = cSeed < achromaEpsilon;
  const hueIsInvalid = hSeed === null || !Number.isFinite(hSeed);
  if (chromaIsTiny || hueIsInvalid) {
    if (typeof options.hueFallback === "number") {
      hSeed = normalizeHue(options.hueFallback);
    } else {
      return {
        space: OKLCH,
        coords: [clamp01(lTarget), 0, 0],
        alpha: 1
      };
    }
  }
  const lSeed = clamp01(get(seed, [OKLCH, "l"]));
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
    space: OKLCH,
    coords: [l, cap, h],
    alpha: 1
  };
  const clamped = toGamut(probe, { space: gamutSpace, method: "css" });
  return get(clamped, [OKLCH, "c"]);
}
export {
  taperChroma
};
//# sourceMappingURL=taper-chroma.mjs.map
