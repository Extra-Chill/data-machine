/**
 * WordPress dependencies
 */
import deprecated from '@wordpress/deprecated';
import { useMemo } from '@wordpress/element';

/**
 * Internal dependencies
 */

import { useContextSystem } from '../../context';
import { useSurface } from '../../surface';
import * as styles from '../styles';
import { useCx } from '../../utils/hooks/use-cx';
function useDeprecatedProps({
  elevation,
  isElevated,
  ...otherProps
}) {
  const propsToReturn = {
    ...otherProps
  };
  let computedElevation = elevation;
  if (isElevated) {
    var _computedElevation;
    deprecated('Card isElevated prop', {
      since: '5.9',
      alternative: 'elevation'
    });
    (_computedElevation = computedElevation) !== null && _computedElevation !== void 0 ? _computedElevation : computedElevation = 2;
  }

  // The `elevation` prop should only be passed when it's not `undefined`,
  // otherwise it will override the value that gets derived from `useContextSystem`.
  if (typeof computedElevation !== 'undefined') {
    propsToReturn.elevation = computedElevation;
  }
  return propsToReturn;
}
export function useCard(props) {
  const {
    className,
    elevation = 0,
    isBorderless = false,
    isRounded = true,
    size = 'medium',
    ...otherProps
  } = useContextSystem(useDeprecatedProps(props), 'Card');
  const cx = useCx();
  const classes = useMemo(() => {
    return cx(styles.Card, isBorderless && styles.boxShadowless, isRounded && styles.rounded, className);
  }, [className, cx, isBorderless, isRounded]);
  const surfaceProps = useSurface({
    ...otherProps,
    className: classes
  });
  return {
    ...surfaceProps,
    elevation,
    isBorderless,
    isRounded,
    size
  };
}
//# sourceMappingURL=hook.js.map