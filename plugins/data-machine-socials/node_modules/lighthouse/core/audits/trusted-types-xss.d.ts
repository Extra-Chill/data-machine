export default TrustedTypesXss;
declare class TrustedTypesXss extends Audit {
    /**
     * @param {LH.Artifacts} artifacts
     * @param {LH.Audit.Context} context
     * @return {Promise<{cspHeaders: string[], cspMetaTags: string[]}>}
     */
    static getRawCsps(artifacts: LH.Artifacts, context: LH.Audit.Context): Promise<{
        cspHeaders: string[];
        cspMetaTags: string[];
    }>;
    /**
     * @param {LH.IcuMessage | string} findingDescription
     * @param {LH.IcuMessage=} severity
     * @return {LH.Audit.Details.TableItem}
     */
    static findingToTableItem(findingDescription: LH.IcuMessage | string, severity?: LH.IcuMessage | undefined): LH.Audit.Details.TableItem;
    /**
     * @param {string[]} cspHeaders
     * @param {string[]} cspMetaTags
     * @return {{score: number, results: LH.Audit.Details.TableItem[]}}
     */
    static constructResults(cspHeaders: string[], cspMetaTags: string[]): {
        score: number;
        results: LH.Audit.Details.TableItem[];
    };
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
    let noTrustedTypesToMitigateXss: string;
    let columnSeverity: string;
}
import { Audit } from './audit.js';
//# sourceMappingURL=trusted-types-xss.d.ts.map