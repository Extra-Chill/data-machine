/**
 * @param {LH.Puppeteer.Page} page
 * @param {{config?: LH.Config, flags?: LH.Flags}} [options]
 * @return {Promise<{endTimespanGather(): Promise<LH.Gatherer.GatherResult>}>}
 */
export function startTimespanGather(page: LH.Puppeteer.Page, options?: {
    config?: LH.Config;
    flags?: LH.Flags;
}): Promise<{
    endTimespanGather(): Promise<LH.Gatherer.GatherResult>;
}>;
export namespace UIStrings {
    let warningNavigationDetected: string;
}
//# sourceMappingURL=timespan-runner.d.ts.map