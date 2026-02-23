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

// packages/e2e-test-utils-playwright/src/editor/transform-block-to.ts
var transform_block_to_exports = {};
__export(transform_block_to_exports, {
  transformBlockTo: () => transformBlockTo
});
module.exports = __toCommonJS(transform_block_to_exports);
async function transformBlockTo(name) {
  await this.page.waitForFunction(
    () => window?.wp?.blocks && window?.wp?.data
  );
  await this.page.evaluate(
    ([blockName]) => {
      const clientIds = window.wp.data.select("core/block-editor").getSelectedBlockClientIds();
      const blocks = window.wp.data.select("core/block-editor").getBlocksByClientId(clientIds);
      window.wp.data.dispatch("core/block-editor").replaceBlocks(
        clientIds,
        window.wp.blocks.switchToBlockType(blocks, blockName)
      );
    },
    [name]
  );
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  transformBlockTo
});
//# sourceMappingURL=transform-block-to.cjs.map
