"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.Lighthouse = void 0;
const index_cjs_1 = require("lighthouse/core/index.cjs");
class Lighthouse {
    page;
    port;
    constructor({ page, port }) {
        this.page = page;
        this.port = port;
    }
    /**
     * Returns the Lighthouse report for the current URL.
     *
     * Runs several Lighthouse audits in a separate browser window and returns
     * the summary.
     */
    async getReport() {
        // From https://github.com/GoogleChrome/lighthouse/blob/36cac182a6c637b1671c57326d7c0241633d0076/core/config/default-config.js#L381-L446
        const audits = {
            'largest-contentful-paint': 'LCP',
            'total-blocking-time': 'TBT',
            interactive: 'TTI',
            'cumulative-layout-shift': 'CLS',
            'interaction-to-next-paint': 'INP',
        };
        const report = await (0, index_cjs_1.default)(this.page.url(), { port: this.port }, {
            extends: 'lighthouse:default',
            settings: {
                // "provided" means no throttling.
                // TODO: Make configurable.
                throttlingMethod: 'provided',
                // Default is "mobile".
                // See https://github.com/GoogleChrome/lighthouse/blob/main/docs/emulation.md
                // TODO: Make configurable.
                formFactor: 'desktop',
                screenEmulation: {
                    disabled: true,
                },
                // Speeds up the report.
                disableFullPageScreenshot: true,
                // Only run certain audits to speed things up.
                onlyAudits: Object.keys(audits),
            },
        });
        const result = {};
        if (!report) {
            return result;
        }
        const { lhr } = report;
        for (const [audit, acronym] of Object.entries(audits)) {
            result[acronym] = lhr.audits[audit]?.numericValue || 0;
        }
        return result;
    }
}
exports.Lighthouse = Lighthouse;
//# sourceMappingURL=index.js.map