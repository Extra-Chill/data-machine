"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.deleteAllBlocks = deleteAllBlocks;
exports.createBlock = createBlock;
/**
 * Delete all blocks using REST API.
 *
 * @see https://developer.wordpress.org/rest-api/reference/blocks/#list-editor-blocks
 * @param this
 */
async function deleteAllBlocks() {
    // List all blocks.
    // https://developer.wordpress.org/rest-api/reference/blocks/#list-editor-blocks
    const blocks = await this.rest({
        path: '/wp/v2/blocks',
        params: {
            per_page: 100,
            // All possible statuses.
            status: 'publish,future,draft,pending,private,trash',
        },
    });
    // Delete blocks.
    // https://developer.wordpress.org/rest-api/reference/blocks/#delete-a-editor-block
    // "/wp/v2/posts" not yet supports batch requests.
    await this.batchRest(blocks.map((block) => ({
        method: 'DELETE',
        path: `/wp/v2/blocks/${block.id}?force=true`,
    })));
}
/**
 * Creates a new block using the REST API.
 *
 * @see https://developer.wordpress.org/rest-api/reference/blocks/#create-a-editor-block.
 * @param this
 * @param payload Block payload.
 */
async function createBlock(payload) {
    const block = await this.rest({
        path: '/wp/v2/blocks',
        method: 'POST',
        data: { ...payload },
    });
    return block;
}
//# sourceMappingURL=blocks.js.map