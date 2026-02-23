/**
 * Internal dependencies
 */
import type { RequestUtils } from './index';
export interface Post {
    id: number;
    content: string;
    status: 'publish' | 'future' | 'draft' | 'pending' | 'private';
    link: string;
}
export interface CreatePostPayload {
    title?: string;
    content?: string;
    status: 'publish' | 'future' | 'draft' | 'pending' | 'private';
    date?: string;
    date_gmt: string;
}
/**
 * Delete all posts using REST API.
 *
 * @param this
 * @param postType The type of post to delete. Defaults to 'posts'.
 */
export declare function deleteAllPosts(this: RequestUtils, postType?: string): Promise<void>;
/**
 * Creates a new post using the REST API.
 *
 * @param this
 * @param payload Post attributes.
 */
export declare function createPost(this: RequestUtils, payload: CreatePostPayload): Promise<Post>;
//# sourceMappingURL=posts.d.ts.map