export type SyntheticLayoutShift = import("@paulirish/trace_engine").Types.Events.SyntheticLayoutShift;
export type SaneSyntheticLayoutShift = SyntheticLayoutShift & {
    args: {
        data: NonNullable<SyntheticLayoutShift["args"]["data"]>;
    };
};
export type DevToolsIcuMessage = {
    i18nId: string;
    values: Record<string, string | number | {
        __i18nBytes: number;
    } | {
        __i18nMillis: number;
    }>;
};
export const TraceProcessor: typeof TraceEngine.Processor.TraceProcessor;
export const TraceHandlers: typeof TraceEngine.Handlers.ModelHandlers;
export const Insights: typeof TraceEngine.Insights;
export const Helpers: typeof TraceEngine.Helpers;
import * as TraceEngine from '@paulirish/trace_engine';
//# sourceMappingURL=trace-engine.d.ts.map