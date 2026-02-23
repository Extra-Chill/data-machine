export default createDidHook;
/**
 * Returns the number of times an action has been fired.
 */
export type DidHook = (hookName: string) => number | undefined;
/**
 * @callback DidHook
 *
 * Returns the number of times an action has been fired.
 *
 * @param {string} hookName The hook name to check.
 *
 * @return {number | undefined} The number of times the hook has run.
 */
/**
 * Returns a function which, when invoked, will return the number of times a
 * hook has been called.
 *
 * @param {import('.').Hooks}    hooks    Hooks instance.
 * @param {import('.').StoreKey} storeKey
 *
 * @return {DidHook} Function that returns a hook's call count.
 */
declare function createDidHook(hooks: import('.').Hooks, storeKey: import('.').StoreKey): DidHook;
//# sourceMappingURL=createDidHook.d.ts.map