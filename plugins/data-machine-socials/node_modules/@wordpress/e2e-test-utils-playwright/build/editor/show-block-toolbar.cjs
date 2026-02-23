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

// packages/e2e-test-utils-playwright/src/editor/show-block-toolbar.ts
var show_block_toolbar_exports = {};
__export(show_block_toolbar_exports, {
  showBlockToolbar: () => showBlockToolbar
});
module.exports = __toCommonJS(show_block_toolbar_exports);
async function showBlockToolbar() {
  await this.page.mouse.move(50, 50);
  await this.page.mouse.move(75, 75);
  await this.page.mouse.move(100, 100);
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  showBlockToolbar
});
//# sourceMappingURL=show-block-toolbar.cjs.map
