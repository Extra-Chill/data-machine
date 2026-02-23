export { MaxPotentialFIDComputed as MaxPotentialFID };
declare const MaxPotentialFIDComputed: typeof MaxPotentialFID & {
    request: (dependencies: import("../../index.js").Artifacts.MetricComputationDataInput, context: LH.Artifacts.ComputedContext) => Promise<import("../../index.js").Artifacts.Metric | import("../../index.js").Artifacts.LanternMetric>;
};
declare class MaxPotentialFID extends NavigationMetric {
    /**
     * @param {LH.Artifacts.NavigationMetricComputationData} data
     * @param {LH.Artifacts.ComputedContext} context
     * @return {Promise<LH.Artifacts.LanternMetric>}
     */
    static computeSimulatedMetric(data: LH.Artifacts.NavigationMetricComputationData, context: LH.Artifacts.ComputedContext): Promise<LH.Artifacts.LanternMetric>;
    /**
     * @param {LH.Artifacts.NavigationMetricComputationData} data
     * @return {Promise<LH.Artifacts.Metric>}
     */
    static computeObservedMetric(data: LH.Artifacts.NavigationMetricComputationData): Promise<LH.Artifacts.Metric>;
}
import { NavigationMetric } from './navigation-metric.js';
//# sourceMappingURL=max-potential-fid.d.ts.map