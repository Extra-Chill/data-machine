"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.getEditedPostContent = getEditedPostContent;
/**
 * Returns a promise which resolves with the edited post content (HTML string).
 *
 * @param this
 *
 * @return Promise resolving with post content markup.
 */
async function getEditedPostContent() {
    await this.page.waitForFunction(() => window?.wp?.data);
    return await this.page.evaluate(() => window.wp.data.select('core/editor').getEditedPostContent());
}
//# sourceMappingURL=get-edited-post-content.js.map