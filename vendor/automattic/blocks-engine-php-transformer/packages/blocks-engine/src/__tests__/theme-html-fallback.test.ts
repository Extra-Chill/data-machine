import * as cheerio from 'cheerio';
import { describe, expect, it } from 'vitest';

import { buildCoverageIsland } from '../theme/html-fallback.js';
import {
  captureSectionContent,
  measureSectionCoverage,
} from '../theme/section-coverage.js';
import type { SectionSpec, SectionSpecImage } from '../theme/section-spec.js';

const COVERAGE_ISLAND_OPENER =
  '<!-- wp:html {"metadata":{"name":"lib-coverage-island"}} -->';
const COVERAGE_ISLAND_CLOSER = '<!-- /wp:html -->';

function image(overrides: Partial<SectionSpecImage> = {}): SectionSpecImage {
  return {
    url: 'assets/hero.jpg',
    sourceUrl: 'source/hero.jpg',
    alt: 'Hero',
    kind: 'img',
    width: 1200,
    height: 800,
    ...overrides,
  };
}

function sectionSpec(overrides: Partial<SectionSpec> = {}): SectionSpec {
  return {
    sectionIndex: 0,
    interactionModel: 'static',
    top: 0,
    height: 640,
    headings: [],
    bodyText: [],
    buttonLabels: [],
    images: [],
    icons: [],
    backgroundBrightness: 1,
    backgroundColor: '#ffffff',
    gradient: null,
    gradientSource: null,
    motionProfile: {
      motionClass: 'none',
      signals: [],
      animatedElements: 0,
    },
    dividerAbove: null,
    dividerBelow: null,
    layout: {
      containerWidth: 1200,
      padding: '0',
      childLayout: 'stack',
      columnCount: 1,
      gap: '0',
    },
    ...overrides,
  };
}

function islandBody(markup: string): string {
  expect(markup.split('\n')[0]).toBe(COVERAGE_ISLAND_OPENER);
  expect(markup.endsWith(`\n${COVERAGE_ISLAND_CLOSER}`)).toBe(true);

  return markup.slice(
    `${COVERAGE_ISLAND_OPENER}\n`.length,
    -`\n${COVERAGE_ISLAND_CLOSER}`.length
  );
}

describe('buildCoverageIsland', () => {
  it('preserves sectionHtml verbatim and round-trips section coverage', () => {
    const sourceHtml =
      '<section class="source"><h2>Handmade planters</h2><p>Small-batch ceramics for bright windows.</p><a href="/shop">Shop the drop</a><img src="assets/source-hero.jpg" alt="Hero"></section>';
    const spec = sectionSpec({
      sectionHtml: sourceHtml,
      headings: ['Handmade planters'],
      bodyText: ['Small-batch ceramics for bright windows.'],
      buttonLabels: ['Shop the drop'],
      buttons: [{ label: 'Shop the drop', href: '/shop' }],
      images: [image({ url: 'assets/source-hero.jpg', alt: 'Hero' })],
    });

    const markup = buildCoverageIsland(spec);

    expect(islandBody(markup)).toBe(sourceHtml);
    expect(
      measureSectionCoverage(captureSectionContent(spec), markup).lost
    ).toBe(false);
  });

  it('serializes SectionSpec content and round-trips section coverage', () => {
    const spec = sectionSpec({
      headings: ['Studio notes', 'Fresh glaze schedule'],
      bodyText: [
        'Glazes are mixed every Friday.',
        'Pickup windows open at noon.',
      ],
      buttonLabels: ['Reserve a kiln tour'],
      buttons: [{ label: 'Reserve a kiln tour', href: '/tour' }],
      images: [
        image({
          url: 'assets/tiles.jpg',
          sourceUrl: 'source/tiles.jpg',
          alt: 'Glazed tiles',
          width: 640,
          height: 480,
        }),
      ],
    });

    const markup = buildCoverageIsland(spec);
    const body = islandBody(markup);
    const $ = cheerio.load(body, null, false);

    expect($('h2').map((_, el) => $(el).text()).get()).toEqual([
      'Studio notes',
    ]);
    expect($('h3').map((_, el) => $(el).text()).get()).toEqual([
      'Fresh glaze schedule',
    ]);
    expect(
      $('p')
        .filter((_, el) => $(el).find('a').length === 0)
        .map((_, el) => $(el).text())
        .get()
    ).toEqual(['Glazes are mixed every Friday.', 'Pickup windows open at noon.']);

    const renderedImage = $('figure img');
    expect(renderedImage).toHaveLength(1);
    expect(renderedImage.attr('src')).toBe('assets/tiles.jpg');
    expect(renderedImage.attr('alt')).toBe('Glazed tiles');

    const renderedButton = $('a');
    expect(renderedButton).toHaveLength(1);
    expect(renderedButton.text()).toBe('Reserve a kiln tour');
    expect(renderedButton.attr('href')).toBe('/tour');

    expect($('h2,h3,p').map((_, el) => $(el).text()).get()).toEqual([
      'Studio notes',
      'Fresh glaze schedule',
      'Glazes are mixed every Friday.',
      'Pickup windows open at noon.',
      'Reserve a kiln tour',
    ]);
    expect(
      measureSectionCoverage(captureSectionContent(spec), markup).lost
    ).toBe(false);
  });

  it('keeps source text present without fabricating native image blocks or text', () => {
    const spec = sectionSpec({
      headings: ['Studio notes'],
      bodyText: ['Glazes are mixed every Friday.'],
      buttonLabels: ['Reserve a kiln tour'],
      images: [image({ url: 'assets/tiles.jpg', alt: 'Glazed tiles' })],
    });

    const markup = buildCoverageIsland(spec);

    expect(markup).toContain('<h2>Studio notes</h2>');
    expect(markup).toContain('<p>Glazes are mixed every Friday.</p>');
    expect(markup).toContain('<a href="#">Reserve a kiln tour</a>');
    expect(markup).toContain('<img src="assets/tiles.jpg" alt="Glazed tiles">');
    expect(markup).not.toContain('Invented');
    expect(markup).not.toContain('<!-- wp:image');
    expect(markup).not.toContain('wp-block-image');
  });
});
