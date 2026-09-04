import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  collectStaticAssets,
  rewriteHtmlImageSrcs,
  type StaticImgRef,
} from '../theme/assets-static.js';
import { ingest } from '../theme/ingest.js';
import type { SiteModel } from '../theme/types.js';

const fixtureRoot = join(import.meta.dirname, 'fixtures/site');

function withTempSite<T>(fn: (paths: { parent: string; root: string }) => T): T {
  const parent = mkdtempSync(join(tmpdir(), 'blocks-engine-theme-assets-static-'));
  const root = join(parent, 'site');
  mkdirSync(root);

  try {
    return fn({ parent, root });
  } finally {
    rmSync(parent, { recursive: true, force: true });
  }
}

describe('theme static assets', () => {
  it('collects fixture image assets with source paths and page refs', () => {
    const site = ingest(fixtureRoot);
    const sourcePath = join(fixtureRoot, 'assets/logo.png');

    const result = collectStaticAssets(site, 'calm-theme');

    expect(result.assets).toEqual([
      {
        relPath: 'assets/logo.png',
        sourcePath,
      },
    ]);
    expect(result.imgRefsByPage.home).toEqual([
      {
        ref: 'assets/logo.png',
        themeRel: 'assets/logo.png',
        sourcePath,
      },
    ]);
    expect(result.imgRefsByPage.about).toBeUndefined();
  });

  it('rewrites double-quoted and single-quoted image src values', () => {
    const refs: StaticImgRef[] = [
      {
        ref: 'assets/logo.png',
        themeRel: 'assets/logo.png',
        sourcePath: '/tmp/logo.png',
      },
      {
        ref: 'images/hero.jpg',
        themeRel: 'assets/images/hero.jpg',
        sourcePath: '/tmp/hero.jpg',
      },
    ];

    expect(
      rewriteHtmlImageSrcs(
        `<img src="assets/logo.png"><img alt="Hero" src='images/hero.jpg'>`,
        refs,
        'calm-theme'
      )
    ).toBe(
      `<img src="/wp-content/themes/calm-theme/assets/logo.png"><img alt="Hero" src='/wp-content/themes/calm-theme/assets/images/hero.jpg'>`
    );
  });

  it('leaves unrelated src values untouched', () => {
    const refs: StaticImgRef[] = [
      {
        ref: 'assets/logo.png',
        themeRel: 'assets/logo.png',
        sourcePath: '/tmp/logo.png',
      },
    ];
    const html = [
      `<script src="assets/logo.png"></script>`,
      `<img src="assets/other.png">`,
      `<img data-src="assets/logo.png">`,
      `<source src="assets/logo.png">`,
    ].join('');

    expect(rewriteHtmlImageSrcs(html, refs, 'calm-theme')).toBe(html);
  });

  it('ignores non-local, missing, and escaping image refs while deduping assets', () => {
    withTempSite(({ parent, root }) => {
      mkdirSync(join(root, 'assets'));
      mkdirSync(join(root, 'pages'));
      writeFileSync(join(root, 'assets/logo.png'), 'png', 'utf8');
      writeFileSync(join(parent, 'outside.png'), 'png', 'utf8');

      const site: SiteModel = {
        root,
        pages: [
          {
            relPath: 'pages/index.html',
            slug: 'home',
            title: 'Home',
            html: [
              `<img src="../assets/logo.png">`,
              `<img src="/assets/logo.png">`,
              `<img src="https://example.test/logo.png">`,
              `<img src="//example.test/logo.png">`,
              `<img src="data:image/png;base64,AA==">`,
              `<img src="#fragment">`,
              `<img src="missing.png">`,
              `<img src="../../outside.png">`,
            ].join(''),
          },
        ],
      };

      const result = collectStaticAssets(site, 'calm-theme');

      expect(result.assets).toEqual([
        {
          relPath: 'assets/logo.png',
          sourcePath: join(root, 'assets/logo.png'),
        },
      ]);
      expect(result.imgRefsByPage.home).toEqual([
        {
          ref: '../assets/logo.png',
          themeRel: 'assets/logo.png',
          sourcePath: join(root, 'assets/logo.png'),
        },
        {
          ref: '/assets/logo.png',
          themeRel: 'assets/logo.png',
          sourcePath: join(root, 'assets/logo.png'),
        },
      ]);
    });
  });
});
