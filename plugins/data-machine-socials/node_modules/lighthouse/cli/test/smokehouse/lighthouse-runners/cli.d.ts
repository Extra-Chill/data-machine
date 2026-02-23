/**
 * Launch Chrome and do a full Lighthouse run via the Lighthouse CLI.
 * @param {string} url
 * @param {LH.Config=} config
 * @param {LocalConsole=} logger
 * @param {Smokehouse.SmokehouseOptions['testRunnerOptions']=} testRunnerOptions
 * @return {Promise<{lhr: LH.Result, artifacts: LH.Artifacts}>}
 */
export function runLighthouse(url: string, config?: LH.Config | undefined, logger?: LocalConsole | undefined, testRunnerOptions?: Smokehouse.SmokehouseOptions["testRunnerOptions"] | undefined): Promise<{
    lhr: LH.Result;
    artifacts: LH.Artifacts;
}>;
import { LocalConsole } from '../lib/local-console.js';
//# sourceMappingURL=cli.d.ts.map