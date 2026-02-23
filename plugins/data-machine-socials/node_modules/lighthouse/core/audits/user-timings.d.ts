export default UserTimings;
export type MarkEvent = {
    name: string;
    isMark: true;
    args: LH.TraceEvent["args"];
    startTime: number;
};
export type MeasureEvent = {
    name: string;
    isMark: false;
    args: LH.TraceEvent["args"];
    startTime: number;
    endTime: number;
    duration: number;
};
/** @typedef {{name: string, isMark: true, args: LH.TraceEvent['args'], startTime: number}} MarkEvent */
/** @typedef {{name: string, isMark: false, args: LH.TraceEvent['args'], startTime: number, endTime: number, duration: number}} MeasureEvent */
declare class UserTimings extends Audit {
    /**
     * @return {Array<string>}
     */
    static get excludedPrefixes(): Array<string>;
    /**
     * We remove mark/measures entered by third parties not of interest to the user
     * @param {MarkEvent|MeasureEvent} evt
     * @return {boolean}
     */
    static excludeEvent(evt: MarkEvent | MeasureEvent): boolean;
    /**
     * @param {LH.Artifacts} artifacts
     * @param {LH.Audit.Context} context
     * @return {Promise<LH.Audit.Product>}
     */
    static audit(artifacts: LH.Artifacts, context: LH.Audit.Context): Promise<LH.Audit.Product>;
}
export namespace UIStrings {
    let title: string;
    let description: string;
    let displayValue: string;
    let columnType: string;
}
import { Audit } from './audit.js';
//# sourceMappingURL=user-timings.d.ts.map