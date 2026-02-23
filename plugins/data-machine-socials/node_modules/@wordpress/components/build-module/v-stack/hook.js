/**
 * Internal dependencies
 */

import { useContextSystem } from '../context';
import { useHStack } from '../h-stack';
export function useVStack(props) {
  const {
    expanded = false,
    alignment = 'stretch',
    ...otherProps
  } = useContextSystem(props, 'VStack');
  const hStackProps = useHStack({
    direction: 'column',
    expanded,
    alignment,
    ...otherProps
  });
  return hStackProps;
}
//# sourceMappingURL=hook.js.map