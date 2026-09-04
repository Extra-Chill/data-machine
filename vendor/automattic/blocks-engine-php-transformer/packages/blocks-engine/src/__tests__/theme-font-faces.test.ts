import { describe, expect, it } from 'vitest';
import { parseFontFaces, stripUnusedFontFaces } from '../theme/font-faces.js';
import {
  absolutizeFontUrl,
  buildFontFaceCss,
  consolidateFontFaces,
  fontFilename,
  matchCapturedFamily,
  parseFontFaces as parseCapturedFontFaces,
  type LocalFontFace,
  type ParsedFontFace as CapturedParsedFontFace,
  type ThemeFontFamily,
} from '../theme/font-capture.js';
import { extractGoogleFontCssUrls } from '../theme/google-fonts.js';
import {
  stripCssSourceMaps,
  stripUnusedFontFaces as stripUnusedCarryFontFaces,
} from '../theme/carry-fonts.js';

describe('parseFontFaces', () => {
  it('parses family, preferred source, format, normalized weight, and style', () => {
    const faces = parseFontFaces(`
      @font-face {
        font-family: "Acme Display";
        src: url("/fonts/acme.woff") format("woff"),
          url("/fonts/acme.woff2?cache=1") format("woff2");
        font-weight: bold;
        font-style: oblique 12deg;
      }
    `);

    expect(faces).toEqual([
      {
        family: 'Acme Display',
        src: '/fonts/acme.woff2?cache=1',
        format: 'woff2',
        weight: '700',
        style: 'oblique',
      },
    ]);
  });

  it('filters generic CSS font families', () => {
    const faces = parseFontFaces(`
      @font-face {
        font-family: serif;
        src: url("/fonts/serif.woff2") format("woff2");
      }
      @font-face {
        font-family: system-ui;
        src: url("/fonts/system.woff2") format("woff2");
      }
    `);

    expect(faces).toEqual([]);
  });

  it('keeps remote gstatic and typekit faces for later self-hosting', () => {
    const faces = parseFontFaces(`
      @font-face {
        font-family: "Roboto";
        src: url("https://fonts.gstatic.com/s/roboto/v30/roboto.woff2") format("woff2");
        font-weight: 400;
      }
      @font-face {
        font-family: "Adobe Face";
        src: url("https://use.typekit.net/abc123.woff") format("woff");
        font-style: italic;
      }
    `);

    expect(faces.map((face) => face.src)).toEqual([
      'https://fonts.gstatic.com/s/roboto/v30/roboto.woff2',
      'https://use.typekit.net/abc123.woff',
    ]);
  });

  it('deduplicates identical parsed faces', () => {
    const block = `
      @font-face {
        font-family: "Acme";
        src: url("/fonts/acme.woff2") format("woff2");
        font-weight: normal;
      }
    `;

    expect(parseFontFaces(block, block)).toHaveLength(1);
  });
});

describe('stripUnusedFontFaces', () => {
  it('removes unused font-face blocks while keeping used blocks', () => {
    const usedFace = `@font-face{font-family:'Libre Baskerville';src:url(/fonts/libre.woff2) format("woff2")}`;
    const unusedFace = `@font-face{font-family:'Unused Sans';src:url(/fonts/unused.woff2) format("woff2")}`;
    const usage = `.title{font-family:"Libre Baskerville",serif}`;

    const result = stripUnusedFontFaces(`${usedFace}\n${unusedFace}\n${usage}`, usage);

    expect(result.removed).toBe(1);
    expect(result.css).toContain("font-family:'Libre Baskerville'");
    expect(result.css).not.toContain('Unused Sans');
    expect(result.css).toContain(usage);
  });
});

