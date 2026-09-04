import { describe, expect, it } from 'vitest';

import { verifyComposedOutput } from '../output-verify';

describe('verifyComposedOutput', () => {
  it('passes when every text node appears in source plain text', () => {
    const source = '<article><h1>Welcome to Foo Industries</h1><p>We make widgets for the modern era.</p></article>';
    const blocks = `<!-- wp:heading -->
<h2 class="wp-block-heading">Welcome to Foo Industries</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>We make widgets for the modern era.</p>
<!-- /wp:paragraph -->`;
    const result = verifyComposedOutput(blocks, source);
    expect(result.valid).toBe(true);
    expect(result.hallucinated).toEqual([]);
  });

  it('fails when output substitutes a different brand name', () => {
    const source = '<article><h1>Foo Industries</h1><p>About us.</p></article>';
    const blocks = `<!-- wp:heading --><h2>Bar Inc</h2><!-- /wp:heading --><!-- wp:paragraph --><p>About us.</p><!-- /wp:paragraph -->`;
    const result = verifyComposedOutput(blocks, source);
    expect(result.valid).toBe(false);
    expect(result.hallucinated).toContain('Bar Inc');
  });

  it('treats wp block comments as metadata, not text', () => {
    const source = '<article><p>Hello</p></article>';
    const blocks = `<!-- wp:cover {"className":"is-style-accent-primary"} --><div><!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph --></div><!-- /wp:cover -->`;
    const result = verifyComposedOutput(blocks, source);
    expect(result.valid).toBe(true);
  });

  it('normalizes case, whitespace, and HTML entities before comparison', () => {
    expect(verifyComposedOutput(`<!-- wp:paragraph --><p>Welcome to Foo Industries</p><!-- /wp:paragraph -->`, '<p>welcome to foo industries</p>').valid).toBe(true);
    expect(verifyComposedOutput(`<!-- wp:paragraph --><p>We make widgets.</p><!-- /wp:paragraph -->`, '<p>We   make\n\nwidgets.</p>').valid).toBe(true);
    expect(verifyComposedOutput(`<!-- wp:paragraph --><p>Tom &amp; Jerry</p><!-- /wp:paragraph -->`, '<p>Tom &amp; Jerry</p>').valid).toBe(true);
  });

  it('reports hallucinations and handles empty markup', () => {
    const source = '<article><p>The original text only.</p></article>';
    const blocks = `<!-- wp:heading --><h2>Hallucinated heading</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Made-up paragraph copy.</p><!-- /wp:paragraph -->`;
    const result = verifyComposedOutput(blocks, source);
    expect(result.valid).toBe(false);
    expect(result.hallucinated.length).toBeGreaterThanOrEqual(2);
    expect(verifyComposedOutput('', '<p>anything</p>')).toEqual({ valid: true, hallucinated: [] });
  });
});
