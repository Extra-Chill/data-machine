/**
 * External dependencies
 */
import type { Page } from '@playwright/test';
type LighthouseConstructorProps = {
    page: Page;
    port: number;
};
export declare class Lighthouse {
    page: Page;
    port: number;
    constructor({ page, port }: LighthouseConstructorProps);
    /**
     * Returns the Lighthouse report for the current URL.
     *
     * Runs several Lighthouse audits in a separate browser window and returns
     * the summary.
     */
    getReport(): Promise<Record<string, number>>;
}
export {};
//# sourceMappingURL=index.d.ts.map