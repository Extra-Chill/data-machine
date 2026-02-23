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

// packages/e2e-test-utils-playwright/src/admin/visit-site-editor.ts
var visit_site_editor_exports = {};
__export(visit_site_editor_exports, {
  visitSiteEditor: () => visitSiteEditor
});
module.exports = __toCommonJS(visit_site_editor_exports);
async function visitSiteEditor(options = {}) {
  const { postId, postType, path, canvas, activeView } = options;
  const query = new URLSearchParams();
  if (postId) {
    query.set("postId", String(postId));
  }
  if (postType) {
    query.set("postType", postType);
  }
  if (path) {
    query.set("path", path);
  }
  if (canvas) {
    query.set("canvas", canvas);
  }
  if (activeView) {
    query.set("activeView", activeView);
  }
  await this.visitAdminPage("site-editor.php", query.toString());
  if (!options.showWelcomeGuide) {
    await this.editor.setPreferences("core/edit-site", {
      welcomeGuide: false,
      welcomeGuideStyles: false,
      welcomeGuidePage: false,
      welcomeGuideTemplate: false
    });
  }
  if (!query.size || postId || canvas === "edit") {
    const canvasLoader = this.page.locator(
      // Spinner was used instead of the progress bar in an earlier
      // version of the site editor.
      ".edit-site-canvas-loader, .edit-site-canvas-spinner"
    );
    try {
      await canvasLoader.waitFor({ state: "visible", timeout: 6e4 });
      await canvasLoader.waitFor({
        state: "hidden",
        // Bigger timeout is needed for larger entities, like the Large Post
        // HTML fixture that we load for performance tests, which often
        // doesn't make it under the default timeout value.
        timeout: 6e4
      });
    } catch (error) {
      await this.page.getByRole("region", { name: "Editor content" }).waitFor();
    }
  }
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  visitSiteEditor
});
//# sourceMappingURL=visit-site-editor.cjs.map
