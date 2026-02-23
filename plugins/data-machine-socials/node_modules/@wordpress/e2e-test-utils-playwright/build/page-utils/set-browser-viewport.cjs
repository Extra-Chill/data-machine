"use strict";
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

// packages/e2e-test-utils-playwright/src/page-utils/set-browser-viewport.js
var set_browser_viewport_exports = {};
__export(set_browser_viewport_exports, {
  setBrowserViewport: () => setBrowserViewport
});
module.exports = __toCommonJS(set_browser_viewport_exports);
var PREDEFINED_DIMENSIONS = {
  large: { width: 960, height: 700 },
  medium: { width: 768, height: 700 },
  small: { width: 600, height: 700 }
};
async function setBrowserViewport(viewport) {
  const dimensions = typeof viewport === "string" ? PREDEFINED_DIMENSIONS[viewport] : viewport;
  await this.page.setViewportSize(dimensions);
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  setBrowserViewport
});
//# sourceMappingURL=set-browser-viewport.cjs.map
