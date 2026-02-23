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

// packages/theme/src/color-ramps/lib/utils.ts
var utils_exports = {};
__export(utils_exports, {
  adjustContrastTarget: () => adjustContrastTarget,
  clampAccentScaleReferenceLightness: () => clampAccentScaleReferenceLightness,
  computeBetterFgColorDirection: () => computeBetterFgColorDirection,
  solveWithBisect: () => solveWithBisect,
  sortByDependency: () => sortByDependency,
  stepsForStep: () => stepsForStep
});
module.exports = __toCommonJS(utils_exports);
var import_register_color_spaces = require("./register-color-spaces.cjs");
var import_constants = require("./constants.cjs");
var import_color_utils = require("./color-utils.cjs");
function buildDependencyGraph(config) {
  const dependencies = /* @__PURE__ */ new Map();
  const dependents = /* @__PURE__ */ new Map();
  Object.keys(config).forEach((step) => {
    dependencies.set(step, []);
  });
  dependents.set("seed", []);
  Object.keys(config).forEach((step) => {
    dependents.set(step, []);
  });
  Object.entries(config).forEach(([stepName, stepConfig]) => {
    const step = stepName;
    const reference = stepConfig.contrast.reference;
    dependencies.get(step).push(reference);
    dependents.get(reference).push(step);
    if (stepConfig.sameAsIfPossible) {
      dependencies.get(step).push(stepConfig.sameAsIfPossible);
      dependents.get(stepConfig.sameAsIfPossible).push(step);
    }
  });
  return { dependencies, dependents };
}
function sortByDependency(config) {
  const { dependents } = buildDependencyGraph(config);
  const result = [];
  const visited = /* @__PURE__ */ new Set();
  const visiting = /* @__PURE__ */ new Set();
  function visit(node) {
    if (visiting.has(node)) {
      throw new Error(
        `Circular dependency detected involving step: ${String(
          node
        )}`
      );
    }
    if (visited.has(node)) {
      return;
    }
    visiting.add(node);
    const nodeDependents = dependents.get(node) || [];
    nodeDependents.forEach((dependent) => {
      visit(dependent);
    });
    visiting.delete(node);
    visited.add(node);
    if (node !== "seed") {
      result.unshift(node);
    }
  }
  visit("seed");
  return result;
}
function stepsForStep(stepName, config) {
  const result = /* @__PURE__ */ new Set();
  function visit(step) {
    if (step === "seed" || result.has(step)) {
      return;
    }
    const stepConfig = config[step];
    if (!stepConfig) {
      return;
    }
    visit(stepConfig.contrast.reference);
    if (stepConfig.sameAsIfPossible) {
      visit(stepConfig.sameAsIfPossible);
    }
    result.add(step);
  }
  visit(stepName);
  return Array.from(result);
}
function computeBetterFgColorDirection(seed, preferLighter) {
  const contrastAgainstBlack = (0, import_color_utils.getContrast)(seed, import_constants.BLACK);
  const contrastAgainstWhite = (0, import_color_utils.getContrast)(seed, import_constants.WHITE);
  return contrastAgainstBlack > contrastAgainstWhite + (preferLighter ? import_constants.WHITE_TEXT_CONTRAST_MARGIN : 0) ? { better: "darker", worse: "lighter" } : { better: "lighter", worse: "darker" };
}
function adjustContrastTarget(target) {
  if (target === 1) {
    return 1;
  }
  return target + import_constants.UNIVERSAL_CONTRAST_TOPUP;
}
function clampAccentScaleReferenceLightness(rawLightness, direction) {
  const thresholds = import_constants.ACCENT_SCALE_BASE_LIGHTNESS_THRESHOLDS[direction];
  return Math.max(thresholds.min, Math.min(thresholds.max, rawLightness));
}
function solveWithBisect(calculateC, calculateValue, initLowerL, initLowerValue, initUpperL, initUpperValue) {
  let lowerL = initLowerL;
  let lowerValue = initLowerValue;
  let lowerReplaced = false;
  let upperL = initUpperL;
  let upperValue = initUpperValue;
  let upperReplaced = false;
  let bestC;
  let bestValue;
  let iterations = 0;
  while (true) {
    iterations++;
    const newL = (lowerL * upperValue - upperL * lowerValue) / (upperValue - lowerValue);
    bestC = calculateC(newL);
    bestValue = calculateValue(bestC);
    if (Math.abs(bestValue) <= import_constants.CONTRAST_EPSILON || iterations >= import_constants.MAX_BISECTION_ITERATIONS) {
      break;
    }
    if (bestValue <= 0) {
      lowerL = newL;
      lowerValue = bestValue;
      if (lowerReplaced) {
        upperValue /= 2;
      }
      lowerReplaced = true;
      upperReplaced = false;
    } else {
      upperL = newL;
      upperValue = bestValue;
      if (upperReplaced) {
        lowerValue /= 2;
      }
      upperReplaced = true;
      lowerReplaced = false;
    }
  }
  return bestC;
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  adjustContrastTarget,
  clampAccentScaleReferenceLightness,
  computeBetterFgColorDirection,
  solveWithBisect,
  sortByDependency,
  stepsForStep
});
//# sourceMappingURL=utils.cjs.map
