/**
 * @param {LH.Result} lhr
 * @param {LH.Renderer.Options} opts
 * @return {HTMLElement}
 */
export function renderReport(lhr: LH.Result, opts?: LH.Renderer.Options): HTMLElement;
/**
 * @param {LH.ReportResult.Category} category
 * @param {Parameters<CategoryRenderer['renderCategoryScore']>[2]=} options
 * @return {DocumentFragment}
 */
export function renderCategoryScore(category: LH.ReportResult.Category, options?: Parameters<CategoryRenderer["renderCategoryScore"]>[2] | undefined): DocumentFragment;
/**
 * @param {Blob} blob
 * @param {string} filename
 */
export function saveFile(blob: Blob, filename: string): void;
/**
 * @param {string} markdownText
 * @return {Element}
 */
export function convertMarkdownCodeSnippets(markdownText: string): Element;
/**
 * @return {DocumentFragment}
 */
export function createStylesElement(): DocumentFragment;
import { CategoryRenderer } from './category-renderer.js';
//# sourceMappingURL=api.d.ts.map