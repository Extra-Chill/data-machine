"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.insertBlock = insertBlock;
/**
 * Insert a block.
 *
 * @param this
 * @param blockRepresentation Inserted block representation.
 * @param options
 * @param options.clientId    Client ID of the parent block to insert into.
 */
async function insertBlock(blockRepresentation, { clientId } = {}) {
    await this.page.waitForFunction(() => window?.wp?.blocks && window?.wp?.data);
    await this.page.evaluate(([_blockRepresentation, _clientId]) => {
        function recursiveCreateBlock({ name, attributes = {}, innerBlocks = [], }) {
            return window.wp.blocks.createBlock(name, attributes, innerBlocks.map((innerBlock) => recursiveCreateBlock(innerBlock)));
        }
        const block = recursiveCreateBlock(_blockRepresentation);
        window.wp.data
            .dispatch('core/block-editor')
            .insertBlock(block, undefined, _clientId);
    }, [blockRepresentation, clientId]);
}
//# sourceMappingURL=insert-block.js.map