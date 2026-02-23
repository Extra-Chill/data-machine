// Copyright 2024 The Chromium Authors. All rights reserved.
// Use of this source code is governed by a BSD-style license that can be
// found in the LICENSE file.
import * as Types from '../types/types.js';
let lastUpdateLayoutTreeEvent = null;
let lastInvalidatedNode = null;
const selectorDataForUpdateLayoutTree = new Map();
const invalidatedNodeList = new Array();
export function reset() {
    lastUpdateLayoutTreeEvent = null;
    lastInvalidatedNode = null;
    selectorDataForUpdateLayoutTree.clear();
    invalidatedNodeList.length = 0;
}
export function handleEvent(event) {
    if (Types.Events.isStyleRecalcInvalidationTracking(event)) {
        /**
         * CSS Style substree invalidation
         * A subtree invalidation comes with two records, 1) a StyleInvalidatorInvalidationTracking
         * event 2) following with a StyleRecalcInvalidationTracking event. List of selectors and style
         * sheet ID information is stored in the 1st event. Subtree flag is stored in the 2nd
         * event.
         */
        if (event.args.data.subtree &&
            event.args.data.reason === Types.Events.StyleRecalcInvalidationReason.RELATED_STYLE_RULE &&
            lastInvalidatedNode && event.args.data.nodeId === lastInvalidatedNode.backendNodeId) {
            lastInvalidatedNode.subtree = true;
            return;
        }
    }
    if (Types.Events.isSelectorStats(event) && lastUpdateLayoutTreeEvent && event.args.selector_stats) {
        selectorDataForUpdateLayoutTree.set(lastUpdateLayoutTreeEvent, {
            timings: event.args.selector_stats.selector_timings,
        });
        return;
    }
    if (Types.Events.isStyleInvalidatorInvalidationTracking(event)) {
        const selectorList = new Array();
        event.args.data.selectors?.forEach(selector => {
            selectorList.push({
                selector: selector.selector,
                styleSheetId: selector.style_sheet_id,
            });
        });
        if (selectorList.length > 0) {
            lastInvalidatedNode = {
                frame: event.args.data.frame,
                backendNodeId: event.args.data.nodeId,
                type: Types.Events.InvalidationEventType.StyleInvalidatorInvalidationTracking,
                selectorList,
                ts: event.ts,
                tts: event.tts,
                subtree: false,
                lastUpdateLayoutTreeEventTs: lastUpdateLayoutTreeEvent ? lastUpdateLayoutTreeEvent.ts : Types.Timing.Micro(0),
            };
            invalidatedNodeList.push(lastInvalidatedNode);
        }
    }
    if (Types.Events.isUpdateLayoutTree(event)) {
        lastUpdateLayoutTreeEvent = event;
        return;
    }
}
export async function finalize() {
}
export function data() {
    return {
        dataForUpdateLayoutEvent: selectorDataForUpdateLayoutTree,
        invalidatedNodeList,
    };
}
//# sourceMappingURL=SelectorStatsHandler.js.map