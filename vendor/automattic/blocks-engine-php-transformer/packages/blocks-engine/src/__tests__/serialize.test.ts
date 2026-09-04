import { describe, expect, it } from 'vitest';

import { serializeBlockAttrs } from '../serialize';

function dlaSerializeBlockAttrs(attrs: Record<string, unknown>): string {
  return JSON.stringify(attrs)
    .replace(/--/g, '\\u002d\\u002d')
    .replace(/</g, '\\u003c')
    .replace(/>/g, '\\u003e')
    .replace(/&/g, '\\u0026')
    .replace(/\\"/g, '\\u0022');
}

describe('serializeBlockAttrs', () => {
  it('escapes comment-delimiter and markup-sensitive bytes', () => {
    expect(serializeBlockAttrs({ name: 'a--b' })).toBe('{"name":"a\\u002d\\u002db"}');
    expect(serializeBlockAttrs({ html: '<b>x</b> & y' })).toBe(
      '{"html":"\\u003cb\\u003ex\\u003c/b\\u003e \\u0026 y"}',
    );
  });

  it('keeps quoted values parseable after unicode escaping', () => {
    const serialized = serializeBlockAttrs({ label: 'A "quoted" value' });

    expect(serialized).toBe('{"label":"A \\u0022quoted\\u0022 value"}');
    expect(JSON.parse(serialized)).toEqual({ label: 'A "quoted" value' });
  });

  it('matches the DLA form-blocks serializer on a shared corpus', () => {
    const corpus: Array<Record<string, unknown>> = [
      {},
      { name: 'plain' },
      { name: 'a--b', label: 'A "quoted" value' },
      { html: '<span data-x="1">A & B</span>' },
      { nested: { text: 'x --> <script>alert("y")</script>' }, list: ['a&b', 3, true] },
    ];

    for (const attrs of corpus) {
      expect(serializeBlockAttrs(attrs)).toBe(dlaSerializeBlockAttrs(attrs));
    }
  });
});
