export default Doctype;
declare class Doctype extends Audit {
    /**
     * @param {LH.Artifacts} artifacts
     * @param {LH.Audit.Context} context
     * @return {Promise<LH.Audit.Product>}
     */
    static audit(artifacts: LH.Artifacts, context: LH.Audit.Context): Promise<LH.Audit.Product>;
}
export namespace UIStrings {
    let title: string;
    let failureTitle: string;
    let description: string;
    let explanationNoDoctype: string;
    let explanationWrongDoctype: string;
    let explanationLimitedQuirks: string;
    let explanationPublicId: string;
    let explanationSystemId: string;
    let explanationBadDoctype: string;
}
import { Audit } from '../audit.js';
//# sourceMappingURL=doctype.d.ts.map