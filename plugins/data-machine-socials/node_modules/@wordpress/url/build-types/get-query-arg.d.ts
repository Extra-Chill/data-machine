/**
 * @typedef {{[key: string]: QueryArgParsed}} QueryArgObject
 */
/**
 * @typedef {string|string[]|QueryArgObject} QueryArgParsed
 */
/**
 * Returns a single query argument of the url
 *
 * @param {string} url URL.
 * @param {string} arg Query arg name.
 *
 * @example
 * ```js
 * const foo = getQueryArg( 'https://wordpress.org?foo=bar&bar=baz', 'foo' ); // bar
 * ```
 *
 * @return {QueryArgParsed|void} Query arg value.
 */
export function getQueryArg(url: string, arg: string): QueryArgParsed | void;
export type QueryArgObject = {
    [key: string]: QueryArgParsed;
};
export type QueryArgParsed = string | string[] | QueryArgObject;
//# sourceMappingURL=get-query-arg.d.ts.map