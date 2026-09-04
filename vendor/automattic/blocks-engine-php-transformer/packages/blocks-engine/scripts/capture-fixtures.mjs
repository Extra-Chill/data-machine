#!/usr/bin/env node
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const DLA_ROOT = process.env.DLA_ROOT ?? '/Users/matt/projects/a8c/data-liberation-agent';
const BLOCK_FIXER_DIR = path.join(DLA_ROOT, 'scripts/block-fixer');
const THIS_DIR = path.dirname(fileURLToPath(import.meta.url));
const CASES_PATH = path.resolve(THIS_DIR, '../src/__fixtures__/cases.json');

const RAW_CASES = [
  {
    id: 'raw.smoke-heading-paragraph-table',
    input:
      '<main><div class="wp-block-group"><h2 class="wp-block-heading">Widget Catalog</h2>' +
      '<p>Three fictional widgets for testing.</p>' +
      '<figure class="wp-block-table"><table><thead><tr><th>Name</th><th>Use</th></tr></thead>' +
      '<tbody><tr><td>Sprocket</td><td>spins</td></tr></tbody></table></figure></div></main>',
  },
  {
    id: 'raw.smoke-spacer-height',
    input: '<div class="wp-block-spacer" style="height:48px"></div><p>After.</p>',
  },
  {
    id: 'raw.smoke-post-meta-strip',
    input:
      '<main><h1 class="wp-block-post-title">A Quiet Honor</h1>' +
      '<div class="wp-block-template-part"><div class="wp-block-post-date"><time datetime="2024-11-11">Nov 11, 2024</time></div></div>' +
      '<div class="wp-block-post-author-name"><a class="wp-block-post-author-name__link" href="/author/poet">Poet</a></div>' +
      '<p>They leave without fanfare, packing pieces of themselves.</p>' +
      '<div class="wp-block-post-terms"><span class="wp-block-post-terms__prefix">Tags: </span><a href="/tag/x" rel="tag">Sentimental</a></div>' +
      '<a class="wp-block-post-navigation-link" href="/prev"><span class="wp-block-post-navigation-link__arrow-previous">Previous</span>Previous</a></main>',
  },
  {
    id: 'raw.sample-wix-div-soup',
    input:
      '<div id="SITE_PAGES"><div data-mesh-id="comp-heroinlineContent" class="wixui-section">' +
      '<div data-testid="richTextElement"><h2>Handmade planters</h2><p>Small-batch ceramics for bright windows.</p></div>' +
      '<div class="wixui-button"><a href="/shop">Shop the drop</a></div></div></div>',
  },
  {
    id: 'raw.sample-squarespace-sqs-block',
    input:
      '<section class="Index-page"><div class="sqs-layout"><div class="sqs-block html-block sqs-block-html">' +
      '<div class="sqs-block-content"><h2>Studio notes</h2><p>Glazes are mixed every Friday.</p></div></div></div></section>',
  },
  {
    id: 'raw.sample-semantic-section',
    input: '<section><h2>Our process</h2><p>We sketch, throw, glaze, and fire each piece in-house.</p></section>',
  },
];

const CANONICALIZE_CASES = [
  {
    id: 'canonicalize.smoke-simple-paragraph',
    input: '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->',
  },
  {
    id: 'canonicalize.smoke-nested-paragraph',
    input: '<!-- wp:paragraph --><p class="outer"><p class="inner">Nested</p></p><!-- /wp:paragraph -->',
  },
  {
    id: 'canonicalize.smoke-heading-paragraph-composition',
    input:
      '<!-- wp:heading {"level":1} --><h1>Title</h1><!-- /wp:heading -->\n' +
      '<!-- wp:paragraph --><p>Body text.</p><!-- /wp:paragraph -->',
  },
  {
    id: 'canonicalize.unknown-jetpack-contact-form-minimal',
    input: '<!-- wp:jetpack/contact-form -->x<!-- /wp:jetpack/contact-form -->',
  },
  {
    id: 'canonicalize.unknown-jetpack-contact-form-full',
    input:
      '<!-- wp:jetpack/contact-form {"style":{"spacing":{"padding":{"top":"16px","right":"16px","bottom":"16px","left":"16px"}}}} -->\n' +
      '<div class="wp-block-jetpack-contact-form">\n' +
      '<!-- wp:jetpack/field-name {"label":"Full name","required":true,"width":50} /-->\n' +
      '<!-- wp:jetpack/field-email {"label":"Email address","required":true,"width":50} /-->\n' +
      '<!-- wp:button {"tagName":"button","type":"submit","lock":{"remove":true},"className":"form-button-submit is-submit","metadata":{"name":"Submit button"}} -->\n' +
      '<div class="wp-block-button form-button-submit is-submit"><button type="submit" class="wp-block-button__link wp-element-button">Send Message</button></div>\n' +
      '<!-- /wp:button -->\n' +
      '</div>\n' +
      '<!-- /wp:jetpack/contact-form -->',
  },
];

