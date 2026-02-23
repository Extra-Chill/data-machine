import * as Platform from '../../../core/platform/platform.js';
import * as Protocol from '../../../generated/protocol.js';
import type * as Handlers from '../handlers/handlers.js';
import * as Types from '../types/types.js';
import { type InsightModel, type InsightSetContext, type InsightSetContextWithNavigation } from './types.js';
export declare const UIStrings: {
    /**
     * @description Title of an insight that recommends avoiding chaining critical requests.
     */
    readonly title: "Network dependency tree";
    /**
     * @description Description of an insight that recommends avoiding chaining critical requests.
     */
    readonly description: "[Avoid chaining critical requests](https://developer.chrome.com/docs/lighthouse/performance/critical-request-chains) by reducing the length of chains, reducing the download size of resources, or deferring the download of unnecessary resources to improve page load.";
    /**
     * @description Description of the warning that recommends avoiding chaining critical requests.
     */
    readonly warningDescription: "Avoid chaining critical requests by reducing the length of chains, reducing the download size of resources, or deferring the download of unnecessary resources to improve page load.";
    /**
     * @description Text status indicating that there isn't long chaining critical network requests.
     */
    readonly noNetworkDependencyTree: "No rendering tasks impacted by network dependencies";
    /**
     * @description Text for the maximum critical path latency. This refers to the longest chain of network requests that
     * the browser must download before it can render the page.
     */
    readonly maxCriticalPathLatency: "Max critical path latency:";
    /** Label for a column in a data table; entries will be the network request */
    readonly columnRequest: "Request";
    /** Label for a column in a data table; entries will be the time from main document till current network request. */
    readonly columnTime: "Time";
    /**
     * @description Title of the table of the detected preconnect origins.
     */
    readonly preconnectOriginsTableTitle: "Preconnected origins";
    /**
     * @description Description of the table of the detected preconnect origins.
     */
    readonly preconnectOriginsTableDescription: "[preconnect](https://developer.chrome.com/docs/lighthouse/performance/uses-rel-preconnect/) hints help the browser establish a connection earlier in the page load, saving time when the first request for that origin is made. The following are the origins that the page preconnected to.";
    /**
     * @description Text status indicating that there isn't any preconnected origins.
     */
    readonly noPreconnectOrigins: "no origins were preconnected";
    /**
     * @description A warning message that is shown when found more than 4 preconnected links. "preconnect" should not be translated.
     */
    readonly tooManyPreconnectLinksWarning: "More than 4 `preconnect` connections were found. These should be used sparingly and only to the most important origins.";
    /**
     * @description A warning message that is shown when the user added preconnect for some unnecessary origins. "preconnect" should not be translated.
     */
    readonly unusedWarning: "Unused preconnect. Only use `preconnect` for origins that the page is likely to request.";
    /**
     * @description A warning message that is shown when the user forget to set the `crossorigin` HTML attribute, or setting it to an incorrect value, on the link is a common mistake when adding preconnect links. "preconnect" should not be translated.
     * */
    readonly crossoriginWarning: "Unused preconnect. Check that the `crossorigin` attribute is used properly.";
    /**
     * @description Label for a column in a data table; entries will be the source of the origin.
     */
    readonly columnSource: "Source";
    /**
     * @description Text status indicating that there isn't preconnect candidates.
     */
    readonly noPreconnectCandidates: "No additional origins are good candidates for preconnecting";
    /**
     * @description Title of the table that shows the origins that the page should have preconnected to.
     */
    readonly estSavingTableTitle: "Preconnect candidates";
    /**
     * @description Description of the table that recommends preconnecting to the origins to save time. "preconnect" should not be translated.
     */
    readonly estSavingTableDescription: "Add [preconnect](https://developer.chrome.com/docs/lighthouse/performance/uses-rel-preconnect/) hints to your most important origins, but try to use no more than 4.";
    /**
     * @description Label for a column in a data table; entries will be the origin of a web resource
     */
    readonly columnOrigin: "Origin";
    /**
     * @description Label for a column in a data table; entries will be the number of milliseconds the user could reduce page load by if they implemented the suggestions.
     */
    readonly columnWastedMs: "Est LCP savings";
};
export declare const i18nString: (id: string, values?: Record<string, string> | undefined) => {i18nId: string, values: Record<string, string|number>, formattedDefault: string};
export declare const TOO_MANY_PRECONNECTS_THRESHOLD = 4;
export interface CriticalRequestNode {
    request: Types.Events.SyntheticNetworkRequest;
    timeFromInitialRequest: Types.Timing.Micro;
    children: CriticalRequestNode[];
    isLongest?: boolean;
    relatedRequests: Set<Types.Events.SyntheticNetworkRequest>;
}
export type PreconnectedOrigin = PreconnectedOriginFromDom | PreconnectedOriginFromResponseHeader;
export interface PreconnectedOriginFromDom {
    node_id: Protocol.DOM.BackendNodeId;
    frame?: string;
    url: string;
    unused: boolean;
    crossorigin: boolean;
    source: 'DOM';
}
export interface PreconnectedOriginFromResponseHeader {
    url: string;
    headerText: string;
    request: Types.Events.SyntheticNetworkRequest;
    unused: boolean;
    crossorigin: boolean;
    source: 'ResponseHeader';
}
export interface PreconnectCandidate {
    origin: Platform.DevToolsPath.UrlString;
    wastedMs: Types.Timing.Milli;
}
export type NetworkDependencyTreeInsightModel = InsightModel<typeof UIStrings, {
    rootNodes: CriticalRequestNode[];
    maxTime: Types.Timing.Micro;
    fail: boolean;
    preconnectedOrigins: PreconnectedOrigin[];
    preconnectCandidates: PreconnectCandidate[];
}>;
/**
 * Parses an HTTP Link header string into an array of url and related header text.
 *
 * Export the function for test purpose.
 * @param linkHeaderValue The value of the HTTP Link header (e.g., '</style.css>; rel=preload; as=style, <https://example.com>; rel="preconnect"').
 * @returns An array of url and header text objects if it contains `rel=preconnect`.
 */
