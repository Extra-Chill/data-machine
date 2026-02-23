export { FirstContentfulPaintAllFramesComputed as FirstContentfulPaintAllFrames };
declare const FirstContentfulPaintAllFramesComputed: typeof FirstContentfulPaintAllFrames & {
    request: (dependencies: import("../../index.js").Artifacts.MetricComputationDataInput, context: LH.Artifacts.ComputedContext) => Promise<import("../../index.js").Artifacts.Metric | import("../../index.js").Artifacts.LanternMetric>;
};
declare class FirstContentfulPaintAllFrames extends NavigationMetric {
    /**
     * @return {Promise<LH.Artifacts.LanternMetric>}
     */
    static computeSimulatedMetric(): Promise<LH.Artifacts.LanternMetric>;
    /**
     * @param {LH.Artifacts.NavigationMetricComputationData} data
     * @return {Promise<LH.Artifacts.Metric>}
     */
    static computeObservedMetric(data: LH.Artifacts.NavigationMetricComputationData): Promise<LH.Artifacts.Metric>;
}
import { NavigationMetric } from './navigation-metric.js';
//# sourceMappingURL=first-contentful-paint-all-frames.d.ts.map