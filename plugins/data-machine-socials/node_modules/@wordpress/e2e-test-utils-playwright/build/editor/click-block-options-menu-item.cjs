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

// packages/e2e-test-utils-playwright/src/editor/click-block-options-menu-item.ts
var click_block_options_menu_item_exports = {};
__export(click_block_options_menu_item_exports, {
  clickBlockOptionsMenuItem: () => clickBlockOptionsMenuItem
});
module.exports = __toCommonJS(click_block_options_menu_item_exports);
async function clickBlockOptionsMenuItem(label) {
  await this.clickBlockToolbarButton("Options");
  await this.page.getByRole("menu", { name: "Options" }).getByRole("menuitem", { name: label }).click();
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  clickBlockOptionsMenuItem
});
//# sourceMappingURL=click-block-options-menu-item.cjs.map
