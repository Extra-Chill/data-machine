/**
 * Polyfill.
 * Get a collapsed range for a given point.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/API/Document/caretRangeFromPoint
 *
 * @param {DocumentMaybeWithCaretPositionFromPoint} doc The document of the range.
 * @param {number}                                  x   Horizontal position within the current viewport.
 * @param {number}                                  y   Vertical position within the current viewport.
 *
 * @return {Range | null} The best range for the given point.
 */
export default function caretRangeFromPoint(doc: DocumentMaybeWithCaretPositionFromPoint, x: number, y: number): Range | null;
export type DocumentMaybeWithCaretPositionFromPoint = {
    caretPositionFromPoint?: (x: number, y: number) => CaretPosition | null;
} & Document;
export type CaretPosition = {
    readonly offset: number;
    readonly offsetNode: Node;
    getClientRect(): DOMRect | null;
};
//# sourceMappingURL=caret-range-from-point.d.ts.map