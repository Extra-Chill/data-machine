export { LargestContentfulPaintAllFramesComputed as LargestContentfulPaintAllFrames };
declare const LargestContentfulPaintAllFramesComputed: typeof LargestContentfulPaintAllFrames & {
    request: (dependencies: import("../../index.js").Artifacts.MetricComputationDataInput, context: LH.Artifacts.ComputedContext) => Promise<import("../../index.js").Artifacts.Metric | import("../../index.js").Artifacts.LanternMetric>;
};
declare class LargestContentfulPaintAllFrames extends NavigationMetric {
    /**
     * TODO: Simulate LCP all frames in lantern.
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
//# sourceMappingURL=largest-contentful-paint-all-frames.d.ts.map