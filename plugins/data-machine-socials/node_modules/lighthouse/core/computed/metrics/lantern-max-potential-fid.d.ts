export { LanternMaxPotentialFIDComputed as LanternMaxPotentialFID };
declare const LanternMaxPotentialFIDComputed: typeof LanternMaxPotentialFID & {
    request: (dependencies: import("../../index.js").Artifacts.MetricComputationDataInput, context: LH.Artifacts.ComputedContext) => Promise<import("../../index.js").Artifacts.LanternMetric>;
};
declare class LanternMaxPotentialFID extends Lantern.Metrics.MaxPotentialFID {
    /**
     * @param {LH.Artifacts.MetricComputationDataInput} data
     * @param {LH.Artifacts.ComputedContext} context
     * @param {Omit<Lantern.Metrics.Extras, 'optimistic'>=} extras
     * @return {Promise<LH.Artifacts.LanternMetric>}
     */
    static computeMetricWithGraphs(data: LH.Artifacts.MetricComputationDataInput, context: LH.Artifacts.ComputedContext, extras?: Omit<Lantern.Metrics.Extras, "optimistic"> | undefined): Promise<LH.Artifacts.LanternMetric>;
    /**
     * @param {LH.Artifacts.MetricComputationDataInput} data
     * @param {LH.Artifacts.ComputedContext} context
     * @return {Promise<LH.Artifacts.LanternMetric>}
     */
    static compute_(data: LH.Artifacts.MetricComputationDataInput, context: LH.Artifacts.ComputedContext): Promise<LH.Artifacts.LanternMetric>;
}
import * as Lantern from '../../lib/lantern/lantern.js';
//# sourceMappingURL=lantern-max-potential-fid.d.ts.map