export default LayoutShifts;
export type Item = LH.Audit.Details.TableItem & {
    node?: LH.Audit.Details.NodeValue;
    score: number;
    subItems?: {
        type: "subitems";
        items: SubItem[];
    };
};
export type SubItem = {
    extra?: LH.Audit.Details.NodeValue | LH.Audit.Details.UrlValue;
    cause: LH.IcuMessage;
};
declare class LayoutShifts extends Audit {
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
    let columnScore: string;
    let rootCauseUnsizedMedia: string;
    let rootCauseFontChanges: string;
    let rootCauseInjectedIframe: string;
    let displayValueShiftsFound: string;
}
import { Audit } from './audit.js';
//# sourceMappingURL=layout-shifts.d.ts.map