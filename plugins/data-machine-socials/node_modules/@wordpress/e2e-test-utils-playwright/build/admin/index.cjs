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

// packages/e2e-test-utils-playwright/src/admin/index.ts
var admin_exports = {};
__export(admin_exports, {
  Admin: () => Admin
});
module.exports = __toCommonJS(admin_exports);
var import_create_new_post = require("./create-new-post.cjs");
var import_get_page_error = require("./get-page-error.cjs");
var import_visit_admin_page = require("./visit-admin-page.cjs");
var import_edit_post = require("./edit-post.cjs");
var import_visit_site_editor = require("./visit-site-editor.cjs");
var Admin = class {
  page;
  context;
  browser;
  pageUtils;
  editor;
  constructor({ page, pageUtils, editor }) {
    this.page = page;
    this.context = page.context();
    this.browser = this.context.browser();
    this.pageUtils = pageUtils;
    this.editor = editor;
  }
  /** @borrows createNewPost as this.createNewPost */
  createNewPost = import_create_new_post.createNewPost.bind(this);
  /** @borrows editPost as this.editPost */
  editPost = import_edit_post.editPost.bind(this);
  /** @borrows getPageError as this.getPageError */
  getPageError = import_get_page_error.getPageError.bind(this);
  /** @borrows visitAdminPage as this.visitAdminPage */
  visitAdminPage = import_visit_admin_page.visitAdminPage.bind(this);
  /** @borrows visitSiteEditor as this.visitSiteEditor */
  visitSiteEditor = import_visit_site_editor.visitSiteEditor.bind(this);
};
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  Admin
});
//# sourceMappingURL=index.cjs.map
