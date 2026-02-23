// packages/theme/src/color-ramps/lib/color-utils.ts
import {
  to,
  toGamut,
  serialize,
  contrastWCAG21,
  sRGB,
  OKLCH
} from "colorjs.io/fn";
import "./register-color-spaces.mjs";
function getColorString(color) {
  const rgbRounded = serialize(to(color, sRGB));
  return serialize(rgbRounded, { format: "hex" });
}
function getContrast(colorA, colorB) {
  return contrastWCAG21(colorA, colorB);
}
function clampToGamut(c) {
  return to(toGamut(c, { space: sRGB, method: "css" }), OKLCH);
}
export {
  clampToGamut,
  getColorString,
  getContrast
};
//# sourceMappingURL=color-utils.mjs.map
