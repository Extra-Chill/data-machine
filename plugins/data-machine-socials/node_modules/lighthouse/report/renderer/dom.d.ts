export class DOM {
    /**
     * @param {Document} document
     * @param {HTMLElement} rootEl
     */
    constructor(document: Document, rootEl: HTMLElement);
    /** @type {Document} */
    _document: Document;
    /** @type {string} */
    _lighthouseChannel: string;
    /** @type {Map<string, DocumentFragment>} */
    _componentCache: Map<string, DocumentFragment>;
    /** @type {HTMLElement} */
    rootEl: HTMLElement;
    /** @type {WeakMap<Element, Element>} */
    _swappableSections: WeakMap<Element, Element>;
    _onSwap: () => void;
    _onSwapHook: () => void;
    /**
     * @template {string} T
     * @param {T} name
     * @param {string=} className
     * @return {HTMLElementByTagName[T]}
     */
    createElement<T extends string>(name: T, className?: string | undefined): HTMLElementByTagName[T];
    /**
     * @param {string} namespaceURI
     * @param {string} name
     * @param {string=} className
     * @return {Element}
     */
    createElementNS(namespaceURI: string, name: string, className?: string | undefined): Element;
    /**
     * @template {string} T
     * @param {T} name
     * @param {string=} className
     * @return {SVGElementByTagName[T]}
     */
    createSVGElement<T extends string>(name: T, className?: string | undefined): SVGElementByTagName[T];
    /**
     * @return {!DocumentFragment}
     */
    createFragment(): DocumentFragment;
    /**
     * @param {string} data
     * @return {!Node}
     */
    createTextNode(data: string): Node;
    /**
     * @template {string} T
     * @param {Element} parentElem
     * @param {T} elementName
     * @param {string=} className
     * @return {HTMLElementByTagName[T]}
     */
    createChildOf<T extends string>(parentElem: Element, elementName: T, className?: string | undefined): HTMLElementByTagName[T];
    /**
     * @param {import('./components.js').ComponentName} componentName
     * @return {!DocumentFragment} A clone of the cached component.
     */
    createComponent(componentName: import("./components.js").ComponentName): DocumentFragment;
    clearComponentCache(): void;
    /**
     * @param {string} text
     * @param {{alwaysAppendUtmSource?: boolean}} opts
     * @return {Element}
     */
    convertMarkdownLinkSnippets(text: string, opts?: {
        alwaysAppendUtmSource?: boolean;
    }): Element;
    /**
     * Set link href, but safely, preventing `javascript:` protocol, etc.
     * @see https://github.com/google/safevalues/
     * @param {HTMLAnchorElement} elem
     * @param {string} url
     */
    safelySetHref(elem: HTMLAnchorElement, url: string): void;
    /**
     * Only create blob URLs for JSON & HTML
     * @param {HTMLAnchorElement} elem
     * @param {Blob} blob
     */
    safelySetBlobHref(elem: HTMLAnchorElement, blob: Blob): void;
    /**
     * @param {string} markdownText
     * @return {Element}
     */
    convertMarkdownCodeSnippets(markdownText: string): Element;
    /**
     * The channel to use for UTM data when rendering links to the documentation.
     * @param {string} lighthouseChannel
     */
    setLighthouseChannel(lighthouseChannel: string): void;
    /**
     * ONLY use if `dom.rootEl` isn't sufficient for your needs. `dom.rootEl` is preferred
     * for all scoping, because a document can have multiple reports within it.
     * @return {Document}
     */
    document(): Document;
    /**
     * TODO(paulirish): import and conditionally apply the DevTools frontend subclasses instead of this
     * @return {boolean}
     */
    isDevTools(): boolean;
    /**
     * Typed and guaranteed context.querySelector. Always returns an element or throws if
     * nothing matches query.
     *
     * @template {string} T
     * @param {T} query
     * @param {ParentNode} context
     * @return {ParseSelector<T>}
     */
    find<T extends string>(query: T, context?: ParentNode): ParseSelector<T>;
    /**
     * Typed context.querySelector.
     *
     * @template {string} T
     * @param {T} query
     * @param {ParentNode} context
     * @return {ParseSelector<T> | null}
     */
    maybeFind<T extends string>(query: T, context?: ParentNode): ParseSelector<T> | null;
    /**
     * Helper for context.querySelectorAll. Returns an Array instead of a NodeList.
     * @template {string} T
     * @param {T} query
     * @param {ParentNode} context
     */
    findAll<T extends string>(query: T, context: ParentNode): import("../../types/internal/query-selector.js").QuerySelectorParse<T>[];
    /**
     * Fires a custom DOM event on target.
     * @param {string} name Name of the event.
     * @param {Node=} target DOM node to fire the event on.
     * @param {*=} detail Custom data to include.
     */
    fireEventOn(name: string, target?: Node | undefined, detail?: any | undefined): void;
    /**
     * Downloads a file (blob) using a[download].
     * @param {Blob|File} blob The file to save.
     * @param {string} filename
     */
    saveFile(blob: Blob | File, filename: string): void;
    /**
     * @param {Element} section1
     * @param {Element} section2
     */
    registerSwappableSections(section1: Element, section2: Element): void;
    /**
     * @param {Element} section
     */
    swapSectionIfPossible(section: Element): void;
}
export type HTMLElementByTagName = HTMLElementTagNameMap & {
    [id: string]: HTMLElement;
};
export type SVGElementByTagName = SVGElementTagNameMap & {
    [id: string]: SVGElement;
};
export type ParseSelector<T extends string> = import("typed-query-selector/parser").ParseSelector<T, Element>;
//# sourceMappingURL=dom.d.ts.map