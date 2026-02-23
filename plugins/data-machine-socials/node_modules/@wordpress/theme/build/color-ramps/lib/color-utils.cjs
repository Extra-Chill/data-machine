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

// packages/theme/src/color-ramps/lib/color-utils.ts
var color_utils_exports = {};
__export(color_utils_exports, {
  clampToGamut: () => clampToGamut,
  getColorString: () => getColorString,
  getContrast: () => getContrast
});
module.exports = __toCommonJS(color_utils_exports);
var import_fn = require("colorjs.io/fn");
var import_register_color_spaces = require("./register-color-spaces.cjs");
function getColorString(color) {
  const rgbRounded = (0, import_fn.serialize)((0, import_fn.to)(color, import_fn.sRGB));
  return (0, import_fn.serialize)(rgbRounded, { format: "hex" });
}
function getContrast(colorA, colorB) {
  return (0, import_fn.contrastWCAG21)(colorA, colorB);
}
function clampToGamut(c) {
  return (0, import_fn.to)((0, import_fn.toGamut)(c, { space: import_fn.sRGB, method: "css" }), import_fn.OKLCH);
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  clampToGamut,
  getColorString,
  getContrast
});
//# sourceMappingURL=color-utils.cjs.map
