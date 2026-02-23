/**
 * Internal dependencies
 */
import type { RequestUtils } from './index';
export interface Comment {
    id: number;
    author: number;
    content: string;
    post: number;
}
export interface CreateCommentPayload {
    content: string;
    post: number;
}
export interface User {
    id: number;
}
/**
 * Create new comment using the REST API.
 *
 * @param this
 * @param payload
 */
export declare function createComment(this: RequestUtils, payload: CreateCommentPayload): Promise<Comment>;
/**
 * Delete all comments using the REST API.
 *
 * @param this
 * @param type - Optional comment type to delete.
 */
export declare function deleteAllComments(this: RequestUtils, type?: string): Promise<void>;
//# sourceMappingURL=comments.d.ts.map