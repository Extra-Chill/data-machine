/**
 * External dependencies
 */
import type { Meta, StoryFn } from '@storybook/react';
/**
 * Internal dependencies
 */
import { UnitControl } from '../';
declare const meta: Meta<typeof UnitControl>;
export default meta;
export declare const Default: StoryFn<typeof UnitControl>;
/**
 * If the `isPressEnterToChange` prop is set to `true`, the `onChange` callback
 * will not fire while a new value is typed in the input field (you can verify this
 * behavior by inspecting the console's output).
 */
export declare const PressEnterToChange: StoryFn<typeof UnitControl>;
/**
 * Most of `NumberControl`'s props can be passed to `UnitControl`, and they will
 * affect its numeric input field.
 */
export declare const TweakingTheNumberInput: StoryFn<typeof UnitControl>;
/**
 * When only one unit is available, the unit selection dropdown becomes static text.
 */
export declare const WithSingleUnit: StoryFn<typeof UnitControl>;
/**
 * It is possible to pass a custom list of units. Every time the unit changes,
 * if the `isResetValueOnUnitChange` is set to `true`, the input's quantity is
 * reset to the new unit's default value.
 */
export declare const WithCustomUnits: StoryFn<typeof UnitControl>;
//# sourceMappingURL=index.story.d.ts.map