import type * as Platform from '../../../core/platform/platform.js';
import * as ThirdPartyWeb from '../../../third_party/third-party-web/third-party-web.js';
import * as Types from '../types/types.js';
import type { TraceEventsForNetworkRequest } from './NetworkRequestsHandler.js';
import type { ParsedTrace } from './types.js';
export type Entity = typeof ThirdPartyWeb.ThirdPartyWeb.entities[number] & {
    isUnrecognized?: boolean;
};
export interface EntityMappings {
    createdEntityCache: Map<string, Entity>;
    entityByEvent: Map<Types.Events.Event, Entity>;
    eventsByEntity: Map<Entity, Types.Events.Event[]>;
    entityByUrlCache: Map<string, Entity>;
}
export declare function getEntityForEvent(event: Types.Events.Event, entityMappings: EntityMappings): Entity | undefined;
export declare function getEntityForUrl(url: string, entityMappings: EntityMappings): Entity | undefined;
export declare function getNonResolvedURL(entry: Types.Events.Event, parsedTrace?: ParsedTrace): Platform.DevToolsPath.UrlString | null;
export declare function makeUpEntity(entityCache: Map<string, Entity>, url: string): Entity | undefined;
export declare function addEventToEntityMapping(event: Types.Events.Event, entityMappings: EntityMappings): void;
export declare function addNetworkRequestToEntityMapping(networkRequest: Types.Events.SyntheticNetworkRequest, entityMappings: EntityMappings, requestTraceEvents: TraceEventsForNetworkRequest): void;
