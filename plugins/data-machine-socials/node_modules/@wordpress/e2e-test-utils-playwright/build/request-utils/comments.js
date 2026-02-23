"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.createComment = createComment;
exports.deleteAllComments = deleteAllComments;
/**
 * Create new comment using the REST API.
 *
 * @param this
 * @param payload
 */
async function createComment(payload) {
    const currentUser = await this.rest({
        path: '/wp/v2/users/me',
        method: 'GET',
    });
    const author = currentUser.id;
    const comment = await this.rest({
        method: 'POST',
        path: '/wp/v2/comments',
        data: { ...payload, author },
    });
    return comment;
}
/**
 * Delete all comments using the REST API.
 *
 * @param this
 * @param type - Optional comment type to delete.
 */
async function deleteAllComments(type) {
    // List all comments.
    // https://developer.wordpress.org/rest-api/reference/comments/#list-comments
    const comments = await this.rest({
        path: '/wp/v2/comments',
        params: {
            per_page: 100,
            status: 'all',
            type: type || 'comment',
        },
    });
    // Delete all comments one by one.
    // https://developer.wordpress.org/rest-api/reference/comments/#delete-a-comment
    // "/wp/v2/comments" doesn't support batch requests yet.
    await Promise.all(comments.map((comment) => this.rest({
        method: 'DELETE',
        path: `/wp/v2/comments/${comment.id}`,
        params: {
            force: true,
        },
    })));
}
//# sourceMappingURL=comments.js.map