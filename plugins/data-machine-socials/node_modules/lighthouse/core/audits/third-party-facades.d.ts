export default ThirdPartyFacades;
export type ThirdPartyEntity = import("third-party-web").IEntity;
export type ThirdPartyProduct = import("third-party-web").IProduct;
export type ThirdPartyFacade = import("third-party-web").IFacade;
export type FacadableProduct = {
    product: ThirdPartyProduct;
    entity: ThirdPartyEntity;
};
declare class ThirdPartyFacades extends Audit {
    /**
     * Sort items by transfer size and combine small items into a single row.
     * Items will be mutated in place to a maximum of 6 rows.
     * @param {import('./third-party-summary.js').URLSummary[]} items
     */
    static condenseItems(items: import("./third-party-summary.js").URLSummary[]): void;
    /**
     * @param {Map<string, import('./third-party-summary.js').Summary>} byURL
     * @param {LH.Artifacts.EntityClassification} classifiedEntities
     * @return {FacadableProduct[]}
     */
    static getProductsWithFacade(byURL: Map<string, import("./third-party-summary.js").Summary>, classifiedEntities: LH.Artifacts.EntityClassification): FacadableProduct[];
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
    let columnProduct: string;
    let categoryVideo: string;
    let categoryCustomerSuccess: string;
    let categoryMarketing: string;
    let categorySocial: string;
}
import { Audit } from './audit.js';
//# sourceMappingURL=third-party-facades.d.ts.map