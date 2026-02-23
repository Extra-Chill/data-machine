/**
 * Internal dependencies
 */
import './register-color-spaces';
import type { Ramp } from './types';
export declare const WHITE: import("colorjs.io/src/color.js").PlainColorObject;
export declare const BLACK: import("colorjs.io/src/color.js").PlainColorObject;
export declare const UNIVERSAL_CONTRAST_TOPUP = 0.02;
export declare const WHITE_TEXT_CONTRAST_MARGIN = 3.1;
export declare const ACCENT_SCALE_BASE_LIGHTNESS_THRESHOLDS: {
    readonly lighter: {
        readonly min: 0.2;
        readonly max: 0.4;
    };
    readonly darker: {
        readonly min: 0.75;
        readonly max: 0.98;
    };
};
export declare const CONTRAST_EPSILON = 0.004;
export declare const MAX_BISECTION_ITERATIONS = 10;
export declare const CONTRAST_COMBINATIONS: {
    bgs: (keyof Ramp)[];
    fgs: (keyof Ramp)[];
    target: number;
}[];
export declare const DEFAULT_SEED_COLORS: {
    bg: string;
    primary: string;
    info: string;
    success: string;
    caution: string;
    warning: string;
    error: string;
};
//# sourceMappingURL=constants.d.ts.map