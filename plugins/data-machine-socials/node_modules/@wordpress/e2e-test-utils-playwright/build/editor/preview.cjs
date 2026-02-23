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

// packages/e2e-test-utils-playwright/src/editor/preview.ts
var preview_exports = {};
__export(preview_exports, {
  openPreviewPage: () => openPreviewPage
});
module.exports = __toCommonJS(preview_exports);
async function openPreviewPage() {
  const editorTopBar = this.page.locator(
    'role=region[name="Editor top bar"i]'
  );
  const previewButton = editorTopBar.locator('role=button[name="View"i]');
  await previewButton.click();
  const [previewPage] = await Promise.all([
    this.context.waitForEvent("page"),
    this.page.click('role=menuitem[name="Preview in new tab"i]')
  ]);
  return previewPage;
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  openPreviewPage
});
//# sourceMappingURL=preview.cjs.map
