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

// packages/e2e-test-utils-playwright/src/editor/index.ts
var editor_exports = {};
__export(editor_exports, {
  Editor: () => Editor
});
module.exports = __toCommonJS(editor_exports);
var import_click_block_options_menu_item = require("./click-block-options-menu-item.cjs");
var import_click_block_toolbar_button = require("./click-block-toolbar-button.cjs");
var import_get_blocks = require("./get-blocks.cjs");
var import_get_edited_post_content = require("./get-edited-post-content.cjs");
var import_insert_block = require("./insert-block.cjs");
var import_open_document_settings_sidebar = require("./open-document-settings-sidebar.cjs");
var import_preview = require("./preview.cjs");
var import_publish_post = require("./publish-post.cjs");
var import_save_draft = require("./save-draft.cjs");
var import_select_blocks = require("./select-blocks.cjs");
var import_set_content = require("./set-content.cjs");
var import_set_preferences = require("./set-preferences.cjs");
var import_show_block_toolbar = require("./show-block-toolbar.cjs");
var import_site_editor = require("./site-editor.cjs");
var import_set_is_fixed_toolbar = require("./set-is-fixed-toolbar.cjs");
var import_switch_to_legacy_canvas = require("./switch-to-legacy-canvas.cjs");
var import_transform_block_to = require("./transform-block-to.cjs");
var import_switch_editor_tool = require("./switch-editor-tool.cjs");
var Editor = class {
  browser;
  page;
  context;
  constructor({ page }) {
    this.page = page;
    this.context = page.context();
    this.browser = this.context.browser();
  }
  get canvas() {
    return this.page.frameLocator('[name="editor-canvas"]');
  }
  /** @borrows clickBlockOptionsMenuItem as this.clickBlockOptionsMenuItem */
  clickBlockOptionsMenuItem = import_click_block_options_menu_item.clickBlockOptionsMenuItem.bind(this);
  /** @borrows clickBlockToolbarButton as this.clickBlockToolbarButton */
  clickBlockToolbarButton = import_click_block_toolbar_button.clickBlockToolbarButton.bind(this);
  /** @borrows getBlocks as this.getBlocks */
  getBlocks = import_get_blocks.getBlocks.bind(this);
  /** @borrows getEditedPostContent as this.getEditedPostContent */
  getEditedPostContent = import_get_edited_post_content.getEditedPostContent.bind(this);
  /** @borrows insertBlock as this.insertBlock */
  insertBlock = import_insert_block.insertBlock.bind(this);
  /** @borrows openDocumentSettingsSidebar as this.openDocumentSettingsSidebar */
  openDocumentSettingsSidebar = import_open_document_settings_sidebar.openDocumentSettingsSidebar.bind(this);
  /** @borrows openPreviewPage as this.openPreviewPage */
  openPreviewPage = import_preview.openPreviewPage.bind(this);
  /** @borrows publishPost as this.publishPost */
  publishPost = import_publish_post.publishPost.bind(this);
  /** @borrows saveDraft as this.saveDraft */
  saveDraft = import_save_draft.saveDraft.bind(this);
  /** @borrows saveSiteEditorEntities as this.saveSiteEditorEntities */
  saveSiteEditorEntities = import_site_editor.saveSiteEditorEntities.bind(this);
  /** @borrows selectBlocks as this.selectBlocks */
  selectBlocks = import_select_blocks.selectBlocks.bind(this);
  /** @borrows setContent as this.setContent */
  setContent = import_set_content.setContent.bind(this);
  /** @borrows setPreferences as this.setPreferences */
  setPreferences = import_set_preferences.setPreferences.bind(this);
  /** @borrows showBlockToolbar as this.showBlockToolbar */
  showBlockToolbar = import_show_block_toolbar.showBlockToolbar.bind(this);
  /** @borrows setIsFixedToolbar as this.setIsFixedToolbar */
  setIsFixedToolbar = import_set_is_fixed_toolbar.setIsFixedToolbar.bind(this);
  /** @borrows switchEditorTool as this.switchEditorTool */
  switchEditorTool = import_switch_editor_tool.switchEditorTool.bind(this);
  /** @borrows switchToLegacyCanvas as this.switchToLegacyCanvas */
  switchToLegacyCanvas = import_switch_to_legacy_canvas.switchToLegacyCanvas.bind(this);
  /** @borrows transformBlockTo as this.transformBlockTo */
  transformBlockTo = import_transform_block_to.transformBlockTo.bind(this);
};
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  Editor
});
//# sourceMappingURL=index.cjs.map
