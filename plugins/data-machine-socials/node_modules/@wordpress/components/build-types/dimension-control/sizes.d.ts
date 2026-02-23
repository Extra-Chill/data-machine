/**
 * Sizes
 *
 * defines the sizes used in dimension controls
 * all hardcoded `size` values are based on the value of
 * the Sass variable `$block-padding` from
 * `packages/block-editor/src/components/dimension-control/sizes.js`.
 */
/**
 * Internal dependencies
 */
import type { Size } from './types';
/**
 * Finds the correct size object from the provided sizes
 * table by size slug (eg: `medium`)
 *
 * @param sizes containing objects for each size definition.
 * @param slug  a string representation of the size (eg: `medium`).
 */
export declare const findSizeBySlug: (sizes: Size[], slug: string) => Size | undefined;
declare const _default: {
    name: string;
    slug: string;
}[];
export default _default;
//# sourceMappingURL=sizes.d.ts.map