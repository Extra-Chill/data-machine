/**
 * Internal dependencies
 */
import type { RequestUtils } from './index';
declare function activateTheme(this: RequestUtils, themeSlug: string): Promise<void>;
declare function getCurrentThemeGlobalStylesPostId(this: RequestUtils): Promise<string>;
/**
 * Deletes all post revisions using the REST API.
 *
 * @param {}              this     RequestUtils.
 * @param {string|number} parentId Post attributes.
 */
declare function getThemeGlobalStylesRevisions(this: RequestUtils, parentId: number | string): Promise<Record<string, Object>[]>;
export { activateTheme, getCurrentThemeGlobalStylesPostId, getThemeGlobalStylesRevisions, };
//# sourceMappingURL=themes.d.ts.map