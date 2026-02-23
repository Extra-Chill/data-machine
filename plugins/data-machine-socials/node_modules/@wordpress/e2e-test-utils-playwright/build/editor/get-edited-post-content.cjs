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

// packages/e2e-test-utils-playwright/src/editor/get-edited-post-content.ts
var get_edited_post_content_exports = {};
__export(get_edited_post_content_exports, {
  getEditedPostContent: () => getEditedPostContent
});
module.exports = __toCommonJS(get_edited_post_content_exports);
async function getEditedPostContent() {
  await this.page.waitForFunction(() => window?.wp?.data);
  return await this.page.evaluate(
    () => window.wp.data.select("core/editor").getEditedPostContent()
  );
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  getEditedPostContent
});
//# sourceMappingURL=get-edited-post-content.cjs.map