const COMPOSE_CASES = [
  {
    id: 'compose.generic-details',
    input: '<details><summary>Q</summary><p>A</p></details>',
  },
  {
    id: 'compose.generic-callout',
    input: '<div class="callout"><h3>Note</h3><p>body</p></div>',
  },
  {
    id: 'compose.generic-pullquote',
    input: '<blockquote class="pullquote"><p>Big idea</p><cite>Me</cite></blockquote>',
  },
  {
    id: 'compose.generic-buttons',
    input: '<div class="btn-group"><a class="button" href="/x">Go</a><a class="btn" href="/y">More</a></div>',
  },
  {
    id: 'compose.generic-media-text',
    input:
      '<div class="media-text"><figure><img src="https://cdn.test/a.jpg" alt="A"/></figure>' +
      '<div><h3>Title</h3><p>copy</p></div></div>',
  },
  {
    id: 'compose.sample-youtube-iframe',
    input: '<figure class="video"><iframe src="https://www.youtube.com/embed/abc123"></iframe></figure>',
  },
  {
    id: 'compose.sample-callout-div',
    input: '<div class="notice callout"><h3>Shipping pause</h3><p>Orders resume Monday.</p></div>',
  },
  {
    id: 'compose.sample-semantic-section-fallback',
    input: '<section><h2>Our process</h2><p>We sketch, throw, glaze, and fire each piece in-house.</p></section>',
  },
  {
    id: 'compose.sample-wix-div-soup-fallback',
    input:
      '<div id="SITE_PAGES"><div data-mesh-id="comp-heroinlineContent" class="wixui-section">' +
      '<div data-testid="richTextElement"><h2>Handmade planters</h2><p>Small-batch ceramics for bright windows.</p></div>' +
      '<div class="wixui-button"><a href="/shop">Shop the drop</a></div></div></div>',
  },
  {
    id: 'compose.sample-squarespace-sqs-block-fallback',
    input:
      '<section class="Index-page"><div class="sqs-layout"><div class="sqs-block html-block sqs-block-html">' +
      '<div class="sqs-block-content"><h2>Studio notes</h2><p>Glazes are mixed every Friday.</p></div></div></div></section>',
  },
];

function setupWordPressGlobals() {
  const requireFromHere = createRequire(import.meta.url);
  const { JSDOM } = requireFromHere(path.join(BLOCK_FIXER_DIR, 'node_modules/jsdom'));
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    url: 'http://localhost',
    pretendToBeVisual: true,
  });
  for (const key of [
    'window',
    'document',
    'DOMParser',
    'XMLSerializer',
    'Node',
    'Element',
    'HTMLElement',
    'getComputedStyle',
    'MutationObserver',
  ]) {
    globalThis[key] = dom.window[key];
  }
  globalThis.requestAnimationFrame = (callback) => setTimeout(callback, 16);
  globalThis.cancelAnimationFrame = (id) => clearTimeout(id);
  globalThis.matchMedia = () => ({
    matches: false,
    addListener() {},
    removeListener() {},
    addEventListener() {},
    removeEventListener() {},
  });
  globalThis.ResizeObserver = class ResizeObserver {
    observe() {}
    unobserve() {}
    disconnect() {}
  };
  Object.defineProperty(globalThis, 'navigator', {
    value: dom.window.navigator,
    writable: true,
    configurable: true,
  });
}

function withQuietConsole(fn) {
  const originalError = console.error;
  const originalWarn = console.warn;
  const originalLog = console.log;
  const originalStderrWrite = process.stderr.write;
  const originalStdoutWrite = process.stdout.write;
  console.error = () => {};
  console.warn = () => {};
  console.log = () => {};
  process.stderr.write = () => true;
  process.stdout.write = () => true;
  try {
    return fn();
  } finally {
    console.error = originalError;
    console.warn = originalWarn;
    console.log = originalLog;
    process.stderr.write = originalStderrWrite;
    process.stdout.write = originalStdoutWrite;
  }
}

