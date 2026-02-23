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

// packages/theme/src/color-ramps/lib/find-color-with-constraints.ts
var find_color_with_constraints_exports = {};
__export(find_color_with_constraints_exports, {
  findColorMeetingRequirements: () => findColorMeetingRequirements
});
module.exports = __toCommonJS(find_color_with_constraints_exports);
var import_fn = require("colorjs.io/fn");
var import_register_color_spaces = require("./register-color-spaces.cjs");
var import_utils = require("./utils.cjs");
var import_constants = require("./constants.cjs");
var import_color_utils = require("./color-utils.cjs");
var import_taper_chroma = require("./taper-chroma.cjs");
function cdiff(c1, c2) {
  return Math.log(c1 / c2);
}
function findColorMeetingRequirements(reference, seed, target, direction, {
  lightnessConstraint,
  taperChromaOptions
} = {}) {
  if (target <= 1) {
    return {
      color: reference,
      reached: true,
      achieved: 1
    };
  }
  function getColorForL(l) {
    let newL = l;
    let newC = (0, import_fn.get)(seed, [import_fn.OKLCH, "c"]);
    if (taperChromaOptions) {
      const tapered = (0, import_taper_chroma.taperChroma)(seed, newL, taperChromaOptions);
      if ("l" in tapered && "c" in tapered) {
        newL = tapered.l;
        newC = tapered.c;
      } else {
        return tapered;
      }
    }
    return (0, import_color_utils.clampToGamut)({
      spaceId: "oklch",
      coords: [newL, newC, (0, import_fn.get)(seed, [import_fn.OKLCH, "h"])]
    });
  }
  const mostContrastingL = direction === "lighter" ? 1 : 0;
  const mostContrastingColor = direction === "lighter" ? import_constants.WHITE : import_constants.BLACK;
  const highestContrast = (0, import_color_utils.getContrast)(reference, mostContrastingColor);
  if (lightnessConstraint) {
    const colorWithExactL = getColorForL(lightnessConstraint.value);
    const exactLContrast = (0, import_color_utils.getContrast)(reference, colorWithExactL);
    const exactLContrastMeetsTarget = cdiff(exactLContrast, target) >= -import_constants.CONTRAST_EPSILON;
    if (exactLContrastMeetsTarget || lightnessConstraint.type === "force") {
      return {
        color: colorWithExactL,
        reached: exactLContrastMeetsTarget,
        achieved: exactLContrast,
        deficit: exactLContrastMeetsTarget ? cdiff(exactLContrast, highestContrast) : cdiff(target, exactLContrast)
      };
    }
  }
  if (cdiff(highestContrast, target) <= import_constants.CONTRAST_EPSILON) {
    return {
      color: mostContrastingColor,
      reached: cdiff(highestContrast, target) >= -import_constants.CONTRAST_EPSILON,
      achieved: highestContrast,
      deficit: cdiff(target, highestContrast)
    };
  }
  const lowerL = (0, import_fn.get)(reference, [import_fn.OKLCH, "l"]);
  const lowerContrast = cdiff(1, target);
  const upperL = mostContrastingL;
  const upperContrast = cdiff(highestContrast, target);
  const bestColor = (0, import_utils.solveWithBisect)(
    getColorForL,
    (c) => cdiff((0, import_color_utils.getContrast)(reference, c), target),
    lowerL,
    lowerContrast,
    upperL,
    upperContrast
  );
  return {
    color: bestColor,
    reached: true,
    achieved: target,
    // Negative number that specifies how much room we have.
    deficit: cdiff(target, highestContrast)
  };
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  findColorMeetingRequirements
});
//# sourceMappingURL=find-color-with-constraints.cjs.map
