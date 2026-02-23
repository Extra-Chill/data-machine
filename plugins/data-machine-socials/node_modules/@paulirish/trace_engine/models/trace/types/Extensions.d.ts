import type * as Platform from '../../../core/platform/platform.js';
import type { Args, ConsoleTimeStamp, Event, PerformanceMark, PerformanceMeasureBegin, Phase, SyntheticBased } from './TraceEvents.js';
export type ExtensionEntryType = 'track-entry' | 'marker';
export declare const extensionPalette: readonly ["primary", "primary-light", "primary-dark", "secondary", "secondary-light", "secondary-dark", "tertiary", "tertiary-light", "tertiary-dark", "error", "warning"];
export type ExtensionColorFromPalette = typeof extensionPalette[number];
export interface ExtensionDataPayloadBase {
    color?: ExtensionColorFromPalette;
    /**
     * We document to users that we support only string values here, but because
     * this is coming from user code the values could be anything, so we ensure we
     * deal with bad data by typing this as unknown.
     */
    properties?: Array<[string, unknown]>;
    tooltipText?: string;
}
export type ExtensionDataPayload = ExtensionTrackEntryPayload | ExtensionMarkerPayload;
export interface ExtensionTrackEntryPayloadDeeplink {
    url: Platform.DevToolsPath.UrlString;
    description: string;
}
export interface ExtensionTrackEntryPayload extends ExtensionDataPayloadBase {
    dataType?: 'track-entry';
    track: string;
    trackGroup?: string;
    additionalContext?: ExtensionTrackEntryPayloadDeeplink;
}
export interface ExtensionMarkerPayload extends ExtensionDataPayloadBase {
    dataType: 'marker';
}
/**
 * Synthetic events created for extension tracks.
 */
export interface SyntheticExtensionTrackEntry extends SyntheticBased<Phase.COMPLETE, PerformanceMeasureBegin | PerformanceMark | ConsoleTimeStamp> {
    args: Args & ExtensionTrackEntryPayload;
}
/**
 * Synthetic events created for extension marks.
 */
export interface SyntheticExtensionMarker extends SyntheticBased<Phase.INSTANT, PerformanceMark> {
    args: Args & ExtensionMarkerPayload;
}
export type SyntheticExtensionEntry = SyntheticExtensionTrackEntry | SyntheticExtensionMarker;
export declare function isExtensionPayloadMarker(payload: {
    dataType?: string;
}): payload is ExtensionMarkerPayload;
export declare function isExtensionPayloadTrackEntry(payload: {
    track?: string;
    dataType?: string;
}): payload is ExtensionTrackEntryPayload;
export declare function isConsoleTimestampPayloadTrackEntry(payload: {
    description?: string;
    url?: string;
}): payload is ExtensionTrackEntryPayloadDeeplink;
export declare function isValidExtensionPayload(payload: {
    track?: string;
    dataType?: string;
    description?: string;
    url?: string;
}): payload is ExtensionDataPayload | ExtensionTrackEntryPayloadDeeplink;
export declare function isSyntheticExtensionEntry(entry: Event): entry is SyntheticExtensionEntry;
export interface ExtensionTrackData {
    name: string;
    isTrackGroup: boolean;
    entriesByTrack: Record<string, SyntheticExtensionTrackEntry[]>;
}
