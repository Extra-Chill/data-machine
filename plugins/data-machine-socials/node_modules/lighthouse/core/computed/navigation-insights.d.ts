export { NavigationInsightsComputed as NavigationInsights };
declare const NavigationInsightsComputed: typeof NavigationInsights & {
    request: (dependencies: {
        trace: LH.Trace;
        settings: LH.Audit.Context["settings"];
        SourceMaps: LH.Artifacts["SourceMaps"];
    }, context: LH.Artifacts.ComputedContext) => Promise<import("@paulirish/trace_engine/models/trace/insights/types.js").InsightSet>;
};
/**
 * @fileoverview Gets insights from the shared trace engine for the navigation audited by Lighthouse.
 * Only usable in navigation mode.
 */
declare class NavigationInsights {
    /**
      * @param {{trace: LH.Trace, settings: LH.Audit.Context['settings'], SourceMaps: LH.Artifacts['SourceMaps']}} data
      * @param {LH.Artifacts.ComputedContext} context
     */
    static compute_(data: {
        trace: LH.Trace;
        settings: LH.Audit.Context["settings"];
        SourceMaps: LH.Artifacts["SourceMaps"];
    }, context: LH.Artifacts.ComputedContext): Promise<import("@paulirish/trace_engine/models/trace/insights/types.js").InsightSet>;
}
//# sourceMappingURL=navigation-insights.d.ts.map