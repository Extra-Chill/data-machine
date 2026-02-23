/**
 * @typedef {Record<string, any>} ComparableObject
 */
/**
 * Returns true if the two arrays or objects are shallow equal, or false
 * otherwise. Also handles primitive values, just in case.
 *
 * @param {unknown} a First object or array to compare.
 * @param {unknown} b Second object or array to compare.
 *
 * @return {boolean} Whether the two values are shallow equal.
 */
export default function isShallowEqual(a: unknown, b: unknown): boolean;
export { default as isShallowEqualObjects } from "./objects";
export { default as isShallowEqualArrays } from "./arrays";
export type ComparableObject = Record<string, any>;
//# sourceMappingURL=index.d.ts.map