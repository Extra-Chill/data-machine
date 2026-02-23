export default UsesHTTP2Audit;
declare class UsesHTTP2Audit extends Audit {
    /**
     * Computes the estimated effect of all results being converted to http/2 on the provided graph.
     *
     * @param {Array<{url: string}>} results
     * @param {LH.Gatherer.Simulation.GraphNode} graph
     * @param {LH.Gatherer.Simulation.Simulator} simulator
     * @param {{label?: string}=} options
     * @return {{savings: number, simulationBefore: LH.Gatherer.Simulation.Result, simulationAfter: LH.Gatherer.Simulation.Result}}
     */
    static computeWasteWithGraph(results: Array<{
        url: string;
    }>, graph: LH.Gatherer.Simulation.GraphNode, simulator: LH.Gatherer.Simulation.Simulator, options?: {
        label?: string;
    } | undefined): {
        savings: number;
        simulationBefore: LH.Gatherer.Simulation.Result;
        simulationAfter: LH.Gatherer.Simulation.Result;
    };
    /**
     * Determines whether a network request is a "static resource" that would benefit from H2 multiplexing.
     * XHRs, tracking pixels, etc generally don't benefit as much because they aren't requested en-masse
     * for the same origin at the exact same time.
     *
     * @param {LH.Artifacts.NetworkRequest} networkRequest
     * @param {LH.Artifacts.EntityClassification} classifiedEntities
     * @return {boolean}
     */
    static isMultiplexableStaticAsset(networkRequest: LH.Artifacts.NetworkRequest, classifiedEntities: LH.Artifacts.EntityClassification): boolean;
    /**
     * Determine the set of resources that aren't HTTP/2 but should be.
     * We're a little conservative about what we surface for a few reasons:
     *
     *    - The simulator approximation of HTTP/2 is a little more generous than reality.
     *    - There's a bit of debate surrounding HTTP/2 due to its worse performance in environments with high packet loss.**
     *    - It's something that you'd have absolutely zero control over with a third-party (can't defer to fix it for example).
     *
     * Therefore, we only surface requests that were...
     *
     *    - Served over HTTP/1.1 or earlier
     *    - Served over an origin that serves at least 6 static asset requests
     *      (if there aren't more requests than browser's max/host, multiplexing isn't as big a deal)
     *    - Not served on localhost (h2 is a pain to deal with locally & and CI)
     *
     * ** = https://news.ycombinator.com/item?id=19086639
     *      https://www.twilio.com/blog/2017/10/http2-issues.html
     *      https://www.cachefly.com/http-2-is-not-a-magic-bullet/
     *
     * @param {Array<LH.Artifacts.NetworkRequest>} networkRecords
     * @param {LH.Artifacts.EntityClassification} classifiedEntities
     * @return {Array<{url: string, protocol: string}>}
     */
    static determineNonHttp2Resources(networkRecords: Array<LH.Artifacts.NetworkRequest>, classifiedEntities: LH.Artifacts.EntityClassification): Array<{
        url: string;
        protocol: string;
    }>;
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
    let displayValue: string;
    let columnProtocol: string;
}
import { Audit } from '../audit.js';
//# sourceMappingURL=uses-http2.d.ts.map