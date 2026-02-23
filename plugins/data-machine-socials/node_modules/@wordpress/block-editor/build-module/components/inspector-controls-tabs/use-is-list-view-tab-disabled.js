// List view tab restricts the blocks that may render to it via the
// allowlist below.
const allowlist = ['core/navigation'];
export const useIsListViewTabDisabled = blockName => {
  return !allowlist.includes(blockName);
};
export default useIsListViewTabDisabled;
//# sourceMappingURL=use-is-list-view-tab-disabled.js.map