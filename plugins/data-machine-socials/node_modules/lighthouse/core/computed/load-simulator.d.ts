export { LoadSimulatorComputed as LoadSimulator };
declare const LoadSimulatorComputed: typeof LoadSimulator & {
    request: (dependencies: {
        devtoolsLog: LH.DevtoolsLog;
        settings: LH.Audit.Context["settings"];
    }, context: LH.Artifacts.ComputedContext) => Promise<import("../../types/gatherer.js").default.Simulation.Simulator>;
};
declare class LoadSimulator {
    /**
     * @param {{devtoolsLog: LH.DevtoolsLog, settings: LH.Audit.Context['settings']}} data
     * @param {LH.Artifacts.ComputedContext} context
     * @return {Promise<LH.Gatherer.Simulation.Simulator>}
     */
    static compute_(data: {
        devtoolsLog: LH.DevtoolsLog;
        settings: LH.Audit.Context["settings"];
    }, context: LH.Artifacts.ComputedContext): Promise<LH.Gatherer.Simulation.Simulator>;
    /**
     * @param {LH.Artifacts.NetworkAnalysis} networkAnalysis
     * @return {LH.PrecomputedLanternData}
     */
    static convertAnalysisToSaveableLanternData(networkAnalysis: LH.Artifacts.NetworkAnalysis): LH.PrecomputedLanternData;
}
//# sourceMappingURL=load-simulator.d.ts.map