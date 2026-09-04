import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { afterEach, describe, expect, it, vi } from 'vitest';

import { siteToTheme } from '../theme/site-to-theme.js';
import {
  createPublicHostGuardedFetch,
  DEFAULT_REMOTE_IMAGE_FETCH_CONFIG,
  fetchRemoteImage,
  type RemoteImageFetchConfig,
} from '../theme/remote-images.js';
import type { SectionSpec, SectionSpecImage } from '../theme/section-spec.js';

const remoteHeroUrl = 'https://cdn.example.test/assets/hero.png';
const remoteHeroBytes = new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a]);

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

async function withTempDir<T>(prefix: string, fn: (dir: string) => Promise<T> | T): Promise<T> {
  const dir = mkdtempSync(join(tmpdir(), prefix));
  try {
    return await fn(dir);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

function imageResponse(bytes: Uint8Array, contentType = 'image/png'): Response {
  return new Response(bytes, {
    status: 200,
    headers: { 'content-type': contentType },
  }) as Response;
}

function writeRemoteImagePage(dir: string, imgSrc: string): void {
  mkdirSync(dir, { recursive: true });
  writeFileSync(
    join(dir, 'index.html'),
    [
      '<!doctype html>',
      '<html><head><title>Fixture</title></head><body>',
      '<main><section>',
      '<h1>Remote image contract</h1>',
      '<p>Body copy.</p>',
      `<img src="${imgSrc}" alt="Remote hero" width="640" height="420">`,
      '</section></main>',
      '</body></html>',
    ].join(''),
    'utf8'
  );
}

function remoteImageSpec(url: string): SectionSpec {
  return {
    sectionIndex: 0,
    interactionModel: 'static',
    top: 0,
    height: 500,
    headings: ['Remote image contract'],
    bodyText: ['Body copy.'],
    buttonLabels: [],
    images: [sectionImage(url)],
    icons: [],
    backgroundBrightness: 1,
    backgroundColor: 'transparent',
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
      containerWidth: 960,
      padding: '0',
      childLayout: 'stack',
      columnCount: 1,
      gap: '0',
    },
    sectionHtml: [
      '<section>',
      '<h1>Remote image contract</h1>',
      '<p>Body copy.</p>',
      `<img src="${url}" alt="Remote hero" width="640" height="420">`,
      '</section>',
    ].join(''),
  };
}

// Drop sectionHtml so preserve-dom declines and the native fallback runs, emitting
// the image-lost placeholder this no-fetch case pins.
function withoutSectionHtml(spec: SectionSpec): SectionSpec {
  const { sectionHtml: _omit, ...rest } = spec;
  return rest;
}

function sectionImage(url: string): SectionSpecImage {
  return {
    url,
    sourceUrl: url,
    alt: 'Remote hero',
    kind: 'img',
    width: 640,
    height: 420,
  };
}

function smallSafetyConfig(overrides: Partial<RemoteImageFetchConfig> = {}): RemoteImageFetchConfig {
  return {
    ...DEFAULT_REMOTE_IMAGE_FETCH_CONFIG,
    timeoutMs: 25,
    maxBytes: 8,
    ...overrides,
  };
}

describe('remote image carry contract', () => {
  it('uses the default global fetch to self-host a remote image and feed the native resolver', async () => {
    await withTempDir('blocks-engine-remote-img-carry-', async (siteDir) => {
      writeRemoteImagePage(siteDir, remoteHeroUrl);
      const fetchMock = vi.fn(async (input: Parameters<typeof fetch>[0]) => {
        if (String(input) !== remoteHeroUrl) throw new Error(`Unexpected fetch: ${String(input)}`);
        return imageResponse(remoteHeroBytes).clone();
      });
      vi.stubGlobal('fetch', fetchMock as unknown as typeof fetch);

      const result = await siteToTheme(siteDir, {
        outDir: join(siteDir, 'theme'),
        themeMeta: { slug: 'fixture-theme' },
        sections: {
          home: [remoteImageSpec(remoteHeroUrl)],
        },
        // Hermetic SSRF guard: resolve the test host to a public IP without real DNS.
        imageHostLookup: async () => [{ address: '93.184.216.34', family: 4 }],
      });

      const template = readFileSync(join(siteDir, 'theme', 'templates', 'front-page.html'), 'utf8');

      expect(fetchMock).toHaveBeenCalledTimes(1);
      expect(result.model.assets).toEqual(
        expect.arrayContaining([
          expect.objectContaining({
            relPath: 'assets/img/hero.png',
            bytes: remoteHeroBytes,
          }),
        ])
      );
      expect(result.written).toEqual(expect.arrayContaining(['assets/img/hero.png']));
      expect(existsSync(join(siteDir, 'theme', 'assets', 'img', 'hero.png'))).toBe(true);
      expect(template).toContain('<!-- wp:image ');
      expect(template).toContain('src="/wp-content/themes/fixture-theme/assets/img/hero.png"');
      expect(template).not.toContain('[image unavailable');
      expect(template).not.toContain(remoteHeroUrl);
    });
  });

  it('keeps the no-fetch path on the existing placeholder behavior', async () => {
    await withTempDir('blocks-engine-remote-img-no-fetch-', async (siteDir) => {
      writeRemoteImagePage(siteDir, remoteHeroUrl);
      vi.stubGlobal('fetch', undefined);

      const result = await siteToTheme(siteDir, {
        outDir: join(siteDir, 'theme'),
        themeMeta: { slug: 'fixture-theme' },
        sections: {
          home: [withoutSectionHtml(remoteImageSpec(remoteHeroUrl))],
        },
      });

      const template = result.model.templates['front-page.html'];

      expect(template).toContain('[image unavailable');
      expect(template).not.toContain(remoteHeroUrl);
      expect(result.model.assets.map((asset) => asset.relPath)).not.toContain('assets/img/hero.png');
    });
  });

  it('degrades network errors without writing a remote image asset', async () => {
    const fetchMock = vi.fn(async () => {
      throw new Error('offline');
    });

    const result = await fetchRemoteImage(remoteHeroUrl, {
      fetchImpl: fetchMock as unknown as typeof fetch,
      config: smallSafetyConfig(),
    });

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(result.ok).toBe(false);
    expect(result).not.toHaveProperty('bytes');
    expect(result.warning).toContain('offline');
  });

  it('rejects oversized image responses without returning partial bytes', async () => {
    const fetchMock = vi.fn(async () => imageResponse(new Uint8Array([1, 2, 3, 4])).clone());

    const result = await fetchRemoteImage(remoteHeroUrl, {
      fetchImpl: fetchMock as unknown as typeof fetch,
      config: smallSafetyConfig({ maxBytes: 3 }),
    });

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(result.ok).toBe(false);
    expect(result).not.toHaveProperty('bytes');
    expect(result.warning).toMatch(/max bytes|too large|oversize/i);
  });

  it('rejects non-image content-types without returning bytes', async () => {
    const fetchMock = vi.fn(async () => imageResponse(new Uint8Array([1, 2, 3]), 'text/plain').clone());

    const result = await fetchRemoteImage(remoteHeroUrl, {
      fetchImpl: fetchMock as unknown as typeof fetch,
      config: smallSafetyConfig(),
    });

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(result.ok).toBe(false);
    expect(result).not.toHaveProperty('bytes');
    expect(result.warning).toMatch(/content-type|image/i);
  });

  it('rejects non-http schemes before fetch', async () => {
    const fetchMock = vi.fn(async () => imageResponse(new Uint8Array([1, 2, 3])).clone());

    for (const url of [
      'file:///tmp/private.png',
      'data:image/png;base64,AA==',
      'ftp://cdn.example.test/hero.png',
    ]) {
      const result = await fetchRemoteImage(url, {
        fetchImpl: fetchMock as unknown as typeof fetch,
        config: smallSafetyConfig(),
      });
      expect(result.ok).toBe(false);
      expect(result).not.toHaveProperty('bytes');
    }

    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('times out remote image requests and degrades without bytes', async () => {
    vi.useFakeTimers();
    const fetchMock = vi.fn(
      async (_input: Parameters<typeof fetch>[0], init?: Parameters<typeof fetch>[1]) =>
        new Promise<Response>((_resolve, reject) => {
          init?.signal?.addEventListener('abort', () => reject(new Error('aborted by test signal')));
        })
    );

    const promise = fetchRemoteImage(remoteHeroUrl, {
      fetchImpl: fetchMock as unknown as typeof fetch,
      config: smallSafetyConfig({ timeoutMs: 5 }),
    });
    await vi.advanceTimersByTimeAsync(5);
    const result = await promise;

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(result.ok).toBe(false);
    expect(result).not.toHaveProperty('bytes');
    expect(result.warning).toMatch(/abort|timeout/i);
  });

  it('refuses to self-host SVG responses (stored-XSS vector)', async () => {
    const fetchMock = vi.fn(async () =>
      imageResponse(new Uint8Array([0x3c, 0x73, 0x76, 0x67]), 'image/svg+xml').clone()
    );

    const result = await fetchRemoteImage(remoteHeroUrl, {
      fetchImpl: fetchMock as unknown as typeof fetch,
      config: smallSafetyConfig({ maxBytes: 1024 }),
    });

    expect(result.ok).toBe(false);
    expect(result).not.toHaveProperty('bytes');
    expect(result.warning).toMatch(/content-type|image/i);
  });

  it('re-validates redirect hops and blocks a redirect to an internal IP without fetching it', async () => {
    const internalUrl = 'http://169.254.169.254/latest/meta-data/';
    const fetchMock = vi.fn(async (input: Parameters<typeof fetch>[0]) => {
      if (String(input) === remoteHeroUrl) {
        return new Response(null, { status: 302, headers: { location: internalUrl } }) as Response;
      }
      // Should never be reached — the internal redirect target must be blocked first.
      return imageResponse(new Uint8Array([1, 2, 3])).clone();
    });

    const result = await fetchRemoteImage(remoteHeroUrl, {
      fetchImpl: fetchMock as unknown as typeof fetch,
      config: smallSafetyConfig({ maxBytes: 1024 }),
    });

    expect(result.ok).toBe(false);
    expect(result).not.toHaveProperty('bytes');
    // The original URL was fetched once; the internal redirect target was NEVER fetched.
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock).not.toHaveBeenCalledWith(internalUrl, expect.anything());
  });

  it('follows a redirect to another public image and self-hosts it', async () => {
    const finalUrl = 'https://cdn2.example.test/assets/hero-final.png';
    const fetchMock = vi.fn(async (input: Parameters<typeof fetch>[0]) => {
      if (String(input) === remoteHeroUrl) {
        return new Response(null, { status: 302, headers: { location: finalUrl } }) as Response;
      }
      return imageResponse(remoteHeroBytes).clone();
    });

    const result = await fetchRemoteImage(remoteHeroUrl, {
      fetchImpl: fetchMock as unknown as typeof fetch,
      config: smallSafetyConfig({ maxBytes: 1024 }),
    });

    expect(result.ok).toBe(true);
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('guards the default fetch against a hostname that resolves to an internal IP', async () => {
    const base = vi.fn(async () => imageResponse(remoteHeroBytes).clone());
    const guarded = createPublicHostGuardedFetch(base as unknown as typeof fetch, async () => [
      { address: '10.0.0.7', family: 4 },
    ]);

    await expect(guarded('https://cdn.example.test/hero.png')).rejects.toThrow(/internal IP/i);
    expect(base).not.toHaveBeenCalled();
  });

  it('allows the default fetch when the hostname resolves to a public IP', async () => {
    const base = vi.fn(async () => imageResponse(remoteHeroBytes).clone());
    const guarded = createPublicHostGuardedFetch(base as unknown as typeof fetch, async () => [
      { address: '93.184.216.34', family: 4 },
    ]);

    const res = await guarded('https://cdn.example.test/hero.png');
    expect(res.status).toBe(200);
    expect(base).toHaveBeenCalledTimes(1);
  });
});
