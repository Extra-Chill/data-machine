import { describe, expect, it, vi } from 'vitest';

import type { ParsedFontFace } from '../theme/font-faces.js';
import { selfHostFonts } from '../theme/fonts-network.js';

const googleCssUrl = 'https://fonts.googleapis.com/css2?family=Inter';
const gstaticFontUrl = 'https://fonts.gstatic.com/s/inter/v12/inter-latin.woff2';

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
  urls: string[];
} {
  const urls: string[] = [];
  const fetchMock = vi.fn(async (input: Parameters<typeof fetch>[0]) => {
    const url = String(input);
    urls.push(url);

    const response = routes[url];
    if (!response) throw new Error(`Unexpected fetch: ${url}`);

    return response.clone();
  });

  return { fetchImpl: fetchMock as unknown as typeof fetch, fetchMock, urls };
}

function throwingFetch(error: Error): {
  fetchImpl: typeof fetch;
  fetchMock: ReturnType<typeof vi.fn>;
} {
  const fetchMock = vi.fn(async () => {
    throw error;
  });

  return { fetchImpl: fetchMock as unknown as typeof fetch, fetchMock };
}

describe('selfHostFonts', () => {
  it('self-hosts Google CSS font files and localizes gstatic URLs', async () => {
    const fontBytes = new Uint8Array([0, 1, 2, 3, 4]);
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

    const result = await selfHostFonts([googleCssUrl], [], {
      themeSlug: 'fixture-theme',
      fetchImpl,
    });

    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(result.warnings).toEqual([]);
    expect(result.assets).toHaveLength(1);

    const [asset] = result.assets;
    expect(asset.bytes).toEqual(fontBytes);
    expect(asset.relPath).toMatch(/^assets\/fonts\/[^/]+\.woff2$/);

    expect(result.localizedCss).toContain(asset.relPath);
    expect(result.localizedCss).not.toContain(gstaticFontUrl);
  });

  it('rejects SSRF and file font sources without fetching them', async () => {
    const metadataUrl = 'http://169.254.169.254/latest/meta-data/font.woff2';
    const fileUrl = 'file:///tmp/private-font.woff2';
    const parsed: ParsedFontFace[] = [
      {
        family: 'Blocked Metadata',
        src: metadataUrl,
        format: 'woff2',
        weight: '400',
        style: 'normal',
      },
      {
        family: 'Blocked File',
        src: fileUrl,
        format: 'woff2',
        weight: '400',
        style: 'normal',
      },
      {
        family: 'Blocked Mapped IPv6',
        src: 'http://[::ffff:7f00:1]/font.woff2',
        format: 'woff2',
        weight: '400',
        style: 'normal',
      },
    ];
    const fetchMock = vi.fn(async () => {
      throw new Error('blocked URLs must not be fetched');
    });

    const result = await selfHostFonts([], parsed, {
      themeSlug: 'fixture-theme',
      fetchImpl: fetchMock as unknown as typeof fetch,
    });

    expect(fetchMock).not.toHaveBeenCalled();
    expect(result.assets).toEqual([]);
    expect(result.localizedCss).toContain(metadataUrl);
    expect(result.localizedCss).toContain(fileUrl);
    expect(result.localizedCss).toContain('http://[::ffff:7f00:1]/font.woff2');
    expect(result.warnings.join('\n')).toContain(metadataUrl);
    expect(result.warnings.join('\n')).toContain(fileUrl);
    expect(result.warnings.join('\n')).toContain('http://[::ffff:7f00:1]/font.woff2');
  });

  it('degrades offline fetch failures into warnings without throwing', async () => {
    const parsed: ParsedFontFace[] = [
      {
        family: 'Inter',
        src: gstaticFontUrl,
        format: 'woff2',
        weight: '400',
        style: 'normal',
      },
    ];
    const { fetchImpl, fetchMock } = throwingFetch(new Error('offline'));

    const result = await selfHostFonts([], parsed, {
      themeSlug: 'fixture-theme',
      fetchImpl,
    });

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(result.assets).toEqual([]);
    expect(result.localizedCss).toContain(gstaticFontUrl);
    expect(result.localizedCss).toContain('font-family: "Inter"');
    expect(result.warnings.length).toBeGreaterThan(0);
    expect(result.warnings.join('\n')).toContain(gstaticFontUrl);
  });
});
