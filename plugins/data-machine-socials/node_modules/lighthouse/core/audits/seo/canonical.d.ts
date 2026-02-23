export default Canonical;
export type CanonicalURLData = {
    uniqueCanonicalURLs: Set<string>;
    hreflangURLs: Set<string>;
    invalidCanonicalLink: LH.Artifacts.LinkElement | undefined;
    relativeCanonicallink: LH.Artifacts.LinkElement | undefined;
};
/**
 * @typedef CanonicalURLData
 * @property {Set<string>} uniqueCanonicalURLs
 * @property {Set<string>} hreflangURLs
 * @property {LH.Artifacts.LinkElement|undefined} invalidCanonicalLink
 * @property {LH.Artifacts.LinkElement|undefined} relativeCanonicallink
 */
declare class Canonical extends Audit {
    /**
     * @param {LH.Artifacts.LinkElement[]} linkElements
     * @return {CanonicalURLData}
     */
    static collectCanonicalURLs(linkElements: LH.Artifacts.LinkElement[]): CanonicalURLData;
    /**
     * @param {CanonicalURLData} canonicalURLData
     * @return {LH.Audit.Product|undefined}
     */
    static findInvalidCanonicalURLReason(canonicalURLData: CanonicalURLData): LH.Audit.Product | undefined;
    /**
     * @param {CanonicalURLData} canonicalURLData
     * @param {URL} canonicalURL
     * @param {URL} baseURL
     * @return {LH.Audit.Product|undefined}
     */
    static findCommonCanonicalURLMistakes(canonicalURLData: CanonicalURLData, canonicalURL: URL, baseURL: URL): LH.Audit.Product | undefined;
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
    let explanationConflict: string;
    let explanationInvalid: string;
    let explanationRelative: string;
    let explanationPointsElsewhere: string;
    let explanationRoot: string;
}
import { Audit } from '../audit.js';
//# sourceMappingURL=canonical.d.ts.map