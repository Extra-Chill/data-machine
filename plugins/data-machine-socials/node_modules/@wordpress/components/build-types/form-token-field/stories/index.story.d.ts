/**
 * External dependencies
 */
import type { Meta, StoryFn } from '@storybook/react';
/**
 * Internal dependencies
 */
import FormTokenField from '../';
declare const meta: Meta<typeof FormTokenField>;
export default meta;
export declare const Default: StoryFn<typeof FormTokenField>;
export declare const Async: StoryFn<typeof FormTokenField>;
export declare const DropdownSelector: StoryFn<typeof FormTokenField>;
/**
 * The rendered output of each suggestion can be customized by passing a
 * render function to the `__experimentalRenderItem` prop. (This is still an experimental feature
 * and is subject to change.)
 */
export declare const WithCustomRenderItem: StoryFn<typeof FormTokenField>;
/**
 * Only values for which the `__experimentalValidateInput` function returns
 * `true` will be tokenized. (This is still an experimental feature and is
 * subject to change.)
 */
export declare const WithValidatedInput: StoryFn<typeof FormTokenField>;
//# sourceMappingURL=index.story.d.ts.map