"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.switchToLegacyCanvas = switchToLegacyCanvas;
/**
 * Switches to legacy (non-iframed) canvas.
 *
 * @param this
 */
async function switchToLegacyCanvas() {
    await this.page.waitForFunction(() => window?.wp?.blocks);
    await this.page.evaluate(() => {
        window.wp.blocks.registerBlockType('test/v2', {
            apiVersion: '2',
            title: 'test',
        });
    });
}
//# sourceMappingURL=switch-to-legacy-canvas.js.map