"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.getSiteSettings = getSiteSettings;
exports.updateSiteSettings = updateSiteSettings;
/**
 * Get the site settings.
 *
 * @see https://developer.wordpress.org/rest-api/reference/settings/#retrieve-a-site-setting
 *
 * @param this RequestUtils.
 */
async function getSiteSettings() {
    return await this.rest({
        path: '/wp/v2/settings',
        method: 'GET',
    });
}
/**
 * Update the site settings.
 *
 * @see https://developer.wordpress.org/rest-api/reference/settings/#update-a-site-setting
 *
 * @param this         RequestUtils.
 * @param siteSettings The partial settings payload to update.
 */
async function updateSiteSettings(siteSettings) {
    return await this.rest({
        path: '/wp/v2/settings',
        method: 'POST',
        data: siteSettings,
    });
}
//# sourceMappingURL=site-settings.js.map