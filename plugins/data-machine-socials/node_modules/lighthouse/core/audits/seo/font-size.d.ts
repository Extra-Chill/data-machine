export default FontSize;
export type FailingNodeData = LH.Artifacts.FontSize["analyzedFailingNodesData"][0];
declare class FontSize extends Audit {
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
    let explanationViewport: string;
    let additionalIllegibleText: string;
    let legibleText: string;
    let columnSelector: string;
    let columnPercentPageText: string;
    let columnFontSize: string;
}
import { Audit } from '../audit.js';
//# sourceMappingURL=font-size.d.ts.map