export default createDoingHook;
/**
 * Returns whether a hook is currently being executed.
 */
export type DoingHook = (hookName?: string | undefined) => boolean;
/**
 * @callback DoingHook
 * Returns whether a hook is currently being executed.
 *
 * @param {string} [hookName] The name of the hook to check for.  If
 *                            omitted, will check for any hook being executed.
 *
 * @return {boolean} Whether the hook is being executed.
 */
/**
 * Returns a function which, when invoked, will return whether a hook is
 * currently being executed.
 *
 * @param {import('.').Hooks}    hooks    Hooks instance.
 * @param {import('.').StoreKey} storeKey
 *
 * @return {DoingHook} Function that returns whether a hook is currently
 *                     being executed.
 */
declare function createDoingHook(hooks: import('.').Hooks, storeKey: import('.').StoreKey): DoingHook;
//# sourceMappingURL=createDoingHook.d.ts.map