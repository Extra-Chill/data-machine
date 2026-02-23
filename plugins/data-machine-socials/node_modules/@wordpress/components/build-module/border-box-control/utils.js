/**
 * External dependencies
 */

/**
 * Internal dependencies
 */
import { parseCSSUnitValue } from '../utils/unit-values';
const sides = ['top', 'right', 'bottom', 'left'];
const borderProps = ['color', 'style', 'width'];
export const isEmptyBorder = border => {
  if (!border) {
    return true;
  }
  return !borderProps.some(prop => border[prop] !== undefined);
};
export const isDefinedBorder = border => {
  // No border, no worries :)
  if (!border) {
    return false;
  }

  // If we have individual borders per side within the border object we
  // need to check whether any of those side borders have been set.
  if (hasSplitBorders(border)) {
    const allSidesEmpty = sides.every(side => isEmptyBorder(border[side]));
    return !allSidesEmpty;
  }

  // If we have a top-level border only, check if that is empty. e.g.
  // { color: undefined, style: undefined, width: undefined }
  // Border radius can still be set within the border object as it is
  // handled separately.
  return !isEmptyBorder(border);
};
export const isCompleteBorder = border => {
  if (!border) {
    return false;
  }
  return borderProps.every(prop => border[prop] !== undefined);
};
export const hasSplitBorders = (border = {}) => {
  return Object.keys(border).some(side => sides.indexOf(side) !== -1);
};
export const hasMixedBorders = borders => {
  if (!hasSplitBorders(borders)) {
    return false;
  }
  const shorthandBorders = sides.map(side => getShorthandBorderStyle(borders?.[side]));
  return !shorthandBorders.every(border => border === shorthandBorders[0]);
};
export const getSplitBorders = border => {
  if (!border || isEmptyBorder(border)) {
    return undefined;
  }
  return {
    top: border,
    right: border,
    bottom: border,
    left: border
  };
};
export const getBorderDiff = (original, updated) => {
  const diff = {};
  if (original.color !== updated.color) {
    diff.color = updated.color;
  }
  if (original.style !== updated.style) {
    diff.style = updated.style;
  }
  if (original.width !== updated.width) {
    diff.width = updated.width;
  }
  return diff;
};
export const getCommonBorder = borders => {
  if (!borders) {
    return undefined;
  }
  const colors = [];
  const styles = [];
  const widths = [];
  sides.forEach(side => {
    colors.push(borders[side]?.color);
    styles.push(borders[side]?.style);
    widths.push(borders[side]?.width);
  });
  const allColorsMatch = colors.every(value => value === colors[0]);
  const allStylesMatch = styles.every(value => value === styles[0]);
  const allWidthsMatch = widths.every(value => value === widths[0]);
  return {
    color: allColorsMatch ? colors[0] : undefined,
    style: allStylesMatch ? styles[0] : undefined,
    width: allWidthsMatch ? widths[0] : getMostCommonUnit(widths)
  };
};
export const getShorthandBorderStyle = (border, fallbackBorder) => {
  if (isEmptyBorder(border)) {
    return fallbackBorder;
  }
  const {
    color: fallbackColor,
    style: fallbackStyle,
    width: fallbackWidth
  } = fallbackBorder || {};
  const {
    color = fallbackColor,
    style = fallbackStyle,
    width = fallbackWidth
  } = border;
  const hasVisibleBorder = !!width && width !== '0' || !!color;
  const borderStyle = hasVisibleBorder ? style || 'solid' : style;
  return [width, borderStyle, color].filter(Boolean).join(' ');
};
export const getMostCommonUnit = values => {
  // Collect all the CSS units.
  const units = values.map(value => value === undefined ? undefined : parseCSSUnitValue(`${value}`)[1]);

  // Return the most common unit out of only the defined CSS units.
  const filteredUnits = units.filter(value => value !== undefined);
  return mode(filteredUnits);
};

/**
 * Finds the mode value out of the array passed favouring the first value
 * as a tiebreaker.
 *
 * @param values Values to determine the mode from.
 *
 * @return The mode value.
 */
function mode(values) {
  if (values.length === 0) {
    return undefined;
  }
  const map = {};
  let maxCount = 0;
  let currentMode;
  values.forEach(value => {
    map[value] = map[value] === undefined ? 1 : map[value] + 1;
    if (map[value] > maxCount) {
      currentMode = value;
      maxCount = map[value];
    }
  });
  return currentMode;
}
//# sourceMappingURL=utils.js.map