/**
 * Internal dependencies
 */

import { useContextSystem } from '../context';
import { useText } from '../text';
import { getHeadingFontSize } from '../utils/font-size';
import { CONFIG, COLORS } from '../utils';
export function useHeading(props) {
  const {
    as: asProp,
    level = 2,
    color = COLORS.gray[900],
    isBlock = true,
    weight = CONFIG.fontWeightHeading,
    ...otherProps
  } = useContextSystem(props, 'Heading');
  const as = asProp || `h${level}`;
  const a11yProps = {};
  if (typeof as === 'string' && as[0] !== 'h') {
    // If not a semantic `h` element, add a11y props:
    a11yProps.role = 'heading';
    a11yProps['aria-level'] = typeof level === 'string' ? parseInt(level) : level;
  }
  const textProps = useText({
    color,
    isBlock,
    weight,
    size: getHeadingFontSize(level),
    ...otherProps
  });
  return {
    ...textProps,
    ...a11yProps,
    as
  };
}
//# sourceMappingURL=hook.js.map