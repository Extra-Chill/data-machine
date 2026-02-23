/**
 * Private function to allow the unit tests to allow
 * a mock module to access the private APIs.
 *
 * @param {string} name The name of the module.
 */
export function allowCoreModule(name: string): void;
/**
 * Private function to allow the unit tests to set
 * a custom list of allowed modules.
 */
export function resetAllowedCoreModules(): void;
/**
 * Private function to allow the unit tests to reset
 * the list of registered private apis.
 */
export function resetRegisteredPrivateApis(): void;
export function __dangerousOptInToUnstableAPIsOnlyForCoreModules(consent: string, moduleName: string): {
    lock: typeof lock;
    unlock: typeof unlock;
};
/**
 * Binds private data to an object.
 * It does not alter the passed object in any way, only
 * registers it in an internal map of private data.
 *
 * The private data can't be accessed by any other means
 * than the `unlock` function.
 *
 * @example
 * ```js
 * const object = {};
 * const privateData = { a: 1 };
 * lock( object, privateData );
 *
 * object
 * // {}
 *
 * unlock( object );
 * // { a: 1 }
 * ```
 *
 * @param {any} object      The object to bind the private data to.
 * @param {any} privateData The private data to bind to the object.
 */
declare function lock(object: any, privateData: any): void;
/**
 * Unlocks the private data bound to an object.
 *
 * It does not alter the passed object in any way, only
 * returns the private data paired with it using the `lock()`
 * function.
 *
 * @example
 * ```js
 * const object = {};
 * const privateData = { a: 1 };
 * lock( object, privateData );
 *
 * object
 * // {}
 *
 * unlock( object );
 * // { a: 1 }
 * ```
 *
 * @param {any} object The object to unlock the private data from.
 * @return {any} The private data bound to the object.
 */
declare function unlock(object: any): any;
export {};
//# sourceMappingURL=implementation.d.ts.map