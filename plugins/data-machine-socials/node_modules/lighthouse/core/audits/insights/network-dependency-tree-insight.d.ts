export default NetworkDependencyTreeInsight;
declare class NetworkDependencyTreeInsight extends Audit {
    /**
     * @param {import('@paulirish/trace_engine').Insights.Models.NetworkDependencyTree.CriticalRequestNode[]} nodes
     * @return {LH.Audit.Details.NetworkNode}
     */
    static traceEngineNodesToDetailsNodes(nodes: import("@paulirish/trace_engine").Insights.Models.NetworkDependencyTree.CriticalRequestNode[]): LH.Audit.Details.NetworkNode;
    /**
     * @param {LH.Artifacts} artifacts
     * @param {LH.Audit.Context} context
     * @return {Promise<LH.Audit.Product>}
     */
    static audit(artifacts: LH.Artifacts, context: LH.Audit.Context): Promise<LH.Audit.Product>;
}
import { Audit } from '../audit.js';
//# sourceMappingURL=network-dependency-tree-insight.d.ts.map