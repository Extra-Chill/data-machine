/**
 * Launch Chrome and do a full Lighthouse run via DevTools.
 * By default, the latest DevTools frontend is used (.tmp/chromium-web-tests/devtools/devtools-frontend)
 * unless DEVTOOLS_PATH is set.
 * CHROME_PATH determines which Chrome is used–otherwise the default is puppeteer's chrome binary.
 * @param {string} url
 * @param {LH.Config=} config
 * @param {import('../lib/local-console.js').LocalConsole=} logger
 * @param {Smokehouse.SmokehouseOptions['testRunnerOptions']=} testRunnerOptions
 * @return {Promise<{lhr: LH.Result, artifacts: LH.Artifacts}>}
 */
export function runLighthouse(url: string, config?: LH.Config | undefined, logger?: import("../lib/local-console.js").LocalConsole | undefined, testRunnerOptions?: Smokehouse.SmokehouseOptions["testRunnerOptions"] | undefined): Promise<{
    lhr: LH.Result;
    artifacts: LH.Artifacts;
}>;
/**
 * Download/pull latest DevTools, build Lighthouse for DevTools, roll to DevTools, and build DevTools.
 */
export function setup(): Promise<void>;
//# sourceMappingURL=devtools.d.ts.map