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

// packages/e2e-test-utils-playwright/src/editor/publish-post.ts
var publish_post_exports = {};
__export(publish_post_exports, {
  publishPost: () => publishPost
});
module.exports = __toCommonJS(publish_post_exports);
async function publishPost() {
  const saveButton = this.page.getByRole("region", { name: "Editor top bar" }).getByRole("button", { name: "Save", exact: true });
  const publishButton = this.page.getByRole("region", { name: "Editor top bar" }).getByRole("button", { name: "Publish", exact: true });
  const buttonToClick = await saveButton.isVisible() ? saveButton : publishButton;
  await buttonToClick.click();
  const entitiesSaveButton = this.page.getByRole("region", { name: "Editor publish" }).getByRole("button", { name: "Save", exact: true });
  const isEntitiesSavePanelVisible = await entitiesSaveButton.isVisible();
  if (isEntitiesSavePanelVisible) {
    await entitiesSaveButton.click();
  }
  await this.page.getByRole("region", {
    name: "Editor publish"
  }).getByRole("button", { name: "Publish", exact: true }).click();
  await this.page.getByRole("button", { name: "Dismiss this notice" }).filter({ hasText: "published" }).waitFor();
  const postId = new URL(this.page.url()).searchParams.get("post");
  return typeof postId === "string" ? parseInt(postId, 10) : null;
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  publishPost
});
//# sourceMappingURL=publish-post.cjs.map
