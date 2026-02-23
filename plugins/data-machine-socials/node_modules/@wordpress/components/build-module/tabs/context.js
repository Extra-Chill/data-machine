/**
 * WordPress dependencies
 */
import { createContext, useContext } from '@wordpress/element';

/**
 * Internal dependencies
 */

export const TabsContext = createContext(undefined);
export const useTabsContext = () => useContext(TabsContext);
//# sourceMappingURL=context.js.map