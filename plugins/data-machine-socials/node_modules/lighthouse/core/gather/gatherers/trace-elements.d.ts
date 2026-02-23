export default TraceElements;
export type TraceElementData = {
    nodeId: number;
    animations?: {
        name?: string;
        failureReasonsMask?: number;
        unsupportedProperties?: string[];
    }[];
    type?: string;
};
declare class TraceElements extends BaseGatherer {
    /**
     * @param {LH.Artifacts.TraceEngineResult} traceEngineResult
     * @param {string|undefined} navigationId
     * @return {Promise<Array<{nodeId: number}>>}
     */
    static getTraceEngineElements(traceEngineResult: LH.Artifacts.TraceEngineResult, navigationId: string | undefined): Promise<Array<{
        nodeId: number;
    }>>;
    /**
     * We want to a single representative node to represent the shift, so let's pick
     * the one with the largest impact (size x distance moved).
     *
     * @param {LH.Artifacts.TraceImpactedNode[]} impactedNodes
     * @param {Map<number, number>} impactByNodeId
     * @return {number|undefined}
     */
    static getBiggestImpactNodeForShiftEvent(impactedNodes: LH.Artifacts.TraceImpactedNode[], impactByNodeId: Map<number, number>): number | undefined;
    /**
     * This function finds the top (up to 15) layout shifts on the page, and returns
     * the id of the largest impacted node of each shift, along with any related nodes
     * that may have caused the shift.
     *
     * @param {LH.Trace} trace
     * @param {LH.Artifacts.TraceEngineResult['parsedTrace']} traceEngineResult
     * @param {LH.Gatherer.Context} context
     * @return {Promise<Array<{nodeId: number}>>}
     */
    static getTopLayoutShifts(trace: LH.Trace, traceEngineResult: LH.Artifacts.TraceEngineResult["parsedTrace"], context: LH.Gatherer.Context): Promise<Array<{
        nodeId: number;
    }>>;
    /**
     * @param {LH.Trace} trace
     * @param {LH.Gatherer.Context} context
     * @return {Promise<TraceElementData|undefined>}
     */
    static getResponsivenessElement(trace: LH.Trace, context: LH.Gatherer.Context): Promise<TraceElementData | undefined>;
    /**
     * @param {LH.Trace} trace
     * @param {LH.Gatherer.Context} context
     * @return {Promise<{nodeId: number, type: string} | undefined>}
     */
    static getLcpElement(trace: LH.Trace, context: LH.Gatherer.Context): Promise<{
        nodeId: number;
        type: string;
    } | undefined>;
    /** @type {LH.Gatherer.GathererMeta<'Trace'|'SourceMaps'>} */
    meta: LH.Gatherer.GathererMeta<"Trace" | "SourceMaps">;
    /** @type {Map<string, string>} */
    animationIdToName: Map<string, string>;
    /** @param {LH.Crdp.Animation.AnimationStartedEvent} args */
    _onAnimationStarted({ animation: { id, name } }: LH.Crdp.Animation.AnimationStartedEvent): void;
    /**
     * Find the node ids of elements which are animated using the Animation trace events.
     * @param {Array<LH.TraceEvent>} mainThreadEvents
     * @return {Promise<Array<TraceElementData>>}
     */
    getAnimatedElements(mainThreadEvents: Array<LH.TraceEvent>): Promise<Array<TraceElementData>>;
    /**
     * @param {LH.Gatherer.Context} context
     */
    startInstrumentation(context: LH.Gatherer.Context): Promise<void>;
    /**
     * @param {LH.Gatherer.Context} context
     */
    stopInstrumentation(context: LH.Gatherer.Context): Promise<void>;
    /**
     * @param {LH.Gatherer.ProtocolSession} session
     * @param {number} backendNodeId
     */
    getNodeDetails(session: LH.Gatherer.ProtocolSession, backendNodeId: number): Promise<import("devtools-protocol").Protocol.Runtime.CallFunctionOnResponse | null>;
    /**
     * @param {LH.Gatherer.Context<'Trace'|'SourceMaps'>} context
     * @return {Promise<LH.Artifacts.TraceElement[]>}
     */
    getArtifact(context: LH.Gatherer.Context<"Trace" | "SourceMaps">): Promise<LH.Artifacts.TraceElement[]>;
}
import BaseGatherer from '../base-gatherer.js';
//# sourceMappingURL=trace-elements.d.ts.map