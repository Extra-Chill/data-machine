export default RenderBlockingInsight;
declare class RenderBlockingInsight extends Audit {
    /**
     * @param {LH.Artifacts} artifacts
     * @param {LH.Audit.Context} context
     * @return {Promise<LH.Audit.Product>}
     */
    static audit(artifacts: LH.Artifacts, context: LH.Audit.Context): Promise<LH.Audit.Product>;
}
import { Audit } from '../audit.js';
//# sourceMappingURL=render-blocking-insight.d.ts.map