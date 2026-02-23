export type LhlMessages = import("../../../shared/localization/locales").LhlMessages;
export namespace UIStrings {
    let ms: string;
    let seconds: string;
    let displayValueByteSavings: string;
    let displayValueMsSavings: string;
    let displayValueElementsFound: string;
    let columnURL: string;
    let columnSize: string;
    let columnResourceSize: string;
    let columnTransferSize: string;
    let columnCacheTTL: string;
    let columnWastedBytes: string;
    let columnWastedMs: string;
    let columnBlockingTime: string;
    let columnTimeSpent: string;
    let columnLocation: string;
    let columnResourceType: string;
    let columnRequests: string;
    let columnName: string;
    let columnSource: string;
    let columnElement: string;
    let columnStartTime: string;
    let columnDuration: string;
    let columnFailingElem: string;
    let columnDescription: string;
    let total: string;
    let totalResourceType: string;
    let documentResourceType: string;
    let scriptResourceType: string;
    let stylesheetResourceType: string;
    let imageResourceType: string;
    let mediaResourceType: string;
    let fontResourceType: string;
    let otherResourceType: string;
    let thirdPartyResourceType: string;
    let otherResourcesLabel: string;
    let firstContentfulPaintMetric: string;
    let interactiveMetric: string;
    let firstMeaningfulPaintMetric: string;
    let totalBlockingTimeMetric: string;
    let maxPotentialFIDMetric: string;
    let speedIndexMetric: string;
    let largestContentfulPaintMetric: string;
    let cumulativeLayoutShiftMetric: string;
    let interactionToNextPaint: string;
    let itemSeverityLow: string;
    let itemSeverityMedium: string;
    let itemSeverityHigh: string;
}
/**
 * Look up the best available locale for the requested language through these fall backs:
 * - exact match
 * - progressively shorter prefixes (`de-CH-1996` -> `de-CH` -> `de`)
 * - supported locales in Intl formatters
 *
 * If `locale` isn't provided or one could not be found, DEFAULT_LOCALE is returned.
 *
 * By default any of the locales Lighthouse has strings for can be returned, but this
 * can be overridden with `possibleLocales`, useful e.g. when Lighthouse is bundled and
 * only DEFAULT_LOCALE is available, but `possibleLocales` can be used to select a
 * locale available to be downloaded on demand.
 * @param {string|string[]=} locales
 * @param {Array<string>=} possibleLocales
 * @return {LH.Locale}
 */
export function lookupLocale(locales?: (string | string[]) | undefined, possibleLocales?: Array<string> | undefined): LH.Locale;
/**
 * Returns a function that generates `LH.IcuMessage` objects to localize the
 * messages in `fileStrings` and the shared `i18n.UIStrings`.
 * @param {string} filename
 * @param {Record<string, string>=} fileStrings
 */
export function createIcuMessageFn(filename: string, fileStrings?: Record<string, string> | undefined): (message: string, values?: Record<string, string | number>) => LH.IcuMessage;
/**
 * Returns true if the given value is a string or an LH.IcuMessage.
 * @param {unknown} value
 * @return {value is string|LH.IcuMessage}
 */
export function isStringOrIcuMessage(value: unknown): value is string | LH.IcuMessage;
//# sourceMappingURL=i18n.d.ts.map