async function loadDlaFunctions() {
  setupWordPressGlobals();
  const requireFromHere = createRequire(import.meta.url);
  const { convertHtmlToBlocks, fixBlocksInTemplate } = withQuietConsole(() => ({
    ...requireFromHere(path.join(BLOCK_FIXER_DIR, 'lib/rawConvert.js')),
    ...requireFromHere(path.join(BLOCK_FIXER_DIR, 'lib/blockFixer.js')),
  }));
  try {
    const { applyBlockRecipe } = await import(
      pathToFileURL(path.join(DLA_ROOT, 'src/lib/replicate/apply-block-recipe.ts')).href
    );
    const { buildHtmlFallbackBlock } = await import(
      pathToFileURL(path.join(DLA_ROOT, 'src/lib/replicate/html-fallback.ts')).href
    );
    return { convertHtmlToBlocks, fixBlocksInTemplate, applyBlockRecipe, buildHtmlFallbackBlock };
  } catch (error) {
    throw new Error(
      `Unable to import DLA TypeScript modules. Run with DLA tsx, for example: cd ${DLA_ROOT} && ./node_modules/.bin/tsx ${fileURLToPath(import.meta.url)} --check\n${error.message}`,
    );
  }
}

function normalizeRawResult(result) {
  assert.equal(typeof result, 'object');
  assert.equal(typeof result.html, 'string');
  assert.equal(typeof result.wpHtmlResidue, 'number');
  assert.equal(Number.isFinite(result.wpHtmlResidue), true);
  return {
    html: result.html,
    wpHtmlResidue: result.wpHtmlResidue,
  };
}

function normalizeFixResult(result) {
  assert.equal(typeof result, 'object');
  assert.equal(typeof result.html, 'string');
  assert.equal(typeof result.changed, 'boolean');
  assert.equal(Array.isArray(result.fixedIssues), true);
  assert.equal(result.fixedIssues.every((issue) => typeof issue === 'string'), true);
  return {
    html: result.html,
    changed: result.changed,
    fixedIssues: result.fixedIssues,
  };
}

function composeWithFallback(input, applyBlockRecipe, buildHtmlFallbackBlock) {
  const ctx = { url: 'https://fixtures.example/source' };
  return applyBlockRecipe(input, undefined, ctx) ?? buildHtmlFallbackBlock(input, {});
}

async function captureCases() {
  const { convertHtmlToBlocks, fixBlocksInTemplate, applyBlockRecipe, buildHtmlFallbackBlock } = await loadDlaFunctions();
  const cases = [];

  for (const { id, input } of RAW_CASES) {
    cases.push({
      id,
      op: 'rawConvert',
      input,
      expected: withQuietConsole(() => normalizeRawResult(convertHtmlToBlocks(input))),
    });
  }

  for (const { id, input } of CANONICALIZE_CASES) {
    cases.push({
      id,
      op: 'canonicalize',
      input,
      expected: withQuietConsole(() => normalizeFixResult(fixBlocksInTemplate(input))),
    });
  }

  for (const { id, input } of COMPOSE_CASES) {
    cases.push({
      id,
      op: 'compose',
      input,
      expected: withQuietConsole(() => composeWithFallback(input, applyBlockRecipe, buildHtmlFallbackBlock)),
    });
  }

  return cases;
}

function serializeCases(cases) {
  return `${JSON.stringify(cases, null, 2)}\n`;
}

async function main() {
  const mode = process.argv[2] ?? '--write';
  const cases = await captureCases();
  const serialized = serializeCases(cases);

  if (mode === '--write') {
    await writeFile(CASES_PATH, serialized);
    console.log(`WROTE ${path.relative(process.cwd(), CASES_PATH)} (${cases.length} cases)`);
    return;
  }

  if (mode === '--check') {
    const existing = await readFile(CASES_PATH, 'utf8');
    assert.equal(serialized, existing);
    console.log(`PASS capture deterministic (${cases.length} cases)`);
    return;
  }

  if (mode === '--stdout') {
    process.stdout.write(serialized);
    return;
  }

  throw new Error(`Unknown mode ${mode}. Use --write, --check, or --stdout.`);
}

await main();
