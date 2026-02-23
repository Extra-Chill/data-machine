"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.deletePage = deletePage;
exports.deleteAllPages = deleteAllPages;
exports.createPage = createPage;
const PAGE_STATUS = [
    'publish',
    'future',
    'draft',
    'pending',
    'private',
    'trash',
];
async function deletePage(id) {
    // https://developer.wordpress.org/rest-api/reference/pages/#delete-a-page
    return await this.rest({
        method: 'DELETE',
        path: `/wp/v2/pages/${id}`,
        params: {
            force: true,
        },
    });
}
/**
 * Delete all pages using REST API.
 *
 * @param this
 */
async function deleteAllPages() {
    // List all pages.
    // https://developer.wordpress.org/rest-api/reference/pages/#list-pages
    const pages = await this.rest({
        path: '/wp/v2/pages',
        params: {
            per_page: 100,
            status: PAGE_STATUS.join(','),
        },
    });
    // Delete all pages one by one.
    // "/wp/v2/pages" not yet supports batch requests.
    await Promise.all(pages.map((page) => deletePage.call(this, page.id)));
}
/**
 * Create a new page.
 *
 * @param this
 * @param payload The page payload.
 */
async function createPage(payload) {
    // https://developer.wordpress.org/rest-api/reference/pages/#create-a-page
    const page = await this.rest({
        method: 'POST',
        path: `/wp/v2/pages`,
        data: { ...payload },
    });
    return page;
}
//# sourceMappingURL=pages.js.map