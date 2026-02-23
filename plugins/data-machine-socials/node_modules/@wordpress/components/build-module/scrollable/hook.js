/**
 * WordPress dependencies
 */
import { useMemo } from '@wordpress/element';

/**
 * Internal dependencies
 */

import { useContextSystem } from '../context';
import * as styles from './styles';
import { useCx } from '../utils/hooks/use-cx';
export function useScrollable(props) {
  const {
    className,
    scrollDirection = 'y',
    smoothScroll = false,
    ...otherProps
  } = useContextSystem(props, 'Scrollable');
  const cx = useCx();
  const classes = useMemo(() => cx(styles.Scrollable, styles.scrollableScrollbar, smoothScroll && styles.smoothScroll, scrollDirection === 'x' && styles.scrollX, scrollDirection === 'y' && styles.scrollY, scrollDirection === 'auto' && styles.scrollAuto, className), [className, cx, scrollDirection, smoothScroll]);
  return {
    ...otherProps,
    className: classes
  };
}
//# sourceMappingURL=hook.js.map