/**
 * @param {string} filename
 * @returns {ReturnType<analyzeLighthouseArtifacts>}
 */
export function analyzeInspectorIssues(filename: string): ReturnType<typeof analyzeLighthouseArtifacts>;
export type IssuesManager = any;
export type Protocol = typeof import("./generated/protocol.js");
/**
 * @param {any} lighthouseArtifacts
 */
declare function analyzeLighthouseArtifacts(lighthouseArtifacts: any): any[];
export {};
//# sourceMappingURL=analyze-inspector-issues.d.mts.map