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

// packages/e2e-test-utils-playwright/src/editor/switch-editor-tool.ts
var switch_editor_tool_exports = {};
__export(switch_editor_tool_exports, {
  switchEditorTool: () => switchEditorTool
});
module.exports = __toCommonJS(switch_editor_tool_exports);
async function switchEditorTool(label) {
  const toolsToolbar = this.page.getByRole("toolbar", {
    name: "Document tools"
  });
  await toolsToolbar.getByRole("button", {
    name: "Tools"
  }).click();
  const menu = this.page.getByRole("menu", {
    name: "Tools"
  });
  await menu.getByRole("menuitemradio", {
    name: label
  }).click();
  await toolsToolbar.getByRole("button", {
    name: "Tools"
  }).click();
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  switchEditorTool
});
//# sourceMappingURL=switch-editor-tool.cjs.map
