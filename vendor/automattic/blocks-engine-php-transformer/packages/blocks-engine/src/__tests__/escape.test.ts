import { describe, expect, it } from 'vitest';

import { escapeHtml, escapeHtmlAttr, escapeHtmlText } from '../escape';

describe('html escaping helpers', () => {
  it('escapes text-node entities', () => {
    expect(escapeHtmlText('A & <B>')).toBe('A &amp; &lt;B&gt;');
  });

  it('escapes double quotes for attributes', () => {
    expect(escapeHtmlAttr('A "quote" & <B>')).toBe('A &quot;quote&quot; &amp; &lt;B&gt;');
  });

  it('escapes apostrophes in the full helper', () => {
    expect(escapeHtml(`A 'quote'`)).toBe('A &#039;quote&#039;');
  });
});
