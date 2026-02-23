export default LongTasks;
export type TaskGroupIds = import("../lib/tracehouse/task-groups.js").TaskGroupIds;
export type Timing = {
    startTime: number;
    duration: number;
};
export type DebugTask = Timing & {
    urlIndex: number;
    [p: string]: number;
};
/** @typedef {import('../lib/tracehouse/task-groups.js').TaskGroupIds} TaskGroupIds */
/** @typedef {{startTime: number, duration: number}} Timing */
/** @typedef {Timing & {urlIndex: number, [p: string]: number}} DebugTask */
declare class LongTasks extends Audit {
    /**
     * Returns the timing information for the given task, recursively walking the
     * task's children and adding up time spent in each type of task activity.
     * If `taskTimingsByEvent` is present, it will be used for task timing instead
     * of the timings on the tasks themselves.
     * If `timeByTaskGroup` is not provided, a new Map will be populated with
     * timing breakdown; if one is provided, timing breakdown will be added to the
     * existing breakdown.
     *
     * TODO: when simulated, a significant number of child tasks are dropped, so
     * most time will be attributed to 'other' (the category of the top-level
     * RunTask). See pruning in `PageDependencyGraph.linkCPUNodes`.
     * @param {LH.Artifacts.TaskNode} task
     * @param {Map<Lantern.Types.TraceEvent, LH.Gatherer.Simulation.NodeTiming>|undefined} taskTimingsByEvent
     * @param {Map<TaskGroupIds, number>} [timeByTaskGroup]
     * @return {{startTime: number, duration: number, timeByTaskGroup: Map<TaskGroupIds, number>}}
     */
    static getTimingBreakdown(task: LH.Artifacts.TaskNode, taskTimingsByEvent: Map<Lantern.Types.TraceEvent, LH.Gatherer.Simulation.NodeTiming> | undefined, timeByTaskGroup?: Map<TaskGroupIds, number>): {
        startTime: number;
        duration: number;
        timeByTaskGroup: Map<TaskGroupIds, number>;
    };
    /**
     * @param {Array<LH.Artifacts.TaskNode>} longTasks
     * @param {Set<string>} jsUrls
     * @param {Map<Lantern.Types.TraceEvent, LH.Gatherer.Simulation.NodeTiming>|undefined} taskTimingsByEvent
     * @return {LH.Audit.Details.DebugData}
     */
    static makeDebugData(longTasks: Array<LH.Artifacts.TaskNode>, jsUrls: Set<string>, taskTimingsByEvent: Map<Lantern.Types.TraceEvent, LH.Gatherer.Simulation.NodeTiming> | undefined): LH.Audit.Details.DebugData;
    /**
     * Get timing from task, overridden by taskTimingsByEvent if provided.
     * @param {LH.Artifacts.TaskNode} task
     * @param {Map<Lantern.Types.TraceEvent, LH.Gatherer.Simulation.NodeTiming>|undefined} taskTimingsByEvent
     * @return {Timing}
     */
    static getTiming(task: LH.Artifacts.TaskNode, taskTimingsByEvent: Map<Lantern.Types.TraceEvent, LH.Gatherer.Simulation.NodeTiming> | undefined): Timing;
    /**
     * @param {LH.Artifacts} artifacts
     * @param {LH.Audit.Context} context
     * @return {Promise<LH.Audit.Product>}
     */
    static audit(artifacts: LH.Artifacts, context: LH.Audit.Context): Promise<LH.Audit.Product>;
}
export namespace UIStrings {
    let title: string;
    let description: string;
    let displayValue: string;
}
import { Audit } from './audit.js';
import * as Lantern from '../lib/lantern/lantern.js';
//# sourceMappingURL=long-tasks.d.ts.map