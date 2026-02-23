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

// packages/e2e-test-utils-playwright/src/request-utils/blocks.ts
var blocks_exports = {};
__export(blocks_exports, {
  createBlock: () => createBlock,
  deleteAllBlocks: () => deleteAllBlocks
});
module.exports = __toCommonJS(blocks_exports);
async function deleteAllBlocks() {
  const blocks = await this.rest({
    path: "/wp/v2/blocks",
    params: {
      per_page: 100,
      // All possible statuses.
      status: "publish,future,draft,pending,private,trash"
    }
  });
  await this.batchRest(
    blocks.map((block) => ({
      method: "DELETE",
      path: `/wp/v2/blocks/${block.id}?force=true`
    }))
  );
}
async function createBlock(payload) {
  const block = await this.rest({
    path: "/wp/v2/blocks",
    method: "POST",
    data: { ...payload }
  });
  return block;
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  createBlock,
  deleteAllBlocks
});
//# sourceMappingURL=blocks.cjs.map
