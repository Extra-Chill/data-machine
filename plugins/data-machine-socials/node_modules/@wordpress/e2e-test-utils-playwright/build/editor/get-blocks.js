"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.getBlocks = getBlocks;
/**
 * Returns the edited blocks.
 *
 * @param this
 * @param options
 * @param options.clientId Limit the results to be only under a partial tree of the specified clientId.
 * @param options.full     Whether to return the full block data or just the name and attributes.
 *
 * @return  The blocks.
 */
async function getBlocks({ clientId, full = false } = {}) {
    await this.page.waitForFunction(() => window?.wp?.blocks && window?.wp?.data);
    return await this.page.evaluate(([_full, _clientId]) => {
        // Serialize serializable attributes of blocks.
        function serializeAttributes(attributes) {
            return Object.fromEntries(Object.entries(attributes).map(([key, value]) => {
                // Serialize RichTextData to string.
                if (value instanceof window.wp.richText.RichTextData) {
                    return [key, value.toString()];
                }
                return [key, value];
            }));
        }
        // Remove other unpredictable properties like clientId from blocks for testing purposes.
        function recursivelyTransformBlocks(blocks) {
            return blocks.map((block) => ({
                name: block.name,
                attributes: serializeAttributes(block.attributes),
                innerBlocks: recursivelyTransformBlocks(block.innerBlocks),
            }));
        }
        const blocks = window.wp.data
            .select('core/block-editor')
            .getBlocks(_clientId);
        // The editor might still contain an unmodified empty block even when it's technically "empty".
        if (blocks.length === 1 &&
            window.wp.blocks.isUnmodifiedDefaultBlock(blocks[0])) {
            return [];
        }
        return _full ? blocks : recursivelyTransformBlocks(blocks);
    }, [full, clientId]);
}
//# sourceMappingURL=get-blocks.js.map