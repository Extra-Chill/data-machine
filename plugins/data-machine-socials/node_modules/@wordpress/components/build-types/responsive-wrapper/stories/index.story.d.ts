/**
 * External dependencies
 */
import type { Meta, StoryFn } from '@storybook/react';
/**
 * Internal dependencies
 */
import ResponsiveWrapper from '..';
declare const meta: Meta<typeof ResponsiveWrapper>;
export default meta;
export declare const Default: import("@storybook/types").AnnotatedStoryFn<import("@storybook/react").ReactRenderer, import("../types").ResponsiveWrapperProps>;
/**
 * When passing an `SVG` element as the `<ResponsiveWrapper />`'s child, make
 * sure that it has the `viewbox` and the `preserveAspectRatio` set.
 *
 * When dealing with SVGs, it may not be possible to derive its `naturalWidth`
 * and `naturalHeight` and therefore passing them as propertied to
 * `<ResponsiveWrapper />`. In this case, the SVG simply keeps scaling up to fill
 * its container, unless the `height` and `width` attributes are specified.
 */
export declare const WithSVG: StoryFn<typeof ResponsiveWrapper>;
//# sourceMappingURL=index.story.d.ts.map