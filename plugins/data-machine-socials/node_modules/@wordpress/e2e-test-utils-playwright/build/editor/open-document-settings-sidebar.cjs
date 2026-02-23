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

// packages/e2e-test-utils-playwright/src/editor/open-document-settings-sidebar.ts
var open_document_settings_sidebar_exports = {};
__export(open_document_settings_sidebar_exports, {
  openDocumentSettingsSidebar: () => openDocumentSettingsSidebar
});
module.exports = __toCommonJS(open_document_settings_sidebar_exports);
async function openDocumentSettingsSidebar() {
  const toggleButton = this.page.getByRole("region", { name: "Editor top bar" }).getByRole("button", {
    name: "Settings",
    exact: true,
    disabled: false
  });
  const isClosed = await toggleButton.getAttribute("aria-expanded") === "false";
  if (isClosed) {
    await toggleButton.click();
    await this.page.getByRole("region", { name: "Editor settings" }).getByRole("button", { name: "Close Settings" }).waitFor();
  }
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  openDocumentSettingsSidebar
});
//# sourceMappingURL=open-document-settings-sidebar.cjs.map
