"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.Editor = void 0;
/**
 * Internal dependencies
 */
const click_block_options_menu_item_1 = require("./click-block-options-menu-item");
const click_block_toolbar_button_1 = require("./click-block-toolbar-button");
const get_blocks_1 = require("./get-blocks");
const get_edited_post_content_1 = require("./get-edited-post-content");
const insert_block_1 = require("./insert-block");
const open_document_settings_sidebar_1 = require("./open-document-settings-sidebar");
const preview_1 = require("./preview");
const publish_post_1 = require("./publish-post");
const save_draft_1 = require("./save-draft");
const select_blocks_1 = require("./select-blocks");
const set_content_1 = require("./set-content");
const set_preferences_1 = require("./set-preferences");
const show_block_toolbar_1 = require("./show-block-toolbar");
const site_editor_1 = require("./site-editor");
const set_is_fixed_toolbar_1 = require("./set-is-fixed-toolbar");
const switch_to_legacy_canvas_1 = require("./switch-to-legacy-canvas");
const transform_block_to_1 = require("./transform-block-to");
const switch_editor_tool_1 = require("./switch-editor-tool");
class Editor {
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
    clickBlockOptionsMenuItem = click_block_options_menu_item_1.clickBlockOptionsMenuItem.bind(this);
    /** @borrows clickBlockToolbarButton as this.clickBlockToolbarButton */
    clickBlockToolbarButton = click_block_toolbar_button_1.clickBlockToolbarButton.bind(this);
    /** @borrows getBlocks as this.getBlocks */
    getBlocks = get_blocks_1.getBlocks.bind(this);
    /** @borrows getEditedPostContent as this.getEditedPostContent */
    getEditedPostContent = get_edited_post_content_1.getEditedPostContent.bind(this);
    /** @borrows insertBlock as this.insertBlock */
    insertBlock = insert_block_1.insertBlock.bind(this);
    /** @borrows openDocumentSettingsSidebar as this.openDocumentSettingsSidebar */
    openDocumentSettingsSidebar = open_document_settings_sidebar_1.openDocumentSettingsSidebar.bind(this);
    /** @borrows openPreviewPage as this.openPreviewPage */
    openPreviewPage = preview_1.openPreviewPage.bind(this);
    /** @borrows publishPost as this.publishPost */
    publishPost = publish_post_1.publishPost.bind(this);
    /** @borrows saveDraft as this.saveDraft */
    saveDraft = save_draft_1.saveDraft.bind(this);
    /** @borrows saveSiteEditorEntities as this.saveSiteEditorEntities */
    saveSiteEditorEntities = site_editor_1.saveSiteEditorEntities.bind(this);
    /** @borrows selectBlocks as this.selectBlocks */
    selectBlocks = select_blocks_1.selectBlocks.bind(this);
    /** @borrows setContent as this.setContent */
    setContent = set_content_1.setContent.bind(this);
    /** @borrows setPreferences as this.setPreferences */
    setPreferences = set_preferences_1.setPreferences.bind(this);
    /** @borrows showBlockToolbar as this.showBlockToolbar */
    showBlockToolbar = show_block_toolbar_1.showBlockToolbar.bind(this);
    /** @borrows setIsFixedToolbar as this.setIsFixedToolbar */
    setIsFixedToolbar = set_is_fixed_toolbar_1.setIsFixedToolbar.bind(this);
    /** @borrows switchEditorTool as this.switchEditorTool */
    switchEditorTool = switch_editor_tool_1.switchEditorTool.bind(this);
    /** @borrows switchToLegacyCanvas as this.switchToLegacyCanvas */
    switchToLegacyCanvas = switch_to_legacy_canvas_1.switchToLegacyCanvas.bind(this);
    /** @borrows transformBlockTo as this.transformBlockTo */
    transformBlockTo = transform_block_to_1.transformBlockTo.bind(this);
}
exports.Editor = Editor;
//# sourceMappingURL=index.js.map