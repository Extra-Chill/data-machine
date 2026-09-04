import { describe, expect, it } from 'vitest';

import { sanitize } from '../sanitize';

describe('sanitize', () => {
  it('strips active content and inline event handlers while preserving real content', () => {
    const dirty =
      '<section onload="track()">' +
      '<script>evil()</script>' +
      '<style>.x{color:red}</style>' +
      '<img src="/u/a.jpg" onerror="steal()"/>' +
      '<?php echo "x"; ?>' +
      '<!-- wp:html -->noise<!-- /wp:html -->' +
      '<p>Real copy</p>' +
      '</section>';
    const out = sanitize(dirty);

    expect(out).not.toMatch(/<script/i);
    expect(out).not.toMatch(/<style/i);
    expect(out).not.toMatch(/on\w+\s*=/i);
    expect(out).not.toContain('<?php');
    expect(out).not.toContain('<!--');
    expect(out).toContain('<p>Real copy</p>');
    expect(out).toContain('<img src="/u/a.jpg"');
  });

  it('removes residual script/style and php open tags', () => {
    expect(sanitize('<script>oops<p>copy</p><style>x')).toBe('oops<p>copy</p>x');
    expect(sanitize('<?php echo "x"; ?><p>copy</p><?')).toBe('<p>copy</p>');
  });
});
