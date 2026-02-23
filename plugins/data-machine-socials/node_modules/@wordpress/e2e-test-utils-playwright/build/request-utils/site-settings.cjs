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

// packages/e2e-test-utils-playwright/src/request-utils/site-settings.ts
var site_settings_exports = {};
__export(site_settings_exports, {
  getSiteSettings: () => getSiteSettings,
  updateSiteSettings: () => updateSiteSettings
});
module.exports = __toCommonJS(site_settings_exports);
async function getSiteSettings() {
  return await this.rest({
    path: "/wp/v2/settings",
    method: "GET"
  });
}
async function updateSiteSettings(siteSettings) {
  return await this.rest({
    path: "/wp/v2/settings",
    method: "POST",
    data: siteSettings
  });
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  getSiteSettings,
  updateSiteSettings
});
//# sourceMappingURL=site-settings.cjs.map
