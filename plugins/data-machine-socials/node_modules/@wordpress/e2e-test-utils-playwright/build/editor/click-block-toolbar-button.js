"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.clickBlockToolbarButton = clickBlockToolbarButton;
/**
 * Clicks a block toolbar button.
 *
 * @param this
 * @param label The text string of the button label.
 */
async function clickBlockToolbarButton(label) {
    await this.showBlockToolbar();
    const blockToolbar = this.page.locator('role=toolbar[name="Block tools"i]');
    const button = blockToolbar.locator(`role=button[name="${label}"]`);
    await button.click();
}
//# sourceMappingURL=click-block-toolbar-button.js.map