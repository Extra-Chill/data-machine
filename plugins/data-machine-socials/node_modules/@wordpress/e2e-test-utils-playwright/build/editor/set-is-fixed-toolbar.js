"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.setIsFixedToolbar = setIsFixedToolbar;
/**
 * Toggles the fixed toolbar option.
 *
 * @param this
 * @param isFixed Boolean value true/false for on/off.
 */
async function setIsFixedToolbar(isFixed) {
    await this.page.waitForFunction(() => window?.wp?.data);
    await this.page.evaluate((_isFixed) => {
        window.wp.data
            .dispatch('core/preferences')
            .set('core', 'fixedToolbar', _isFixed);
    }, isFixed);
}
//# sourceMappingURL=set-is-fixed-toolbar.js.map