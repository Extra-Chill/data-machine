"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.expect = exports.test = void 0;
/**
 * External dependencies
 */
const path = require("path");
const test_1 = require("@playwright/test");
Object.defineProperty(exports, "expect", { enumerable: true, get: function () { return test_1.expect; } });
const get_port_1 = require("get-port");
/**
 * Internal dependencies
 */
const index_1 = require("./index");
const STORAGE_STATE_PATH = process.env.STORAGE_STATE_PATH ||
    path.join(process.cwd(), 'artifacts/storage-states/admin.json');
/**
 * Set of console logging types observed to protect against unexpected yet
 * handled (i.e. not catastrophic) errors or warnings. Each key corresponds
 * to the Playwright ConsoleMessage type, its value the corresponding function
 * on the console global object.
 */
const OBSERVED_CONSOLE_MESSAGE_TYPES = ['warn', 'error'];
/**
 * Adds a page event handler to emit uncaught exception to process if one of
 * the observed console logging types is encountered.
 *
 * @param message The console message.
 */
function observeConsoleLogging(message) {
    const type = message.type();
    if (!OBSERVED_CONSOLE_MESSAGE_TYPES.includes(type)) {
        return;
    }
    const text = message.text();
    // An exception is made for _blanket_ deprecation warnings: Those
    // which log regardless of whether a deprecated feature is in use.
    if (text.includes('This is a global warning')) {
        return;
    }
    // A chrome advisory warning about SameSite cookies is informational
    // about future changes, tracked separately for improvement in core.
    //
    // See: https://core.trac.wordpress.org/ticket/37000
    // See: https://www.chromestatus.com/feature/5088147346030592
    // See: https://www.chromestatus.com/feature/5633521622188032
    if (text.includes('A cookie associated with a cross-site resource') ||
        text.includes('https://developer.mozilla.org/docs/Web/HTTP/Headers/Set-Cookie/SameSite')) {
        return;
    }
    // Viewing posts on the front end can result in this error, which
    // has nothing to do with Gutenberg.
    if (text.includes('net::ERR_UNKNOWN_URL_SCHEME')) {
        return;
    }
    // TODO: Not implemented yet.
    // Network errors are ignored only if we are intentionally testing
    // offline mode.
    // if (
    // 	text.includes( 'net::ERR_INTERNET_DISCONNECTED' ) &&
    // 	isOfflineMode()
    // ) {
    // 	return;
    // }
    // As of WordPress 5.3.2 in Chrome 79, navigating to the block editor
    // (Posts > Add New) will display a console warning about
    // non - unique IDs.
    // See: https://core.trac.wordpress.org/ticket/23165
    if (text.includes('elements with non-unique id #_wpnonce')) {
        return;
    }
    // Ignore all JQMIGRATE (jQuery migrate) deprecation warnings.
    if (text.includes('JQMIGRATE')) {
        return;
    }
    // https://bugzilla.mozilla.org/show_bug.cgi?id=1404468
    if (text.includes('Layout was forced before the page was fully loaded')) {
        return;
    }
    // Deprecated warnings coming from the third-party libraries.
    if (text.includes('MouseEvent.moz')) {
        return;
    }
    const logFunction = type;
    // Disable reason: We intentionally bubble up the console message
    // which, unless the test explicitly anticipates the logging via
    // @wordpress/jest-console matchers, will cause the intended test
    // failure.
    // eslint-disable-next-line no-console
    console[logFunction](text);
}
const test = test_1.test.extend({
    admin: async ({ page, pageUtils, editor }, use) => {
        await use(new index_1.Admin({ page, pageUtils, editor }));
    },
    editor: async ({ page }, use) => {
        await use(new index_1.Editor({ page }));
    },
    page: async ({ page }, use) => {
        page.on('console', observeConsoleLogging);
        await use(page);
        // Clear local storage after each test.
        // This needs to be wrapped with a try/catch because it can fail when
        // the test is skipped (e.g. via fixme).
        try {
            await page.evaluate(() => {
                window.localStorage.clear();
            });
        }
        catch (error) {
            // noop.
        }
        await page.close();
    },
    pageUtils: async ({ page, browserName }, use) => {
        await use(new index_1.PageUtils({ page, browserName }));
    },
    requestUtils: [
        async ({}, use, workerInfo) => {
            const requestUtils = await index_1.RequestUtils.setup({
                baseURL: workerInfo.project.use.baseURL,
                storageStatePath: STORAGE_STATE_PATH,
            });
            await use(requestUtils);
        },
        { scope: 'worker', auto: true },
    ],
    // Spins up a new browser for use by the Lighthouse fixture
    // so that Lighthouse can connect to the debugging port.
    // As a worker-scoped fixture, this will only launch 1
    // instance for the whole test worker, so multiple tests
    // will share the same instance with the same port.
    lighthousePort: [
        async ({}, use) => {
            const port = await (0, get_port_1.default)();
            const browser = await test_1.chromium.launch({
                args: [`--remote-debugging-port=${port}`],
            });
            await use(port);
            await browser.close();
        },
        { scope: 'worker' },
    ],
    lighthouse: async ({ page, lighthousePort }, use) => {
        await use(new index_1.Lighthouse({ page, port: lighthousePort }));
    },
    metrics: async ({ page }, use) => {
        await use(new index_1.Metrics({ page }));
    },
});
exports.test = test;
//# sourceMappingURL=test.js.map