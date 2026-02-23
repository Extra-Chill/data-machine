export default BFCache;
declare class BFCache extends Audit {
    /**
     * @param {LH.Artifacts} artifacts
     * @return {Promise<LH.Audit.Product>}
     */
    static audit(artifacts: LH.Artifacts): Promise<LH.Audit.Product>;
}
export namespace UIStrings {
    let title: string;
    let failureTitle: string;
    let description: string;
    let actionableFailureType: string;
    let notActionableFailureType: string;
    let supportPendingFailureType: string;
    let failureReasonColumn: string;
    let failureTypeColumn: string;
    let warningHeadless: string;
    let displayValue: string;
}
import { Audit } from './audit.js';
//# sourceMappingURL=bf-cache.d.ts.map