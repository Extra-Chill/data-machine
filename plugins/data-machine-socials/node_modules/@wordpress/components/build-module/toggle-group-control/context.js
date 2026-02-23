/**
 * WordPress dependencies
 */
import { createContext, useContext } from '@wordpress/element';

/**
 * Internal dependencies
 */

const ToggleGroupControlContext = createContext({});
export const useToggleGroupControlContext = () => useContext(ToggleGroupControlContext);
export default ToggleGroupControlContext;
//# sourceMappingURL=context.js.map