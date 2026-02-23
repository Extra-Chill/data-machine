export default createHasHook;
/**
 * Returns whether any handlers are attached for the given hookName and optional namespace.
 */
export type HasHook = (hookName: string, namespace?: string | undefined) => boolean;
/**
 * @callback HasHook
 *
 * Returns whether any handlers are attached for the given hookName and optional namespace.
 *
 * @param {string} hookName    The name of the hook to check for.
 * @param {string} [namespace] Optional. The unique namespace identifying the callback
 *                             in the form `vendor/plugin/function`.
 *
 * @return {boolean} Whether there are handlers that are attached to the given hook.
 */
/**
 * Returns a function which, when invoked, will return whether any handlers are
 * attached to a particular hook.
 *
 * @param {import('.').Hooks}    hooks    Hooks instance.
 * @param {import('.').StoreKey} storeKey
 *
 * @return {HasHook} Function that returns whether any handlers are
 *                   attached to a particular hook and optional namespace.
 */
declare function createHasHook(hooks: import('.').Hooks, storeKey: import('.').StoreKey): HasHook;
//# sourceMappingURL=createHasHook.d.ts.map