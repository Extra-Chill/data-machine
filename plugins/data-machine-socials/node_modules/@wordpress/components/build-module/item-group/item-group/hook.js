/**
 * Internal dependencies
 */

import { useContextSystem } from '../../context';

/**
 * Internal dependencies
 */
import * as styles from '../styles';
import { useCx } from '../../utils/hooks/use-cx';
export function useItemGroup(props) {
  const {
    className,
    isBordered = false,
    isRounded = true,
    isSeparated = false,
    role = 'list',
    ...otherProps
  } = useContextSystem(props, 'ItemGroup');
  const cx = useCx();
  const classes = cx(isBordered && styles.bordered, isSeparated && styles.separated, isRounded && styles.rounded, className);
  return {
    isBordered,
    className: classes,
    role,
    isSeparated,
    ...otherProps
  };
}
//# sourceMappingURL=hook.js.map