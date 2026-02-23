/**
 * Copies the given text to the clipboard when the element is clicked.
 *
 * @template {HTMLElement} TElementType
 * @param {string | (() => string)} text      The text to copy. Use a function if not
 *                                            already available and expensive to compute.
 * @param {Function}                onSuccess Called when to text is copied.
 *
 * @return {import('react').Ref<TElementType>} A ref to assign to the target element.
 */
export default function useCopyToClipboard<TElementType extends HTMLElement>(text: string | (() => string), onSuccess: Function): import("react").Ref<TElementType>;
//# sourceMappingURL=index.d.ts.map