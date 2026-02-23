export type DOM = import("./dom.js").DOM;
export type DetailsRenderer = import("./details-renderer.js").DetailsRenderer;
export type NetworkSegment = {
    node: LH.Audit.Details.SimpleCriticalRequestNode[string] | LH.Audit.Details.NetworkNode[string];
    isLastChild: boolean;
    hasChildren: boolean;
    treeMarkers: boolean[];
};
/** @typedef {import('./dom.js').DOM} DOM */
/** @typedef {import('./details-renderer.js').DetailsRenderer} DetailsRenderer */
/**
 * @typedef NetworkSegment
 * @property {LH.Audit.Details.SimpleCriticalRequestNode[string]|LH.Audit.Details.NetworkNode[string]} node
 * @property {boolean} isLastChild
 * @property {boolean} hasChildren
 * @property {boolean[]} treeMarkers
 */
export class CriticalRequestChainRenderer {
    /**
     * Helper to create context for each critical-request-chain node based on its
     * parent. Calculates if this node is the last child, whether it has any
     * children itself and what the tree looks like all the way back up to the root,
     * so the tree markers can be drawn correctly.
     * @param {LH.Audit.Details.SimpleCriticalRequestNode|LH.Audit.Details.NetworkNode} parent
     * @param {string} id
     * @param {Array<boolean>=} treeMarkers
     * @param {boolean=} parentIsLastChild
     * @return {NetworkSegment}
     */
    static createSegment(parent: LH.Audit.Details.SimpleCriticalRequestNode | LH.Audit.Details.NetworkNode, id: string, treeMarkers?: Array<boolean> | undefined, parentIsLastChild?: boolean | undefined): NetworkSegment;
    /**
     * Creates the DOM for a tree segment.
     * @param {DOM} dom
     * @param {NetworkSegment} segment
     * @param {DetailsRenderer} detailsRenderer
     * @return {Node}
     */
    static createChainNode(dom: DOM, segment: NetworkSegment, detailsRenderer: DetailsRenderer): Node;
    /**
     * Recursively builds a tree from segments.
     * @param {DOM} dom
     * @param {NetworkSegment} segment
     * @param {Element} elem Parent element.
     * @param {DetailsRenderer} detailsRenderer
     */
    static buildTree(dom: DOM, segment: NetworkSegment, elem: Element, detailsRenderer: DetailsRenderer): void;
    /**
     * @param {DOM} dom
     * @param {LH.Audit.Details.CriticalRequestChain|LH.Audit.Details.NetworkTree} details
     * @param {DetailsRenderer} detailsRenderer
     * @return {Element}
     */
    static render(dom: DOM, details: LH.Audit.Details.CriticalRequestChain | LH.Audit.Details.NetworkTree, detailsRenderer: DetailsRenderer): Element;
}
//# sourceMappingURL=crc-details-renderer.d.ts.map