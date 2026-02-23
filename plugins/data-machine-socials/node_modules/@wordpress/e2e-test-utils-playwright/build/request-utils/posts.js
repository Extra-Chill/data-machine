"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.deleteAllPosts = deleteAllPosts;
exports.createPost = createPost;
/**
 * Delete all posts using REST API.
 *
 * @param this
 * @param postType The type of post to delete. Defaults to 'posts'.
 */
async function deleteAllPosts(postType = 'posts') {
    // List all posts.
    // https://developer.wordpress.org/rest-api/reference/posts/#list-posts
    const posts = await this.rest({
        path: `/wp/v2/${postType}`,
        params: {
            per_page: 100,
            // All possible statuses.
            status: 'publish,future,draft,pending,private,trash',
        },
    });
    // Delete all posts one by one.
    // https://developer.wordpress.org/rest-api/reference/posts/#delete-a-post
    // "/wp/v2/posts" not yet supports batch requests.
    await Promise.all(posts.map((post) => this.rest({
        method: 'DELETE',
        path: `/wp/v2/${postType}/${post.id}`,
        params: {
            force: true,
        },
    })));
}
/**
 * Creates a new post using the REST API.
 *
 * @param this
 * @param payload Post attributes.
 */
async function createPost(payload) {
    const post = await this.rest({
        method: 'POST',
        path: `/wp/v2/posts`,
        data: { ...payload },
    });
    return post;
}
//# sourceMappingURL=posts.js.map