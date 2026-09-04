import { beforeAll, describe, expect, it } from 'vitest';
import { createRequire } from 'node:module';
import { setupDomGlobals } from '../dom-globals.js';

const require = createRequire(import.meta.url);

type ParsedBlock = { attributes: { className?: string; style?: Record<string, unknown> } };

type WpRuntime = {
  registerCoreBlocks(): void;
  createBlock(name: string, attributes: Record<string, unknown>): unknown;
  serialize(blocks: unknown[]): string;
  parse(markup: string): ParsedBlock[];
  validateBlock(block: unknown): [boolean, unknown[]];
};

describe('fixture87 real WordPress validation', () => {
  let wp: WpRuntime;

  beforeAll(() => {
    setupDomGlobals();
    wp = require('@wordpress/blocks') as WpRuntime;
    const library = require('@wordpress/block-library') as Pick<WpRuntime, 'registerCoreBlocks'>;
    library.registerCoreBlocks();
  });

  it('round-trips all three tour cards and five gallery figures through core save and reload', () => {
    const cards = ['#315b74', '#475d42', '#754d45'].map((tone, index) => ({
      className: `tour-card fixture87-card-${index}`,
      style: {
        border: { color: 'var(--line)', style: 'solid', width: '1px', radius: 'var(--radius)' },
        dimensions: { minHeight: '430px' },
        spacing: { padding: { top: '1.2rem', right: '1.2rem', bottom: '1.2rem', left: '1.2rem' } },
      },
      carrierCss: `.fixture87-card-${index}{--tone:${tone} !important}`,
    }));
    const figures = [
      ['#27485f', '#87d8ff', '280px'], ['#6f493e', '#ff8762', '390px'], ['#284932', '#4fbf8f', '240px'], ['#182f48', '#f6ead3', '330px'], ['#243c65', '#ffd36f', '260px'],
    ].map(([a, b, h], index) => ({
      className: `photo fixture87-figure-${index}`,
      style: {
        border: { color: 'var(--line)', style: 'solid', width: '1px', radius: '22px' },
        dimensions: { minHeight: 'var(--h)' },
        spacing: { margin: { top: '0', right: '0', bottom: '1rem', left: '0' } },
      },
      carrierCss: `.fixture87-figure-${index}{--a:${a} !important;--b:${b} !important;--h:${h} !important}`,
    }));
    const fixture = [...cards, ...figures];
    const persisted = wp.serialize(fixture.map(({ carrierCss, ...attributes }) => wp.createBlock('core/group', attributes)));
    const reloaded = wp.parse(persisted);

    expect(reloaded).toHaveLength(8);
    expect(reloaded.map((block) => wp.validateBlock(block)[0])).toEqual(Array(8).fill(true));
    expect(persisted).not.toContain('--tone:');
    expect(persisted).not.toContain('--a:');
    expect(persisted).not.toContain('--h:');

    expect(reloaded.map((block) => block.attributes.className)).toEqual(fixture.map(({ className }) => className));
    expect(reloaded.map((block) => block.attributes.style)).toEqual(fixture.map(({ style }) => style));
  });
});
