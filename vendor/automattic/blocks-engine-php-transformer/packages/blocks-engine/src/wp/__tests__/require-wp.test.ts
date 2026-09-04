import { describe, it, expect, beforeAll } from 'vitest';
import { setupDomGlobals } from '../dom-globals.js';
import { requireWp } from '../require-wp.js';

// @wordpress/block-library (and its transitive dep @wordpress/block-editor)
// access `window` at module-load time, so DOM globals must be installed before
// requireWp('@wordpress/block-library') is called for the first time.
beforeAll(() => {
  setupDomGlobals();
});

describe('requireWp (dev mode resolves real packages)', () => {
  it('returns blocks symbols', () => {
    const blocks = requireWp('@wordpress/blocks');
    expect(typeof blocks.rawHandler).toBe('function');
    expect(typeof blocks.serialize).toBe('function');
    expect(typeof blocks.createBlock).toBe('function');
    expect(typeof blocks.getBlockAttributes).toBe('function');
    expect(typeof blocks.parse).toBe('function');
  });
  it('returns registerCoreBlocks for block-library', () => {
    expect(typeof requireWp('@wordpress/block-library').registerCoreBlocks).toBe('function');
  });
  it('returns parse for the grammar parser', () => {
    expect(typeof requireWp('@wordpress/block-serialization-default-parser').parse).toBe('function');
  });
});
