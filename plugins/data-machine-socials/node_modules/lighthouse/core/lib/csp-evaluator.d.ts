export type Finding = import("csp_evaluator/finding").Finding;
/**
 * @param {Finding} finding
 * @return {LH.IcuMessage|string}
 */
export function getTranslatedDescription(finding: Finding): LH.IcuMessage | string;
/**
 * @param {string[]} rawCsps
 * @return {{bypasses: Finding[], warnings: Finding[], syntax: Finding[][]}}
 */
export function evaluateRawCspsForXss(rawCsps: string[]): {
    bypasses: Finding[];
    warnings: Finding[];
    syntax: Finding[][];
};
/**
 * @param {string} rawCsp
 */
export function parseCsp(rawCsp: string): import("csp_evaluator/dist/csp.js").Csp;
export namespace UIStrings {
    let missingBaseUri: string;
    let missingScriptSrc: string;
    let missingObjectSrc: string;
    let strictDynamic: string;
    let unsafeInline: string;
    let unsafeInlineFallback: string;
    let allowlistFallback: string;
    let reportToOnly: string;
    let reportingDestinationMissing: string;
    let nonceLength: string;
    let nonceCharset: string;
    let missingSemicolon: string;
    let unknownDirective: string;
    let unknownKeyword: string;
    let deprecatedReflectedXSS: string;
    let deprecatedReferrer: string;
    let deprecatedDisownOpener: string;
    let plainWildcards: string;
    let plainUrlScheme: string;
}
//# sourceMappingURL=csp-evaluator.d.ts.map