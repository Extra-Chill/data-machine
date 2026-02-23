export default LegacyJavascript;
export type ByteEfficiencyProduct = import("./byte-efficiency-audit.js").ByteEfficiencyProduct;
export type Item = LH.Audit.ByteEfficiencyItem & {
    subItems: {
        type: "subitems";
        items: SubItem[];
    };
};
export type SubItem = {
    signal: string;
    location: LH.Audit.Details.SourceLocationValue;
};
declare class LegacyJavascript extends ByteEfficiencyAudit {
    /**
     * @param {LH.Artifacts} artifacts
     * @param {Array<LH.Artifacts.NetworkRequest>} networkRecords
     * @param {LH.Audit.Context} context
     * @return {Promise<ByteEfficiencyProduct>}
     */
    static audit_(artifacts: LH.Artifacts, networkRecords: Array<LH.Artifacts.NetworkRequest>, context: LH.Audit.Context): Promise<ByteEfficiencyProduct>;
}
export namespace UIStrings {
    let title: string;
    let description: string;
    let detectedCoreJs2Warning: string;
}
import { ByteEfficiencyAudit } from './byte-efficiency-audit.js';
//# sourceMappingURL=legacy-javascript.d.ts.map