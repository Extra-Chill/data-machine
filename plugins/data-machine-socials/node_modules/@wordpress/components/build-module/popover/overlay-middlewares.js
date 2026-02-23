/**
 * External dependencies
 */

import { size } from '@floating-ui/react-dom';
export function overlayMiddlewares() {
  return [{
    name: 'overlay',
    fn({
      rects
    }) {
      return rects.reference;
    }
  }, size({
    apply({
      rects,
      elements
    }) {
      var _elements$floating;
      const {
        firstElementChild
      } = (_elements$floating = elements.floating) !== null && _elements$floating !== void 0 ? _elements$floating : {};

      // Only HTMLElement instances have the `style` property.
      if (!(firstElementChild instanceof HTMLElement)) {
        return;
      }

      // Reduce the height of the popover to the available space.
      Object.assign(firstElementChild.style, {
        width: `${rects.reference.width}px`,
        height: `${rects.reference.height}px`
      });
    }
  })];
}
//# sourceMappingURL=overlay-middlewares.js.map