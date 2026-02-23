/**
 * Internal dependencies
 */
import type { RequestUtils } from './index';
/**
 * Sets the Gutenberg experiments.
 *
 * @param this
 * @param experiments Array of experimental flags to enable. Pass in an empty array to disable all experiments.
 *                    Use 'active_templates' for the template activation feature.
 */
declare function setGutenbergExperiments(this: RequestUtils, experiments: string[]): Promise<void>;
export { setGutenbergExperiments };
//# sourceMappingURL=gutenberg-experiments.d.ts.map