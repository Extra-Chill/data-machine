export default ClickjackingMitigation;
declare class ClickjackingMitigation extends Audit {
    /**
     * @param {LH.Artifacts} artifacts
     * @param {LH.Audit.Context} context
     * @return {Promise<{cspHeaders: string[], xfoHeaders: string[]}>}
     */
    static getRawCspsAndXfo(artifacts: LH.Artifacts, context: LH.Audit.Context): Promise<{
        cspHeaders: string[];
        xfoHeaders: string[];
    }>;
    /**
     * @param {string | undefined} directive
     * @param {LH.IcuMessage | string} findingDescription
     * @param {LH.IcuMessage=} severity
     * @return {LH.Audit.Details.TableItem}
     */
    static findingToTableItem(directive: string | undefined, findingDescription: LH.IcuMessage | string, severity?: LH.IcuMessage | undefined): LH.Audit.Details.TableItem;
    /**
     * @param {string[]} cspHeaders
     * @param {string[]} xfoHeaders
     * @return {{score: number, results: LH.Audit.Details.TableItem[]}}
     */
    static constructResults(cspHeaders: string[], xfoHeaders: string[]): {
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
    let noClickjackingMitigation: string;
    let columnSeverity: string;
}
import { Audit } from './audit.js';
//# sourceMappingURL=clickjacking-mitigation.d.ts.map