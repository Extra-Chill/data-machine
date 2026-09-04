import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, expect, it } from 'vitest';

import { sectionExtract } from '../theme/section-extract.js';
import type { SitePage } from '../theme/types.js';

const testDir = dirname(fileURLToPath(import.meta.url));
const fixturesDir = join(testDir, 'fixtures/site');

function pageWithHtml(html: string): SitePage {
  return {
    relPath: 'index.html',
    slug: 'index',
    title: 'Test',
    html,
  };
}

describe('sectionExtract', () => {
  it('extracts contentful sections from the fixture page', () => {
    const sections = sectionExtract(
      pageWithHtml(readFileSync(join(fixturesDir, 'index.html'), 'utf8'))
    );

    expect(sections.length).toBeGreaterThanOrEqual(1);
    expect(sections.every((section) => section.selector)).toBe(true);
    expect(sections.map((section) => section.selector)).not.toContain('#empty');
    expect(sections).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          selector: '#hero',
          headings: ['Build calmer block themes'],
          bodyText: ['Static source pages become a structured theme pipeline.'],
          buttonLabels: ['Learn more'],
          sectionHtml: expect.stringContaining('Build calmer block themes'),
        }),
        expect.objectContaining({
          selector: 'section[aria-labelledby="features-title"]',
          headings: ['What carries forward'],
          bodyText: ['Headings, body copy, selectors, and simple calls to action.'],
        }),
      ])
    );
  });

  it('omits empty candidate sections', () => {
    const sections = sectionExtract(
      pageWithHtml(`
        <main>
          <section id="empty"></section>
          <section id="content"><h2>Useful section</h2><p>Recognizable copy.</p></section>
        </main>
      `)
    );

    expect(sections).toHaveLength(1);
    expect(sections[0]).toMatchObject({
      selector: '#content',
      headings: ['Useful section'],
      bodyText: ['Recognizable copy.'],
    });
  });

  it('segments semantic landmarks and heading bands with stable selectors', () => {
    const sections = sectionExtract(
      pageWithHtml(`
        <body>
          <header><nav aria-label="Primary"><a href="/">Home</a><a href="/about">About</a></nav></header>
          <main>
            <h1>Opening band</h1>
            <p>Introductory body.</p>
            <h2>Second band</h2>
            <p>Follow-up body.</p>
          </main>
          <aside role="complementary"><h2>Related</h2><a href="/guide">Guide</a></aside>
          <footer><p>Footer copy</p></footer>
        </body>
      `)
    );

    expect(sections.map((section) => section.selector)).toEqual([
      'nav[aria-label="Primary"]',
      'main > h1:nth-of-type(1)',
      'main > h2:nth-of-type(1)',
      'aside[role="complementary"]',
      'footer',
    ]);
    expect(sections.map((section) => section.interactionModel)).toEqual([
      'nav',
      'static',
      'static',
      'static',
      'footer',
    ]);
    expect(sections[1]).toMatchObject({
      headings: ['Opening band'],
      bodyText: ['Introductory body.'],
    });
    expect(sections[2]).toMatchObject({
      headings: ['Second band'],
      bodyText: ['Follow-up body.'],
    });
  });

  it('keeps explicit sections that follow heading bands in the same container', () => {
    const sections = sectionExtract(
      pageWithHtml(`
        <main>
          <h1>Opening band</h1>
          <p>Introductory body.</p>
          <section id="later"><h2>Later section</h2><p>Section body.</p></section>
        </main>
      `)
    );

    expect(sections.map((section) => section.selector)).toEqual([
      'main > h1:nth-of-type(1)',
      '#later',
    ]);
    expect(sections[1]).toMatchObject({
      headings: ['Later section'],
      bodyText: ['Section body.'],
    });
  });

  it('keeps recognizable text from common div and span containers', () => {
    const sections = sectionExtract(
      pageWithHtml(`
        <main>
          <section id="div-copy"><div>Plain div copy.</div></section>
          <section id="span-copy"><span>Plain span copy.</span></section>
        </main>
      `)
    );

    expect(sections).toEqual([
      expect.objectContaining({
        selector: '#div-copy',
        bodyText: ['Plain div copy.'],
      }),
      expect.objectContaining({
        selector: '#span-copy',
        bodyText: ['Plain span copy.'],
      }),
    ]);
  });

  it('captures designed top-level bands that have no heading or landmark', () => {
    const sections = sectionExtract(
      pageWithHtml(`
        <body>
          <main>
            <section id="hero"><h1>Welcome</h1><p>Intro copy.</p></section>
          </main>
          <div class="ticker-band" aria-hidden="true">
            <div class="ticker-track">
              <span class="ticker-item">Driftwood Roasters</span>
              <span class="ticker-item">Est. 2018</span>
              <span class="ticker-item">Pacific Coast Blend</span>
            </div>
          </div>
        </body>
      `)
    );

    const ticker = sections.find((section) =>
      section.sectionHtml?.includes('ticker-band')
    );
    expect(ticker).toBeDefined();
    expect(ticker?.bodyText).toEqual(
      expect.arrayContaining(['Driftwood Roasters', 'Est. 2018', 'Pacific Coast Blend'])
    );
    expect(ticker?.selector).toBeTruthy();
  });

  it('does not import browser runtimes in the P0-2 stage implementations', () => {
    const source = [
      readFileSync(join(testDir, '../theme/ingest.ts'), 'utf8'),
      readFileSync(join(testDir, '../theme/section-extract.ts'), 'utf8'),
    ].join('\n');

    expect(source).not.toMatch(/from ['"](?:playwright|puppeteer|jsdom)/);
    expect(source).not.toMatch(/require\(['"](?:playwright|puppeteer|jsdom)['"]\)/);
  });
});