describe('font helper DLA parity surface', () => {
  it('extractGoogleFontCssUrls finds unique css/css2 URLs and decodes HTML ampersands', () => {
    const urls = extractGoogleFontCssUrls([
      '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400&amp;display=swap" rel="stylesheet">',
      "@import url('https://fonts.googleapis.com/css?family=Roboto:wght@700&display=swap');",
      'https://fonts.googleapis.com/css2?family=Inter:wght@400&amp;display=swap',
    ]);

    expect(urls).toEqual([
      'https://fonts.googleapis.com/css2?family=Inter:wght@400&display=swap',
      'https://fonts.googleapis.com/css?family=Roboto:wght@700&display=swap',
    ]);
  });

  it('parseCapturedFontFaces preserves the DLA third-party host filter', () => {
    const faces = parseCapturedFontFaces(`
      @font-face {
        font-family: "Roboto";
        src: url("https://fonts.gstatic.com/s/roboto/v30/roboto.woff2") format("woff2");
        font-weight: 400;
      }
      @font-face {
        font-family: "Shop Sans";
        src: url("https://cdn.shop.example/fonts/shop-sans.woff2") format("woff2");
        font-weight: bold;
        font-style: italic;
      }
    `);

    expect(faces).toEqual([
      {
        family: 'Shop Sans',
        src: 'https://cdn.shop.example/fonts/shop-sans.woff2',
        format: 'woff2',
        weight: '700',
        style: 'italic',
      },
    ]);
  });

  it('consolidateFontFaces groups variant family names and prefers cleaner woff2 sources', () => {
    const faces: CapturedParsedFontFace[] = [
      {
        family: 'Larsseit Bold',
        src: 'https://cdn.example/fonts/larsseit-bold_abcdef123.woff2',
        format: 'woff2',
        weight: '400',
        style: 'normal',
      },
      {
        family: 'Larsseit-Bold',
        src: 'https://cdn.example/fonts/larsseit-bold.woff2',
        format: 'woff2',
        weight: '400',
        style: 'normal',
      },
      {
        family: 'Larsseit Italic',
        src: 'https://cdn.example/fonts/larsseit-italic.woff',
        format: 'woff',
        weight: '400',
        style: 'italic',
      },
    ];

    expect(consolidateFontFaces(faces)).toEqual([
      {
        family: 'Larsseit',
        src: 'https://cdn.example/fonts/larsseit-bold.woff2',
        format: 'woff2',
        weight: '700',
        style: 'normal',
      },
      {
        family: 'Larsseit',
        src: 'https://cdn.example/fonts/larsseit-italic.woff',
        format: 'woff',
        weight: '400',
        style: 'italic',
      },
    ]);
  });

  it('matchCapturedFamily matches suffix-stripped builder family names', () => {
    const faces: CapturedParsedFontFace[] = [
      {
        family: 'avenir-lt-w01_35',
        src: 'https://cdn.example/fonts/avenir.woff2',
        format: 'woff2',
        weight: '400',
        style: 'normal',
      },
    ];

    expect(matchCapturedFamily('"avenir-lt-w01_35-light1475496", sans-serif', faces)).toBe(
      'avenir-lt-w01_35'
    );
  });

  it('absolutizeFontUrl and fontFilename match DLA golden behavior', () => {
    expect(absolutizeFontUrl('//cdn.example.com/fonts/acme.woff2')).toBe(
      'https://cdn.example.com/fonts/acme.woff2'
    );
    expect(absolutizeFontUrl('../fonts/acme.woff2', 'https://example.com/css/site/main.css')).toBe(
      'https://example.com/css/fonts/acme.woff2'
    );

    const face: CapturedParsedFontFace = {
      family: 'Acme Display',
      src: 'https://cdn.example.com/font?id=123',
      format: 'woff2',
      weight: '400',
      style: 'normal',
    };

    expect(fontFilename(face)).toBe('Acme-Display-400.woff2');
  });

  it('buildFontFaceCss emits the DLA self-hosted source-font block', () => {
    const faces: LocalFontFace[] = [
      {
        family: 'Acme Display',
        src: 'https://cdn.example.com/acme-bold.woff2',
        format: 'woff2',
        weight: '700',
        style: 'italic',
        localPath: 'assets/fonts/acme-bold.woff2',
      },
    ];
    const family: ThemeFontFamily = {
      fontFamily: 'Acme Display, sans-serif',
      name: 'Acme Display',
      slug: 'acme-display',
      fontFace: [
        {
          fontFamily: 'Acme Display',
          fontWeight: '700',
          fontStyle: 'italic',
          src: ['file:./assets/fonts/acme-bold.woff2'],
        },
      ],
    };

    expect(family.slug).toBe('acme-display');
    expect(buildFontFaceCss(faces)).toBe(`\n/*
 * Self-hosted source fonts. Captured from the source site's @font-face
 * declarations and downloaded into assets/fonts/ so headings + body render in
 * the real typeface rather than a system fallback.
 */
@font-face {
\tfont-family: 'Acme Display';
\tsrc: url('assets/fonts/acme-bold.woff2') format('woff2');
\tfont-weight: 700;
\tfont-style: italic;
\tfont-display: swap;
}
`);
  });

  it('stripCssSourceMaps removes sourceMappingURL comments only', () => {
    expect(
      stripCssSourceMaps(
        'a{}/*# sourceMappingURL=source.css.map */b{}/* sourceMappingURL=https://cdn.example/map.css.map */'
      )
    ).toBe('a{}b{}');
  });

  it('stripUnusedCarryFontFaces returns kept URLs and DLA stripped count', () => {
    const usedFace =
      "@font-face{font-family:'Libre Baskerville';src:url(/fonts/libre.woff2) format('woff2')}";
    const unusedFace =
      "@font-face{font-family:'Unused Sans';src:url(/fonts/unused.woff2) format('woff2')}";
    const usage = '.title{font-family:"Libre Baskerville",serif}';

    const result = stripUnusedCarryFontFaces(`${usedFace}\n${unusedFace}\n${usage}`, usage);

    expect(result).toEqual({
      css: `${usedFace}\n\n${usage}`,
      keptUrls: ['/fonts/libre.woff2'],
      stripped: 1,
    });
  });
});
