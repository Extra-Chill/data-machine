/**
 * @param {LH.Artifacts.MetricComputationDataInput} data
 * @param {LH.Artifacts.ComputedContext} context
 */
export function getComputationDataParamsFromTrace(data: LH.Artifacts.MetricComputationDataInput, context: LH.Artifacts.ComputedContext): Promise<{
    simulator: import("../../../types/gatherer.js").default.Simulation.Simulator;
    graph: import("../../../types/gatherer.js").default.Simulation.GraphNode;
    processedNavigation: Lantern.Types.Simulation.ProcessedNavigation;
}>;
/**
 * @param {LH.Artifacts.MetricComputationDataInput} data
 * @param {LH.Artifacts.ComputedContext} context
 */
export function getComputationDataParamsFromDevtoolsLog(data: LH.Artifacts.MetricComputationDataInput, context: LH.Artifacts.ComputedContext): Promise<{
    simulator: import("../../../types/gatherer.js").default.Simulation.Simulator;
    graph: import("../../../types/gatherer.js").default.Simulation.GraphNode;
    processedNavigation: import("../../index.js").Artifacts.ProcessedNavigation;
}>;
/**
 * @param {LH.Artifacts.MetricComputationDataInput} data
 * @param {LH.Artifacts.ComputedContext} context
 */
export function getComputationDataParams(data: LH.Artifacts.MetricComputationDataInput, context: LH.Artifacts.ComputedContext): Promise<{
    simulator: import("../../../types/gatherer.js").default.Simulation.Simulator;
    graph: import("../../../types/gatherer.js").default.Simulation.GraphNode;
    processedNavigation: Lantern.Types.Simulation.ProcessedNavigation;
}>;
/**
 * @param {unknown} err
 * @return {never}
 */
export function lanternErrorAdapter(err: unknown): never;
import * as Lantern from '../../lib/lantern/lantern.js';
//# sourceMappingURL=lantern-metric.d.ts.map