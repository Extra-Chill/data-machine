"use strict";
/**
 * This filed is partially copied from @wordpress/keycodes to keep the package
 * (internal-)dependencies free.
 */
Object.defineProperty(exports, "__esModule", { value: true });
exports.modifiers = exports.SHIFT = exports.COMMAND = exports.CTRL = exports.ALT = void 0;
/**
 * Keycode for ALT key.
 */
exports.ALT = 'alt';
/**
 * Keycode for CTRL key.
 */
exports.CTRL = 'ctrl';
/**
 * Keycode for COMMAND key.
 */
exports.COMMAND = 'meta';
/**
 * Keycode for SHIFT key.
 */
exports.SHIFT = 'shift';
/**
 * Object that contains functions that return the available modifier
 * depending on platform.
 */
exports.modifiers = {
    primary: (_isApple) => (_isApple() ? [exports.COMMAND] : [exports.CTRL]),
    primaryShift: (_isApple) => _isApple() ? [exports.SHIFT, exports.COMMAND] : [exports.CTRL, exports.SHIFT],
    primaryAlt: (_isApple) => _isApple() ? [exports.ALT, exports.COMMAND] : [exports.CTRL, exports.ALT],
    secondary: (_isApple) => _isApple() ? [exports.SHIFT, exports.ALT, exports.COMMAND] : [exports.CTRL, exports.SHIFT, exports.ALT],
    access: (_isApple) => (_isApple() ? [exports.CTRL, exports.ALT] : [exports.SHIFT, exports.ALT]),
    ctrl: () => [exports.CTRL],
    alt: () => [exports.ALT],
    ctrlShift: () => [exports.CTRL, exports.SHIFT],
    shift: () => [exports.SHIFT],
    shiftAlt: () => [exports.SHIFT, exports.ALT],
    undefined: () => [],
};
//# sourceMappingURL=keycodes.js.map