/**
 * Get schema of possible paths for phrasing content.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/Guide/HTML/Content_categories#Phrasing_content
 *
 * @param {string} [context] Set to "paste" to exclude invisible elements and
 *                           sensitive data.
 *
 * @return {Partial<ContentSchema>} Schema.
 */
export function getPhrasingContentSchema(context?: string | undefined): Partial<ContentSchema>;
/**
 * Find out whether or not the given node is phrasing content.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/Guide/HTML/Content_categories#Phrasing_content
 *
 * @param {Node} node The node to test.
 *
 * @return {boolean} True if phrasing content, false if not.
 */
export function isPhrasingContent(node: Node): boolean;
/**
 * @param {Node} node
 * @return {boolean} Node is text content
 */
export function isTextContent(node: Node): boolean;
export type ContentSchema = Record<string, SemanticElementDefinition>;
export type SemanticElementDefinition = {
    /**
     * Content attributes
     */
    attributes?: string[] | undefined;
    /**
     * Content attributes
     */
    children?: ContentSchema | undefined;
};
//# sourceMappingURL=phrasing-content.d.ts.map