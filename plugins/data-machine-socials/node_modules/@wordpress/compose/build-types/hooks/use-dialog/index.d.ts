/**
 * External dependencies
 */
import type { RefCallback, SyntheticEvent } from 'react';
import useFocusOnMount from '../use-focus-on-mount';
import useFocusOutside from '../use-focus-outside';
type DialogOptions = {
    /**
     * Determines whether focus should be automatically moved to the popover
     * when it mounts. `false` causes no focus shift, `true` causes the popover
     * itself to gain focus, and `firstElement` focuses the first focusable
     * element within the popover.
     *
     * @default 'firstElement'
     */
    focusOnMount?: Parameters<typeof useFocusOnMount>[0];
    /**
     * Determines whether tabbing is constrained to within the popover,
     * preventing keyboard focus from leaving the popover content without
     * explicit focus elsewhere, or whether the popover remains part of the
     * wider tab order.
     * If no value is passed, it will be derived from `focusOnMount`.
     *
     * @see focusOnMount
     * @default `focusOnMount` !== false
     */
    constrainTabbing?: boolean;
    onClose?: () => void;
    /**
     * Use the `onClose` prop instead.
     *
     * @deprecated
     */
    __unstableOnClose?: (type: string | undefined, event: SyntheticEvent) => void;
};
type useDialogReturn = [
    RefCallback<HTMLElement>,
    ReturnType<typeof useFocusOutside> & Pick<HTMLElement, 'tabIndex'>
];
/**
 * Returns a ref and props to apply to a dialog wrapper to enable the following behaviors:
 *  - constrained tabbing.
 *  - focus on mount.
 *  - return focus on unmount.
 *  - focus outside.
 *
 * @param options Dialog Options.
 */
declare function useDialog(options: DialogOptions): useDialogReturn;
export default useDialog;
//# sourceMappingURL=index.d.ts.map