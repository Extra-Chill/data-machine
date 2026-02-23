export { CriticalRequestChainsComputed as CriticalRequestChains };
declare const CriticalRequestChainsComputed: typeof CriticalRequestChains & {
    request: (dependencies: {
        URL: LH.Artifacts["URL"];
        SourceMaps: LH.Artifacts["SourceMaps"];
        devtoolsLog: LH.DevtoolsLog;
        trace: LH.Trace;
        settings: LH.Audit.Context["settings"];
    }, context: LH.Artifacts.ComputedContext) => Promise<import("../index.js").Artifacts.CriticalRequestNode>;
};
declare class CriticalRequestChains {
    /**
     * For now, we use network priorities as a proxy for "render-blocking"/critical-ness.
     * It's imperfect, but there is not a higher-fidelity signal available yet.
     * @see https://docs.google.com/document/d/1bCDuq9H1ih9iNjgzyAL0gpwNFiEP4TZS-YLRp_RuMlc
     * @param {Lantern.Types.NetworkRequest} request
     * @param {Lantern.Types.NetworkRequest} mainResource
     * @return {boolean}
     */
    static isCritical(request: Lantern.Types.NetworkRequest, mainResource: Lantern.Types.NetworkRequest): boolean;
    /**
     * Create a tree of critical requests.
     * @param {LH.Artifacts.NetworkRequest} mainResource
     * @param {LH.Gatherer.Simulation.GraphNode} graph
     * @return {LH.Artifacts.CriticalRequestNode}
     */
    static extractChainsFromGraph(mainResource: LH.Artifacts.NetworkRequest, graph: LH.Gatherer.Simulation.GraphNode): LH.Artifacts.CriticalRequestNode;
    /**
     * @param {{URL: LH.Artifacts['URL'], SourceMaps: LH.Artifacts['SourceMaps'], devtoolsLog: LH.DevtoolsLog, trace: LH.Trace, settings: LH.Audit.Context['settings']}} data
     * @param {LH.Artifacts.ComputedContext} context
     * @return {Promise<LH.Artifacts.CriticalRequestNode>}
     */
    static compute_(data: {
        URL: LH.Artifacts["URL"];
        SourceMaps: LH.Artifacts["SourceMaps"];
        devtoolsLog: LH.DevtoolsLog;
        trace: LH.Trace;
        settings: LH.Audit.Context["settings"];
    }, context: LH.Artifacts.ComputedContext): Promise<LH.Artifacts.CriticalRequestNode>;
}
import * as Lantern from '../lib/lantern/lantern.js';
//# sourceMappingURL=critical-request-chains.d.ts.map