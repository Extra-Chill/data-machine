/**
 * Internal dependencies
 */
import type { Editor } from './index';
interface Options {
    isOnlyCurrentEntityDirty?: boolean;
}
/**
 * Save entities in the site editor. Assumes the editor is in a dirty state.
 *
 * @param this
 * @param options
 */
export declare function saveSiteEditorEntities(this: Editor, options?: Options): Promise<void>;
export {};
//# sourceMappingURL=site-editor.d.ts.map