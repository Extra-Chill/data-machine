/**
 * Returns an object of query arguments of the given URL. If the given URL is
 * invalid or has no querystring, an empty object is returned.
 *
 * @param {string} url URL.
 *
 * @example
 * ```js
 * const foo = getQueryArgs( 'https://wordpress.org?foo=bar&bar=baz' );
 * // { "foo": "bar", "bar": "baz" }
 * ```
 *
 * @return {QueryArgs} Query args object.
 */
export function getQueryArgs(url: string): QueryArgs;
export type QueryArgParsed = import('./get-query-arg').QueryArgParsed;
export type QueryArgs = Record<string, QueryArgParsed>;
//# sourceMappingURL=get-query-args.d.ts.map