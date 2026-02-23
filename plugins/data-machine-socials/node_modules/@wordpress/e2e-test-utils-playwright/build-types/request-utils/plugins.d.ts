/**
 * Internal dependencies
 */
import type { RequestUtils } from './index';
/**
 * Fetch the plugins from API and cache them in memory,
 * since they are unlikely to change during testing.
 *
 * @param this
 * @param forceRefetch Force refetch the installed plugins to update the cache.
 */
declare function getPluginsMap(this: RequestUtils, forceRefetch?: boolean): Promise<Record<string, string>>;
/**
 * Activates an installed plugin.
 *
 * @param this RequestUtils.
 * @param slug Plugin slug.
 */
declare function activatePlugin(this: RequestUtils, slug: string): Promise<void>;
/**
 * Deactivates an active plugin.
 *
 * @param this RequestUtils.
 * @param slug Plugin slug.
 */
declare function deactivatePlugin(this: RequestUtils, slug: string): Promise<void>;
export { getPluginsMap, activatePlugin, deactivatePlugin };
//# sourceMappingURL=plugins.d.ts.map