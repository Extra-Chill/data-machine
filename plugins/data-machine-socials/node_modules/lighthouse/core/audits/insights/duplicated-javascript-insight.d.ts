export default DuplicatedJavaScriptInsight;
export type Item = LH.Audit.Details.TableItem & {
    source: string;
    subItems: {
        type: "subitems";
        items: SubItem[];
    };
};
export type SubItem = {
    url: string;
    sourceTransferBytes: number | LH.Audit.Details.TextValue;
};
/** @typedef {LH.Audit.Details.TableItem & {source: string, subItems: {type: 'subitems', items: SubItem[]}}} Item */
/** @typedef {{url: string, sourceTransferBytes: number|LH.Audit.Details.TextValue}} SubItem */
declare class DuplicatedJavaScriptInsight extends Audit {
    /**
     * @param {LH.Artifacts} artifacts
     * @param {LH.Audit.Context} context
     * @return {Promise<LH.Audit.Product>}
     */
    static audit(artifacts: LH.Artifacts, context: LH.Audit.Context): Promise<LH.Audit.Product>;
}
import { Audit } from '../audit.js';
//# sourceMappingURL=duplicated-javascript-insight.d.ts.map