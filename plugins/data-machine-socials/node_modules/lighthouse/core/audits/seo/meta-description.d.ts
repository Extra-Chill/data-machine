export default Description;
declare class Description extends Audit {
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
    let explanation: string;
}
import { Audit } from '../audit.js';
//# sourceMappingURL=meta-description.d.ts.map