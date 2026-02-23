"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.clickBlockOptionsMenuItem = clickBlockOptionsMenuItem;
/**
 * Clicks a block toolbar button.
 *
 * @param this
 * @param label The text string of the button label.
 */
async function clickBlockOptionsMenuItem(label) {
    await this.clickBlockToolbarButton('Options');
    await this.page
        .getByRole('menu', { name: 'Options' })
        .getByRole('menuitem', { name: label })
        .click();
}
//# sourceMappingURL=click-block-options-menu-item.js.map