export default HTTPS;
declare class HTTPS extends Audit {
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
    let displayValue: string;
    let columnInsecureURL: string;
    let columnResolution: string;
    let allowed: string;
    let blocked: string;
    let warning: string;
    let upgraded: string;
}
import { Audit } from './audit.js';
//# sourceMappingURL=is-on-https.d.ts.map