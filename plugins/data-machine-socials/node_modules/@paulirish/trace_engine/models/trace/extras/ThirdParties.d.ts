import type * as ThirdPartyWeb from '../../../third_party/third-party-web/third-party-web.js';
import * as Handlers from '../handlers/handlers.js';
import * as Types from '../types/types.js';
export type Entity = typeof ThirdPartyWeb.ThirdPartyWeb.entities[number];
interface BaseSummary {
    entity: Entity;
    transferSize: number;
    mainThreadTime: Types.Timing.Milli;
}
export interface EntitySummary extends BaseSummary {
    relatedEvents: Types.Events.Event[];
}
export interface URLSummary extends BaseSummary {
    url: string;
    request?: Types.Events.SyntheticNetworkRequest;
}
export declare function summarizeByThirdParty(parsedTrace: Handlers.Types.ParsedTrace, traceBounds: Types.Timing.TraceWindowMicro): EntitySummary[];
/**
 * Used only by Lighthouse.
 */
export declare function summarizeByURL(parsedTrace: Handlers.Types.ParsedTrace, traceBounds: Types.Timing.TraceWindowMicro): URLSummary[];
export {};
