// Copyright 2022 The Chromium Authors. All rights reserved.
// Use of this source code is governed by a BSD-style license that can be
// found in the LICENSE file.
import * as Platform from '../../core/platform/platform.js';
import * as Handlers from './handlers/handlers.js';
import * as Helpers from './helpers/helpers.js';
import { TraceParseProgressEvent, TraceProcessor } from './Processor.js';
import * as Types from './types/types.js';
/**
 * The Model is responsible for parsing arrays of raw trace events and storing the
 * resulting data. It can store multiple traces at once, and can return the data for
 * any of them.
 *
 * Most uses of this class should be through `createWithAllHandlers`, but
 * `createWithSubsetOfHandlers` can be used to run just some handlers.
 **/
export class Model extends EventTarget {
    #traces = [];
    #nextNumberByDomain = new Map();
    #recordingsAvailable = [];
    #lastRecordingIndex = 0;
    #processor;
    #config = Types.Configuration.defaults();
    static createWithAllHandlers(config) {
        return new Model(Handlers.ModelHandlers, config);
    }
    /**
     * Runs only the provided handlers.
     *
     * Callers must ensure they are providing all dependant handlers (although Meta is included automatically),
     * and must know that the result of `.parsedTrace` will be limited to the handlers provided, even though
     * the type won't reflect that.
     */
    static createWithSubsetOfHandlers(traceHandlers, config) {
        return new Model(traceHandlers, config);
    }
    constructor(handlers, config) {
        super();
        if (config) {
            this.#config = config;
        }
        this.#processor = new TraceProcessor(handlers, this.#config);
    }
    /**
     * Parses an array of trace events into a structured object containing all the
     * information parsed by the trace handlers.
     * You can `await` this function to pause execution until parsing is complete,
     * or instead rely on the `ModuleUpdateEvent` that is dispatched when the
     * parsing is finished.
     *
     * Once parsed, you then have to call the `parsedTrace` method, providing an
     * index of the trace you want to have the data for. This is because any model
     * can store a number of traces. Each trace is given an index, which starts at 0
     * and increments by one as a new trace is parsed.
     *
     * @example
     * // Awaiting the parse method() to block until parsing complete
     * await this.traceModel.parse(events);
     * const data = this.traceModel.parsedTrace(0)
     * @example
     * // Using an event listener to be notified when tracing is complete.
     * this.traceModel.addEventListener(Trace.ModelUpdateEvent.eventName, (event) => {
     *   if(event.data.data === 'done') {
     *     // trace complete
     *     const data = this.traceModel.parsedTrace(0);
     *   }
     * });
     * void this.traceModel.parse(events);
     **/
    async parse(traceEvents, config) {
        const metadata = config?.metadata || {};
        const isFreshRecording = config?.isFreshRecording || false;
        const isCPUProfile = metadata?.dataOrigin === Types.File.DataOrigin.CPU_PROFILE;
        // During parsing, periodically update any listeners on each processors'
        // progress (if they have any updates).
        const onTraceUpdate = (event) => {
            const { data } = event;
            this.dispatchEvent(new ModelUpdateEvent({ type: "PROGRESS_UPDATE" /* ModelUpdateType.PROGRESS_UPDATE */, data }));
        };
        this.#processor.addEventListener(TraceParseProgressEvent.eventName, onTraceUpdate);
        // Create a parsed trace file.  It will be populated with data from the processor.
        const file = {
            traceEvents,
            metadata,
            parsedTrace: null,
            traceInsights: null,
            syntheticEventsManager: Helpers.SyntheticEvents.SyntheticEventsManager.createAndActivate(traceEvents),
        };
        try {
            // Wait for all outstanding promises before finishing the async execution,
            // but perform all tasks in parallel.
            const parseConfig = {
                isFreshRecording,
                isCPUProfile,
                metadata,
                resolveSourceMap: config?.resolveSourceMap,
            };
            // if (window.location.href.includes('devtools/bundled') || window.location.search.includes('debugFrontend')) {
            //   // Someone is debugging DevTools, enable the logger.
            //   const times: Record<string, number> = {};
            //   parseConfig.logger = {
            //     start(id) {
            //       times[id] = performance.now();
            //     },
            //     end(id) {
            //       performance.measure(id, {start: times[id]});
            //     },
            //   };
            // }
            await this.#processor.parse(traceEvents, parseConfig);
            this.#storeParsedFileData(file, this.#processor.parsedTrace, this.#processor.insights);
            // We only push the file onto this.#traces here once we know it's valid
            // and there's been no errors in the parsing.
            this.#traces.push(file);
        }
        catch (e) {
            throw e;
        }
        finally {
            // All processors have finished parsing, no more updates are expected.
            this.#processor.removeEventListener(TraceParseProgressEvent.eventName, onTraceUpdate);
            // Finally, update any listeners that all processors are 'done'.
            this.dispatchEvent(new ModelUpdateEvent({ type: "COMPLETE" /* ModelUpdateType.COMPLETE */, data: 'done' }));
        }
    }
    #storeParsedFileData(file, data, insights) {
        file.parsedTrace = data;
        file.traceInsights = insights;
        this.#lastRecordingIndex++;
        let recordingName = `Trace ${this.#lastRecordingIndex}`;
        let origin = null;
        if (file.parsedTrace) {
            origin = Helpers.Trace.extractOriginFromTrace(file.parsedTrace.Meta.mainFrameURL);
            if (origin) {
                const nextSequenceForDomain = Platform.MapUtilities.getWithDefault(this.#nextNumberByDomain, origin, () => 1);
                recordingName = `${origin} (${nextSequenceForDomain})`;
                this.#nextNumberByDomain.set(origin, nextSequenceForDomain + 1);
            }
        }
        this.#recordingsAvailable.push(recordingName);
    }
    lastTraceIndex() {
        return this.size() - 1;
    }
    /**
     * Returns the parsed trace data indexed by the order in which it was stored.
     * If no index is given, the last stored parsed data is returned.
     */
    parsedTrace(index = this.#traces.length - 1) {
        return this.#traces.at(index)?.parsedTrace ?? null;
    }
    traceInsights(index = this.#traces.length - 1) {
        return this.#traces.at(index)?.traceInsights ?? null;
    }
    metadata(index = this.#traces.length - 1) {
        return this.#traces.at(index)?.metadata ?? null;
    }
    overrideModifications(index, newModifications) {
        if (this.#traces[index]) {
            this.#traces[index].metadata.modifications = newModifications;
        }
    }
    rawTraceEvents(index = this.#traces.length - 1) {
        return this.#traces.at(index)?.traceEvents ?? null;
    }
    syntheticTraceEventsManager(index = this.#traces.length - 1) {
        return this.#traces.at(index)?.syntheticEventsManager ?? null;
    }
    size() {
        return this.#traces.length;
    }
    deleteTraceByIndex(recordingIndex) {
        this.#traces.splice(recordingIndex, 1);
        this.#recordingsAvailable.splice(recordingIndex, 1);
    }
    getRecordingsAvailable() {
        return this.#recordingsAvailable;
    }
    resetProcessor() {
        this.#processor.reset();
    }
}
export class ModelUpdateEvent extends Event {
    data;
    static eventName = 'modelupdate';
    constructor(data) {
        super(ModelUpdateEvent.eventName);
        this.data = data;
    }
}
export function isModelUpdateDataComplete(eventData) {
    return eventData.type === "COMPLETE" /* ModelUpdateType.COMPLETE */;
}
//# sourceMappingURL=ModelImpl.js.map