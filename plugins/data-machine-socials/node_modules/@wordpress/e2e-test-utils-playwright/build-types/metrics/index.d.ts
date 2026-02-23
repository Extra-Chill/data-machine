/**
 * External dependencies
 */
import type { Page, Browser } from '@playwright/test';
type EventType = 'click' | 'focus' | 'focusin' | 'keydown' | 'keypress' | 'keyup' | 'mouseout' | 'mouseover';
interface TraceEvent {
    cat: string;
    name: string;
    dur?: number;
    args: {
        data?: {
            type: EventType;
        };
    };
}
interface Trace {
    traceEvents: TraceEvent[];
}
type MetricsConstructorProps = {
    page: Page;
};
interface WebVitalsMeasurements {
    CLS?: number;
    FCP?: number;
    FID?: number;
    INP?: number;
    LCP?: number;
    TTFB?: number;
}
export declare class Metrics {
    browser: Browser;
    page: Page;
    trace: Trace;
    webVitals: WebVitalsMeasurements;
    constructor({ page }: MetricsConstructorProps);
    /**
     * Returns durations from the Server-Timing header.
     *
     * @param fields Optional fields to filter.
     */
    getServerTiming(fields?: string[]): Promise<Record<string, number>>;
    /**
     * Returns time to first byte (TTFB) using the Navigation Timing API.
     *
     * @see https://web.dev/ttfb/#measure-ttfb-in-javascript
     *
     * @return TTFB value.
     */
    getTimeToFirstByte(): Promise<number>;
    /**
     * Returns the Largest Contentful Paint (LCP) value using the dedicated API.
     *
     * @see https://w3c.github.io/largest-contentful-paint/
     * @see https://web.dev/lcp/#measure-lcp-in-javascript
     *
     * @return LCP value.
     */
    getLargestContentfulPaint(): Promise<number>;
    /**
     * Returns the Cumulative Layout Shift (CLS) value using the dedicated API.
     *
     * @see https://github.com/WICG/layout-instability
     * @see https://web.dev/cls/#measure-layout-shifts-in-javascript
     *
     * @return CLS value.
     */
    getCumulativeLayoutShift(): Promise<number>;
    /**
     * Returns the loading durations using the Navigation Timing API. All the
     * durations exclude the server response time.
     *
     * @return Object with loading metrics durations.
     */
    getLoadingDurations(): Promise<{
        serverResponse: number;
        firstPaint: number;
        domContentLoaded: number;
        loaded: number;
        firstContentfulPaint: number;
        timeSinceResponseEnd: number;
    }>;
    /**
     * Starts Chromium tracing with predefined options for performance testing.
     *
     * @param options Options to pass to `browser.startTracing()`.
     */
    startTracing(options?: {}): Promise<void>;
    /**
     * Stops Chromium tracing and saves the trace.
     */
    stopTracing(): Promise<void>;
    /**
     * @return Durations of all traced `keydown`, `keypress`, and `keyup`
     * events.
     */
    getTypingEventDurations(): number[][];
    /**
     * @return Durations of all traced `focus` and `focusin` events.
     */
    getSelectionEventDurations(): number[][];
    /**
     * @return Durations of all traced `click` events.
     */
    getClickEventDurations(): number[][];
    /**
     * @return Durations of all traced `mouseover` and `mouseout` events.
     */
    getHoverEventDurations(): number[][];
    /**
     * @param eventType Type of event to filter.
     * @return Durations of all events of a given type.
     */
    getEventDurations(eventType: EventType): number[];
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
    initWebVitals(reload?: boolean): Promise<void>;
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
    getWebVitals(): Promise<WebVitalsMeasurements>;
}
export {};
//# sourceMappingURL=index.d.ts.map