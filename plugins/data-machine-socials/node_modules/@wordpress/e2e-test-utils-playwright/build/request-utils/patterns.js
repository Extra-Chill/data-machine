"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.deleteAllPatternCategories = deleteAllPatternCategories;
/**
 * Delete all pattern categories using REST API.
 *
 * @see https://developer.wordpress.org/rest-api/reference/categories/#list-categories
 * @param this
 */
async function deleteAllPatternCategories() {
    // List all pattern categories.
    // https://developer.wordpress.org/rest-api/reference/categories/#list-categories
    const categories = await this.rest({
        path: '/wp/v2/wp_pattern_category',
        params: {
            per_page: 100,
        },
    });
    // Delete pattern categories.
    // https://developer.wordpress.org/rest-api/reference/categories/#delete-a-category
    // "/wp/v2/category" does not yet supports batch requests.
    await this.batchRest(categories.map((category) => ({
        method: 'DELETE',
        path: `/wp/v2/wp_pattern_category/${category.id}?force=true`,
    })));
}
//# sourceMappingURL=patterns.js.map