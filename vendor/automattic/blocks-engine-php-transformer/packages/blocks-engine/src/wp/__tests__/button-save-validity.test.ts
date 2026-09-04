import { beforeAll, describe, expect, it } from 'vitest';
import { createRequire } from 'node:module';
import { setupDomGlobals } from '../dom-globals.js';

const require = createRequire(import.meta.url);

type WpRuntime = {
  registerCoreBlocks(): void;
  createBlock(name: string, attributes: Record<string, unknown>): unknown;
  serialize(blocks: unknown[]): string;
  parse(markup: string): unknown[];
  validateBlock(block: unknown): [boolean, unknown[]];
};

describe('core/button save validity', () => {
  let wp: WpRuntime;

  beforeAll(() => {
    setupDomGlobals();
    wp = require('@wordpress/blocks') as WpRuntime;
    const library = require('@wordpress/block-library') as Pick<WpRuntime, 'registerCoreBlocks'>;
    library.registerCoreBlocks();
  });

  it('keeps the generated control carrier on the supported block wrapper', () => {
    const button = wp.createBlock('core/button', {
      className: 'blocks-engine-control-fixture',
      text: '<span class="product-row__name">Product</span><span>$25</span>',
      url: '/product',
      style: { color: { background: '#123456' } },
    });
    const persisted = wp.serialize([button]);
    const reloaded = wp.parse(persisted);

    expect(persisted).toContain('<div class="wp-block-button blocks-engine-control-fixture">');
    expect(persisted).toContain('<a class="wp-block-button__link has-background wp-element-button"');
    expect(persisted).not.toContain('wp-block-button product-row');
    expect(persisted).not.toContain('wp-block-button__link has-background product-row');
    expect(reloaded).toHaveLength(1);
    expect(wp.validateBlock(reloaded[0])[0]).toBe(true);
  });
});
