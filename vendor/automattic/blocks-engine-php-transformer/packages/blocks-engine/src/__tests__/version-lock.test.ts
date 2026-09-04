import { describe, it, expect } from 'vitest';
import wpBlocksPkg from '@wordpress/blocks/package.json' with { type: 'json' };
import jsdomPkg from 'jsdom/package.json' with { type: 'json' };

describe('dependency version lock (fidelity guard)', () => {
  it('resolves the exact pinned @wordpress/blocks + jsdom', () => {
    expect(wpBlocksPkg.version).toBe('15.22.0');
    expect(jsdomPkg.version).toBe('29.0.1');
  });
});
