declare namespace _default {
    export let simulationNamesToIgnore: string[];
    export { convertNodeTimingsToTrace };
}
export default _default;
export type CompleteNodeTiming = import("./lantern/lantern.js").Simulation.CompleteNodeTiming;
/**
 * @license
 * Copyright 2018 Google LLC
 * SPDX-License-Identifier: Apache-2.0
 */
/** @typedef {import('./lantern/lantern.js').Simulation.CompleteNodeTiming} CompleteNodeTiming */
/**
 * @param {Map<LH.Gatherer.Simulation.GraphNode, CompleteNodeTiming>} nodeTimings
 * @return {LH.Trace}
 */
declare function convertNodeTimingsToTrace(nodeTimings: Map<LH.Gatherer.Simulation.GraphNode, CompleteNodeTiming>): LH.Trace;
//# sourceMappingURL=lantern-trace-saver.d.ts.map