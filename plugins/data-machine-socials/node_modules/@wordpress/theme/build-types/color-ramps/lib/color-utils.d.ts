/**
 * External dependencies
 */
import { type ColorTypes } from 'colorjs.io/fn';
/**
 * Internal dependencies
 */
import './register-color-spaces';
/**
 * Get string representation of a color
 * @param color Color object to stringify
 * @return String representation
 */
export declare function getColorString(color: ColorTypes): string;
/**
 * Get contrast value between two colors
 * @param colorA First color
 * @param colorB Second color
 * @return WCAG 2.1 contrast ratio
 */
export declare function getContrast(colorA: ColorTypes, colorB: ColorTypes): number;
/**
 * Make sure that a color is valid in the sRGB gamut and convert it to OKLCH.
 * @param c
 */
export declare function clampToGamut(c: ColorTypes): import("colorjs.io/src/color.js").PlainColorObject;
//# sourceMappingURL=color-utils.d.ts.map