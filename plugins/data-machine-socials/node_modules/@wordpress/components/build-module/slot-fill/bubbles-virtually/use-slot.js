/**
 * WordPress dependencies
 */
import { useMemo, useContext } from '@wordpress/element';
import { useObservableValue } from '@wordpress/compose';

/**
 * Internal dependencies
 */
import SlotFillContext from './slot-fill-context';
export default function useSlot(name) {
  const registry = useContext(SlotFillContext);
  const slot = useObservableValue(registry.slots, name);
  const api = useMemo(() => ({
    updateSlot: fillProps => registry.updateSlot(name, fillProps),
    unregisterSlot: ref => registry.unregisterSlot(name, ref),
    registerFill: ref => registry.registerFill(name, ref),
    unregisterFill: ref => registry.unregisterFill(name, ref)
  }), [name, registry]);
  return {
    ...slot,
    ...api
  };
}
//# sourceMappingURL=use-slot.js.map