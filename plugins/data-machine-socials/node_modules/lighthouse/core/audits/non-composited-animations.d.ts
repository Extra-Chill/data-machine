export default NonCompositedAnimations;
declare class NonCompositedAnimations extends Audit {
    /**
     * @param {LH.Artifacts} artifacts
     * @return {Promise<LH.Audit.Product>}
     */
    static audit(artifacts: LH.Artifacts): Promise<LH.Audit.Product>;
}
export namespace UIStrings {
    let title: string;
    let description: string;
    let displayValue: string;
    let unsupportedCSSProperty: string;
    let unsupportedCustomCSSProperty: string;
    let transformDependsBoxSize: string;
    let filterMayMovePixels: string;
    let nonReplaceCompositeMode: string;
    let incompatibleAnimations: string;
    let unsupportedTimingParameters: string;
}
import { Audit } from './audit.js';
//# sourceMappingURL=non-composited-animations.d.ts.map