/**
 * Decorate computableArtifact with a caching `request()` method which will
 * automatically call `computableArtifact.compute_()` under the hood.
 * @template {{name: string, compute_(dependencies: unknown, context: LH.Artifacts.ComputedContext): Promise<unknown>}} C
 * @template {Array<keyof LH.Util.FirstParamType<C['compute_']>>} K
 * @param {C} computableArtifact
 * @param {(K & ([keyof LH.Util.FirstParamType<C['compute_']>] extends [K[number]] ? unknown : never)) | null} keys
 *        List of properties of `dependencies` used by `compute_`; other properties are filtered out.
 *        Use `null` to allow all properties. Ensures that only required properties are used for caching result.
 *        For optional properties of `dependencies`, undefined cannot be used and if found is treated as an error.
 *        This is to guard against developer mistakes. For optional properties, make it nullable instead.
 */
export function makeComputedArtifact<C extends {
    name: string;
    compute_(dependencies: unknown, context: LH.Artifacts.ComputedContext): Promise<unknown>;
}, K extends Array<keyof LH.Util.FirstParamType<C["compute_"]>>>(computableArtifact: C, keys: (K & ([keyof LH.Util.FirstParamType<C["compute_"]>] extends [K[number]] ? unknown : never)) | null): C & {
    request: (dependencies: LH.Util.FirstParamType<C["compute_"]>, context: LH.Artifacts.ComputedContext) => ReturnType<C["compute_"]>;
};
//# sourceMappingURL=computed-artifact.d.ts.map