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

// packages/e2e-test-utils-playwright/src/editor/get-blocks.ts
var get_blocks_exports = {};
__export(get_blocks_exports, {
  getBlocks: () => getBlocks
});
module.exports = __toCommonJS(get_blocks_exports);
async function getBlocks({ clientId, full = false } = {}) {
  await this.page.waitForFunction(
    () => window?.wp?.blocks && window?.wp?.data
  );
  return await this.page.evaluate(
    ([_full, _clientId]) => {
      function serializeAttributes(attributes) {
        return Object.fromEntries(
          Object.entries(attributes).map(([key, value]) => {
            if (value instanceof window.wp.richText.RichTextData) {
              return [key, value.toString()];
            }
            return [key, value];
          })
        );
      }
      function recursivelyTransformBlocks(blocks2) {
        return blocks2.map((block) => ({
          name: block.name,
          attributes: serializeAttributes(block.attributes),
          innerBlocks: recursivelyTransformBlocks(
            block.innerBlocks
          )
        }));
      }
      const blocks = window.wp.data.select("core/block-editor").getBlocks(_clientId);
      if (blocks.length === 1 && window.wp.blocks.isUnmodifiedDefaultBlock(blocks[0])) {
        return [];
      }
      return _full ? blocks : recursivelyTransformBlocks(blocks);
    },
    [full, clientId]
  );
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  getBlocks
});
//# sourceMappingURL=get-blocks.cjs.map
