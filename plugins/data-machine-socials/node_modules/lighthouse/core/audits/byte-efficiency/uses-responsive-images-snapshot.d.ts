export default UsesResponsiveImagesSnapshot;
declare class UsesResponsiveImagesSnapshot extends Audit {
    /**
     * @param {LH.Artifacts} artifacts
     * @return {Promise<LH.Audit.Product>}
     */
    static audit(artifacts: LH.Artifacts): Promise<LH.Audit.Product>;
}
export namespace UIStrings {
    let title: string;
    let failureTitle: string;
    let columnDisplayedDimensions: string;
    let columnActualDimensions: string;
}
import { Audit } from '../audit.js';
//# sourceMappingURL=uses-responsive-images-snapshot.d.ts.map