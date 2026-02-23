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

// packages/e2e-test-utils-playwright/src/page-utils/is-current-url.ts
var is_current_url_exports = {};
__export(is_current_url_exports, {
  isCurrentURL: () => isCurrentURL
});
module.exports = __toCommonJS(is_current_url_exports);
var import_config = require("../config.cjs");
function isCurrentURL(path) {
  const currentURL = new URL(this.page.url());
  const expectedURL = new URL(path, import_config.WP_BASE_URL);
  return expectedURL.pathname === currentURL.pathname;
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  isCurrentURL
});
//# sourceMappingURL=is-current-url.cjs.map
