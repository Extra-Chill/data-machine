export default createCurrentHook;
/**
 * Returns a function which, when invoked, will return the name of the
 * currently running hook, or `null` if no hook of the given type is currently
 * running.
 *
 * @param {import('.').Hooks}    hooks    Hooks instance.
 * @param {import('.').StoreKey} storeKey
 *
 * @return {() => string | null} Function that returns the current hook name or null.
 */
declare function createCurrentHook(hooks: import('.').Hooks, storeKey: import('.').StoreKey): () => string | null;
//# sourceMappingURL=createCurrentHook.d.ts.map