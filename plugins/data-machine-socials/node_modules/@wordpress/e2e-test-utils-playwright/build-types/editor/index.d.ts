/**
 * External dependencies
 */
import type { Browser, Page, BrowserContext, FrameLocator } from '@playwright/test';
/**
 * Internal dependencies
 */
import { clickBlockOptionsMenuItem } from './click-block-options-menu-item';
import { clickBlockToolbarButton } from './click-block-toolbar-button';
import { getBlocks } from './get-blocks';
import { getEditedPostContent } from './get-edited-post-content';
import { insertBlock } from './insert-block';
import { openDocumentSettingsSidebar } from './open-document-settings-sidebar';
import { openPreviewPage } from './preview';
import { publishPost } from './publish-post';
import { saveDraft } from './save-draft';
import { selectBlocks } from './select-blocks';
import { setContent } from './set-content';
import { setPreferences } from './set-preferences';
import { showBlockToolbar } from './show-block-toolbar';
import { saveSiteEditorEntities } from './site-editor';
import { setIsFixedToolbar } from './set-is-fixed-toolbar';
import { switchToLegacyCanvas } from './switch-to-legacy-canvas';
import { transformBlockTo } from './transform-block-to';
import { switchEditorTool } from './switch-editor-tool';
type EditorConstructorProps = {
    page: Page;
};
export declare class Editor {
    browser: Browser;
    page: Page;
    context: BrowserContext;
    constructor({ page }: EditorConstructorProps);
    get canvas(): FrameLocator;
    /** @borrows clickBlockOptionsMenuItem as this.clickBlockOptionsMenuItem */
    clickBlockOptionsMenuItem: typeof clickBlockOptionsMenuItem;
    /** @borrows clickBlockToolbarButton as this.clickBlockToolbarButton */
    clickBlockToolbarButton: typeof clickBlockToolbarButton;
    /** @borrows getBlocks as this.getBlocks */
    getBlocks: typeof getBlocks;
    /** @borrows getEditedPostContent as this.getEditedPostContent */
    getEditedPostContent: typeof getEditedPostContent;
    /** @borrows insertBlock as this.insertBlock */
    insertBlock: typeof insertBlock;
    /** @borrows openDocumentSettingsSidebar as this.openDocumentSettingsSidebar */
    openDocumentSettingsSidebar: typeof openDocumentSettingsSidebar;
    /** @borrows openPreviewPage as this.openPreviewPage */
    openPreviewPage: typeof openPreviewPage;
    /** @borrows publishPost as this.publishPost */
    publishPost: typeof publishPost;
    /** @borrows saveDraft as this.saveDraft */
    saveDraft: typeof saveDraft;
    /** @borrows saveSiteEditorEntities as this.saveSiteEditorEntities */
    saveSiteEditorEntities: typeof saveSiteEditorEntities;
    /** @borrows selectBlocks as this.selectBlocks */
    selectBlocks: typeof selectBlocks;
    /** @borrows setContent as this.setContent */
    setContent: typeof setContent;
    /** @borrows setPreferences as this.setPreferences */
    setPreferences: typeof setPreferences;
    /** @borrows showBlockToolbar as this.showBlockToolbar */
    showBlockToolbar: typeof showBlockToolbar;
    /** @borrows setIsFixedToolbar as this.setIsFixedToolbar */
    setIsFixedToolbar: typeof setIsFixedToolbar;
    /** @borrows switchEditorTool as this.switchEditorTool */
    switchEditorTool: typeof switchEditorTool;
    /** @borrows switchToLegacyCanvas as this.switchToLegacyCanvas */
    switchToLegacyCanvas: typeof switchToLegacyCanvas;
    /** @borrows transformBlockTo as this.transformBlockTo */
    transformBlockTo: typeof transformBlockTo;
}
export {};
//# sourceMappingURL=index.d.ts.map