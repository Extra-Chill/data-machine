/**
 * Internal dependencies
 */
import type { Ramp } from '../lib/types';
type RampTableProps = {
    ramps: {
        seed: {
            name: keyof Ramp;
            value: string;
        };
        ramp: Record<keyof Ramp, string>;
        warnings?: string[];
    }[];
};
export declare const RampTable: import("react").ForwardRefExoticComponent<RampTableProps & import("react").RefAttributes<HTMLDivElement>>;
export {};
//# sourceMappingURL=ramp-table.d.ts.map