export declare function handleLinkResponseHeader(linkHeaderValue: string): Array<{
    url: string;
    headerText: string;
}>;
export declare function generatePreconnectedOrigins(parsedTrace: Handlers.Types.ParsedTrace, context: InsightSetContextWithNavigation, contextRequests: Types.Events.SyntheticNetworkRequest[], preconnectCandidates: PreconnectCandidate[]): PreconnectedOrigin[];
export declare function generatePreconnectCandidates(parsedTrace: Handlers.Types.ParsedTrace, context: InsightSetContextWithNavigation, contextRequests: Types.Events.SyntheticNetworkRequest[]): PreconnectCandidate[];
export declare function generateInsight(parsedTrace: Handlers.Types.ParsedTrace, context: InsightSetContext): NetworkDependencyTreeInsightModel;
export declare function createOverlays(model: NetworkDependencyTreeInsightModel): Types.Overlays.Overlay[];
/**
 * http://tools.ietf.org/html/rfc3986#section-5.2.4
 */
export declare function normalizePath(path: string): string;
export declare function schemeIs(url: Platform.DevToolsPath.UrlString, scheme: string): boolean;
/**
 * File paths in DevTools that are represented either as unencoded absolute or relative paths, or encoded paths, or URLs.
 * @example
 * RawPathString: “/Hello World/file.js”
 * EncodedPathString: “/Hello%20World/file.js”
 * UrlString: “file:///Hello%20World/file/js”
 */
