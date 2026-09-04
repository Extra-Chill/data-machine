import { createRequire } from 'node:module';
import { installDomGlobals } from './dom-globals.js';
import { requireWp } from './require-wp.js';

type JSDOMConstructor = new (
  html?: string,
  options?: { pretendToBeVisual?: boolean; url?: string },
) => BootstrapDom;

type JSDOMModule = {
  JSDOM: JSDOMConstructor;
};

type BlockLibraryModule = {
  registerCoreBlocks: () => void;
};

type BootstrapWindow = Window &
  typeof globalThis & {
    close: () => void;
  };

type BootstrapDom = {
  window: BootstrapWindow;
};

const requireFromHere = createRequire(
  typeof __filename === 'string' ? __filename : import.meta.url,
);

let dom: BootstrapDom | undefined;
let ready = false;

export function bootstrap(): void {
  if (ready) {
    return;
  }

  const { JSDOM } = requireFromHere('jsdom') as JSDOMModule;
  dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    url: 'http://localhost',
    pretendToBeVisual: true,
  });

  installDomGlobals(dom.window);

  try {
    const { registerCoreBlocks } = requireWp(
      '@wordpress/block-library',
    ) as unknown as BlockLibraryModule;
    registerCoreBlocks();
    ready = true;
  } catch (error) {
    dom.window.close();
    dom = undefined;
    throw error;
  }
}

export function __resetBootstrapForTest(): void {
  ready = false;
  dom?.window.close();
  dom = undefined;
}
