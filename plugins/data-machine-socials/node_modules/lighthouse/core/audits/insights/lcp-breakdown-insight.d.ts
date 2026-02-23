export default LCPBreakdownInsight;
declare class LCPBreakdownInsight extends Audit {
    /**
     * @param {Required<import('@paulirish/trace_engine/models/trace/insights/LCPBreakdown.js').LCPBreakdownInsightModel>['subparts']} subparts
     * @return {LH.Audit.Details.Table}
     */
    static makeSubpartsTable(subparts: Required<import("@paulirish/trace_engine/models/trace/insights/LCPBreakdown.js").LCPBreakdownInsightModel>["subparts"]): LH.Audit.Details.Table;
    /**
     * @param {LH.Artifacts} artifacts
     * @param {LH.Audit.Context} context
     * @return {Promise<LH.Audit.Product>}
     */
    static audit(artifacts: LH.Artifacts, context: LH.Audit.Context): Promise<LH.Audit.Product>;
}
import { Audit } from '../audit.js';
//# sourceMappingURL=lcp-breakdown-insight.d.ts.map