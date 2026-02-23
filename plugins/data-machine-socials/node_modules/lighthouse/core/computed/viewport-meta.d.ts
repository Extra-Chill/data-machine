export { ViewportMetaComputed as ViewportMeta };
export type ViewportMetaResult = {
    /**
     * Whether the page has any viewport tag.
     */
    hasViewportTag: boolean;
    /**
     * Whether the viewport tag is optimized for mobile screens.
     */
    isMobileOptimized: boolean;
    /**
     * Warnings if the parser encountered invalid content in the viewport tag.
     */
    parserWarnings: Array<string>;
    /**
     * The `content` attribute value, if a viewport was defined.
     */
    rawContentString: string | undefined;
};
declare const ViewportMetaComputed: typeof ViewportMeta & {
    request: (dependencies: {
        name?: string;
        content?: string;
        property?: string;
        httpEquiv?: string;
        charset?: string;
        node: import("../index.js").Artifacts.NodeDetails;
    }[], context: LH.Artifacts.ComputedContext) => Promise<ViewportMetaResult>;
};
declare class ViewportMeta {
    /**
     * @param {LH.GathererArtifacts['MetaElements']} MetaElements
     * @return {Promise<ViewportMetaResult>}
    */
    static compute_(MetaElements: LH.GathererArtifacts["MetaElements"]): Promise<ViewportMetaResult>;
}
//# sourceMappingURL=viewport-meta.d.ts.map