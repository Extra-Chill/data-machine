/**
 * External dependencies
 */
import { type PlainColorObject, type ColorSpace } from 'colorjs.io/fn';
/**
 * Internal dependencies
 */
import './register-color-spaces';
export interface TaperChromaOptions {
    gamut?: ColorSpace;
    alpha?: number;
    carry?: number;
    cUpperBound?: number;
    radiusLight?: number;
    radiusDark?: number;
    kLight?: number;
    kDark?: number;
    hueFallback?: number;
    achromaEpsilon?: number;
}
/**
 * Given the seed and the target lightness, tapers the chroma smoothly.
 * - C_intended = Cmax(Lt,H0) * alpha * (seedRelative^carry)
 * - Continuous taper vs |Lt - Ls| to softly reduce chroma for neighbors
 * - Downward-only clamp on C (preserve L & H)
 * @param seed
 * @param lTarget
 * @param options
 */
export declare function taperChroma(seed: PlainColorObject, // already OKLCH
lTarget: number, // [0..1]
options?: TaperChromaOptions): {
    l: number;
    c: number;
} | PlainColorObject;
//# sourceMappingURL=taper-chroma.d.ts.map