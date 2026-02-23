/**
 * WordPress dependencies
 */
import { createContext, useContext } from '@wordpress/element';

/**
 * Internal dependencies
 */

export const NavigationMenuContext = createContext({
  menu: undefined,
  search: ''
});
export const useNavigationMenuContext = () => useContext(NavigationMenuContext);
//# sourceMappingURL=context.js.map