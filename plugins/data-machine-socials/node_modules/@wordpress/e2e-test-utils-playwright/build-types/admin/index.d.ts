/**
 * External dependencies
 */
import type { Browser, Page, BrowserContext } from '@playwright/test';
/**
 * Internal dependencies
 */
import { createNewPost } from './create-new-post';
import { getPageError } from './get-page-error';
import { visitAdminPage } from './visit-admin-page';
import { editPost } from './edit-post';
import { visitSiteEditor } from './visit-site-editor';
import type { PageUtils } from '../page-utils';
import type { Editor } from '../editor';
type AdminConstructorProps = {
    page: Page;
    pageUtils: PageUtils;
    editor: Editor;
};
export declare class Admin {
    page: Page;
    context: BrowserContext;
    browser: Browser;
    pageUtils: PageUtils;
    editor: Editor;
    constructor({ page, pageUtils, editor }: AdminConstructorProps);
    /** @borrows createNewPost as this.createNewPost */
    createNewPost: typeof createNewPost;
    /** @borrows editPost as this.editPost */
    editPost: typeof editPost;
    /** @borrows getPageError as this.getPageError */
    getPageError: typeof getPageError;
    /** @borrows visitAdminPage as this.visitAdminPage */
    visitAdminPage: typeof visitAdminPage;
    /** @borrows visitSiteEditor as this.visitSiteEditor */
    visitSiteEditor: typeof visitSiteEditor;
}
export {};
//# sourceMappingURL=index.d.ts.map