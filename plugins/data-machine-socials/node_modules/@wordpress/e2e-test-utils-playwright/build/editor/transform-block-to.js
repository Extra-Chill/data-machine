"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.transformBlockTo = transformBlockTo;
/**
 * Clicks the default block appender.
 *
 * @param this
 * @param name Block name.
 */
async function transformBlockTo(name) {
    await this.page.waitForFunction(() => window?.wp?.blocks && window?.wp?.data);
    await this.page.evaluate(([blockName]) => {
        const clientIds = window.wp.data
            .select('core/block-editor')
            .getSelectedBlockClientIds();
        const blocks = window.wp.data
            .select('core/block-editor')
            .getBlocksByClientId(clientIds);
        window.wp.data
            .dispatch('core/block-editor')
            .replaceBlocks(clientIds, window.wp.blocks.switchToBlockType(blocks, blockName));
    }, [name]);
}
//# sourceMappingURL=transform-block-to.js.map