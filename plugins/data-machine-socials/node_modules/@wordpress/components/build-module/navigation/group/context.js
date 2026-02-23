/**
 * WordPress dependencies
 */
import { createContext, useContext } from '@wordpress/element';

/**
 * Internal dependencies
 */

export const NavigationGroupContext = createContext({
  group: undefined
});
export const useNavigationGroupContext = () => useContext(NavigationGroupContext);
//# sourceMappingURL=context.js.map