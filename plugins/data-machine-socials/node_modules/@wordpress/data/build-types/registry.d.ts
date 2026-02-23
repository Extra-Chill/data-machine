/**
 * Creates a new store registry, given an optional object of initial store
 * configurations.
 *
 * @param {Object}  storeConfigs Initial store configurations.
 * @param {Object?} parent       Parent registry.
 *
 * @return {WPDataRegistry} Data registry.
 */
export function createRegistry(storeConfigs?: Object, parent?: Object | null): WPDataRegistry;
export type StoreDescriptor = import("./types").StoreDescriptor<any>;
/**
 * An isolated orchestrator of store registrations.
 */
export type WPDataRegistry = {
    /**
     * Given a namespace key and settings
     * object, registers a new generic
     * store.
     */
    registerGenericStore: Function;
    /**
     * Given a namespace key and settings
     * object, registers a new namespace
     * store.
     */
    registerStore: Function;
    /**
     * Given a function callback, invokes
     * the callback on any change to state
     * within any registered store.
     */
    subscribe: Function;
    /**
     * Given a namespace key, returns an
     * object of the  store's registered
     * selectors.
     */
    select: Function;
    /**
     * Given a namespace key, returns an
     * object of the store's registered
     * action dispatchers.
     */
    dispatch: Function;
};
/**
 * An object of registry function overrides.
 */
export type WPDataPlugin = {
    /**
     * registers store.
     */
    registerStore: Function;
};
//# sourceMappingURL=registry.d.ts.map