export default ImageSizeResponsive;
export type Result = {
    url: string;
    node: LH.Audit.Details.NodeValue;
    displayedSize: string;
    actualSize: string;
    actualPixels: number;
    expectedSize: string;
    expectedPixels: number;
};
export type ImageWithNaturalDimensions = LH.Artifacts.ImageElement & Required<Pick<LH.Artifacts.ImageElement, "naturalDimensions">>;
declare class ImageSizeResponsive extends Audit {
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
    let columnDisplayed: string;
    let columnActual: string;
    let columnExpected: string;
}
import { Audit } from './audit.js';
//# sourceMappingURL=image-size-responsive.d.ts.map