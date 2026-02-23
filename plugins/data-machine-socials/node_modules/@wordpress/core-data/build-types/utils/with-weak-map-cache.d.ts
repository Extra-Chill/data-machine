export default withWeakMapCache;
/**
 * Given a function, returns an enhanced function which caches the result and
 * tracks in WeakMap. The result is only cached if the original function is
 * passed a valid object-like argument (requirement for WeakMap key).
 *
 * @param {Function} fn Original function.
 *
 * @return {Function} Enhanced caching function.
 */
declare function withWeakMapCache(fn: Function): Function;
//# sourceMappingURL=with-weak-map-cache.d.ts.map