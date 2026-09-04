// scripts/generate-block-attrs-snapshot.mjs
//
// Regenerates src/core-block-attrs.json — the registered-metadata snapshot
// the block-contract check validates emitted markup against (block name →
// attribute names, plus the core/group tagName allowlist). Snapshot route
// deliberately chosen over importing @wordpress/block-library in library
// context: the library is jsdom-bound and React-pinned, while the snapshot is
// small, deterministic, and test-friendly.
//
// Run:
//   node scripts/generate-block-attrs-snapshot.mjs
//
// Re-run on @wordpress/block-library bumps in @automattic/blocks-engine.
import { createRequire } from 'node:module';
import { writeFileSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const engineRoot = join(here, '..');
const engineRequire = createRequire(join(engineRoot, 'package.json'));

// --- JSDOM globals BEFORE @wordpress/blocks -------------------------------
const { JSDOM } = engineRequire('jsdom');
const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
  url: 'http://localhost',
  pretendToBeVisual: true,
});
global.window = dom.window;
global.document = dom.window.document;
global.DOMParser = dom.window.DOMParser;
global.XMLSerializer = dom.window.XMLSerializer;
global.Node = dom.window.Node;
global.Element = dom.window.Element;
global.HTMLElement = dom.window.HTMLElement;
global.getComputedStyle = dom.window.getComputedStyle;
global.MutationObserver = dom.window.MutationObserver;
global.requestAnimationFrame = (cb) => setTimeout(cb, 16);
global.cancelAnimationFrame = (id) => clearTimeout(id);
global.matchMedia = () => ({
  matches: false,
  addListener: () => {},
  removeListener: () => {},
  addEventListener: () => {},
  removeEventListener: () => {},
});
global.ResizeObserver = class ResizeObserver {
  observe() {}
  unobserve() {}
  disconnect() {}
};
Object.defineProperty(global, 'navigator', {
  value: dom.window.navigator,
  writable: true,
  configurable: true,
});
// Keep @wordpress/* chatter off stdout.
console.log = (...args) => console.error(...args);
console.warn = (...args) => console.error(...args);

const { getBlockTypes } = engineRequire('@wordpress/blocks');
const { registerCoreBlocks } = engineRequire('@wordpress/block-library');
registerCoreBlocks();

// Block name → sorted attribute names. Sorted keys for byte-stable output.
const blocks = {};
for (const bt of getBlockTypes().sort((a, b) => a.name.localeCompare(b.name))) {
  blocks[bt.name] = Object.keys(bt.attributes ?? {}).sort();
}

// core/group tagName allowlist: NOT in block.json metadata (the attribute is
// a free string there) — the canonical list lives in the group edit UI's
// options. Extract it mechanically from the installed build so the snapshot
// tracks the pinned version; fall back to the documented list if the build
// shape ever changes (and say so loudly).
const DOCUMENTED_GROUP_TAGS = ['div', 'header', 'main', 'section', 'article', 'aside', 'footer'];
let groupTagNames = DOCUMENTED_GROUP_TAGS;
try {
  // Direct path (not require.resolve): the package's `exports` map does not
  // expose build/* subpaths, but the file is right there on disk.
  const libRoot = dirname(engineRequire.resolve('@wordpress/block-library/package.json'));
  const editCjs = readFileSync(join(libRoot, 'build', 'group', 'edit.cjs'), 'utf8');
  const extracted = [...editCjs.matchAll(/value:\s*"([a-z]+)"/g)].map((m) => m[1]);
  const known = extracted.filter((t) => /^[a-z]+$/.test(t));
  if (known.length >= 5 && known.includes('div')) {
    groupTagNames = [...new Set(known)];
  } else {
    console.error('[snapshot] group edit.cjs extraction looked wrong — using the documented list');
  }
} catch (e) {
  console.error(`[snapshot] group edit.cjs unreadable (${e.message}) — using the documented list`);
}

const enginePkg = JSON.parse(readFileSync(join(engineRoot, 'package.json'), 'utf8'));
const out = {
  __generated: 'node scripts/generate-block-attrs-snapshot.mjs (deps: @automattic/blocks-engine)',
  __wpBlockLibrary: enginePkg.dependencies['@wordpress/block-library'],
  groupTagNames,
  blocks,
};

const target = join(here, '..', 'src', 'core-block-attrs.json');
writeFileSync(target, JSON.stringify(out, null, 2) + '\n');
console.error(`[snapshot] wrote ${target}: ${Object.keys(blocks).length} blocks, groupTagNames=[${groupTagNames.join(', ')}]`);
