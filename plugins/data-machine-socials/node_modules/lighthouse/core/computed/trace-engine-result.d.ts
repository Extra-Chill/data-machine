export { TraceEngineResultComputed as TraceEngineResult };
declare const TraceEngineResultComputed: typeof TraceEngineResult & {
    request: (dependencies: {
        trace: LH.Trace;
        settings: LH.Audit.Context["settings"];
        SourceMaps: LH.Artifacts["SourceMaps"];
    }, context: LH.Artifacts.ComputedContext) => Promise<LH.Artifacts.TraceEngineResult>;
};
/**
 * @fileoverview Processes trace with the shared trace engine.
 */
declare class TraceEngineResult {
    /**
     * @param {LH.TraceEvent[]} _traceEvents
     * @param {LH.Audit.Context['settings']} settings
     * @param {LH.Artifacts['SourceMaps']} SourceMaps
     * @return {Promise<LH.Artifacts.TraceEngineResult>}
     */
    static runTraceEngine(_traceEvents: LH.TraceEvent[], settings: LH.Audit.Context["settings"], SourceMaps: LH.Artifacts["SourceMaps"]): Promise<LH.Artifacts.TraceEngineResult>;
    /**
     * Adapts the given DevTools function that returns a localized string to one
     * that returns a LH.IcuMessage.
     *
     * @template {any[]} Args
     * @template {import('../lib/trace-engine.js').DevToolsIcuMessage} Ret
     * @param {ReturnType<i18n.createIcuMessageFn>} str_
     * @param {(...args: Args) => Ret} fn
     * @return {(...args: Args) => LH.IcuMessage}
     */
    static localizeFunction<Args extends any[], Ret extends import("../lib/trace-engine.js").DevToolsIcuMessage>(str_: ReturnType<typeof i18n.createIcuMessageFn>, fn: (...args: Args) => Ret): (...args: Args) => LH.IcuMessage;
    /**
     * Converts the input parameters given to `i18nString` usages in DevTools to a
     * LH.IcuMessage.
     *
     * @param {ReturnType<i18n.createIcuMessageFn>} str_
     * @param {import('../lib/trace-engine.js').DevToolsIcuMessage} traceEngineI18nObject
     * @return {LH.IcuMessage}
     */
    static localize(str_: ReturnType<typeof i18n.createIcuMessageFn>, traceEngineI18nObject: import("../lib/trace-engine.js").DevToolsIcuMessage): LH.IcuMessage;
    /**
     * Recursively finds all DevToolsIcuMessage objects and replaces them with LH.IcuMessage.
     *
     * @param {ReturnType<i18n.createIcuMessageFn>} str_
     * @param {object} object
     */
    static localizeObject(str_: ReturnType<typeof i18n.createIcuMessageFn>, object: object): void;
    /**
     * @param {import('@paulirish/trace_engine/models/trace/insights/types.js').TraceInsightSets} insightSets
     */
    static localizeInsights(insightSets: import("@paulirish/trace_engine/models/trace/insights/types.js").TraceInsightSets): void;
    /**
     * @param {{trace: LH.Trace, settings: LH.Audit.Context['settings'], SourceMaps: LH.Artifacts['SourceMaps']}} data
     * @param {LH.Artifacts.ComputedContext} context
     * @return {Promise<LH.Artifacts.TraceEngineResult>}
     */
    static compute_(data: {
        trace: LH.Trace;
        settings: LH.Audit.Context["settings"];
        SourceMaps: LH.Artifacts["SourceMaps"];
    }, context: LH.Artifacts.ComputedContext): Promise<LH.Artifacts.TraceEngineResult>;
}
import * as LH from '../../types/lh.js';
import * as i18n from '../lib/i18n/i18n.js';
//# sourceMappingURL=trace-engine-result.d.ts.map