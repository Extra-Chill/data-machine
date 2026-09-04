import { describe, expect, it } from 'vitest';

import { heuristicBlocks } from '../heuristics';

describe('heuristicBlocks', () => {
  it('handles pure paragraphs and headings', () => {
    const result = heuristicBlocks('<h2>Section</h2><p>Some prose.</p><h3>Subsection</h3><p>More prose.</p>');
    expect(result.handled).toBe(true);
    expect(result.blocks).toContain('<!-- wp:heading -->');
    expect(result.blocks).toContain('<!-- wp:heading {"level":3} -->');
    expect(result.blocks).toContain('Section');
    expect(result.blocks).toContain('Subsection');
  });

  it('handles a leading image followed by paragraphs', () => {
    const result = heuristicBlocks('<img src="https://example.com/hero.jpg" alt="Hero"><p>Caption-like text.</p><p>More body.</p>');
    expect(result.handled).toBe(true);
    expect(result.blocks).toContain('<!-- wp:image -->');
    expect(result.blocks).toContain('src="https://example.com/hero.jpg"');
    expect(result.blocks).toContain('alt="Hero"');
    expect(result.blocks).toContain('<!-- wp:paragraph -->');
  });

  it('handles a single section with heading and paragraphs as a group', () => {
    const result = heuristicBlocks('<section><h2>About</h2><p>We make things.</p></section>');
    expect(result.handled).toBe(true);
    expect(result.blocks).toContain('<!-- wp:group -->');
    expect(result.blocks).toContain('<!-- wp:heading -->');
    expect(result.blocks).toContain('About');
  });

  it('refuses unsupported shapes', () => {
    expect(heuristicBlocks('<section><h2>One</h2></section><section><h2>Two</h2></section>').handled).toBe(false);
    expect(heuristicBlocks('<ul><li>a</li><li>b</li></ul>').handled).toBe(false);
    expect(heuristicBlocks('<table><tr><td>x</td></tr></table>').handled).toBe(false);
    expect(heuristicBlocks('<div class="hero">stuff</div>').handled).toBe(false);
    expect(heuristicBlocks('').handled).toBe(false);
    expect(heuristicBlocks('   \n  ').handled).toBe(false);
    expect(heuristicBlocks('<h1>Title</h1><p>Body.</p>').handled).toBe(false);
    expect(heuristicBlocks('stray text<p>then a paragraph</p>').handled).toBe(false);
  });

  it('preserves inline markup inside paragraphs', () => {
    const result = heuristicBlocks('<p>Click <a href="/x"><strong>here</strong></a> now.</p>');
    expect(result.handled).toBe(true);
    expect(result.blocks).toContain('<a href="/x">');
    expect(result.blocks).toContain('<strong>here</strong>');
  });
});
