/**
 * WordPress dependencies
 */
import { createContext, useContext } from '@wordpress/element';
const RovingTabIndexContext = createContext(undefined);
export const useRovingTabIndexContext = () => useContext(RovingTabIndexContext);
export const RovingTabIndexProvider = RovingTabIndexContext.Provider;
//# sourceMappingURL=roving-tab-index-context.js.map