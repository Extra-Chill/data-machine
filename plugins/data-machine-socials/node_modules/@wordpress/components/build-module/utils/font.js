/**
 * Internal dependencies
 */
import FONT from './font-values';

/**
 *
 * @param {keyof FONT} value Path of value from `FONT`
 * @return {string} Font rule value
 */
export function font(value) {
  var _FONT$value;
  return (_FONT$value = FONT[value]) !== null && _FONT$value !== void 0 ? _FONT$value : '';
}
//# sourceMappingURL=font.js.map