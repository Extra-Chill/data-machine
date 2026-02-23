/**
 * WordPress dependencies
 */
import { createContext, useContext } from '@wordpress/element';
export const FlexContext = createContext({
  flexItemDisplay: undefined
});
export const useFlexContext = () => useContext(FlexContext);
//# sourceMappingURL=context.js.map