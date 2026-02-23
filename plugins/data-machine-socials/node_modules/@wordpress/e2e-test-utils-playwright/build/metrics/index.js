"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.Metrics = void 0;
const path_1 = require("path");
class Metrics {
    browser;
    page;
    trace;
    webVitals = {};
    constructor({ page }) {
        this.page = page;
        this.browser = page.context().browser();
        this.trace = { traceEvents: [] };
    }
    /**
     * Returns durations from the Server-Timing header.
     *
     * @param fields Optional fields to filter.
     */
    async getServerTiming(fields = []) {
        return this.page.evaluate((f) => performance.getEntriesByType('navigation')[0].serverTiming.reduce((acc, entry) => {
            if (f.length === 0 || f.includes(entry.name)) {
                acc[entry.name] = entry.duration;
            }
            return acc;
        }, {}), fields);
    }
    /**
     * Returns time to first byte (TTFB) using the Navigation Timing API.
     *
     * @see https://web.dev/ttfb/#measure-ttfb-in-javascript
     *
     * @return TTFB value.
     */
    async getTimeToFirstByte() {
        return await this.page.evaluate(() => {
            const { responseStart, startTime } = performance.getEntriesByType('navigation')[0];
            return responseStart - startTime;
        });
    }
    /**
     * Returns the Largest Contentful Paint (LCP) value using the dedicated API.
     *
     * @see https://w3c.github.io/largest-contentful-paint/
     * @see https://web.dev/lcp/#measure-lcp-in-javascript
     *
     * @return LCP value.
     */
    async getLargestContentfulPaint() {
        return await this.page.evaluate(() => new Promise((resolve) => {
            new PerformanceObserver((entryList) => {
                const entries = entryList.getEntries();
                // The last entry is the largest contentful paint.
                const largestPaintEntry = entries.at(-1);
                resolve(largestPaintEntry?.startTime || 0);
            }).observe({
                type: 'largest-contentful-paint',
                buffered: true,
            });
        }));
    }
    /**
     * Returns the Cumulative Layout Shift (CLS) value using the dedicated API.
     *
     * @see https://github.com/WICG/layout-instability
     * @see https://web.dev/cls/#measure-layout-shifts-in-javascript
     *
     * @return CLS value.
     */
    async getCumulativeLayoutShift() {
        return await this.page.evaluate(() => new Promise((resolve) => {
            let CLS = 0;
            new PerformanceObserver((l) => {
                const entries = l.getEntries();
                entries.forEach((entry) => {
                    if (!entry.hadRecentInput) {
                        CLS += entry.value;
                    }
                });
                resolve(CLS);
            }).observe({
                type: 'layout-shift',
                buffered: true,
            });
        }));
    }
    /**
     * Returns the loading durations using the Navigation Timing API. All the
     * durations exclude the server response time.
     *
     * @return Object with loading metrics durations.
     */
    async getLoadingDurations() {
        return await this.page.evaluate(() => {
            const [{ requestStart, responseStart, responseEnd, domContentLoadedEventEnd, loadEventEnd, },] = performance.getEntriesByType('navigation');
            const paintTimings = performance.getEntriesByType('paint');
            const firstPaintStartTime = paintTimings.find(({ name }) => name === 'first-paint').startTime;
            const firstContentfulPaintStartTime = paintTimings.find(({ name }) => name === 'first-contentful-paint').startTime;
            return {
                // Server side metric.
                serverResponse: responseStart - requestStart,
                // For client side metrics, consider the end of the response (the
                // browser receives the HTML) as the start time (0).
                firstPaint: firstPaintStartTime - responseEnd,
                domContentLoaded: domContentLoadedEventEnd - responseEnd,
                loaded: loadEventEnd - responseEnd,
                firstContentfulPaint: firstContentfulPaintStartTime - responseEnd,
                timeSinceResponseEnd: performance.now() - responseEnd,
            };
        });
    }
    /**
     * Starts Chromium tracing with predefined options for performance testing.
     *
     * @param options Options to pass to `browser.startTracing()`.
     */
    async startTracing(options = {}) {
        return await this.browser.startTracing(this.page, {
            screenshots: false,
            categories: ['devtools.timeline'],
            ...options,
        });
    }
    /**
     * Stops Chromium tracing and saves the trace.
     */
    async stopTracing() {
        const traceBuffer = await this.browser.stopTracing();
        const traceJSON = JSON.parse(traceBuffer.toString());
        this.trace = traceJSON;
    }
    /**
     * @return Durations of all traced `keydown`, `keypress`, and `keyup`
     * events.
     */
    getTypingEventDurations() {
        return [
            this.getEventDurations('keydown'),
            this.getEventDurations('keypress'),
            this.getEventDurations('keyup'),
        ];
    }
    /**
     * @return Durations of all traced `focus` and `focusin` events.
     */
    getSelectionEventDurations() {
        return [
            this.getEventDurations('focus'),
            this.getEventDurations('focusin'),
        ];
    }
    /**
     * @return Durations of all traced `click` events.
     */
    getClickEventDurations() {
        return [this.getEventDurations('click')];
    }
    /**
     * @return Durations of all traced `mouseover` and `mouseout` events.
     */
    getHoverEventDurations() {
        return [
            this.getEventDurations('mouseover'),
            this.getEventDurations('mouseout'),
        ];
    }
    /**
     * @param eventType Type of event to filter.
     * @return Durations of all events of a given type.
     */
    getEventDurations(eventType) {
        if (this.trace.traceEvents.length === 0) {
            throw new Error('No trace events found. Did you forget to call stopTracing()?');
        }
        return this.trace.traceEvents
            .filter((item) => item.cat === 'devtools.timeline' &&
            item.name === 'EventDispatch' &&
            item?.args?.data?.type === eventType &&
            !!item.dur)
            .map((item) => (item.dur ? item.dur / 1000 : 0));
    }
    /**
     * Initializes the web-vitals library upon next page navigation.
     *
     * Defaults to automatically triggering the navigation,
     * but it can also be done manually.
     *
     * @example
     * ```js
     * await metrics.initWebVitals();
     * console.log( await metrics.getWebVitals() );
     * ```
     *
     * @example
     * ```js
     * await metrics.initWebVitals( false );
     * await page.goto( '/some-other-page' );
     * console.log( await metrics.getWebVitals() );
     * ```
     *
     * @param reload Whether to force navigation by reloading the current page.
     */
    async initWebVitals(reload = true) {
        await this.page.addInitScript({
            path: (0, path_1.join)(__dirname, '../../../../node_modules/web-vitals/dist/web-vitals.umd.cjs'),
        });
        await this.page.exposeFunction('__reportVitals__', (data) => {
            const measurement = JSON.parse(data);
            this.webVitals[measurement.name] = measurement.value;
        });
        await this.page.addInitScript(() => {
            const reportVitals = (measurement) => window.__reportVitals__(JSON.stringify(measurement));
            window.addEventListener('DOMContentLoaded', () => {
                // @ts-expect-error This is valid but web-vitals does not register the global types.
                window.webVitals.onCLS(reportVitals);
                // @ts-expect-error This is valid but web-vitals does not register the global types.
                window.webVitals.onFCP(reportVitals);
                // @ts-expect-error This is valid but web-vitals does not register the global types.
                window.webVitals.onFID(reportVitals);
                // @ts-expect-error This is valid but web-vitals does not register the global types.
                window.webVitals.onINP(reportVitals);
                // @ts-expect-error This is valid but web-vitals does not register the global types.
                window.webVitals.onLCP(reportVitals);
                // @ts-expect-error This is valid but web-vitals does not register the global types.
                window.webVitals.onTTFB(reportVitals);
            });
        });
        if (reload) {
            // By reloading the page the script will be applied.
            await this.page.reload();
        }
    }
    /**
     * Returns web vitals as collected by the web-vitals library.
     *
     * If the web-vitals library hasn't been loaded on the current page yet,
     * it will be initialized with a page reload.
     *
     * Reloads the page to force web-vitals to report all collected metrics.
     *
     * @return {WebVitalsMeasurements} Web vitals measurements.
     */
    async getWebVitals() {
        // Reset values.
        this.webVitals = {};
        const hasScript = await this.page.evaluate(
        // @ts-expect-error This is valid but web-vitals does not register the global types.
        () => typeof window.webVitals !== 'undefined');
        if (!hasScript) {
            await this.initWebVitals();
        }
        // Trigger navigation so the web-vitals library reports values on unload.
        await this.page.reload();
        return this.webVitals;
    }
}
exports.Metrics = Metrics;
//# sourceMappingURL=index.js.map