type BrandedPathString = Platform.DevToolsPath.UrlString | Platform.DevToolsPath.RawPathString | Platform.DevToolsPath.EncodedPathString;
export declare class ParsedURL {
    isValid: boolean;
    url: string;
    scheme: string;
    user: string;
    host: string;
    port: string;
    path: string;
    queryParams: string;
    fragment: string;
    folderPathComponents: string;
    lastPathComponent: string;
    readonly blobInnerScheme: string | undefined;
    constructor(url: string);
    static fromString(string: string): ParsedURL | null;
    static preEncodeSpecialCharactersInPath(path: string): string;
    static rawPathToEncodedPathString(path: Platform.DevToolsPath.RawPathString): Platform.DevToolsPath.EncodedPathString;
    /**
     * @param name Must not be encoded
     */
    static encodedFromParentPathAndName(parentPath: Platform.DevToolsPath.EncodedPathString, name: string): Platform.DevToolsPath.EncodedPathString;
    /**
     * @param name Must not be encoded
     */
    static urlFromParentUrlAndName(parentUrl: Platform.DevToolsPath.UrlString, name: string): Platform.DevToolsPath.UrlString;
    static encodedPathToRawPathString(encPath: Platform.DevToolsPath.EncodedPathString): Platform.DevToolsPath.RawPathString;
    static rawPathToUrlString(fileSystemPath: Platform.DevToolsPath.RawPathString): Platform.DevToolsPath.UrlString;
    static relativePathToUrlString(relativePath: Platform.DevToolsPath.RawPathString, baseURL: Platform.DevToolsPath.UrlString): Platform.DevToolsPath.UrlString;
    static urlToRawPathString(fileURL: Platform.DevToolsPath.UrlString, isWindows?: boolean): Platform.DevToolsPath.RawPathString;
    static sliceUrlToEncodedPathString(url: Platform.DevToolsPath.UrlString, start: number): Platform.DevToolsPath.EncodedPathString;
    static substr<DevToolsPathType extends BrandedPathString>(devToolsPath: DevToolsPathType, from: number, length?: number): DevToolsPathType;
    static substring<DevToolsPathType extends BrandedPathString>(devToolsPath: DevToolsPathType, start: number, end?: number): DevToolsPathType;
    static prepend<DevToolsPathType extends BrandedPathString>(prefix: string, devToolsPath: DevToolsPathType): DevToolsPathType;
    static concatenate<DevToolsPathType extends BrandedPathString>(devToolsPath: DevToolsPathType, ...appendage: string[]): DevToolsPathType;
    static trim<DevToolsPathType extends BrandedPathString>(devToolsPath: DevToolsPathType): DevToolsPathType;
    static slice<DevToolsPathType extends BrandedPathString>(devToolsPath: DevToolsPathType, start?: number, end?: number): DevToolsPathType;
    static join<DevToolsPathType extends BrandedPathString>(devToolsPaths: DevToolsPathType[], separator?: string): DevToolsPathType;
    static split<DevToolsPathType extends BrandedPathString>(devToolsPath: DevToolsPathType, separator: string | RegExp, limit?: number): DevToolsPathType[];
    static toLowerCase<DevToolsPathType extends BrandedPathString>(devToolsPath: DevToolsPathType): DevToolsPathType;
    static isValidUrlString(str: string): str is Platform.DevToolsPath.UrlString;
    static urlWithoutHash(url: string): string;
    static urlRegex(): RegExp;
    static extractPath(url: Platform.DevToolsPath.UrlString): Platform.DevToolsPath.EncodedPathString;
    static extractOrigin(url: Platform.DevToolsPath.UrlString): Platform.DevToolsPath.UrlString;
    static extractExtension(url: string): string;
    static extractName(url: string): string;
    static completeURL(baseURL: Platform.DevToolsPath.UrlString, href: string): Platform.DevToolsPath.UrlString | null;
    static splitLineAndColumn(string: string): {
        url: Platform.DevToolsPath.UrlString;
        lineNumber: (number | undefined);
        columnNumber: (number | undefined);
    };
    static removeWasmFunctionInfoFromURL(url: string): Platform.DevToolsPath.UrlString;
    private static beginsWithWindowsDriveLetter;
    private static beginsWithScheme;
    static isRelativeURL(url: string): boolean;
    isAboutBlank(): boolean;
    isDataURL(): boolean;
    extractDataUrlMimeType(): {
        type: string | undefined;
        subtype: string | undefined;
    };
    isBlobURL(): boolean;
    lastPathComponentWithFragment(): string;
    domain(): string;
    securityOrigin(): Platform.DevToolsPath.UrlString;
    urlWithoutScheme(): string;
    static urlRegexInstance: RegExp | null;
}
export {};
