/**
 * WordPress dependencies
 */
import { useMemo } from '@wordpress/element';

/**
 * Internal dependencies
 */
import * as styles from '../styles';
import { useContextSystem } from '../../context';
import { useCx } from '../../utils';
export function useBorderBoxControlVisualizer(props) {
  const {
    className,
    value,
    size = 'default',
    ...otherProps
  } = useContextSystem(props, 'BorderBoxControlVisualizer');

  // Generate class names.
  const cx = useCx();
  const classes = useMemo(() => {
    return cx(styles.borderBoxControlVisualizer(value, size), className);
  }, [cx, className, value, size]);
  return {
    ...otherProps,
    className: classes,
    value
  };
}
//# sourceMappingURL=hook.js.map