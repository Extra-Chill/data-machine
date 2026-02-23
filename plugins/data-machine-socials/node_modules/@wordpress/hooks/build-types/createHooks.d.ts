/**
 * Internal class for constructing hooks. Use `createHooks()` function
 *
 * Note, it is necessary to expose this class to make its type public.
 *
 * @private
 */
export class _Hooks {
    /** @type {import('.').Store} actions */
    actions: import('.').Store;
    /** @type {import('.').Store} filters */
    filters: import('.').Store;
    addAction: import("./createAddHook").AddHook;
    addFilter: import("./createAddHook").AddHook;
    removeAction: import("./createRemoveHook").RemoveHook;
    removeFilter: import("./createRemoveHook").RemoveHook;
    hasAction: import("./createHasHook").HasHook;
    hasFilter: import("./createHasHook").HasHook;
    removeAllActions: import("./createRemoveHook").RemoveHook;
    removeAllFilters: import("./createRemoveHook").RemoveHook;
    doAction: (hookName: string, ...args: unknown[]) => unknown;
    applyFilters: (hookName: string, ...args: unknown[]) => unknown;
    currentAction: () => string | null;
    currentFilter: () => string | null;
    doingAction: import("./createDoingHook").DoingHook;
    doingFilter: import("./createDoingHook").DoingHook;
    didAction: import("./createDidHook").DidHook;
    didFilter: import("./createDidHook").DidHook;
}
export default createHooks;
export type Hooks = _Hooks;
/** @typedef {_Hooks} Hooks */
/**
 * Returns an instance of the hooks object.
 *
 * @return {Hooks} A Hooks instance.
 */
declare function createHooks(): Hooks;
//# sourceMappingURL=createHooks.d.ts.map