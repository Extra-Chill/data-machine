import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { afterEach, describe, expect, it, vi } from 'vitest';

import { siteToTheme } from '../theme/site-to-theme.js';
import type { SectionSpec } from '../theme/section-spec.js';

afterEach(() => {
  vi.unstubAllGlobals();
});

// A spec with no sectionHtml: preserve-dom (the default) has nothing to preserve and
// declines, so the section falls through to the native renderer — the class-discarding
// path this contrast pins.
function nativeMenuSpec(): SectionSpec {
  return {
    sectionIndex: 0,
    interactionModel: 'static',
    top: 0,
    height: 0,
    headings: ['Our menu', 'Flat white'],
    bodyText: ['Oat milk'],
    buttonLabels: [],
    images: [],
    icons: [],
    backgroundBrightness: 1,
    backgroundColor: 'transparent',
    gradient: null,
    gradientSource: null,
    motionProfile: { motionClass: 'none', signals: [], animatedElements: 0 },
    dividerAbove: null,
    dividerBelow: null,
    layout: { containerWidth: 0, padding: '0', childLayout: 'stack', columnCount: 1, gap: '0' },
  };
}

async function withTempDir<T>(prefix: string, fn: (dir: string) => Promise<T> | T): Promise<T> {
  const dir = mkdtempSync(join(tmpdir(), prefix));
  try {
    return await fn(dir);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

function writeRichSite(dir: string): void {
  mkdirSync(dir, { recursive: true });
  // A stylesheet that targets the section's source classes — makes the section "rich".
  writeFileSync(
    join(dir, 'style.css'),
    '.menu{padding:4rem;background:#0b0b0b}.menu-card{border:1px solid #333;border-radius:8px}.menu-card__name{font-weight:700}',
    'utf8'
  );
  writeFileSync(
    join(dir, 'index.html'),
    [
      '<!doctype html><html><head><title>Cafe</title>',
      '<link rel="stylesheet" href="style.css"></head><body>',
      '<main><section class="menu">',
      '<h2 class="menu-title">Our menu</h2>',
      '<div class="menu-card"><h3 class="menu-card__name">Flat white</h3><p class="menu-card__desc">Oat milk</p></div>',
      '</section></main>',
      '</body></html>',
    ].join(''),
    'utf8'
  );
}

describe('rich-section routing activation in siteToTheme', () => {
  it('carries source section/inner classes into the body only when routeRichSections is enabled', async () => {
    await withTempDir('blocks-engine-rich-routing-on-', async (dir) => {
      writeRichSite(dir);
      const result = await siteToTheme(dir, {
        outDir: join(dir, 'theme'),
        themeMeta: { slug: 'cafe-theme' },
        routeRichSections: true,
      });

      const frontPage = result.model.templates['front-page.html'] ?? '';
      // The carried CSS targets .menu / .menu-card / .menu-card__name, so those sections route
      // through preserve-dom and KEEP their source classes (so the carried CSS can style them).
      expect(frontPage).toContain('menu-card');
      expect(frontPage).toContain('menu-card__name');
      expect(frontPage).toContain('Flat white');
      expect(frontPage).toContain('Oat milk');
    });
  });

  it('does NOT carry source classes on the native path (no preservable source HTML)', async () => {
    await withTempDir('blocks-engine-rich-routing-off-', async (dir) => {
      writeRichSite(dir);
      const result = await siteToTheme(dir, {
        outDir: join(dir, 'theme'),
        themeMeta: { slug: 'cafe-theme' },
        routeRichSections: false,
        // Feed a spec with no sectionHtml so preserve-dom declines and the native
        // renderer runs — that path discards source layout classes by design.
        sections: {
          home: [nativeMenuSpec()],
        },
      });

      const frontPage = result.model.templates['front-page.html'] ?? '';
      // Native reconstruction discards source layout classes; content text still survives.
      expect(frontPage).not.toContain('menu-card__name');
      expect(frontPage).toContain('Flat white');
    });
  });
});
