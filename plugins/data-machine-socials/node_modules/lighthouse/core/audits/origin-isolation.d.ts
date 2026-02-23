export default OriginIsolation;
declare class OriginIsolation extends Audit {
    /**
     * @param {LH.Artifacts} artifacts
     * @param {LH.Audit.Context} context
     * @return {Promise<string[]>}
     */
    static getRawCoop(artifacts: LH.Artifacts, context: LH.Audit.Context): Promise<string[]>;
    /**
     * @param {string | undefined} coopDirective
     * @param {LH.IcuMessage | string} findingDescription
     * @param {LH.IcuMessage=} severity
     * @return {LH.Audit.Details.TableItem}
     */
    static findingToTableItem(coopDirective: string | undefined, findingDescription: LH.IcuMessage | string, severity?: LH.IcuMessage | undefined): LH.Audit.Details.TableItem;
    /**
     * @param {string[]} coopHeaders
     * @return {{score: number, results: LH.Audit.Details.TableItem[]}}
     */
    static constructResults(coopHeaders: string[]): {
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
    let noCoop: string;
    let invalidSyntax: string;
    let columnDirective: string;
    let columnSeverity: string;
}
import { Audit } from './audit.js';
//# sourceMappingURL=origin-isolation.d.ts.map