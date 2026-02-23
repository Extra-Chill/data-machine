/**
 * @typedef {Object} WPFormat
 *
 * @property {string}        name        A string identifying the format. Must be
 *                                       unique across all registered formats.
 * @property {string}        tagName     The HTML tag this format will wrap the
 *                                       selection with.
 * @property {boolean}       interactive Whether format makes content interactive or not.
 * @property {string | null} [className] A class to match the format.
 * @property {string}        title       Name of the format.
 * @property {Function}      edit        Should return a component for the user to
 *                                       interact with the new registered format.
 */
/**
 * Registers a new format provided a unique name and an object defining its
 * behavior.
 *
 * @param {string}   name     Format name.
 * @param {WPFormat} settings Format settings.
 *
 * @return {WPFormat|undefined} The format, if it has been successfully
 *                              registered; otherwise `undefined`.
 */
export function registerFormatType(name: string, settings: WPFormat): WPFormat | undefined;
export type WPFormat = {
    /**
     * A string identifying the format. Must be
     * unique across all registered formats.
     */
    name: string;
    /**
     * The HTML tag this format will wrap the
     * selection with.
     */
    tagName: string;
    /**
     * Whether format makes content interactive or not.
     */
    interactive: boolean;
    /**
     * A class to match the format.
     */
    className?: string | null | undefined;
    /**
     * Name of the format.
     */
    title: string;
    /**
     * Should return a component for the user to
     * interact with the new registered format.
     */
    edit: Function;
};
//# sourceMappingURL=register-format-type.d.ts.map