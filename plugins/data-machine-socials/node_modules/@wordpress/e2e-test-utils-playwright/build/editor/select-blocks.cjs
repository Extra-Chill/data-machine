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

// packages/e2e-test-utils-playwright/src/editor/select-blocks.ts
var select_blocks_exports = {};
__export(select_blocks_exports, {
  selectBlocks: () => selectBlocks
});
module.exports = __toCommonJS(select_blocks_exports);
async function selectBlocks(startSelectorOrLocator, endSelectorOrLocator) {
  const startBlock = typeof startSelectorOrLocator === "string" ? this.canvas.locator(startSelectorOrLocator) : startSelectorOrLocator;
  const endBlock = typeof endSelectorOrLocator === "string" ? this.canvas.locator(endSelectorOrLocator) : endSelectorOrLocator;
  const startClientId = await startBlock.getAttribute("data-block");
  const endClientId = await endBlock?.getAttribute("data-block");
  if (endClientId) {
    await this.page.evaluate(
      ([startId, endId]) => {
        wp.data.dispatch("core/block-editor").multiSelect(startId, endId);
      },
      [startClientId, endClientId]
    );
  } else {
    await this.page.evaluate(
      ([clientId]) => {
        wp.data.dispatch("core/block-editor").selectBlock(clientId);
      },
      [startClientId]
    );
  }
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  selectBlocks
});
//# sourceMappingURL=select-blocks.cjs.map
