/**
 * External dependencies
 */
import type { Meta, StoryFn } from '@storybook/react';
/**
 * Internal dependencies
 */
import { ToggleGroupControl } from '../index';
declare const meta: Meta<typeof ToggleGroupControl>;
export default meta;
export declare const Default: StoryFn<typeof ToggleGroupControl>;
/**
 * A tooltip can be shown for each option by enabling the `showTooltip` prop.
 * The `aria-label` will be used in the tooltip if provided. Otherwise, the
 * `label` will be used.
 */
export declare const WithTooltip: StoryFn<typeof ToggleGroupControl>;
/**
 * The `ToggleGroupControlOptionIcon` component can be used for icon options. A `label` is required
 * on each option for accessibility, which will be shown in a tooltip.
 */
export declare const WithIcons: StoryFn<typeof ToggleGroupControl>;
/**
 * When the `isDeselectable` prop is true, the option can be deselected by clicking on it again.
 */
export declare const Deselectable: StoryFn<typeof ToggleGroupControl>;
//# sourceMappingURL=index.story.d.ts.map