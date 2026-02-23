export = SourceMap;
declare class SourceMap {
    /**
     * @param {string} compiledURL
     * @param {string} sourceMappingURL
     * @param {object} payload
     * Implements Source Map V3 model. See https://github.com/google/closure-compiler/wiki/Source-Maps
     * for format description.
     */
    constructor(compiledURL: string, sourceMappingURL: string, payload: object);
    compiledURL(): string;
    url(): string;
    sourceURLs(): any[];
    embeddedContentByURL(sourceURL: any): any;
    hasScopeInfo(): boolean;
    findEntry(lineNumber: any, columnNumber: any, inlineFrameIndex: any): {
        lineNumber: number;
        columnNumber: number;
        sourceURL?: string;
        sourceLineNumber: number;
        sourceColumnNumber: number;
        name?: string;
        lastColumnNumber?: number;
    } | {
        lineNumber: any;
        columnNumber: any;
        sourceIndex: any;
        sourceURL: any;
        sourceLineNumber: any;
        sourceColumnNumber: any;
        name: undefined;
    } | null;
    findEntryRanges(lineNumber: any, columnNumber: any): {
        range: any;
        sourceRange: any;
        sourceURL: string;
    } | null;
    sourceLineMapping(sourceURL: any, lineNumber: any, columnNumber: any): {
        lineNumber: number;
        columnNumber: number;
        sourceURL?: string;
        sourceLineNumber: number;
        sourceColumnNumber: number;
        name?: string;
        lastColumnNumber?: number;
    } | null;
    findReverseIndices(sourceURL: any, lineNumber: any, columnNumber: any): any;
    findReverseEntries(sourceURL: any, lineNumber: any, columnNumber: any): any;
    findReverseRanges(sourceURL: any, lineNumber: any, columnNumber: any): any[];
    /** @return {Array<{lineNumber: number, columnNumber: number, sourceURL?: string, sourceLineNumber: number, sourceColumnNumber: number, name?: string, lastColumnNumber?: number}>} */
    mappings(): Array<{
        lineNumber: number;
        columnNumber: number;
        sourceURL?: string;
        sourceLineNumber: number;
        sourceColumnNumber: number;
        name?: string;
        lastColumnNumber?: number;
    }>;
    reversedMappings(sourceURL: any): any;
    eachSection(callback: any): void;
    parseSources(sourceMap: any): void;
    parseMap(map: any, baseSourceIndex: any, baseLineNumber: any, baseColumnNumber: any): void;
    isSeparator(char: any): boolean;
    mapsOrigin(): boolean;
    hasIgnoreListHint(sourceURL: any): any;
    /**
     * Returns a list of ranges in the generated script for original sources that
     * match a predicate. Each range is a [begin, end) pair, meaning that code at
     * the beginning location, up to but not including the end location, matches
     * the predicate.
     */
    findRanges(predicate: any, options: any): any[];
    expandCallFrame(frame: any): any;
    resolveScopeChain(frame: any): any;
    findOriginalFunctionName(position: any): any;
    #private;
}
declare namespace SourceMap {
    export { parseSourceMap, __esModule, SourceMapEntry, SourceMap, TokenIterator };
}
/**
 * Parses the {@link content} as JSON, ignoring BOM markers in the beginning, and
 * also handling the CORB bypass prefix correctly.
 *
 * @param content the string representation of a sourcemap.
 * @returns the {@link SourceMapV3} representation of the {@link content}.
 */
declare function parseSourceMap(content: any): any;
declare const __esModule: boolean;
declare class SourceMapEntry {
    static compare(entry1: any, entry2: any): number;
    constructor(lineNumber: any, columnNumber: any, sourceIndex: any, sourceURL: any, sourceLineNumber: any, sourceColumnNumber: any, name: any);
    lineNumber: any;
    columnNumber: any;
    sourceIndex: any;
    sourceURL: any;
    sourceLineNumber: any;
    sourceColumnNumber: any;
    name: any;
}
declare class TokenIterator {
    constructor(string: any);
    next(): any;
    /** Returns the unicode value of the next character and advances the iterator  */
    nextCharCode(): any;
    peek(): any;
    hasNext(): boolean;
    nextVLQ(): number;
    /**
     * @returns the next VLQ number without iterating further. Or returns null if
     * the iterator is at the end or it's not a valid number.
     */
    peekVLQ(): number | null;
    #private;
}
//# sourceMappingURL=SourceMap.d.ts.map