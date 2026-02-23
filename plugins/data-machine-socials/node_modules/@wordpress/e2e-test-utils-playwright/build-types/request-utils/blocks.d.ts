/**
 * Internal dependencies
 */
import type { RequestUtils } from './index';
type CreateBlockPayload = {
    date?: string;
    date_gmt?: string;
    slug?: string;
    title: string;
    status: 'publish' | 'future' | 'draft' | 'pending' | 'private';
    content?: string;
    meta?: unknown;
    wp_pattern_category?: number[];
};
/**
 * Delete all blocks using REST API.
 *
 * @see https://developer.wordpress.org/rest-api/reference/blocks/#list-editor-blocks
 * @param this
 */
export declare function deleteAllBlocks(this: RequestUtils): Promise<void>;
/**
 * Creates a new block using the REST API.
 *
 * @see https://developer.wordpress.org/rest-api/reference/blocks/#create-a-editor-block.
 * @param this
 * @param payload Block payload.
 */
export declare function createBlock(this: RequestUtils, payload: CreateBlockPayload): Promise<any>;
export {};
//# sourceMappingURL=blocks.d.ts.map