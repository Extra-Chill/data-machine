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

// packages/theme/src/color-ramps/lib/index.ts
var lib_exports = {};
__export(lib_exports, {
  buildRamp: () => buildRamp
});
module.exports = __toCommonJS(lib_exports);
var import_fn = require("colorjs.io/fn");
var import_register_color_spaces = require("./register-color-spaces.cjs");
var import_color_utils = require("./color-utils.cjs");
var import_find_color_with_constraints = require("./find-color-with-constraints.cjs");
var import_utils = require("./utils.cjs");
var import_constants = require("./constants.cjs");
function calculateRamp({
  seed,
  sortedSteps,
  config,
  mainDir,
  oppDir,
  pinLightness
}) {
  const rampResults = {};
  let warnings;
  let maxDeficit = -Infinity;
  let maxDeficitDirection = "lighter";
  let maxDeficitStep;
  const calculatedColors = /* @__PURE__ */ new Map();
  calculatedColors.set("seed", seed);
  for (const stepName of sortedSteps) {
    let computeDirection = function(color, followDirection) {
      if (followDirection === "main") {
        return mainDir;
      }
      if (followDirection === "opposite") {
        return oppDir;
      }
      if (followDirection === "best") {
        return (0, import_utils.computeBetterFgColorDirection)(
          color,
          contrast.preferLighter
        ).better;
      }
      return followDirection;
    };
    const {
      contrast,
      lightness: stepLightnessConstraint,
      taperChromaOptions,
      sameAsIfPossible
    } = config[stepName];
    const referenceColor = calculatedColors.get(contrast.reference);
    if (!referenceColor) {
      throw new Error(
        `Reference color for step ${stepName} not found: ${contrast.reference}`
      );
    }
    if (sameAsIfPossible) {
      const candidateColor = calculatedColors.get(sameAsIfPossible);
      if (!candidateColor) {
        throw new Error(
          `Same-as color for step ${stepName} not found: ${sameAsIfPossible}`
        );
      }
      const candidateContrast = (0, import_color_utils.getContrast)(
        referenceColor,
        candidateColor
      );
      const adjustedTarget2 = (0, import_utils.adjustContrastTarget)(contrast.target);
      if (candidateContrast >= adjustedTarget2) {
        calculatedColors.set(stepName, candidateColor);
        rampResults[stepName] = (0, import_color_utils.getColorString)(candidateColor);
        continue;
      }
    }
    const computedDir = computeDirection(
      referenceColor,
      contrast.followDirection
    );
    const adjustedTarget = (0, import_utils.adjustContrastTarget)(contrast.target);
    let lightnessConstraint;
    if (pinLightness?.stepName === stepName) {
      lightnessConstraint = {
        value: pinLightness.value,
        type: "force"
      };
    } else if (stepLightnessConstraint) {
      lightnessConstraint = {
        value: stepLightnessConstraint(computedDir),
        type: "onlyIfSucceeds"
      };
    }
    const searchResults = (0, import_find_color_with_constraints.findColorMeetingRequirements)(
      referenceColor,
      seed,
      adjustedTarget,
      computedDir,
      {
        lightnessConstraint,
        taperChromaOptions
      }
    );
    if (!contrast.ignoreWhenAdjustingSeed && searchResults.deficit && searchResults.deficit > maxDeficit) {
      maxDeficit = searchResults.deficit;
      maxDeficitDirection = computedDir;
      maxDeficitStep = stepName;
    }
    calculatedColors.set(stepName, searchResults.color);
    rampResults[stepName] = (0, import_color_utils.getColorString)(searchResults.color);
    if (!searchResults.reached && !contrast.ignoreWhenAdjustingSeed) {
      warnings ??= [];
      warnings.push(stepName);
    }
  }
  return {
    rampResults,
    warnings,
    maxDeficit,
    maxDeficitDirection,
    maxDeficitStep
  };
}
function buildRamp(seedArg, config, {
  mainDirection,
  pinLightness,
  rescaleToFitContrastTargets = true
} = {}) {
  let seed;
  try {
    seed = (0, import_color_utils.clampToGamut)(seedArg);
  } catch (error) {
    throw new Error(
      `Invalid seed color "${seedArg}": ${error instanceof Error ? error.message : "Unknown error"}`
    );
  }
  let mainDir = "lighter";
  let oppDir = "darker";
  if (mainDirection) {
    mainDir = mainDirection;
    oppDir = mainDirection === "darker" ? "lighter" : "darker";
  } else {
    const { better, worse } = (0, import_utils.computeBetterFgColorDirection)(seed);
    mainDir = better;
    oppDir = worse;
  }
  const sortedSteps = (0, import_utils.sortByDependency)(config);
  const {
    rampResults,
    warnings,
    maxDeficit,
    maxDeficitDirection,
    maxDeficitStep
  } = calculateRamp({
    seed,
    sortedSteps,
    config,
    mainDir,
    oppDir,
    pinLightness
  });
  let bestRamp = rampResults;
  if (maxDeficit > import_constants.CONTRAST_EPSILON && rescaleToFitContrastTargets) {
    let getSeedForL = function(l) {
      return (0, import_color_utils.clampToGamut)((0, import_fn.set)((0, import_fn.clone)(seed), [import_fn.OKLCH, "l"], l));
    }, getDeficitForSeed = function(s) {
      const iterationResults = calculateRamp({
        seed: s,
        sortedSteps: iterSteps,
        config,
        mainDir,
        oppDir,
        pinLightness
      });
      return iterationResults.maxDeficitDirection === maxDeficitDirection ? iterationResults.maxDeficit : -maxDeficit;
    };
    const iterSteps = (0, import_utils.stepsForStep)(maxDeficitStep, config);
    const lowerSeedL = maxDeficitDirection === "lighter" ? 0 : 1;
    const lowerDeficit = -maxDeficit;
    const upperSeedL = (0, import_fn.get)(seed, [import_fn.OKLCH, "l"]);
    const upperDeficit = maxDeficit;
    const bestSeed = (0, import_utils.solveWithBisect)(
      getSeedForL,
      getDeficitForSeed,
      lowerSeedL,
      lowerDeficit,
      upperSeedL,
      upperDeficit
    );
    bestRamp = calculateRamp({
      seed: bestSeed,
      sortedSteps,
      config,
      mainDir,
      oppDir,
      pinLightness
    }).rampResults;
  }
  if (mainDir === "darker") {
    const tmpSurface1 = bestRamp.surface1;
    bestRamp.surface1 = bestRamp.surface3;
    bestRamp.surface3 = tmpSurface1;
  }
  return {
    ramp: bestRamp,
    warnings,
    direction: mainDir
  };
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  buildRamp
});
//# sourceMappingURL=index.cjs.map
