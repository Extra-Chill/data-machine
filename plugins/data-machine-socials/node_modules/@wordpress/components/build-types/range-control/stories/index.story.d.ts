/**
 * External dependencies
 */
import type { Meta, StoryFn } from '@storybook/react';
/**
 * Internal dependencies
 */
import RangeControl from '..';
declare const meta: Meta<typeof RangeControl>;
export default meta;
export declare const Default: StoryFn<typeof RangeControl>;
/**
 * Setting the `step` prop to `"any"` will allow users to select non-integer
 * values. This also overrides both `withInputField` and `showTooltip` props to
 * `false`.
 */
export declare const WithAnyStep: StoryFn<typeof RangeControl>;
/**
 * Use `marks` to render a visual representation of `step` ticks. Marks may be
 * automatically generated or custom mark indicators can be provided by an
 * `Array`.
 */
export declare const WithIntegerStepAndMarks: StoryFn<typeof RangeControl>;
/**
 * Decimal values may be used for `marks` rendered as a visual representation of
 * `step` ticks. Marks may be automatically generated or custom mark indicators
 * can be provided by an `Array`.
 */
export declare const WithDecimalStepAndMarks: StoryFn<typeof RangeControl>;
/**
 * A negative `min` value can be used to constrain `RangeControl` values. Mark
 * indicators can represent negative values as well. Marks may be automatically
 * generated or custom mark indicators can be provided by an `Array`.
 */
export declare const WithNegativeMinimumAndMarks: StoryFn<typeof RangeControl>;
/**
 * The entire range of valid values for a `RangeControl` may be negative. Mark
 * indicators can represent negative values as well. Marks may be automatically
 * generated or custom mark indicators can be provided by an `Array`.
 */
export declare const WithNegativeRangeAndMarks: StoryFn<typeof RangeControl>;
/**
 * When a `RangeControl` has a `step` value of `any` a user may select
 * non-integer values. This may still be used in conjunction with `marks`
 * rendering a visual representation of `step` ticks.
 */
export declare const WithAnyStepAndMarks: StoryFn<typeof RangeControl>;
//# sourceMappingURL=index.story.d.ts.map