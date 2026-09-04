import { describe, it, expect, beforeAll } from 'vitest';
import { existsSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { setupDomGlobals } from '../dom-globals.js';

const require = createRequire(import.meta.url);
const bundlePath = fileURLToPath(new URL('../../../dist/wp-runtime.cjs', import.meta.url));

describe('wp-runtime bundle', () => {
  let m: Record<string, (...args: unknown[]) => unknown>;
  beforeAll(() => {
    if (!existsSync(bundlePath)) throw new Error('run `pnpm build` first');
    // The bundled WP runtime touches window/document at module-init, so DOM
    // globals must be installed before requiring it.
    setupDomGlobals();
    m = require(bundlePath) as Record<string, (...args: unknown[]) => unknown>;
  });

  it('exports the WordPress symbols the engine calls', () => {
    for (const name of ['registerCoreBlocks', 'rawHandler', 'serialize', 'parse', 'createBlock', 'getBlockAttributes', 'parseGrammar']) {
      expect(typeof m[name], name).toBe('function');
    }
  });

  it('converts HTML to core blocks with no wp:html residue', () => {
    (m.registerCoreBlocks as () => void)();
    const html = '<h2>Hello</h2><p>x <strong>b</strong></p><ul><li>one</li></ul><blockquote><p>q</p></blockquote><table><tbody><tr><td>a</td></tr></tbody></table>';
    const out = (m.serialize as (b: unknown) => string)((m.rawHandler as (o: { HTML: string }) => unknown)({ HTML: html }));
    expect(out.match(/<!-- wp:html/g) ?? []).toHaveLength(0);
    expect((out.match(/<!-- wp:/g) ?? []).length).toBeGreaterThanOrEqual(6);
  });
});
