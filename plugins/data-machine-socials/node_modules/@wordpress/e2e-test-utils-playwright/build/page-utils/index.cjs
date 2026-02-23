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

// packages/e2e-test-utils-playwright/src/page-utils/index.ts
var page_utils_exports = {};
__export(page_utils_exports, {
  PageUtils: () => PageUtils
});
module.exports = __toCommonJS(page_utils_exports);
var import_drag_files = require("./drag-files.cjs");
var import_is_current_url = require("./is-current-url.cjs");
var import_press_keys = require("./press-keys.cjs");
var import_set_browser_viewport = require("./set-browser-viewport.cjs");
var import_emulate_network_conditions = require("./emulate-network-conditions.cjs");
var PageUtils = class {
  browser;
  page;
  context;
  browserName;
  constructor({ page, browserName }) {
    this.page = page;
    this.context = page.context();
    this.browser = this.context.browser();
    this.browserName = browserName;
  }
  /** @borrows dragFiles as this.dragFiles */
  dragFiles = import_drag_files.dragFiles.bind(this);
  /** @borrows isCurrentURL as this.isCurrentURL */
  isCurrentURL = import_is_current_url.isCurrentURL.bind(this);
  /** @borrows pressKeys as this.pressKeys */
  pressKeys = import_press_keys.pressKeys.bind(this);
  /** @borrows setBrowserViewport as this.setBrowserViewport */
  setBrowserViewport = import_set_browser_viewport.setBrowserViewport.bind(this);
  /** @borrows setClipboardData as this.setClipboardData */
  setClipboardData = import_press_keys.setClipboardData.bind(this);
  /** @borrows emulateNetworkConditions as this.emulateNetworkConditions */
  emulateNetworkConditions = import_emulate_network_conditions.emulateNetworkConditions.bind(this);
};
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  PageUtils
});
//# sourceMappingURL=index.cjs.map
