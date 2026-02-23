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

// packages/e2e-test-utils-playwright/src/admin/visit-admin-page.ts
var visit_admin_page_exports = {};
__export(visit_admin_page_exports, {
  visitAdminPage: () => visitAdminPage
});
module.exports = __toCommonJS(visit_admin_page_exports);
var import_path = require("path");
async function visitAdminPage(adminPath, query) {
  await this.page.goto(
    (0, import_path.join)("wp-admin", adminPath) + (query ? `?${query}` : "")
  );
  if (this.pageUtils.isCurrentURL("wp-admin/upgrade.php")) {
    await this.page.click(".button.button-large.button-primary");
    await this.page.click(".button.button-large");
  }
  if (this.pageUtils.isCurrentURL("wp-login.php")) {
    throw new Error("Not logged in");
  }
  const error = await this.getPageError();
  if (error) {
    throw new Error("Unexpected error in page content: " + error);
  }
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  visitAdminPage
});
//# sourceMappingURL=visit-admin-page.cjs.map
