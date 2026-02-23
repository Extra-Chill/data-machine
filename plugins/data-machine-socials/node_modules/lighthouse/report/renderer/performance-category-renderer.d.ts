/**
 * @typedef {('DEFAULT'|'AUDITS'|'INSIGHTS')} InsightsExperimentState
 */
export class PerformanceCategoryRenderer extends CategoryRenderer {
    /** @type InsightsExperimentState*/
    _memoryInsightToggleState: InsightsExperimentState;
    /**
     * @param {LH.ReportResult.AuditRef} audit
     * @return {!Element}
     */
    _renderMetric(audit: LH.ReportResult.AuditRef): Element;
    /**
     * Get a link to the interactive scoring calculator with the metric values.
     * @param {LH.ReportResult.AuditRef[]} auditRefs
     * @return {string}
     */
    _getScoringCalculatorHref(auditRefs: LH.ReportResult.AuditRef[]): string;
    /**
     * Returns overallImpact and linearImpact for an audit.
     * The overallImpact is determined by the audit saving's effect on the overall performance score.
     * We use linearImpact to compare audits where their overallImpact is rounded down to 0.
     *
     * @param {LH.ReportResult.AuditRef} audit
     * @param {LH.ReportResult.AuditRef[]} metricAudits
     * @return {{overallImpact: number, overallLinearImpact: number}}
     */
    overallImpact(audit: LH.ReportResult.AuditRef, metricAudits: LH.ReportResult.AuditRef[]): {
        overallImpact: number;
        overallLinearImpact: number;
    };
    /**
     * @param {InsightsExperimentState} newState
    **/
    _persistInsightToggleToStorage(newState: InsightsExperimentState): void;
    /**
     * @returns {InsightsExperimentState}
    **/
    _getInsightToggleState(): InsightsExperimentState;
    /**
     * @returns {InsightsExperimentState}
    **/
    _getRawInsightToggleState(): InsightsExperimentState;
    /**
     * @param {HTMLButtonElement} button
    **/
    _setInsightToggleButtonText(button: HTMLButtonElement): void;
    /**
     * @param {HTMLElement} element
     */
    _renderInsightsToggle(element: HTMLElement): void;
    /**
     * @param {LH.ReportResult.Category} category
     * @param {Object<string, LH.Result.ReportGroup>} groups
     * @param {{gatherMode: LH.Result.GatherMode}=} options
     * @return {Element}
     * @override
     */
    override render(category: LH.ReportResult.Category, groups: {
        [x: string]: import("../../types/lhr/lhr.js").default.ReportGroup;
    }, options?: {
        gatherMode: LH.Result.GatherMode;
    } | undefined): Element;
    /**
     * @param {LH.ReportResult.Category} category
     * @param {Object<string, LH.Result.ReportGroup>} groups
     * @param {string[]} groupNames
     * @param {LH.ReportResult.AuditRef[]} metricAudits
     * @return {Element|null}
     */
    renderFilterableSection(category: LH.ReportResult.Category, groups: {
        [x: string]: import("../../types/lhr/lhr.js").default.ReportGroup;
    }, groupNames: string[], metricAudits: LH.ReportResult.AuditRef[]): Element | null;
    /**
     * Render the control to filter the audits by metric. The filtering is done at runtime by CSS only
     * @param {LH.ReportResult.AuditRef[]} filterableMetrics
     * @param {HTMLDivElement} categoryEl
     * @param {(acronym: string) => void} onFilterChange
     */
    renderMetricAuditFilter(filterableMetrics: LH.ReportResult.AuditRef[], categoryEl: HTMLDivElement, onFilterChange: (acronym: string) => void): void;
}
export type DOM = import("./dom.js").DOM;
export type InsightsExperimentState = ("DEFAULT" | "AUDITS" | "INSIGHTS");
import { CategoryRenderer } from './category-renderer.js';
//# sourceMappingURL=performance-category-renderer.d.ts.map