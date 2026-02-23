/**
 * WordPress dependencies
 */

import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { store as blockEditorStore } from '../../store';
import { unlock } from '../../lock-unlock';
export default function useListViewClientIds({
  blocks,
  rootClientId
}) {
  return useSelect(select => {
    const {
      getDraggedBlockClientIds,
      getSelectedBlockClientIds,
      getEnabledClientIdsTree
    } = unlock(select(blockEditorStore));
    return {
      selectedClientIds: getSelectedBlockClientIds(),
      draggedClientIds: getDraggedBlockClientIds(),
      clientIdsTree: blocks !== null && blocks !== void 0 ? blocks : getEnabledClientIdsTree(rootClientId)
    };
  }, [blocks, rootClientId]);
}
//# sourceMappingURL=use-list-view-client-ids.js.map