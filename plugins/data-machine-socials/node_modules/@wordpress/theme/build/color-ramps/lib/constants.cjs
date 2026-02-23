var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __getOwnPropNames = Object.getOwnPropertyNames;
var __hasOwnProp = Object.prototype.hasOwnProperty;
var __export = (target, all) => {
  for (var name in all)
    __defProp(target, name, { get: all[name], enumerable: true });
};
var __copyProps = (to2, from, except, desc) => {
  if (from && typeof from === "object" || typeof from === "function") {
    for (let key of __getOwnPropNames(from))
      if (!__hasOwnProp.call(to2, key) && key !== except)
        __defProp(to2, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
  }
  return to2;
};
var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);

// packages/theme/src/color-ramps/lib/constants.ts
var constants_exports = {};
__export(constants_exports, {
  ACCENT_SCALE_BASE_LIGHTNESS_THRESHOLDS: () => ACCENT_SCALE_BASE_LIGHTNESS_THRESHOLDS,
  BLACK: () => BLACK,
  CONTRAST_COMBINATIONS: () => CONTRAST_COMBINATIONS,
  CONTRAST_EPSILON: () => CONTRAST_EPSILON,
  DEFAULT_SEED_COLORS: () => DEFAULT_SEED_COLORS,
  MAX_BISECTION_ITERATIONS: () => MAX_BISECTION_ITERATIONS,
  UNIVERSAL_CONTRAST_TOPUP: () => UNIVERSAL_CONTRAST_TOPUP,
  WHITE: () => WHITE,
  WHITE_TEXT_CONTRAST_MARGIN: () => WHITE_TEXT_CONTRAST_MARGIN
});
module.exports = __toCommonJS(constants_exports);
var import_fn = require("colorjs.io/fn");
var import_register_color_spaces = require("./register-color-spaces.cjs");
var WHITE = (0, import_fn.to)("white", import_fn.OKLCH);
var BLACK = (0, import_fn.to)("black", import_fn.OKLCH);
var UNIVERSAL_CONTRAST_TOPUP = 0.02;
var WHITE_TEXT_CONTRAST_MARGIN = 3.1;
var ACCENT_SCALE_BASE_LIGHTNESS_THRESHOLDS = {
  lighter: { min: 0.2, max: 0.4 },
  darker: { min: 0.75, max: 0.98 }
};
var CONTRAST_EPSILON = 4e-3;
var MAX_BISECTION_ITERATIONS = 10;
var CONTRAST_COMBINATIONS = [
  {
    bgs: ["surface1", "surface2", "surface3"],
    fgs: ["fgSurface3", "fgSurface4"],
    target: 4.5
  },
  {
    bgs: ["surface4", "surface5"],
    fgs: ["fgSurface4"],
    target: 4.5
  },
  {
    bgs: ["bgFill1"],
    fgs: ["fgFill"],
    target: 4.5
  },
  {
    bgs: ["bgFillInverted1"],
    fgs: ["fgFillInverted"],
    target: 4.5
  },
  {
    bgs: ["bgFillInverted1"],
    fgs: ["fgFillInverted"],
    target: 4.5
  },
  {
    bgs: ["surface1", "surface2", "surface3"],
    fgs: ["stroke3"],
    target: 3
  }
];
var DEFAULT_SEED_COLORS = {
  bg: "#f8f8f8",
  primary: "#3858e9",
  info: "#0090ff",
  success: "#4ab866",
  caution: "#f0d149",
  warning: "#f0b849",
  error: "#cc1818"
};
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  ACCENT_SCALE_BASE_LIGHTNESS_THRESHOLDS,
  BLACK,
  CONTRAST_COMBINATIONS,
  CONTRAST_EPSILON,
  DEFAULT_SEED_COLORS,
  MAX_BISECTION_ITERATIONS,
  UNIVERSAL_CONTRAST_TOPUP,
  WHITE,
  WHITE_TEXT_CONTRAST_MARGIN
});
//# sourceMappingURL=constants.cjs.map
