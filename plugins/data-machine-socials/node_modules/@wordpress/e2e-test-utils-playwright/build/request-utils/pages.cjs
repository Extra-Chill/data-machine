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

// packages/e2e-test-utils-playwright/src/request-utils/pages.ts
var pages_exports = {};
__export(pages_exports, {
  createPage: () => createPage,
  deleteAllPages: () => deleteAllPages,
  deletePage: () => deletePage
});
module.exports = __toCommonJS(pages_exports);
var PAGE_STATUS = [
  "publish",
  "future",
  "draft",
  "pending",
  "private",
  "trash"
];
async function deletePage(id) {
  return await this.rest({
    method: "DELETE",
    path: `/wp/v2/pages/${id}`,
    params: {
      force: true
    }
  });
}
async function deleteAllPages() {
  const pages = await this.rest({
    path: "/wp/v2/pages",
    params: {
      per_page: 100,
      status: PAGE_STATUS.join(",")
    }
  });
  await Promise.all(
    pages.map((page) => deletePage.call(this, page.id))
  );
}
async function createPage(payload) {
  const page = await this.rest({
    method: "POST",
    path: `/wp/v2/pages`,
    data: { ...payload }
  });
  return page;
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  createPage,
  deleteAllPages,
  deletePage
});
//# sourceMappingURL=pages.cjs.map
