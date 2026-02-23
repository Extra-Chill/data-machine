/**
 * External dependencies
 */
import type { Meta, StoryFn } from '@storybook/react';
/**
 * Internal dependencies
 */
import Draggable from '..';
declare const meta: Meta<typeof Draggable>;
export default meta;
export declare const Default: StoryFn<typeof Draggable>;
/**
 * `appendToOwnerDocument` is used to append the element being dragged to the body of the owner document.
 *
 * This is useful when the element being dragged should not receive styles from its parent.
 * For example, when the element's parent sets a `z-index` value that would cause the dragged
 * element to be rendered behind other elements.
 */
export declare const AppendElementToOwnerDocument: StoryFn<typeof Draggable>;
//# sourceMappingURL=index.story.d.ts.map