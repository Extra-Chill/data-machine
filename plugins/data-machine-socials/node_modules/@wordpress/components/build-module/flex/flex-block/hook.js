/**
 * Internal dependencies
 */

import { useContextSystem } from '../../context';
import { useFlexItem } from '../flex-item';
export function useFlexBlock(props) {
  const otherProps = useContextSystem(props, 'FlexBlock');
  const flexItemProps = useFlexItem({
    isBlock: true,
    ...otherProps
  });
  return flexItemProps;
}
//# sourceMappingURL=hook.js.map