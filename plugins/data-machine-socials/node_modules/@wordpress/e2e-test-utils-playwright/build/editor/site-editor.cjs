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

// packages/e2e-test-utils-playwright/src/editor/site-editor.ts
var site_editor_exports = {};
__export(site_editor_exports, {
  saveSiteEditorEntities: () => saveSiteEditorEntities
});
module.exports = __toCommonJS(site_editor_exports);
async function saveSiteEditorEntities(options = {}) {
  const editorTopBar = this.page.getByRole("region", {
    name: "Editor top bar"
  });
  const saveButton = editorTopBar.getByRole("button", {
    name: "Save",
    exact: true
  });
  const publishButton = editorTopBar.getByRole("button", {
    name: "Publish"
  });
  const publishButtonIsVisible = !await saveButton.isVisible();
  const buttonToClick = publishButtonIsVisible ? publishButton : saveButton;
  await buttonToClick.click();
  if (!options.isOnlyCurrentEntityDirty) {
    await this.page.getByRole("region", {
      name: /(Editor publish|Save panel)/
    }).getByRole("button", { name: "Save", exact: true }).click();
  }
  await this.page.getByRole("button", { name: "Dismiss this notice" }).getByText(/(updated|published)\./).first().waitFor();
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  saveSiteEditorEntities
});
//# sourceMappingURL=site-editor.cjs.map
