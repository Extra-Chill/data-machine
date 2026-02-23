/**
 * WordPress dependencies
 */
import { createContext } from '@wordpress/element';

/**
 * Internal dependencies
 */

const initialContextValue = {
  location: {},
  goTo: () => {},
  goBack: () => {},
  goToParent: () => {},
  addScreen: () => {},
  removeScreen: () => {},
  params: {}
};
export const NavigatorContext = createContext(initialContextValue);
//# sourceMappingURL=context.js.map