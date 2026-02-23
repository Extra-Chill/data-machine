export { NetworkAnalysisComputed as NetworkAnalysis };
declare const NetworkAnalysisComputed: typeof NetworkAnalysis & {
    request: (dependencies: import("../index.js").DevtoolsLog, context: LH.Artifacts.ComputedContext) => Promise<import("../index.js").Artifacts.NetworkAnalysis>;
};
declare class NetworkAnalysis {
    /**
     * @param {LH.DevtoolsLog} devtoolsLog
     * @param {LH.Artifacts.ComputedContext} context
     * @return {Promise<LH.Artifacts.NetworkAnalysis>}
     */
    static compute_(devtoolsLog: LH.DevtoolsLog, context: LH.Artifacts.ComputedContext): Promise<LH.Artifacts.NetworkAnalysis>;
}
//# sourceMappingURL=network-analysis.d.ts.map