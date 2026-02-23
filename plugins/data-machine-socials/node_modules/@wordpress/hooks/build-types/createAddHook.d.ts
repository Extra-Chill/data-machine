export default createAddHook;
/**
 * Adds the hook to the appropriate hooks container.
 */
export type AddHook = (hookName: string, namespace: string, callback: import('.').Callback, priority?: number | undefined) => any;
/**
 * @callback AddHook
 *
 * Adds the hook to the appropriate hooks container.
 *
 * @param {string}               hookName      Name of hook to add
 * @param {string}               namespace     The unique namespace identifying the callback in the form `vendor/plugin/function`.
 * @param {import('.').Callback} callback      Function to call when the hook is run
 * @param {number}               [priority=10] Priority of this hook
 */
/**
 * Returns a function which, when invoked, will add a hook.
 *
 * @param {import('.').Hooks}    hooks    Hooks instance.
 * @param {import('.').StoreKey} storeKey
 *
 * @return {AddHook} Function that adds a new hook.
 */
declare function createAddHook(hooks: import('.').Hooks, storeKey: import('.').StoreKey): AddHook;
//# sourceMappingURL=createAddHook.d.ts.map