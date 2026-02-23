import { expect } from '@playwright/test';
/**
 * Internal dependencies
 */
import { Admin, Editor, PageUtils, RequestUtils, Metrics, Lighthouse } from './index';
declare const test: import("@playwright/test").TestType<import("@playwright/test").PlaywrightTestArgs & import("@playwright/test").PlaywrightTestOptions & {
    admin: Admin;
    editor: Editor;
    pageUtils: PageUtils;
    snapshotConfig: void;
    metrics: Metrics;
    lighthouse: Lighthouse;
}, import("@playwright/test").PlaywrightWorkerArgs & import("@playwright/test").PlaywrightWorkerOptions & {
    requestUtils: RequestUtils;
    lighthousePort: number;
}>;
export { test, expect };
//# sourceMappingURL=test.d.ts.map