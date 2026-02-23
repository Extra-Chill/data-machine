"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.switchEditorTool = switchEditorTool;
/**
 * Switch the editor tool being used.
 *
 * @param this
 * @param label The text string of the button label.
 */
async function switchEditorTool(label) {
    const toolsToolbar = this.page.getByRole('toolbar', {
        name: 'Document tools',
    });
    await toolsToolbar
        .getByRole('button', {
        name: 'Tools',
    })
        .click();
    const menu = this.page.getByRole('menu', {
        name: 'Tools',
    });
    await menu
        .getByRole('menuitemradio', {
        name: label,
    })
        .click();
    await toolsToolbar
        .getByRole('button', {
        name: 'Tools',
    })
        .click();
}
//# sourceMappingURL=switch-editor-tool.js.map