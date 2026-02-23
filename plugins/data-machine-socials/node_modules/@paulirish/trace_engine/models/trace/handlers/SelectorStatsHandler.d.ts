import type * as Protocol from '../../../generated/protocol.js';
import * as Types from '../types/types.js';
interface SelectorWithStyleSheedId {
    selector: string;
    styleSheetId: string;
}
interface InvalidatedNode {
    frame: string;
    backendNodeId: Protocol.DOM.BackendNodeId;
    type: Types.Events.InvalidationEventType;
    selectorList: SelectorWithStyleSheedId[];
    ts: Types.Timing.Micro;
    tts?: Types.Timing.Micro;
    subtree: boolean;
    lastUpdateLayoutTreeEventTs: Types.Timing.Micro;
}
export declare function reset(): void;
export declare function handleEvent(event: Types.Events.Event): void;
export declare function finalize(): Promise<void>;
export interface SelectorStatsData {
    dataForUpdateLayoutEvent: Map<Types.Events.UpdateLayoutTree, {
        timings: Types.Events.SelectorTiming[];
    }>;
    invalidatedNodeList: InvalidatedNode[];
}
export declare function data(): SelectorStatsData;
export {};
