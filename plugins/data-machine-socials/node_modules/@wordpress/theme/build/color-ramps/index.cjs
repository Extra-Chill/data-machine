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

// packages/theme/src/color-ramps/index.ts
var color_ramps_exports = {};
__export(color_ramps_exports, {
  DEFAULT_SEED_COLORS: () => import_constants2.DEFAULT_SEED_COLORS,
  buildAccentRamp: () => buildAccentRamp,
  buildBgRamp: () => buildBgRamp,
  checkAccessibleCombinations: () => checkAccessibleCombinations
});
module.exports = __toCommonJS(color_ramps_exports);
var import_fn = require("colorjs.io/fn");
var import_register_color_spaces = require("./lib/register-color-spaces.cjs");
var import_lib = require("./lib/index.cjs");
var import_utils = require("./lib/utils.cjs");
var import_ramp_configs = require("./lib/ramp-configs.cjs");
var import_color_utils = require("./lib/color-utils.cjs");
var import_constants = require("./lib/constants.cjs");
var import_constants2 = require("./lib/constants.cjs");
function buildBgRamp(seed) {
  if (typeof seed !== "string" || seed.trim() === "") {
    throw new Error("Seed color must be a non-empty string");
  }
  return (0, import_lib.buildRamp)(seed, import_ramp_configs.BG_RAMP_CONFIG);
}
var STEP_TO_PIN = "surface2";
function getBgRampInfo(ramp) {
  return {
    mainDirection: ramp.direction,
    pinLightness: {
      stepName: STEP_TO_PIN,
      value: (0, import_utils.clampAccentScaleReferenceLightness)(
        (0, import_fn.get)(ramp.ramp[STEP_TO_PIN], [import_fn.OKLCH, "l"]),
        ramp.direction
      )
    }
  };
}
function buildAccentRamp(seed, bgRamp) {
  if (typeof seed !== "string" || seed.trim() === "") {
    throw new Error("Seed color must be a non-empty string");
  }
  const bgRampInfo = bgRamp ? getBgRampInfo(bgRamp) : void 0;
  return (0, import_lib.buildRamp)(seed, import_ramp_configs.ACCENT_RAMP_CONFIG, bgRampInfo);
}
function checkAccessibleCombinations({
  bgRamp,
  accentRamps = []
}) {
  const unmetTargets = [];
  [bgRamp, ...accentRamps].forEach((ramp) => {
    import_constants.CONTRAST_COMBINATIONS.forEach(({ bgs, fgs, target }) => {
      for (const bg of bgs) {
        for (const fg of fgs) {
          const bgColor = ramp.ramp[bg];
          const fgColor = ramp.ramp[fg];
          const achievedContrast = (0, import_color_utils.getContrast)(bgColor, fgColor);
          if (achievedContrast < target) {
            unmetTargets.push({
              bgName: bg,
              bgColor,
              fgName: fg,
              fgColor,
              unmetContrast: target,
              achievedContrast
            });
          }
        }
      }
    });
  });
  accentRamps.forEach((ramp) => {
    import_constants.CONTRAST_COMBINATIONS.forEach(({ bgs, fgs, target }) => {
      for (const bg of bgs) {
        for (const fg of fgs) {
          const bgColor = bgRamp.ramp[bg];
          const fgColor = ramp.ramp[fg];
          const achievedContrast = (0, import_color_utils.getContrast)(bgColor, fgColor);
          if (achievedContrast < target) {
            unmetTargets.push({
              bgName: bg,
              bgColor,
              fgName: fg,
              fgColor,
              unmetContrast: target,
              achievedContrast
            });
          }
        }
      }
    });
  });
  return unmetTargets;
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  DEFAULT_SEED_COLORS,
  buildAccentRamp,
  buildBgRamp,
  checkAccessibleCombinations
});
//# sourceMappingURL=index.cjs.map
