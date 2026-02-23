export default createRemoveHook;
/**
 * Removes the specified callback (or all callbacks) from the hook with a given hookName
 * and namespace.
 */
export type RemoveHook = (hookName: string, namespace: string) => number | undefined;
/**
 * @callback RemoveHook
 * Removes the specified callback (or all callbacks) from the hook with a given hookName
 * and namespace.
 *
 * @param {string} hookName  The name of the hook to modify.
 * @param {string} namespace The unique namespace identifying the callback in the
 *                           form `vendor/plugin/function`.
 *
 * @return {number | undefined} The number of callbacks removed.
 */
/**
 * Returns a function which, when invoked, will remove a specified hook or all
 * hooks by the given name.
 *
 * @param {import('.').Hooks}    hooks             Hooks instance.
 * @param {import('.').StoreKey} storeKey
 * @param {boolean}              [removeAll=false] Whether to remove all callbacks for a hookName,
 *                                                 without regard to namespace. Used to create
 *                                                 `removeAll*` functions.
 *
 * @return {RemoveHook} Function that removes hooks.
 */
declare function createRemoveHook(hooks: import('.').Hooks, storeKey: import('.').StoreKey, removeAll?: boolean | undefined): RemoveHook;
//# sourceMappingURL=createRemoveHook.d.ts.map