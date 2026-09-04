import { describe, it, expect } from 'vitest';
import pkg from '../../package.json' with { type: 'json' };

describe('package dependency shape', () => {
  it('declares no @wordpress/* in runtime dependencies', () => {
    const runtime = Object.keys(pkg.dependencies ?? {});
    expect(runtime.filter((d) => d.startsWith('@wordpress/'))).toEqual([]);
  });
  it('keeps jsdom, cheerio, domhandler as runtime dependencies', () => {
    for (const d of ['jsdom', 'cheerio', 'domhandler']) {
      expect(pkg.dependencies, d).toHaveProperty(d);
    }
  });
});
