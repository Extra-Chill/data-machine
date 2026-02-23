export default useWarnOnChange;
/**
 * Hook that performs a shallow comparison between the preview value of an object
 * and the new one, if there's a difference, it prints it to the console.
 * this is useful in performance related work, to check why a component re-renders.
 *
 *  @example
 *
 * ```jsx
 * function MyComponent(props) {
 *    useWarnOnChange(props);
 *
 *    return "Something";
 * }
 * ```
 *
 * @param {object} object Object which changes to compare.
 * @param {string} prefix Just a prefix to show when console logging.
 */
declare function useWarnOnChange(object: object, prefix?: string): void;
//# sourceMappingURL=index.d.ts.map