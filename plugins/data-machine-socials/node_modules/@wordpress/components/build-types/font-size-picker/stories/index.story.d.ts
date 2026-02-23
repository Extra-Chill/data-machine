/**
 * External dependencies
 */
import type { Meta, StoryFn } from '@storybook/react';
/**
 * Internal dependencies
 */
import FontSizePicker from '../';
declare const meta: Meta<typeof FontSizePicker>;
export default meta;
export declare const Default: StoryFn<typeof FontSizePicker>;
export declare const WithSlider: StoryFn<typeof FontSizePicker>;
/**
 * With custom font sizes disabled via the `disableCustomFontSizes` prop, the user will
 * only be able to pick one of the predefined sizes passed in `fontSizes`.
 */
export declare const WithCustomSizesDisabled: StoryFn<typeof FontSizePicker>;
/**
 * When there are more than 5 font size options, the UI is no longer a toggle group.
 */
export declare const WithMoreFontSizes: StoryFn<typeof FontSizePicker>;
/**
 * When units like `px` are specified explicitly, it will be shown as a label hint.
 */
export declare const WithUnits: StoryFn<typeof FontSizePicker>;
/**
 * The label hint will not be shown if it is a complex CSS value. Some examples of complex CSS values
 * in this context are CSS functions like `calc()`, `clamp()`, and `var()`.
 */
export declare const WithComplexCSSValues: StoryFn<typeof FontSizePicker>;
//# sourceMappingURL=index.story.d.ts.map