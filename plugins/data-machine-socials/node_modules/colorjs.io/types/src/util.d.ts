/**
 * Check if a value is a string (including a String object)
 * @param {any} str - Value to check
 * @returns {str is string}
 */
export function isString(str: any): str is string;
/**
 * Determine the internal JavaScript [[Class]] of an object.
 * @param {any} o - Value to check
 * @returns {string}
 */
export function type(o: any): string;
/**
 * @param {number} n
 * @param {{ precision?: number | undefined, unit?: string | undefined }} options
 * @returns {string}
 */
export function serializeNumber(n: number, { precision, unit }: {
    precision?: number | undefined;
    unit?: string | undefined;
}): string;
/**
 * Check if a value corresponds to a none argument
 * @param {any} n - Value to check
 * @returns {n is null}
 */
export function isNone(n: any): n is null;
/**
 * Replace none values with 0
 * @param {number | null} n
 * @returns {number}
 */
export function skipNone(n: number | null): number;
/**
 * Round a number to a certain number of significant digits
 * @param {number} n - The number to round
 * @param {number} precision - Number of significant digits
 */
export function toPrecision(n: number, precision: number): number;
/**
 * @param {number} start
 * @param {number} end
 * @param {number} p
 */
export function interpolate(start: number, end: number, p: number): number;
/**
 * @param {number} start
 * @param {number} end
 * @param {number} value
 */
export function interpolateInv(start: number, end: number, value: number): number;
/**
 * @param {[number, number]} from
 * @param {[number, number]} to
 * @param {number} value
 */
export function mapRange(from: [number, number], to: [number, number], value: number): number;
/**
 * Clamp value between the minimum and maximum
 * @param {number} min minimum value to return
 * @param {number} val the value to return if it is between min and max
 * @param {number} max maximum value to return
 */
export function clamp(min: number, val: number, max: number): number;
/**
 * Copy sign of one value to another.
 * @param {number} to - Number to copy sign to
 * @param {number} from - Number to copy sign from
 */
export function copySign(to: number, from: number): number;
/**
 * Perform pow on a signed number and copy sign to result
 * @param {number} base The base number
 * @param {number} exp The exponent
 */
export function spow(base: number, exp: number): number;
/**
 * Perform a divide, but return zero if the denominator is zero
 * @param {number} n The numerator
 * @param {number} d The denominator
 */
export function zdiv(n: number, d: number): number;
/**
 * Perform a bisect on a sorted list and locate the insertion point for
 * a value in arr to maintain sorted order.
 * @param {number[]} arr - array of sorted numbers
 * @param {number} value - value to find insertion point for
 * @param {number} lo - used to specify a the low end of a subset of the list
 * @param {number} hi - used to specify a the high end of a subset of the list
 */
export function bisectLeft(arr: number[], value: number, lo?: number, hi?: number): number;
/**
 * Determines whether an argument is an instance of a constructor, including subclasses.
 * This is done by first just checking `instanceof`,
 * and then comparing the string names of the constructors if that fails.
 * @param {any} arg
 * @param {C} constructor
 * @template {new (...args: any) => any} C
 * @returns {arg is InstanceType<C>}
 */
export function isInstance<C extends new (...args: any) => any>(arg: any, constructor: C): arg is InstanceType<C>;
export { default as multiplyMatrices, multiply_v3_m3x3 } from "./multiply-matrices.js";
//# sourceMappingURL=util.d.ts.map