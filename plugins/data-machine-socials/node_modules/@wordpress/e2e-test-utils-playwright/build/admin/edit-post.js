"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.editPost = editPost;
/**
 * Open the post with given ID in the editor.
 *
 * @param this
 * @param postId Post ID to visit.
 */
async function editPost(postId) {
    const query = new URLSearchParams();
    query.set('post', String(postId));
    query.set('action', 'edit');
    await this.visitAdminPage('post.php', query.toString());
    await this.editor.setPreferences('core/edit-post', {
        welcomeGuide: false,
        fullscreenMode: false,
    });
}
//# sourceMappingURL=edit-post.js.map