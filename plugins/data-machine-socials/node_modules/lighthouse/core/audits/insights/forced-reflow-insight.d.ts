export default ForcedReflowInsight;
declare class ForcedReflowInsight extends Audit {
    /**
     * @param {import('@paulirish/trace_engine/models/trace/insights/ForcedReflow.js').ForcedReflowAggregatedData} topLevelFunctionCallData
     * @returns {LH.Audit.Details.Table}
     */
    static makeTopFunctionTable(topLevelFunctionCallData: import("@paulirish/trace_engine/models/trace/insights/ForcedReflow.js").ForcedReflowAggregatedData): LH.Audit.Details.Table;
    /**
     * @param {import('@paulirish/trace_engine/models/trace/insights/ForcedReflow.js').ForcedReflowInsightModel} insight
     * @returns {LH.Audit.Details.Table}
     */
    static makeBottomUpTable(insight: import("@paulirish/trace_engine/models/trace/insights/ForcedReflow.js").ForcedReflowInsightModel): LH.Audit.Details.Table;
    /**
     * @param {LH.Artifacts} artifacts
     * @param {LH.Audit.Context} context
     * @return {Promise<LH.Audit.Product>}
     */
    static audit(artifacts: LH.Artifacts, context: LH.Audit.Context): Promise<LH.Audit.Product>;
}
import { Audit } from '../audit.js';
//# sourceMappingURL=forced-reflow-insight.d.ts.map