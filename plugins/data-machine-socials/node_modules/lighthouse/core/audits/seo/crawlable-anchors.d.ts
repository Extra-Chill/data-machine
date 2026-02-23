export default CrawlableAnchors;
declare class CrawlableAnchors extends Audit {
    /**
     * @param {LH.Artifacts} artifacts
     * @return {LH.Audit.Product}
     */
    static audit({ AnchorElements: anchorElements, URL: url }: LH.Artifacts): LH.Audit.Product;
}
export namespace UIStrings {
    let title: string;
    let failureTitle: string;
    let description: string;
    let columnFailingLink: string;
}
import { Audit } from '../audit.js';
//# sourceMappingURL=crawlable-anchors.d.ts.map