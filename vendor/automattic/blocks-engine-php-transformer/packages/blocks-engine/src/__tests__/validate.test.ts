import { describe, expect, it } from 'vitest';

import { blockMarkupRoundtrips } from '../validate';

describe('blockMarkupRoundtrips', () => {
  it('accepts balanced block markup', () => {
    expect(blockMarkupRoundtrips('<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->')).toEqual({ ok: true });
  });

  it('accepts self-closing block markup', () => {
    expect(blockMarkupRoundtrips('<!-- wp:jetpack/field-name /-->')).toEqual({ ok: true });
  });

  it('rejects empty markup', () => {
    expect(blockMarkupRoundtrips('')).toEqual({ ok: false, reason: 'empty markup' });
  });

  it('rejects mismatched closes and unclosed opens', () => {
    expect(blockMarkupRoundtrips('<!-- wp:group --><!-- /wp:paragraph -->')).toEqual({
      ok: false,
      reason: 'mismatched block close: expected /wp:group, got /wp:paragraph',
    });
    expect(blockMarkupRoundtrips('<!-- wp:group -->')).toEqual({
      ok: false,
      reason: 'unclosed blocks: group',
    });
  });
});
