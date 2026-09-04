import { createRequire } from 'node:module';

type DomWindow = typeof globalThis & {
  document: Document;
  DOMParser: typeof DOMParser;
  XMLSerializer: typeof XMLSerializer;
  Node: typeof Node;
  Element: typeof Element;
  HTMLElement: typeof HTMLElement;
  getComputedStyle: typeof getComputedStyle;
  MutationObserver: typeof MutationObserver;
  navigator: Navigator;
};

type DomGlobalTarget = typeof globalThis & {
  window: Window & typeof globalThis;
  document: Document;
  DOMParser: typeof DOMParser;
  XMLSerializer: typeof XMLSerializer;
  Node: typeof Node;
  Element: typeof Element;
  HTMLElement: typeof HTMLElement;
  getComputedStyle: typeof getComputedStyle;
  MutationObserver: typeof MutationObserver;
  requestAnimationFrame: typeof requestAnimationFrame;
  cancelAnimationFrame: typeof cancelAnimationFrame;
  matchMedia: typeof matchMedia;
  ResizeObserver: typeof ResizeObserver;
  navigator: Navigator;
};

/**
 * Install jsdom-backed DOM globals (window, document, Node, etc.) plus
 * synthetic shims for requestAnimationFrame, cancelAnimationFrame,
 * matchMedia, and ResizeObserver onto `globalThis`.
 *
 * Shared by bootstrap.ts (runtime) and tests so both surfaces stay in sync.
 */
export function installDomGlobals(window: DomWindow): void {
  const target = globalThis as DomGlobalTarget;

  target.window = window as unknown as Window & typeof globalThis;
  target.document = window.document;
  target.DOMParser = window.DOMParser;
  target.XMLSerializer = window.XMLSerializer;
  target.Node = window.Node;
  target.Element = window.Element;
  target.HTMLElement = window.HTMLElement;
  target.getComputedStyle = window.getComputedStyle;
  target.MutationObserver = window.MutationObserver;
  target.requestAnimationFrame = ((callback) =>
    setTimeout(() => {
      callback(Date.now());
    }, 16) as unknown as number) as typeof requestAnimationFrame;
  target.cancelAnimationFrame = ((id) => {
    clearTimeout(id as unknown as ReturnType<typeof setTimeout>);
  }) as typeof cancelAnimationFrame;
  target.matchMedia = (() => ({
    matches: false,
    addListener() {},
    removeListener() {},
    addEventListener() {},
    removeEventListener() {},
  })) as unknown as typeof matchMedia;
  target.ResizeObserver = class ResizeObserver {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  } as typeof ResizeObserver;

  Object.defineProperty(target, 'navigator', {
    value: window.navigator,
    writable: true,
    configurable: true,
  });
}

type JSDOMModule = {
  JSDOM: new (
    html?: string,
    options?: { url?: string; pretendToBeVisual?: boolean },
  ) => { window: DomWindow };
};

const requireFromHere = createRequire(
  typeof __filename === 'string' ? __filename : import.meta.url,
);

/**
 * Create a fresh jsdom window and install its globals. Convenience wrapper used
 * by tests so they don't import `jsdom` directly (which is loaded via
 * createRequire here, matching bootstrap.ts, and needs no @types/jsdom).
 */
export function setupDomGlobals(): void {
  const { JSDOM } = requireFromHere('jsdom') as JSDOMModule;
  const { window } = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    url: 'http://localhost',
    pretendToBeVisual: true,
  });
  installDomGlobals(window);
}
