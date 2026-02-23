/**
 * Internal dependencies
 */
import type { Editor } from './index';
type PreferencesContext = 'core/edit-post' | 'core/edit-site' | 'core/customize-widgets';
/**
 * Set the preferences of the editor.
 *
 * @param this
 * @param context     Context to set preferences for.
 * @param preferences Preferences to set.
 */
export declare function setPreferences(this: Editor, context: PreferencesContext, preferences: Record<string, any>): Promise<void>;
export {};
//# sourceMappingURL=set-preferences.d.ts.map