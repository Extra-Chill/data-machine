/**
 * Internal dependencies
 */
import './register-color-spaces';
import type { Ramp, RampDirection, RampConfig, RampResult } from './types';
export declare function buildRamp(seedArg: string, config: RampConfig, { mainDirection, pinLightness, rescaleToFitContrastTargets, }?: {
    mainDirection?: RampDirection;
    pinLightness?: {
        stepName: keyof Ramp;
        value: number;
    };
    rescaleToFitContrastTargets?: boolean;
}): RampResult;
//# sourceMappingURL=index.d.ts.map