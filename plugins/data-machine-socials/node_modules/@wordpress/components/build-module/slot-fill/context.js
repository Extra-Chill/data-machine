/**
 * WordPress dependencies
 */
import { createContext } from '@wordpress/element';
/**
 * Internal dependencies
 */

const initialValue = {
  registerSlot: () => {},
  unregisterSlot: () => {},
  registerFill: () => {},
  unregisterFill: () => {},
  getSlot: () => undefined,
  getFills: () => [],
  subscribe: () => () => {}
};
export const SlotFillContext = createContext(initialValue);
export default SlotFillContext;
//# sourceMappingURL=context.js.map