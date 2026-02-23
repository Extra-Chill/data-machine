export default CLSCulpritsInsight;
export type SubItem = {
    extra?: LH.Audit.Details.NodeValue | LH.Audit.Details.UrlValue;
    cause: LH.IcuMessage;
};
declare class CLSCulpritsInsight extends Audit {
    /**
     * @param {import('@paulirish/trace_engine/models/trace/insights/CLSCulprits.js').CLSCulpritsInsightModel} insight
     * @param {import('../../lib/trace-engine.js').SaneSyntheticLayoutShift} event
     * @param {LH.Artifacts.TraceElement[]} TraceElements
     * @return {LH.Audit.Details.TableSubItems|undefined}
     */
    static getCulpritSubItems(insight: import("@paulirish/trace_engine/models/trace/insights/CLSCulprits.js").CLSCulpritsInsightModel, event: import("../../lib/trace-engine.js").SaneSyntheticLayoutShift, TraceElements: LH.Artifacts.TraceElement[]): LH.Audit.Details.TableSubItems | undefined;
    /**
     * @param {LH.Artifacts} artifacts
     * @param {LH.Audit.Context} context
     * @return {Promise<LH.Audit.Product>}
     */
    static audit(artifacts: LH.Artifacts, context: LH.Audit.Context): Promise<LH.Audit.Product>;
}
export namespace UIStrings {
    let columnScore: string;
}
import { Audit } from '../audit.js';
//# sourceMappingURL=cls-culprits-insight.d.ts.map