/**
 * Internal dependencies
 */
import type { RequestUtils } from './index';
declare const PAGE_STATUS: readonly ["publish", "future", "draft", "pending", "private", "trash"];
export type Page = {
    id: number;
    status: (typeof PAGE_STATUS)[number];
};
export type CreatePagePayload = {
    title?: string;
    content?: string;
    status: (typeof PAGE_STATUS)[number];
    date?: string;
    date_gmt?: string;
};
export declare function deletePage(this: RequestUtils, id: number): Promise<any>;
/**
 * Delete all pages using REST API.
 *
 * @param this
 */
export declare function deleteAllPages(this: RequestUtils): Promise<void>;
/**
 * Create a new page.
 *
 * @param this
 * @param payload The page payload.
 */
export declare function createPage(this: RequestUtils, payload: CreatePagePayload): Promise<Page>;
export {};
//# sourceMappingURL=pages.d.ts.map