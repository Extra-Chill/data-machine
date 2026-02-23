/**
 * External dependencies
 */
import type { Meta, StoryFn } from '@storybook/react';
/**
 * Internal dependencies
 */
import CheckboxControl from '..';
declare const meta: Meta<typeof CheckboxControl>;
export default meta;
export declare const Default: StoryFn<typeof CheckboxControl>;
export declare const Indeterminate: StoryFn<typeof CheckboxControl>;
/**
 * For more complex designs, a custom `<label>` element can be associated with the checkbox
 * by leaving the `label` prop undefined and using the `id` and `htmlFor` props instead.
 * Because the label element also functions as a click target for the checkbox, [do not
 * place interactive elements](https://developer.mozilla.org/en-US/docs/Web/HTML/Element/label#interactive_content)
 * such as links or buttons inside the `<label>` node.
 *
 * Similarly, a custom description can be added by omitting the `help` prop
 * and using the `aria-describedby` prop instead.
 */
export declare const WithCustomLabel: StoryFn<typeof CheckboxControl>;
//# sourceMappingURL=index.story.d.ts.map