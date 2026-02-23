"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.resetPreferences = resetPreferences;
/**
 * Reset user preferences
 *
 * @param this Request utils.
 */
async function resetPreferences() {
    await this.rest({
        path: '/wp/v2/users/me',
        method: 'PUT',
        data: {
            meta: {
                persisted_preferences: {},
            },
        },
    });
}
//# sourceMappingURL=preferences.js.map