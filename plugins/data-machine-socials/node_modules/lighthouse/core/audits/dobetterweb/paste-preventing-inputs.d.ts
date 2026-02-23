export default PastePreventingInputsAudit;
declare class PastePreventingInputsAudit extends Audit {
    /**
     * @param {LH.Artifacts} artifacts
     * @return {LH.Audit.Product}
     */
    static audit(artifacts: LH.Artifacts): LH.Audit.Product;
}
export namespace UIStrings {
    let title: string;
    let failureTitle: string;
    let description: string;
}
import { Audit } from '../audit.js';
//# sourceMappingURL=paste-preventing-inputs.d.ts.map