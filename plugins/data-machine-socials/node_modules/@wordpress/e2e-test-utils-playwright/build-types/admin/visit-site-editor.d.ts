/**
 * Internal dependencies
 */
import type { Admin } from './';
interface SiteEditorOptions {
    postId?: string | number;
    postType?: string;
    path?: string;
    canvas?: string;
    activeView?: string;
    showWelcomeGuide?: boolean;
}
/**
 * Visits the Site Editor main page.
 *
 * @param this
 * @param options Options to visit the site editor.
 */
export declare function visitSiteEditor(this: Admin, options?: SiteEditorOptions): Promise<void>;
export {};
//# sourceMappingURL=visit-site-editor.d.ts.map