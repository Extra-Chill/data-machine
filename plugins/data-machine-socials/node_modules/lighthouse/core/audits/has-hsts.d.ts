export default HasHsts;
declare class HasHsts extends Audit {
    /**
     * @param {LH.Artifacts} artifacts
     * @param {LH.Audit.Context} context
     * @return {Promise<string[]>}
     */
    static getRawHsts(artifacts: LH.Artifacts, context: LH.Audit.Context): Promise<string[]>;
    /**
     * @param {string} hstsDirective
     * @param {LH.IcuMessage | string} findingDescription
     * @param {LH.IcuMessage=} severity
     * @return {LH.Audit.Details.TableItem}
     */
    static findingToTableItem(hstsDirective: string, findingDescription: LH.IcuMessage | string, severity?: LH.IcuMessage | undefined): LH.Audit.Details.TableItem;
    /**
     * @param {string[]} hstsHeaders
     * @return {{score: number, results: LH.Audit.Details.TableItem[]}}
     */
    static constructResults(hstsHeaders: string[]): {
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
    let noHsts: string;
    let noPreload: string;
    let noSubdomain: string;
    let noMaxAge: string;
    let lowMaxAge: string;
    let invalidSyntax: string;
    let columnDirective: string;
    let columnSeverity: string;
}
import { Audit } from './audit.js';
//# sourceMappingURL=has-hsts.d.ts.map