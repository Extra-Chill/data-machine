"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.Admin = void 0;
/**
 * Internal dependencies
 */
const create_new_post_1 = require("./create-new-post");
const get_page_error_1 = require("./get-page-error");
const visit_admin_page_1 = require("./visit-admin-page");
const edit_post_1 = require("./edit-post");
const visit_site_editor_1 = require("./visit-site-editor");
class Admin {
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
    createNewPost = create_new_post_1.createNewPost.bind(this);
    /** @borrows editPost as this.editPost */
    editPost = edit_post_1.editPost.bind(this);
    /** @borrows getPageError as this.getPageError */
    getPageError = get_page_error_1.getPageError.bind(this);
    /** @borrows visitAdminPage as this.visitAdminPage */
    visitAdminPage = visit_admin_page_1.visitAdminPage.bind(this);
    /** @borrows visitSiteEditor as this.visitSiteEditor */
    visitSiteEditor = visit_site_editor_1.visitSiteEditor.bind(this);
}
exports.Admin = Admin;
//# sourceMappingURL=index.js.map