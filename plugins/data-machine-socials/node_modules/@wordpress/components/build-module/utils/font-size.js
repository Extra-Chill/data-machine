/**
 * External dependencies
 */

/**
 * Internal dependencies
 */
import CONFIG from './config-values';
export const BASE_FONT_SIZE = 13;
export const PRESET_FONT_SIZES = {
  body: BASE_FONT_SIZE,
  caption: 10,
  footnote: 11,
  largeTitle: 28,
  subheadline: 12,
  title: 20
};
export const HEADING_FONT_SIZES = [1, 2, 3, 4, 5, 6].flatMap(n => [n, n.toString()]);
export function getFontSize(size = BASE_FONT_SIZE) {
  if (size in PRESET_FONT_SIZES) {
    return getFontSize(PRESET_FONT_SIZES[size]);
  }
  if (typeof size !== 'number') {
    const parsed = parseFloat(size);
    if (Number.isNaN(parsed)) {
      return size;
    }
    size = parsed;
  }
  const ratio = `(${size} / ${BASE_FONT_SIZE})`;
  return `calc(${ratio} * ${CONFIG.fontSize})`;
}
export function getHeadingFontSize(size = 3) {
  if (!HEADING_FONT_SIZES.includes(size)) {
    return getFontSize(size);
  }
  const headingSize = `fontSizeH${size}`;
  return CONFIG[headingSize];
}
//# sourceMappingURL=font-size.js.map