export { ScreenshotsComputed as Screenshots };
declare const ScreenshotsComputed: typeof Screenshots & {
    request: (dependencies: import("../index.js").Trace, context: LH.Artifacts.ComputedContext) => Promise<{
        timestamp: number;
        datauri: string;
    }[]>;
};
declare class Screenshots {
    /**
     * @param {LH.Trace} trace
     * @return {Promise<Array<{timestamp: number, datauri: string}>>}
    */
    static compute_(trace: LH.Trace): Promise<Array<{
        timestamp: number;
        datauri: string;
    }>>;
}
//# sourceMappingURL=screenshots.d.ts.map