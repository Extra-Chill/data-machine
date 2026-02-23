/**
 * WordPress dependencies
 */
import { createContext, useContext } from '@wordpress/element';

/**
 * Internal dependencies
 */

export const ItemGroupContext = createContext({
  size: 'medium'
});
export const useItemGroupContext = () => useContext(ItemGroupContext);
//# sourceMappingURL=context.js.map