// packages/theme/src/color-ramps/lib/index.ts
import {
  clone,
  get,
  OKLCH,
  set
} from "colorjs.io/fn";
import "./register-color-spaces.mjs";
import { clampToGamut, getContrast, getColorString } from "./color-utils.mjs";
import { findColorMeetingRequirements } from "./find-color-with-constraints.mjs";
import {
  sortByDependency,
  computeBetterFgColorDirection,
  adjustContrastTarget,
  stepsForStep,
  solveWithBisect
} from "./utils.mjs";
import { CONTRAST_EPSILON } from "./constants.mjs";
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
        return computeBetterFgColorDirection(
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
      const candidateContrast = getContrast(
        referenceColor,
        candidateColor
      );
      const adjustedTarget2 = adjustContrastTarget(contrast.target);
      if (candidateContrast >= adjustedTarget2) {
        calculatedColors.set(stepName, candidateColor);
        rampResults[stepName] = getColorString(candidateColor);
        continue;
      }
    }
    const computedDir = computeDirection(
      referenceColor,
      contrast.followDirection
    );
    const adjustedTarget = adjustContrastTarget(contrast.target);
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
    const searchResults = findColorMeetingRequirements(
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
    rampResults[stepName] = getColorString(searchResults.color);
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
    seed = clampToGamut(seedArg);
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
    const { better, worse } = computeBetterFgColorDirection(seed);
    mainDir = better;
    oppDir = worse;
  }
  const sortedSteps = sortByDependency(config);
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
  if (maxDeficit > CONTRAST_EPSILON && rescaleToFitContrastTargets) {
    let getSeedForL = function(l) {
      return clampToGamut(set(clone(seed), [OKLCH, "l"], l));
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
    const iterSteps = stepsForStep(maxDeficitStep, config);
    const lowerSeedL = maxDeficitDirection === "lighter" ? 0 : 1;
    const lowerDeficit = -maxDeficit;
    const upperSeedL = get(seed, [OKLCH, "l"]);
    const upperDeficit = maxDeficit;
    const bestSeed = solveWithBisect(
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
export {
  buildRamp
};
//# sourceMappingURL=index.mjs.map
