export { SpeedlineComputed as Speedline };
declare const SpeedlineComputed: typeof Speedline & {
    request: (dependencies: import("../index.js").Trace, context: LH.Artifacts.ComputedContext) => Promise<import("../index.js").Artifacts.Speedline>;
};
declare class Speedline {
    /**
     * @param {LH.Trace} trace
     * @param {LH.Artifacts.ComputedContext} context
     * @return {Promise<LH.Artifacts.Speedline>}
     */
    static compute_(trace: LH.Trace, context: LH.Artifacts.ComputedContext): Promise<LH.Artifacts.Speedline>;
}
//# sourceMappingURL=speedline.d.ts.map