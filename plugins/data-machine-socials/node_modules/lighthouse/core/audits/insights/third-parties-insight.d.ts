export default ThirdPartiesInsight;
export type URLSummary = {
    transferSize: number;
    mainThreadTime: number;
    url: string | LH.IcuMessage;
};
/**
 * @typedef URLSummary
 * @property {number} transferSize
 * @property {number} mainThreadTime
 * @property {string | LH.IcuMessage} url
 */
declare class ThirdPartiesInsight extends Audit {
    /**
     * @param {LH.Artifacts.Entity} entity
     * @param {import('@paulirish/trace_engine/models/trace/extras/ThirdParties.js').URLSummary[]} urlSummaries
     * @return {URLSummary[]}
     */
    static makeSubItems(entity: LH.Artifacts.Entity, urlSummaries: import("@paulirish/trace_engine/models/trace/extras/ThirdParties.js").URLSummary[]): URLSummary[];
    /**
     * @param {LH.Artifacts} artifacts
     * @param {LH.Audit.Context} context
     * @return {Promise<LH.Audit.Product>}
     */
    static audit(artifacts: LH.Artifacts, context: LH.Audit.Context): Promise<LH.Audit.Product>;
}
import { Audit } from '../audit.js';
//# sourceMappingURL=third-parties-insight.d.ts.map