export default RenderBlockingResources;
declare class RenderBlockingResources extends Audit {
    /**
     * @param {LH.Artifacts} artifacts
     * @param {LH.Audit.Context} context
     * @return {Promise<{fcpWastedMs: number, lcpWastedMs: number, results: Array<{url: string, totalBytes: number, wastedMs: number}>}>}
     */
    static computeResults(artifacts: LH.Artifacts, context: LH.Audit.Context): Promise<{
        fcpWastedMs: number;
        lcpWastedMs: number;
        results: Array<{
            url: string;
            totalBytes: number;
            wastedMs: number;
        }>;
    }>;
    /**
     * Estimates how much faster this page would reach FCP if we inlined all the used CSS from the
     * render blocking stylesheets and deferred all the scripts. This is more conservative than
     * removing all the assets and more aggressive than inlining everything.
     *
     * *Most* of the time, scripts in the head are there accidentally/due to lack of awareness
     * rather than necessity, so we're comfortable with this balance. In the worst case, we're telling
     * devs that they should be able to get to a reasonable first paint without JS, which is not a bad
     * thing.
     *
     * @param {LH.Gatherer.Simulation.Simulator} simulator
     * @param {LH.Gatherer.Simulation.GraphNode} fcpGraph
     * @param {Set<string>} deferredIds
     * @param {Map<string, number>} wastedCssBytesByUrl
     * @param {LH.Artifacts.DetectedStack[]} Stacks
     * @return {number}
     */
    static estimateSavingsWithGraphs(simulator: LH.Gatherer.Simulation.Simulator, fcpGraph: LH.Gatherer.Simulation.GraphNode, deferredIds: Set<string>, wastedCssBytesByUrl: Map<string, number>, Stacks: LH.Artifacts.DetectedStack[]): number;
    /**
     * @param {LH.Artifacts} artifacts
     * @param {LH.Audit.Context} context
     * @return {Promise<Map<string, number>>}
     */
    static computeWastedCSSBytes(artifacts: LH.Artifacts, context: LH.Audit.Context): Promise<Map<string, number>>;
    /**
     * @param {LH.Artifacts} artifacts
     * @param {LH.Audit.Context} context
     * @return {Promise<LH.Audit.Product>}
     */
    static audit(artifacts: LH.Artifacts, context: LH.Audit.Context): Promise<LH.Audit.Product>;
}
export namespace UIStrings {
    let title: string;
    let description: string;
}
import { Audit } from '../audit.js';
//# sourceMappingURL=render-blocking-resources.d.ts.map