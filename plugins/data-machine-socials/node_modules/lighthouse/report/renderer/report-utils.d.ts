export class ReportUtils {
    /**
     * Returns a new LHR that's reshaped for slightly better ergonomics within the report rendereer.
     * Also, sets up the localized UI strings used within renderer and makes changes to old LHRs to be
     * compatible with current renderer.
     * The LHR passed in is not mutated.
     * TODO(team): we all agree the LHR shape change is technical debt we should fix
     * @param {LH.Result} lhr
     * @return {LH.ReportResult}
     */
    static prepareReportResult(lhr: LH.Result): LH.ReportResult;
    /**
     * Given an audit's details, identify and return a URL locator function that
     * can be called later with an `item` to extract the URL of it.
     * @param {LH.FormattedIcu<LH.Audit.Details.TableColumnHeading[]>} headings
     * @return {((item: LH.FormattedIcu<LH.Audit.Details.TableItem>) => string|undefined)=}
     */
    static getUrlLocatorFn(headings: LH.FormattedIcu<LH.Audit.Details.TableColumnHeading[]>): ((item: LH.FormattedIcu<LH.Audit.Details.TableItem>) => string | undefined) | undefined;
    /**
     * Mark TableItems/OpportunityItems with entity names.
     * @param {LH.Result.Entities} entities
     * @param {LH.FormattedIcu<LH.Audit.Details.Opportunity|LH.Audit.Details.Table>} details
     */
    static classifyEntities(entities: LH.Result.Entities, details: LH.FormattedIcu<LH.Audit.Details.Opportunity | LH.Audit.Details.Table>): void;
    /**
     * Returns a comparator created from the supplied list of keys
     * @param {Array<string>} sortedBy
     * @return {((a: LH.Audit.Details.TableItem, b: LH.Audit.Details.TableItem) => number)}
     */
    static getTableItemSortComparator(sortedBy: Array<string>): ((a: LH.Audit.Details.TableItem, b: LH.Audit.Details.TableItem) => number);
    /**
     * @param {LH.Result['configSettings']} settings
     * @return {!{deviceEmulation: string, screenEmulation?: string, networkThrottling: string, cpuThrottling: string, summary: string}}
     */
    static getEmulationDescriptions(settings: LH.Result["configSettings"]): {
        deviceEmulation: string;
        screenEmulation?: string;
        networkThrottling: string;
        cpuThrottling: string;
        summary: string;
    };
    /**
     * Used to determine if the "passed" for the purposes of showing up in the "failed" or "passed"
     * sections of the report.
     *
     * @param {{score: (number|null), scoreDisplayMode: string}} audit
     * @return {boolean}
     */
    static showAsPassed(audit: {
        score: (number | null);
        scoreDisplayMode: string;
    }): boolean;
    /**
     * Convert a score to a rating label.
     * TODO: Return `'error'` for `score === null && !scoreDisplayMode`.
     *
     * @param {number|null} score
     * @param {string=} scoreDisplayMode
     * @return {string}
     */
    static calculateRating(score: number | null, scoreDisplayMode?: string | undefined): string;
    /**
     * @param {LH.ReportResult.Category} category
     */
    static calculateCategoryFraction(category: LH.ReportResult.Category): {
        numPassed: number;
        numPassableAudits: number;
        numInformative: number;
        totalWeight: number;
    };
    /**
     * @param {string} categoryId
     */
    static isPluginCategory(categoryId: string): boolean;
    /**
     * @param {LH.Result.GatherMode} gatherMode
     */
    static shouldDisplayAsFraction(gatherMode: LH.Result.GatherMode): gatherMode is "timespan" | "snapshot";
}
export namespace UIStrings {
    let varianceDisclaimer: string;
    let calculatorLink: string;
    let showRelevantAudits: string;
    let opportunityResourceColumnLabel: string;
    let opportunitySavingsColumnLabel: string;
    let errorMissingAuditInfo: string;
    let errorLabel: string;
    let warningHeader: string;
    let warningAuditsGroupTitle: string;
    let passedAuditsGroupTitle: string;
    let notApplicableAuditsGroupTitle: string;
    let manualAuditsGroupTitle: string;
    let toplevelWarningsMessage: string;
    let crcInitialNavigation: string;
    let crcLongestDurationLabel: string;
    let snippetExpandButtonLabel: string;
    let snippetCollapseButtonLabel: string;
    let lsPerformanceCategoryDescription: string;
    let labDataTitle: string;
    let thirdPartyResourcesLabel: string;
    let viewTreemapLabel: string;
    let viewTraceLabel: string;
    let dropdownPrintSummary: string;
    let dropdownPrintExpanded: string;
    let dropdownCopyJSON: string;
    let dropdownSaveHTML: string;
    let dropdownSaveJSON: string;
    let dropdownViewer: string;
    let dropdownSaveGist: string;
    let dropdownDarkTheme: string;
    let dropdownViewUnthrottledTrace: string;
    let runtimeSettingsDevice: string;
    let runtimeSettingsNetworkThrottling: string;
    let runtimeSettingsCPUThrottling: string;
    let runtimeSettingsUANetwork: string;
    let runtimeSettingsBenchmark: string;
    let runtimeSettingsAxeVersion: string;
    let runtimeSettingsScreenEmulation: string;
    let footerIssue: string;
    let runtimeNoEmulation: string;
    let runtimeMobileEmulation: string;
    let runtimeDesktopEmulation: string;
    let runtimeUnknown: string;
    let runtimeSingleLoad: string;
    let runtimeAnalysisWindow: string;
    let runtimeAnalysisWindowTimespan: string;
    let runtimeAnalysisWindowSnapshot: string;
    let runtimeSingleLoadTooltip: string;
    let throttlingProvided: string;
    let show: string;
    let hide: string;
    let expandView: string;
    let collapseView: string;
    let runtimeSlow4g: string;
    let runtimeCustom: string;
    let firstPartyChipLabel: string;
    let openInANewTabTooltip: string;
    let unattributable: string;
    let insightsNotice: string;
    let tryInsights: string;
    let goBackToAudits: string;
}
//# sourceMappingURL=report-utils.d.ts.map