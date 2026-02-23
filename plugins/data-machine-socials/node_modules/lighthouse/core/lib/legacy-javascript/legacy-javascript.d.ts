export type Pattern = {
    name: string;
    expression: string;
    estimateBytes?: (content: string) => number;
};
export type PatternMatchResult = {
    name: string;
    line: number;
    column: number;
};
export type Result = {
    matches: PatternMatchResult[];
    estimatedByteSavings: number;
};
/**
 * @param {string} content
 * @param {import('../cdt/generated/SourceMap.js')|null} map
 * @return {Result}
 */
export function detectLegacyJavaScript(content: string, map: import("../cdt/generated/SourceMap.js") | null): Result;
/**
 * @return {Pattern[]}
 */
export function getTransformPatterns(): Pattern[];
export function getCoreJsPolyfillData(): {
    name: string;
    coreJs3Module: string;
}[];
//# sourceMappingURL=legacy-javascript.d.ts.map