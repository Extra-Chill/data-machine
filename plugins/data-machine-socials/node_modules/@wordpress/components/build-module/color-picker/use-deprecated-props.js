/**
 * External dependencies
 */
import { colord } from 'colord';
import memoize from 'memize';

/**
 * WordPress dependencies
 */
import { useCallback } from '@wordpress/element';

/**
 * Internal dependencies
 */

function isLegacyProps(props) {
  return typeof props.onChangeComplete !== 'undefined' || typeof props.disableAlpha !== 'undefined' || typeof props.color?.hex === 'string';
}
function getColorFromLegacyProps(color) {
  if (color === undefined) {
    return;
  }
  if (typeof color === 'string') {
    return color;
  }
  if (color.hex) {
    return color.hex;
  }
  return undefined;
}
const transformColorStringToLegacyColor = memoize(color => {
  const colordColor = colord(color);
  const hex = colordColor.toHex();
  const rgb = colordColor.toRgb();
  const hsv = colordColor.toHsv();
  const hsl = colordColor.toHsl();
  return {
    hex,
    rgb,
    hsv,
    hsl,
    source: 'hex',
    oldHue: hsl.h
  };
});
export function useDeprecatedProps(props) {
  const {
    onChangeComplete
  } = props;
  const legacyChangeHandler = useCallback(color => {
    onChangeComplete(transformColorStringToLegacyColor(color));
  }, [onChangeComplete]);
  if (isLegacyProps(props)) {
    return {
      color: getColorFromLegacyProps(props.color),
      enableAlpha: !props.disableAlpha,
      onChange: legacyChangeHandler
    };
  }
  return {
    ...props,
    color: props.color,
    enableAlpha: props.enableAlpha,
    onChange: props.onChange
  };
}
//# sourceMappingURL=use-deprecated-props.js.map