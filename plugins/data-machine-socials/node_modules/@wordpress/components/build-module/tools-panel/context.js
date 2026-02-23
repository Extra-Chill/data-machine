/**
 * WordPress dependencies
 */
import { createContext, useContext } from '@wordpress/element';

/**
 * Internal dependencies
 */

const noop = () => undefined;
export const ToolsPanelContext = createContext({
  menuItems: {
    default: {},
    optional: {}
  },
  hasMenuItems: false,
  isResetting: false,
  shouldRenderPlaceholderItems: false,
  registerPanelItem: noop,
  deregisterPanelItem: noop,
  flagItemCustomization: noop,
  registerResetAllFilter: noop,
  deregisterResetAllFilter: noop,
  areAllOptionalControlsHidden: true
});
export const useToolsPanelContext = () => useContext(ToolsPanelContext);
//# sourceMappingURL=context.js.map