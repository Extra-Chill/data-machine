/**
 * WordPress dependencies
 */
import { useContext } from '@wordpress/element';
import { useObservableValue } from '@wordpress/compose';

/**
 * Internal dependencies
 */
import SlotFillContext from './slot-fill-context';
export default function useSlotFills(name) {
  const registry = useContext(SlotFillContext);
  return useObservableValue(registry.fills, name);
}
//# sourceMappingURL=use-slot-fills.js.map