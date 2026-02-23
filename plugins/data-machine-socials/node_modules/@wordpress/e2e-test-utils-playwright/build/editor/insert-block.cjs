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

// packages/e2e-test-utils-playwright/src/editor/insert-block.ts
var insert_block_exports = {};
__export(insert_block_exports, {
  insertBlock: () => insertBlock
});
module.exports = __toCommonJS(insert_block_exports);
async function insertBlock(blockRepresentation, { clientId } = {}) {
  await this.page.waitForFunction(
    () => window?.wp?.blocks && window?.wp?.data
  );
  await this.page.evaluate(
    ([_blockRepresentation, _clientId]) => {
      function recursiveCreateBlock({
        name,
        attributes = {},
        innerBlocks = []
      }) {
        return window.wp.blocks.createBlock(
          name,
          attributes,
          innerBlocks.map(
            (innerBlock) => recursiveCreateBlock(innerBlock)
          )
        );
      }
      const block = recursiveCreateBlock(_blockRepresentation);
      window.wp.data.dispatch("core/block-editor").insertBlock(block, void 0, _clientId);
    },
    [blockRepresentation, clientId]
  );
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  insertBlock
});
//# sourceMappingURL=insert-block.cjs.map
