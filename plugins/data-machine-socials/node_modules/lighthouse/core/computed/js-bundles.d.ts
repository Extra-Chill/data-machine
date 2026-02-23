export { JSBundlesComputed as JSBundles };
declare const JSBundlesComputed: typeof JSBundles & {
    request: (dependencies: Pick<import("../index.js").Artifacts, "Scripts" | "SourceMaps">, context: LH.Artifacts.ComputedContext) => Promise<import("../index.js").Artifacts.Bundle[]>;
};
declare class JSBundles {
    /**
     * @param {Pick<LH.Artifacts, 'SourceMaps'|'Scripts'>} artifacts
     */
    static compute_(artifacts: Pick<LH.Artifacts, "SourceMaps" | "Scripts">): Promise<import("../index.js").Artifacts.Bundle[]>;
}
//# sourceMappingURL=js-bundles.d.ts.map