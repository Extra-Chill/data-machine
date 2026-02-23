export default ManualAudit;
declare class ManualAudit extends Audit {
    /**
     * @return {Pick<LH.Audit.Meta, 'scoreDisplayMode'|'requiredArtifacts'>}
     */
    static get partialMeta(): Pick<LH.Audit.Meta, "scoreDisplayMode" | "requiredArtifacts">;
    /**
     * @return {LH.Audit.Product}
     */
    static audit(): LH.Audit.Product;
}
import { Audit } from '../audit.js';
//# sourceMappingURL=manual-audit.d.ts.map