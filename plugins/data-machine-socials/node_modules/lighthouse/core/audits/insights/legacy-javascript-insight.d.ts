export default LegacyJavaScriptInsight;
export type Item = LH.Audit.Details.TableItem & {
    subItems: {
        type: "subitems";
        items: SubItem[];
    };
};
export type SubItem = {
    signal: string;
    location: LH.Audit.Details.SourceLocationValue;
};
/** @typedef {LH.Audit.Details.TableItem & {subItems: {type: 'subitems', items: SubItem[]}}} Item */
/** @typedef {{signal: string, location: LH.Audit.Details.SourceLocationValue}} SubItem */
declare class LegacyJavaScriptInsight extends Audit {
    /**
     * @param {LH.Artifacts} artifacts
     * @param {LH.Audit.Context} context
     * @return {Promise<LH.Audit.Product>}
     */
    static audit(artifacts: LH.Artifacts, context: LH.Audit.Context): Promise<LH.Audit.Product>;
}
import { Audit } from '../audit.js';
//# sourceMappingURL=legacy-javascript-insight.d.ts.map