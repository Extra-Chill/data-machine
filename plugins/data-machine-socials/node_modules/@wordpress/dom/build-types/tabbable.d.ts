/**
 * Returns true if the specified element is tabbable, or false otherwise.
 *
 * @param {Element} element Element to test.
 *
 * @return {boolean} Whether element is tabbable.
 */
export function isTabbableIndex(element: Element): boolean;
/**
 * @param {Element} context
 * @return {HTMLElement[]} Tabbable elements within the context.
 */
export function find(context: Element): HTMLElement[];
/**
 * Given a focusable element, find the preceding tabbable element.
 *
 * @param {Element} element The focusable element before which to look. Defaults
 *                          to the active element.
 *
 * @return {HTMLElement|undefined} Preceding tabbable element.
 */
export function findPrevious(element: Element): HTMLElement | undefined;
/**
 * Given a focusable element, find the next tabbable element.
 *
 * @param {Element} element The focusable element after which to look. Defaults
 *                          to the active element.
 *
 * @return {HTMLElement|undefined} Next tabbable element.
 */
export function findNext(element: Element): HTMLElement | undefined;
export type MaybeHTMLInputElement = HTMLElement & {
    type?: string;
    checked?: boolean;
    name?: string;
};
//# sourceMappingURL=tabbable.d.ts.map