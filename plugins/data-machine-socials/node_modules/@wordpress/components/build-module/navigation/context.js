/**
 * WordPress dependencies
 */
import { createContext, useContext } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { ROOT_MENU } from './constants';
const noop = () => {};
const defaultIsEmpty = () => false;
const defaultGetter = () => undefined;
export const NavigationContext = createContext({
  activeItem: undefined,
  activeMenu: ROOT_MENU,
  setActiveMenu: noop,
  navigationTree: {
    items: {},
    getItem: defaultGetter,
    addItem: noop,
    removeItem: noop,
    menus: {},
    getMenu: defaultGetter,
    addMenu: noop,
    removeMenu: noop,
    childMenu: {},
    traverseMenu: noop,
    isMenuEmpty: defaultIsEmpty
  }
});
export const useNavigationContext = () => useContext(NavigationContext);
//# sourceMappingURL=context.js.map