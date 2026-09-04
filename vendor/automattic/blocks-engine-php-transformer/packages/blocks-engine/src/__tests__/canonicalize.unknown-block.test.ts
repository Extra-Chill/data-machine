import { describe, expect, it } from 'vitest';
import { canonicalize } from '../wp/canonicalize';

describe('canonicalize unknown blocks', () => {
  it('preserves unknown jetpack block delimiters', () => {
    const result = canonicalize(
      '<!-- wp:jetpack/contact-form -->x<!-- /wp:jetpack/contact-form -->',
    );

    expect(result.html).toContain('wp:jetpack/contact-form');
  });
});
