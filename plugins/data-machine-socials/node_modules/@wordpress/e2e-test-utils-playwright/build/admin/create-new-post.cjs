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

// packages/e2e-test-utils-playwright/src/admin/create-new-post.ts
var create_new_post_exports = {};
__export(create_new_post_exports, {
  createNewPost: () => createNewPost
});
module.exports = __toCommonJS(create_new_post_exports);
async function createNewPost(options = {}) {
  const query = new URLSearchParams();
  const { postType, title, content, excerpt } = options;
  if (postType) {
    query.set("post_type", postType);
  }
  if (title) {
    query.set("post_title", title);
  }
  if (content) {
    query.set("content", content);
  }
  if (excerpt) {
    query.set("excerpt", excerpt);
  }
  await this.visitAdminPage("post-new.php", query.toString());
  await this.editor.setPreferences("core/edit-post", {
    welcomeGuide: options.showWelcomeGuide ?? false,
    fullscreenMode: options.fullscreenMode ?? false
  });
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  createNewPost
});
//# sourceMappingURL=create-new-post.cjs.map
