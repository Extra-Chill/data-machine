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

// packages/e2e-test-utils-playwright/src/editor/switch-to-legacy-canvas.ts
var switch_to_legacy_canvas_exports = {};
__export(switch_to_legacy_canvas_exports, {
  switchToLegacyCanvas: () => switchToLegacyCanvas
});
module.exports = __toCommonJS(switch_to_legacy_canvas_exports);
async function switchToLegacyCanvas() {
  await this.page.waitForFunction(() => window?.wp?.blocks);
  await this.page.evaluate(() => {
    window.wp.blocks.registerBlockType("test/v2", {
      apiVersion: "2",
      title: "test"
    });
  });
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  switchToLegacyCanvas
});
//# sourceMappingURL=switch-to-legacy-canvas.cjs.map
