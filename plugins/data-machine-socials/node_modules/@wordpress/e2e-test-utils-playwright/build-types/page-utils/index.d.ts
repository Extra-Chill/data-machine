/**
 * External dependencies
 */
import type { Browser, Page, BrowserContext, PlaywrightWorkerOptions } from '@playwright/test';
/**
 * Internal dependencies
 */
import { dragFiles } from './drag-files';
import { isCurrentURL } from './is-current-url';
import { setClipboardData, pressKeys } from './press-keys';
import { setBrowserViewport } from './set-browser-viewport';
import { emulateNetworkConditions } from './emulate-network-conditions';
type PageUtilConstructorParams = {
    page: Page;
    browserName: PlaywrightWorkerOptions['browserName'];
};
declare class PageUtils {
    browser: Browser;
    page: Page;
    context: BrowserContext;
    browserName: PlaywrightWorkerOptions['browserName'];
    constructor({ page, browserName }: PageUtilConstructorParams);
    /** @borrows dragFiles as this.dragFiles */
    dragFiles: typeof dragFiles;
    /** @borrows isCurrentURL as this.isCurrentURL */
    isCurrentURL: typeof isCurrentURL;
    /** @borrows pressKeys as this.pressKeys */
    pressKeys: typeof pressKeys;
    /** @borrows setBrowserViewport as this.setBrowserViewport */
    setBrowserViewport: typeof setBrowserViewport;
    /** @borrows setClipboardData as this.setClipboardData */
    setClipboardData: typeof setClipboardData;
    /** @borrows emulateNetworkConditions as this.emulateNetworkConditions */
    emulateNetworkConditions: typeof emulateNetworkConditions;
}
export { PageUtils };
//# sourceMappingURL=index.d.ts.map