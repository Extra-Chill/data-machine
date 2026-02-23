"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.setContent = setContent;
/**
 * Set the content of the editor.
 *
 * @param this
 * @param html Serialized block HTML.
 */
async function setContent(html) {
    await this.page.waitForFunction(() => window?.wp?.blocks && window?.wp?.data);
    await this.page.evaluate((_html) => {
        const blocks = window.wp.blocks.parse(_html);
        window.wp.data.dispatch('core/block-editor').resetBlocks(blocks);
    }, html);
}
//# sourceMappingURL=set-content.js.map