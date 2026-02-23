export default DOMSize;
declare class DOMSize extends Audit {
    /**
     * @return {LH.Audit.ScoreOptions}
     */
    static get defaultOptions(): LH.Audit.ScoreOptions;
    /**
     * @param {LH.Artifacts} artifacts
     * @param {LH.Audit.Context} context
     * @return {Promise<number|undefined>}
     */
    static computeTbtImpact(artifacts: LH.Artifacts, context: LH.Audit.Context): Promise<number | undefined>;
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
    let columnStatistic: string;
    let columnValue: string;
    let displayValue: string;
    let statisticDOMElements: string;
    let statisticDOMDepth: string;
    let statisticDOMWidth: string;
}
import { Audit } from '../audit.js';
//# sourceMappingURL=dom-size.d.ts.map