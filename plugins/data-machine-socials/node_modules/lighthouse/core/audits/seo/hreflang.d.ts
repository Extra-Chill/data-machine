export default Hreflang;
export type Source = string | LH.Audit.Details.NodeValue | undefined;
export type InvalidHreflang = {
    source: Source;
    subItems: {
        type: "subitems";
        items: SubItem[];
    };
};
export type SubItem = {
    reason: LH.IcuMessage;
};
declare class Hreflang extends Audit {
    /**
     * @param {LH.Artifacts} artifacts
     * @return {LH.Audit.Product}
     */
    static audit({ LinkElements }: LH.Artifacts): LH.Audit.Product;
}
export namespace UIStrings {
    let title: string;
    let failureTitle: string;
    let description: string;
    let unexpectedLanguage: string;
    let notFullyQualified: string;
}
import { Audit } from '../audit.js';
//# sourceMappingURL=hreflang.d.ts.map