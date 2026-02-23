/**
 * Internal dependencies
 */
import type { RequestUtils } from './index';
type SiteSettings = {
    title: string;
    description: string;
    url: string;
    email: string;
    timezone: string;
    date_format: string;
    time_format: string;
    start_of_week: number;
    language: string;
    use_smilies: boolean;
    default_category: number;
    default_post_format: string;
    posts_per_page: number;
    default_ping_status: 'open' | 'closed';
    default_comment_status: 'open' | 'closed';
    show_on_front: 'posts' | 'page';
    page_on_front: number;
    page_for_posts: number;
};
/**
 * Get the site settings.
 *
 * @see https://developer.wordpress.org/rest-api/reference/settings/#retrieve-a-site-setting
 *
 * @param this RequestUtils.
 */
export declare function getSiteSettings(this: RequestUtils): Promise<SiteSettings>;
/**
 * Update the site settings.
 *
 * @see https://developer.wordpress.org/rest-api/reference/settings/#update-a-site-setting
 *
 * @param this         RequestUtils.
 * @param siteSettings The partial settings payload to update.
 */
export declare function updateSiteSettings(this: RequestUtils, siteSettings: Partial<SiteSettings>): Promise<SiteSettings>;
export {};
//# sourceMappingURL=site-settings.d.ts.map