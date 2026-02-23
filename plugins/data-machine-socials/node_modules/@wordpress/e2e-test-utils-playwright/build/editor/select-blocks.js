"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.selectBlocks = selectBlocks;
async function selectBlocks(startSelectorOrLocator, endSelectorOrLocator) {
    const startBlock = typeof startSelectorOrLocator === 'string'
        ? this.canvas.locator(startSelectorOrLocator)
        : startSelectorOrLocator;
    const endBlock = typeof endSelectorOrLocator === 'string'
        ? this.canvas.locator(endSelectorOrLocator)
        : endSelectorOrLocator;
    const startClientId = await startBlock.getAttribute('data-block');
    const endClientId = await endBlock?.getAttribute('data-block');
    if (endClientId) {
        await this.page.evaluate(([startId, endId]) => {
            // @ts-ignore
            wp.data
                .dispatch('core/block-editor')
                .multiSelect(startId, endId);
        }, [startClientId, endClientId]);
    }
    else {
        await this.page.evaluate(([clientId]) => {
            // @ts-ignore
            wp.data.dispatch('core/block-editor').selectBlock(clientId);
        }, [startClientId]);
    }
}
//# sourceMappingURL=select-blocks.js.map