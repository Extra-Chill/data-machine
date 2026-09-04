import { join } from 'node:path';

import { describe, expect, it, vi } from 'vitest';

import { assets, ingest, type StageCtx } from '../theme/index.js';

const fixtureRoot = join(import.meta.dirname, 'fixtures/site');
const googleCssUrl = 'https://fonts.googleapis.com/css2?family=Inter';
const gstaticFontUrl = 'https://fonts.gstatic.com/s/inter/v12/inter-latin.woff2';

function fixtureCtx(): StageCtx {
  const site = ingest(fixtureRoot);

  return {
    srcDir: fixtureRoot,
    site,
    themeMeta: {
      name: 'Fixture Theme',
      slug: 'fixture-theme',
    },
    warn() {},
  };
}

function textResponse(body: string): Response {
  return new Response(body, {
    status: 200,
    headers: { 'content-type': 'text/css' },
  }) as Response;
}

function bytesResponse(bytes: Uint8Array): Response {
  return new Response(bytes, {
    status: 200,
    headers: { 'content-type': 'font/woff2' },
  }) as Response;
}

function routeFetch(routes: Record<string, Response>): {
  fetchImpl: typeof fetch;
  fetchMock: ReturnType<typeof vi.fn>;
} {
  const fetchMock = vi.fn(async (input: Parameters<typeof fetch>[0]) => {
    const url = String(input);
    const response = routes[url];
    if (!response) throw new Error(`Unexpected fetch: ${url}`);

    return response.clone();
  });

  return { fetchImpl: fetchMock as unknown as typeof fetch, fetchMock };
}

describe('theme assets stage', () => {
  it('returns static inventory and no font CSS without fetchImpl', async () => {
    const result = await assets(fixtureCtx(), {});

    expect(result.inventory.assets).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          relPath: 'assets/logo.png',
        }),
      ])
    );
    expect(result.fontCss).toBe('');
  });

  it('self-hosts fixture Google fonts when fetchImpl is provided', async () => {
    const fontBytes = new Uint8Array([5, 6, 7, 8]);
    const googleCss = `
      @font-face {
        font-family: 'Inter';
        src: url('${gstaticFontUrl}') format('woff2');
        font-weight: 400;
        font-style: normal;
      }
    `;
    const { fetchImpl, fetchMock } = routeFetch({
      [googleCssUrl]: textResponse(googleCss),
      [gstaticFontUrl]: bytesResponse(fontBytes),
    });

    const result = await assets(fixtureCtx(), { fetchImpl });

    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(result.inventory.assets).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          relPath: 'assets/logo.png',
        }),
      ])
    );

    const fontAsset = result.inventory.assets.find((asset) =>
      asset.relPath.startsWith('assets/fonts/')
    );
    expect(fontAsset).toEqual(
      expect.objectContaining({
        bytes: fontBytes,
      })
    );
    expect(result.fontCss).toContain('assets/fonts/');
    expect(result.fontCss).not.toContain(gstaticFontUrl);
  });
});
