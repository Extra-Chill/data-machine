export { TimingSummaryComputed as TimingSummary };
declare const TimingSummaryComputed: typeof TimingSummary & {
    request: (dependencies: {
        trace: LH.Trace;
        devtoolsLog: LH.DevtoolsLog;
        gatherContext: LH.Artifacts["GatherContext"];
        settings: LH.Util.ImmutableObject<LH.Config.Settings>;
        URL: LH.Artifacts["URL"];
        SourceMaps: LH.Artifacts["SourceMaps"];
    }, context: LH.Artifacts.ComputedContext) => Promise<{
        metrics: LH.Artifacts.TimingSummary;
        debugInfo: Record<string, boolean>;
    }>;
};
declare class TimingSummary {
    /**
       * @param {LH.Trace} trace
       * @param {LH.DevtoolsLog} devtoolsLog
       * @param {LH.Artifacts['GatherContext']} gatherContext
       * @param {LH.Util.ImmutableObject<LH.Config.Settings>} settings
       * @param {LH.Artifacts['URL']} URL
       * @param {LH.Artifacts['SourceMaps']} SourceMaps
       * @param {LH.Artifacts.ComputedContext} context
       * @return {Promise<{metrics: LH.Artifacts.TimingSummary, debugInfo: Record<string,boolean>}>}
       */
    static summarize(trace: LH.Trace, devtoolsLog: LH.DevtoolsLog, gatherContext: LH.Artifacts["GatherContext"], settings: LH.Util.ImmutableObject<LH.Config.Settings>, URL: LH.Artifacts["URL"], SourceMaps: LH.Artifacts["SourceMaps"], context: LH.Artifacts.ComputedContext): Promise<{
        metrics: LH.Artifacts.TimingSummary;
        debugInfo: Record<string, boolean>;
    }>;
    /**
     * @param {{trace: LH.Trace, devtoolsLog: LH.DevtoolsLog, gatherContext: LH.Artifacts['GatherContext']; settings: LH.Util.ImmutableObject<LH.Config.Settings>, URL: LH.Artifacts['URL'], SourceMaps: LH.Artifacts['SourceMaps']}} data
     * @param {LH.Artifacts.ComputedContext} context
     * @return {Promise<{metrics: LH.Artifacts.TimingSummary, debugInfo: Record<string,boolean>}>}
     */
    static compute_(data: {
        trace: LH.Trace;
        devtoolsLog: LH.DevtoolsLog;
        gatherContext: LH.Artifacts["GatherContext"];
        settings: LH.Util.ImmutableObject<LH.Config.Settings>;
        URL: LH.Artifacts["URL"];
        SourceMaps: LH.Artifacts["SourceMaps"];
    }, context: LH.Artifacts.ComputedContext): Promise<{
        metrics: LH.Artifacts.TimingSummary;
        debugInfo: Record<string, boolean>;
    }>;
}
//# sourceMappingURL=timing-summary.d.ts.map