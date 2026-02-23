/**
 * Internal dependencies
 */
import type { FontSizePickerProps, FontSize } from './types';
/**
 * Some themes use css vars for their font sizes, so until we
 * have the way of calculating them don't display them.
 *
 * @param value The value that is checked.
 * @return Whether the value is a simple css value.
 */
export declare function isSimpleCssValue(value: NonNullable<FontSizePickerProps['value']>): boolean;
/**
 * If all of the given font sizes have the same unit (e.g. 'px'), return that
 * unit. Otherwise return null.
 *
 * @param fontSizes List of font sizes.
 * @return The common unit, or null.
 */
export declare function getCommonSizeUnit(fontSizes: FontSize[]): string | null | undefined;
//# sourceMappingURL=utils.d.ts.map