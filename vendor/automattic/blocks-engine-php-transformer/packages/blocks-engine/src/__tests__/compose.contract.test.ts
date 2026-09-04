import { describe, expect, it } from 'vitest';

import { compose } from '../compose';
import type { ConversionContext, Converter, HtmlFallback, RecipeRule } from '../types';

describe('compose public contract', () => {
  it('freezes the shared Phase D type surface and compose signature', () => {
    const ctx: ConversionContext = {
      url: 'https://example.test/',
      mediaMap: {
        'https://cdn.example.test/a.jpg': '/wp-content/uploads/a.jpg',
      },
    };
    const converter: Converter = (html, conversionCtx) => {
      expect(conversionCtx).toBe(ctx);
      expect(html).toBe('<p>source</p>');
      return null;
    };
    const recipe: RecipeRule = {
      match: '.card',
      block: 'core/group',
      attrs: { layout: { type: 'constrained' } },
      inner: 'innerHtml',
    };
    const fallback: HtmlFallback = (html) => html.toUpperCase();

    expect(typeof compose).toBe('function');
    expect(converter('<p>source</p>', ctx)).toBeNull();
    expect(recipe.inner).toBe('innerHtml');
    expect(typeof fallback).toBe('function');
  });
});
