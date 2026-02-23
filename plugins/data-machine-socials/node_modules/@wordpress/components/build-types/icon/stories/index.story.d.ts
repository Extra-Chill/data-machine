/**
 * External dependencies
 */
import type { Meta, StoryFn } from '@storybook/react';
/**
 * Internal dependencies
 */
import Icon from '..';
declare const meta: Meta<typeof Icon>;
export default meta;
export declare const Default: import("@storybook/types").AnnotatedStoryFn<import("@storybook/react").ReactRenderer, import("..").Props>;
export declare const FillColor: StoryFn<typeof Icon>;
export declare const WithAFunction: import("@storybook/types").AnnotatedStoryFn<import("@storybook/react").ReactRenderer, import("..").Props>;
export declare const WithAComponent: import("@storybook/types").AnnotatedStoryFn<import("@storybook/react").ReactRenderer, import("..").Props>;
export declare const WithAnSVG: import("@storybook/types").AnnotatedStoryFn<import("@storybook/react").ReactRenderer, import("..").Props>;
/**
 * Although it's preferred to use icons from the `@wordpress/icons` package, Dashicons are still supported,
 * as long as you are in a context where the Dashicons stylesheet is loaded. To simulate that here,
 * use the Global CSS Injector in the Storybook toolbar at the top and select the "WordPress" preset.
 */
export declare const WithADashicon: StoryFn<typeof Icon>;
//# sourceMappingURL=index.story.d.ts.map