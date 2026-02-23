export { PageDependencyGraphComputed as PageDependencyGraph };
declare const PageDependencyGraphComputed: typeof PageDependencyGraph & {
    request: (dependencies: {
        trace: LH.Trace;
        devtoolsLog: LH.DevtoolsLog;
        settings: LH.Audit.Context["settings"];
        URL: LH.Artifacts["URL"];
        SourceMaps: LH.Artifacts["SourceMaps"];
        fromTrace: boolean;
    }, context: LH.Artifacts.ComputedContext) => Promise<import("../../types/gatherer.js").default.Simulation.GraphNode>;
};
declare class PageDependencyGraph {
    /**
     * @param {{trace: LH.Trace, devtoolsLog: LH.DevtoolsLog, settings: LH.Audit.Context['settings'], URL: LH.Artifacts['URL'], SourceMaps: LH.Artifacts['SourceMaps'], fromTrace: boolean}} data
     * @param {LH.Artifacts.ComputedContext} context
     * @return {Promise<LH.Gatherer.Simulation.GraphNode>}
     */
    static compute_(data: {
        trace: LH.Trace;
        devtoolsLog: LH.DevtoolsLog;
        settings: LH.Audit.Context["settings"];
        URL: LH.Artifacts["URL"];
        SourceMaps: LH.Artifacts["SourceMaps"];
        fromTrace: boolean;
    }, context: LH.Artifacts.ComputedContext): Promise<LH.Gatherer.Simulation.GraphNode>;
}
//# sourceMappingURL=page-dependency-graph.d.ts.map