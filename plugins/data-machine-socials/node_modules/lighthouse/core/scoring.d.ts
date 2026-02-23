export class ReportScoring {
    /**
     * Computes the weighted-average of the score of the list of items.
     * @param {Array<{score: number|null, weight: number}>} items
     * @return {number|null}
     */
    static arithmeticMean(items: Array<{
        score: number | null;
        weight: number;
    }>): number | null;
    /**
     * Returns the report JSON object with computed scores.
     * @param {Object<string, LH.Config.Category>} configCategories
     * @param {Object<string, LH.RawIcu<LH.Audit.Result>>} resultsByAuditId
     * @return {Object<string, LH.RawIcu<LH.Result.Category>>}
     */
    static scoreAllCategories(configCategories: {
        [x: string]: import("./index.js").Config.Category;
    }, resultsByAuditId: {
        [x: string]: {
            displayValue?: string | import("./index.js").IcuMessage | undefined;
            explanation?: string | import("./index.js").IcuMessage | undefined;
            errorMessage?: string | import("./index.js").IcuMessage | undefined;
            errorStack?: string | import("./index.js").IcuMessage | undefined;
            warnings?: (string | import("./index.js").IcuMessage)[] | undefined;
            score: number | null;
            scoreDisplayMode: import("../types/lhr/audit-result.js").ScoreDisplayMode;
            title: string | import("./index.js").IcuMessage;
            id: string | import("./index.js").IcuMessage;
            description: string | import("./index.js").IcuMessage;
            numericValue?: number | undefined;
            numericUnit?: string | import("./index.js").IcuMessage | undefined;
            details?: {
                type: "criticalrequestchain";
                longestChain: {
                    duration: number;
                    length: number;
                    transferSize: number;
                };
                chains: {
                    [x: string]: {
                        request: {
                            url: string | import("./index.js").IcuMessage;
                            startTime: number;
                            endTime: number;
                            responseReceivedTime: number;
                            transferSize: number;
                        };
                        children?: /*elided*/ any | undefined;
                    };
                };
                debugData?: {
                    [x: string]: any;
                    type: "debugdata";
                } | undefined;
            } | {
                type: "network-tree";
                longestChain: {
                    duration: number;
                };
                chains: {
                    [x: string]: {
                        url: string | import("./index.js").IcuMessage;
                        navStartToEndTime: number;
                        transferSize: number;
                        isLongest?: boolean | undefined;
                        children?: /*elided*/ any | undefined;
                    };
                };
                debugData?: {
                    [x: string]: any;
                    type: "debugdata";
                } | undefined;
            } | {
                [x: string]: any;
                type: "debugdata";
            } | {
                type: "treemap-data";
                nodes: {
                    name: string | import("./index.js").IcuMessage;
                    resourceBytes: number;
                    encodedBytes?: number | undefined;
                    unusedBytes?: number | undefined;
                    duplicatedNormalizedModuleName?: string | import("./index.js").IcuMessage | undefined;
                    children?: /*elided*/ any[] | undefined;
                }[];
                debugData?: {
                    [x: string]: any;
                    type: "debugdata";
                } | undefined;
            } | {
                type: "filmstrip";
                scale: number;
                items: {
                    timing: number;
                    timestamp: number;
                    data: string | import("./index.js").IcuMessage;
                }[];
                debugData?: {
                    [x: string]: any;
                    type: "debugdata";
                } | undefined;
            } | {
                type: "list";
                items: ({
                    type: "network-tree";
                    longestChain: {
                        duration: number;
                    };
                    chains: {
                        [x: string]: {
                            url: string | import("./index.js").IcuMessage;
                            navStartToEndTime: number;
                            transferSize: number;
                            isLongest?: boolean | undefined;
                            children?: /*elided*/ any | undefined;
                        };
                    };
                    debugData?: {
                        [x: string]: any;
                        type: "debugdata";
                    } | undefined;
                } | {
                    [x: string]: any;
                    type: "debugdata";
                } | {
                    type: "checklist";
                    items: {
                        [x: string]: {
                            value: boolean;
                            label: import("./index.js").IcuMessage | string;
                        };
                    };
                    debugData?: {
                        [x: string]: any;
                        type: "debugdata";
                    } | undefined;
                } | {
                    type: "table";
                    headings: {
                        key: string | import("./index.js").IcuMessage | null;
                        label: import("./index.js").IcuMessage | string;
                        valueType: import("../types/lhr/audit-details.js").default.ItemValueType;
                        subItemsHeading?: {
                            key: string | import("./index.js").IcuMessage;
                            valueType?: import("../types/lhr/audit-details.js").default.ItemValueType | undefined;
                            displayUnit?: string | import("./index.js").IcuMessage | undefined;
                            granularity?: number | undefined;
                        } | undefined;
                        displayUnit?: string | import("./index.js").IcuMessage | undefined;
                        granularity?: number | undefined;
                    }[];
                    items: {
                        [x: string]: string | number | boolean | import("./index.js").IcuMessage | {
                            [x: string]: any;
                            type: "debugdata";
                        } | {
                            type: "node";
                            lhId?: string | import("./index.js").IcuMessage | undefined;
                            path?: string | import("./index.js").IcuMessage | undefined;
                            selector?: string | import("./index.js").IcuMessage | undefined;
                            boundingRect?: {
                                width: number;
                                height: number;
                                top: number;
                                right: number;
                                bottom: number;
                                left: number;
                            } | undefined;
                            snippet?: string | import("./index.js").IcuMessage | undefined;
                            nodeLabel?: string | import("./index.js").IcuMessage | undefined;
                            explanation?: string | import("./index.js").IcuMessage | undefined;
                        } | {
                            type: "text";
                            value: import("./index.js").IcuMessage | string;
                        } | {
                            type: "subitems";
                            items: /*elided*/ any[];
                        } | {
                            type: "source-location";
                            url: string | import("./index.js").IcuMessage;
                            urlProvider: "network" | "comment";
                            line: number;
                            column: number;
                            original?: {
                                file: string | import("./index.js").IcuMessage;
                                line: number;
                                column: number;
                            } | undefined;
                            functionName?: string | import("./index.js").IcuMessage | undefined;
                        } | {
                            type: "link";
                            text: string | import("./index.js").IcuMessage;
                            url: string | import("./index.js").IcuMessage;
                        } | {
                            type: "url";
                            value: string | import("./index.js").IcuMessage;
                        } | {
                            type: "code";
                            value: import("./index.js").IcuMessage | string;
                        } | {
                            type: "numeric";
                            value: number;
                            granularity?: number | undefined;
                        } | undefined;
                        debugData?: {
                            [x: string]: any;
                            type: "debugdata";
                        } | undefined;
                        subItems?: {
                            type: "subitems";
                            items: /*elided*/ any[];
                        } | undefined;
                    }[];
                    summary?: {
                        wastedMs?: number | undefined;
                        wastedBytes?: number | undefined;
                    } | undefined;
                    sortedBy?: (string | import("./index.js").IcuMessage)[] | undefined;
                    isEntityGrouped?: boolean | undefined;
                    skipSumming?: (string | import("./index.js").IcuMessage)[] | undefined;
                    debugData?: {
                        [x: string]: any;
                        type: "debugdata";
                    } | undefined;
                } | {
                    type: "node";
                    lhId?: string | import("./index.js").IcuMessage | undefined;
                    path?: string | import("./index.js").IcuMessage | undefined;
                    selector?: string | import("./index.js").IcuMessage | undefined;
                    boundingRect?: {
                        width: number;
                        height: number;
                        top: number;
                        right: number;
                        bottom: number;
                        left: number;
                    } | undefined;
                    snippet?: string | import("./index.js").IcuMessage | undefined;
                    nodeLabel?: string | import("./index.js").IcuMessage | undefined;
                    explanation?: string | import("./index.js").IcuMessage | undefined;
                } | {
                    type: "text";
                    value: import("./index.js").IcuMessage | string;
                } | {
                    type: "list-section";
                    title?: (import("./index.js").IcuMessage | string) | undefined;
                    description?: (import("./index.js").IcuMessage | string) | undefined;
                    value: {
                        type: "network-tree";
                        longestChain: {
                            duration: number;
                        };
                        chains: {
                            [x: string]: {
                                url: string | import("./index.js").IcuMessage;
                                navStartToEndTime: number;
                                transferSize: number;
                                isLongest?: boolean | undefined;
                                children?: /*elided*/ any | undefined;
                            };
                        };
                        debugData?: {
                            [x: string]: any;
                            type: "debugdata";
                        } | undefined;
                    } | {
                        [x: string]: any;
                        type: "debugdata";
                    } | {
                        type: "checklist";
                        items: {
                            [x: string]: {
                                value: boolean;
                                label: import("./index.js").IcuMessage | string;
                            };
                        };
                        debugData?: {
                            [x: string]: any;
                            type: "debugdata";
                        } | undefined;
                    } | {
                        type: "table";
                        headings: {
                            key: string | import("./index.js").IcuMessage | null;
                            label: import("./index.js").IcuMessage | string;
                            valueType: import("../types/lhr/audit-details.js").default.ItemValueType;
                            subItemsHeading?: {
                                key: string | import("./index.js").IcuMessage;
                                valueType?: import("../types/lhr/audit-details.js").default.ItemValueType | undefined;
                                displayUnit?: string | import("./index.js").IcuMessage | undefined;
                                granularity?: number | undefined;
                            } | undefined;
                            displayUnit?: string | import("./index.js").IcuMessage | undefined;
                            granularity?: number | undefined;
                        }[];
                        items: {
                            [x: string]: string | number | boolean | import("./index.js").IcuMessage | {
                                [x: string]: any;
                                type: "debugdata";
                            } | {
                                type: "node";
                                lhId?: string | import("./index.js").IcuMessage | undefined;
                                path?: string | import("./index.js").IcuMessage | undefined;
                                selector?: string | import("./index.js").IcuMessage | undefined;
                                boundingRect?: {
                                    width: number;
                                    height: number;
                                    top: number;
                                    right: number;
                                    bottom: number;
                                    left: number;
                                } | undefined;
                                snippet?: string | import("./index.js").IcuMessage | undefined;
                                nodeLabel?: string | import("./index.js").IcuMessage | undefined;
                                explanation?: string | import("./index.js").IcuMessage | undefined;
                            } | {
                                type: "text";
                                value: import("./index.js").IcuMessage | string;
                            } | {
                                type: "subitems";
                                items: /*elided*/ any[];
                            } | {
                                type: "source-location";
                                url: string | import("./index.js").IcuMessage;
                                urlProvider: "network" | "comment";
                                line: number;
                                column: number;
                                original?: {
                                    file: string | import("./index.js").IcuMessage;
                                    line: number;
                                    column: number;
                                } | undefined;
                                functionName?: string | import("./index.js").IcuMessage | undefined;
                            } | {
                                type: "link";
                                text: string | import("./index.js").IcuMessage;
                                url: string | import("./index.js").IcuMessage;
                            } | {
                                type: "url";
                                value: string | import("./index.js").IcuMessage;
                            } | {
                                type: "code";
                                value: import("./index.js").IcuMessage | string;
                            } | {
                                type: "numeric";
                                value: number;
                                granularity?: number | undefined;
                            } | undefined;
                            debugData?: {
                                [x: string]: any;
                                type: "debugdata";
                            } | undefined;
                            subItems?: {
                                type: "subitems";
                                items: /*elided*/ any[];
                            } | undefined;
                        }[];
                        summary?: {
                            wastedMs?: number | undefined;
                            wastedBytes?: number | undefined;
                        } | undefined;
                        sortedBy?: (string | import("./index.js").IcuMessage)[] | undefined;
                        isEntityGrouped?: boolean | undefined;
                        skipSumming?: (string | import("./index.js").IcuMessage)[] | undefined;
                        debugData?: {
                            [x: string]: any;
                            type: "debugdata";
                        } | undefined;
                    } | {
                        type: "node";
                        lhId?: string | import("./index.js").IcuMessage | undefined;
                        path?: string | import("./index.js").IcuMessage | undefined;
                        selector?: string | import("./index.js").IcuMessage | undefined;
                        boundingRect?: {
                            width: number;
                            height: number;
                            top: number;
                            right: number;
                            bottom: number;
                            left: number;
                        } | undefined;
                        snippet?: string | import("./index.js").IcuMessage | undefined;
                        nodeLabel?: string | import("./index.js").IcuMessage | undefined;
                        explanation?: string | import("./index.js").IcuMessage | undefined;
                    } | {
                        type: "text";
                        value: import("./index.js").IcuMessage | string;
                    };
                })[];
                debugData?: {
                    [x: string]: any;
                    type: "debugdata";
                } | undefined;
            } | {
                type: "opportunity";
                headings: {
                    key: string | import("./index.js").IcuMessage | null;
                    label: import("./index.js").IcuMessage | string;
                    valueType: import("../types/lhr/audit-details.js").default.ItemValueType;
                    subItemsHeading?: {
                        key: string | import("./index.js").IcuMessage;
                        valueType?: import("../types/lhr/audit-details.js").default.ItemValueType | undefined;
                        displayUnit?: string | import("./index.js").IcuMessage | undefined;
                        granularity?: number | undefined;
                    } | undefined;
                    displayUnit?: string | import("./index.js").IcuMessage | undefined;
                    granularity?: number | undefined;
                }[];
                items: {
                    [x: string]: string | number | boolean | import("./index.js").IcuMessage | {
                        [x: string]: any;
                        type: "debugdata";
                    } | {
                        type: "node";
                        lhId?: string | import("./index.js").IcuMessage | undefined;
                        path?: string | import("./index.js").IcuMessage | undefined;
                        selector?: string | import("./index.js").IcuMessage | undefined;
                        boundingRect?: {
                            width: number;
                            height: number;
                            top: number;
                            right: number;
                            bottom: number;
                            left: number;
                        } | undefined;
                        snippet?: string | import("./index.js").IcuMessage | undefined;
                        nodeLabel?: string | import("./index.js").IcuMessage | undefined;
                        explanation?: string | import("./index.js").IcuMessage | undefined;
                    } | {
                        type: "text";
                        value: import("./index.js").IcuMessage | string;
                    } | {
                        type: "subitems";
                        items: {
                            [x: string]: string | number | boolean | import("./index.js").IcuMessage | {
                                [x: string]: any;
                                type: "debugdata";
                            } | {
                                type: "node";
                                lhId?: string | import("./index.js").IcuMessage | undefined;
                                path?: string | import("./index.js").IcuMessage | undefined;
                                selector?: string | import("./index.js").IcuMessage | undefined;
                                boundingRect?: {
                                    width: number;
                                    height: number;
                                    top: number;
                                    right: number;
                                    bottom: number;
                                    left: number;
                                } | undefined;
                                snippet?: string | import("./index.js").IcuMessage | undefined;
                                nodeLabel?: string | import("./index.js").IcuMessage | undefined;
                                explanation?: string | import("./index.js").IcuMessage | undefined;
                            } | {
                                type: "text";
                                value: import("./index.js").IcuMessage | string;
                            } | /*elided*/ any | {
                                type: "source-location";
                                url: string | import("./index.js").IcuMessage;
                                urlProvider: "network" | "comment";
                                line: number;
                                column: number;
                                original?: {
                                    file: string | import("./index.js").IcuMessage;
                                    line: number;
                                    column: number;
                                } | undefined;
                                functionName?: string | import("./index.js").IcuMessage | undefined;
                            } | {
                                type: "link";
                                text: string | import("./index.js").IcuMessage;
                                url: string | import("./index.js").IcuMessage;
                            } | {
                                type: "url";
                                value: string | import("./index.js").IcuMessage;
                            } | {
                                type: "code";
                                value: import("./index.js").IcuMessage | string;
                            } | {
                                type: "numeric";
                                value: number;
                                granularity?: number | undefined;
                            } | undefined;
                            debugData?: {
                                [x: string]: any;
                                type: "debugdata";
                            } | undefined;
                            subItems?: /*elided*/ any | undefined;
                        }[];
                    } | {
                        type: "source-location";
                        url: string | import("./index.js").IcuMessage;
                        urlProvider: "network" | "comment";
                        line: number;
                        column: number;
                        original?: {
                            file: string | import("./index.js").IcuMessage;
                            line: number;
                            column: number;
                        } | undefined;
                        functionName?: string | import("./index.js").IcuMessage | undefined;
                    } | {
                        type: "link";
                        text: string | import("./index.js").IcuMessage;
                        url: string | import("./index.js").IcuMessage;
                    } | {
                        type: "url";
                        value: string | import("./index.js").IcuMessage;
                    } | {
                        type: "code";
                        value: import("./index.js").IcuMessage | string;
                    } | {
                        type: "numeric";
                        value: number;
                        granularity?: number | undefined;
                    } | undefined;
                    url: string | import("./index.js").IcuMessage;
                    wastedBytes?: number | undefined;
                    totalBytes?: number | undefined;
                    wastedMs?: number | undefined;
                    debugData?: {
                        [x: string]: any;
                        type: "debugdata";
                    } | undefined;
                    subItems?: {
                        type: "subitems";
                        items: {
                            [x: string]: string | number | boolean | import("./index.js").IcuMessage | {
                                [x: string]: any;
                                type: "debugdata";
                            } | {
                                type: "node";
                                lhId?: string | import("./index.js").IcuMessage | undefined;
                                path?: string | import("./index.js").IcuMessage | undefined;
                                selector?: string | import("./index.js").IcuMessage | undefined;
                                boundingRect?: {
                                    width: number;
                                    height: number;
                                    top: number;
                                    right: number;
                                    bottom: number;
                                    left: number;
                                } | undefined;
                                snippet?: string | import("./index.js").IcuMessage | undefined;
                                nodeLabel?: string | import("./index.js").IcuMessage | undefined;
                                explanation?: string | import("./index.js").IcuMessage | undefined;
                            } | {
                                type: "text";
                                value: import("./index.js").IcuMessage | string;
                            } | /*elided*/ any | {
                                type: "source-location";
                                url: string | import("./index.js").IcuMessage;
                                urlProvider: "network" | "comment";
                                line: number;
                                column: number;
                                original?: {
                                    file: string | import("./index.js").IcuMessage;
                                    line: number;
                                    column: number;
                                } | undefined;
                                functionName?: string | import("./index.js").IcuMessage | undefined;
                            } | {
                                type: "link";
                                text: string | import("./index.js").IcuMessage;
                                url: string | import("./index.js").IcuMessage;
                            } | {
                                type: "url";
                                value: string | import("./index.js").IcuMessage;
                            } | {
                                type: "code";
                                value: import("./index.js").IcuMessage | string;
                            } | {
                                type: "numeric";
                                value: number;
                                granularity?: number | undefined;
                            } | undefined;
                            debugData?: {
                                [x: string]: any;
                                type: "debugdata";
                            } | undefined;
                            subItems?: /*elided*/ any | undefined;
                        }[];
                    } | undefined;
                }[];
                sortedBy?: (string | import("./index.js").IcuMessage)[] | undefined;
                isEntityGrouped?: boolean | undefined;
                skipSumming?: (string | import("./index.js").IcuMessage)[] | undefined;
                overallSavingsMs?: number | undefined;
                overallSavingsBytes?: number | undefined;
                debugData?: {
                    [x: string]: any;
                    type: "debugdata";
                } | undefined;
            } | {
                type: "screenshot";
                timing: number;
                timestamp: number;
                data: string | import("./index.js").IcuMessage;
                debugData?: {
                    [x: string]: any;
                    type: "debugdata";
                } | undefined;
            } | {
                type: "checklist";
                items: {
                    [x: string]: {
                        value: boolean;
                        label: import("./index.js").IcuMessage | string;
                    };
                };
                debugData?: {
                    [x: string]: any;
                    type: "debugdata";
                } | undefined;
            } | {
                type: "table";
                headings: {
                    key: string | import("./index.js").IcuMessage | null;
                    label: import("./index.js").IcuMessage | string;
                    valueType: import("../types/lhr/audit-details.js").default.ItemValueType;
                    subItemsHeading?: {
                        key: string | import("./index.js").IcuMessage;
                        valueType?: import("../types/lhr/audit-details.js").default.ItemValueType | undefined;
                        displayUnit?: string | import("./index.js").IcuMessage | undefined;
                        granularity?: number | undefined;
                    } | undefined;
                    displayUnit?: string | import("./index.js").IcuMessage | undefined;
                    granularity?: number | undefined;
                }[];
                items: {
                    [x: string]: string | number | boolean | import("./index.js").IcuMessage | {
                        [x: string]: any;
                        type: "debugdata";
                    } | {
                        type: "node";
                        lhId?: string | import("./index.js").IcuMessage | undefined;
                        path?: string | import("./index.js").IcuMessage | undefined;
                        selector?: string | import("./index.js").IcuMessage | undefined;
                        boundingRect?: {
                            width: number;
                            height: number;
                            top: number;
                            right: number;
                            bottom: number;
                            left: number;
                        } | undefined;
                        snippet?: string | import("./index.js").IcuMessage | undefined;
                        nodeLabel?: string | import("./index.js").IcuMessage | undefined;
                        explanation?: string | import("./index.js").IcuMessage | undefined;
                    } | {
                        type: "text";
                        value: import("./index.js").IcuMessage | string;
                    } | {
                        type: "subitems";
                        items: /*elided*/ any[];
                    } | {
                        type: "source-location";
                        url: string | import("./index.js").IcuMessage;
                        urlProvider: "network" | "comment";
                        line: number;
                        column: number;
                        original?: {
                            file: string | import("./index.js").IcuMessage;
                            line: number;
                            column: number;
                        } | undefined;
                        functionName?: string | import("./index.js").IcuMessage | undefined;
                    } | {
                        type: "link";
                        text: string | import("./index.js").IcuMessage;
                        url: string | import("./index.js").IcuMessage;
                    } | {
                        type: "url";
                        value: string | import("./index.js").IcuMessage;
                    } | {
                        type: "code";
                        value: import("./index.js").IcuMessage | string;
                    } | {
                        type: "numeric";
                        value: number;
                        granularity?: number | undefined;
                    } | undefined;
                    debugData?: {
                        [x: string]: any;
                        type: "debugdata";
                    } | undefined;
                    subItems?: {
                        type: "subitems";
                        items: /*elided*/ any[];
                    } | undefined;
                }[];
                summary?: {
                    wastedMs?: number | undefined;
                    wastedBytes?: number | undefined;
                } | undefined;
                sortedBy?: (string | import("./index.js").IcuMessage)[] | undefined;
                isEntityGrouped?: boolean | undefined;
                skipSumming?: (string | import("./index.js").IcuMessage)[] | undefined;
                debugData?: {
                    [x: string]: any;
                    type: "debugdata";
                } | undefined;
            } | undefined;
            metricSavings?: {
                [x: string]: number | undefined;
            } | undefined;
            scoringOptions?: {
                p10: number;
                median: number;
            } | undefined;
            guidanceLevel?: number | undefined;
            replacesAudits?: (string | import("./index.js").IcuMessage)[] | undefined;
        };
    }): {
        [x: string]: {
            id: string | import("./index.js").IcuMessage;
            title: string | import("./index.js").IcuMessage;
            description?: string | import("./index.js").IcuMessage | undefined;
            manualDescription?: string | import("./index.js").IcuMessage | undefined;
            score: number | null;
            auditRefs: {
                id: string | import("./index.js").IcuMessage;
                weight: number;
                group?: string | import("./index.js").IcuMessage | undefined;
                acronym?: string | import("./index.js").IcuMessage | undefined;
            }[];
            supportedModes?: import("./index.js").Result.GatherMode[] | undefined;
        };
    };
}
//# sourceMappingURL=scoring.d.ts.map