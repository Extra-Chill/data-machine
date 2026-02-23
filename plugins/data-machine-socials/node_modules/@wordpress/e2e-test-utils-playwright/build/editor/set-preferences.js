"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.setPreferences = setPreferences;
/**
 * Set the preferences of the editor.
 *
 * @param this
 * @param context     Context to set preferences for.
 * @param preferences Preferences to set.
 */
async function setPreferences(context, preferences) {
    await this.page.waitForFunction(() => window?.wp?.data);
    await this.page.evaluate(async (props) => {
        for (const [key, value] of Object.entries(props.preferences)) {
            await window.wp.data
                .dispatch('core/preferences')
                .set(props.context, key, value);
        }
    }, { context, preferences });
}
//# sourceMappingURL=set-preferences.js.map