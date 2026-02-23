export function createI18n(initialData?: LocaleData | undefined, initialDomain?: string | undefined, hooks?: import("@wordpress/hooks/build-types/createHooks")._Hooks | undefined): I18n;
export type LocaleData = Record<string, any>;
/**
 * Returns locale data by domain in a
 * Jed-formatted JSON object shape.
 */
export type GetLocaleData = (domain?: string) => LocaleData;
/**
 * Merges locale data into the Tannin instance by domain. Note that this
 * function will overwrite the domain configuration. Accepts data in a
 * Jed-formatted JSON object shape.
 */
export type SetLocaleData = (data?: LocaleData, domain?: string) => void;
/**
 * Merges locale data into the Tannin instance by domain. Note that this
 * function will also merge the domain configuration. Accepts data in a
 * Jed-formatted JSON object shape.
 */
export type AddLocaleData = (data?: LocaleData, domain?: string) => void;
/**
 * Resets all current Tannin instance locale data and sets the specified
 * locale data for the domain. Accepts data in a Jed-formatted JSON object shape.
 */
export type ResetLocaleData = (data?: LocaleData, domain?: string) => void;
export type SubscribeCallback = () => void;
export type UnsubscribeCallback = () => void;
/**
 * Subscribes to changes of locale data
 */
export type Subscribe = (callback: SubscribeCallback) => UnsubscribeCallback;
/**
 * Retrieve the domain to use when calling domain-specific filters.
 */
export type GetFilterDomain = (domain?: string) => string;
/**
 * Retrieve the translation of text.
 */
export type __ = (text: string, domain?: string) => string;
/**
 * Retrieve translated string with gettext context.
 */
export type _x = (text: string, context: string, domain?: string) => string;
/**
 * Translates and retrieves the singular or plural form based on the supplied
 * number.
 */
export type _n = (single: string, plural: string, number: number, domain?: string) => string;
/**
 * Translates and retrieves the singular or plural form based on the supplied
 * number, with gettext context.
 */
export type _nx = (single: string, plural: string, number: number, context: string, domain?: string) => string;
/**
 * Check if current locale is RTL.
 *
 * **RTL (Right To Left)** is a locale property indicating that text is written from right to left.
 * For example, the `he` locale (for Hebrew) specifies right-to-left. Arabic (ar) is another common
 * language written RTL. The opposite of RTL, LTR (Left To Right) is used in other languages,
 * including English (`en`, `en-US`, `en-GB`, etc.), Spanish (`es`), and French (`fr`).
 */
export type IsRtl = () => boolean;
/**
 * Check if there is a translation for a given string in singular form.
 */
export type HasTranslation = (single: string, context?: string, domain?: string) => boolean;
export type Hooks = import('@wordpress/hooks').Hooks;
/**
 * An i18n instance
 */
export type I18n = {
    /**
     * Returns locale data by domain in a Jed-formatted JSON object shape.
     */
    getLocaleData: GetLocaleData;
    /**
     * Merges locale data into the Tannin instance by domain. Note that this
     * function will overwrite the domain configuration. Accepts data in a
     * Jed-formatted JSON object shape.
     */
    setLocaleData: SetLocaleData;
    /**
     * Merges locale data into the Tannin instance by domain. Note that this
     * function will also merge the domain configuration. Accepts data in a
     * Jed-formatted JSON object shape.
     */
    addLocaleData: AddLocaleData;
    /**
     * Resets all current Tannin instance locale data and sets the specified
     * locale data for the domain. Accepts data in a Jed-formatted JSON object shape.
     */
    resetLocaleData: ResetLocaleData;
    /**
     * Subscribes to changes of Tannin locale data.
     */
    subscribe: Subscribe;
    /**
     * Retrieve the translation of text.
     */
    __: __;
    /**
     * Retrieve translated string with gettext context.
     */
    _x: _x;
    /**
     * Translates and retrieves the singular or plural form based on the supplied
     * number.
     */
    _n: _n;
    /**
     * Translates and retrieves the singular or plural form based on the supplied
     * number, with gettext context.
     */
    _nx: _nx;
    /**
     * Check if current locale is RTL.
     */
    isRTL: IsRtl;
    /**
     * Check if there is a translation for a given string.
     */
    hasTranslation: HasTranslation;
};
//# sourceMappingURL=create-i18n.d.ts.map