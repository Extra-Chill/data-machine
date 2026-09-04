import { parseFontFaces, type ParsedFontFace } from './font-faces.js';
import type { AssetFile } from './types.js';

export interface FontSelfHostResult {
  assets: AssetFile[];
  localizedCss: string;
  warnings: string[];
}

const FONTS_DIR = 'assets/fonts';
const WOFF2_USER_AGENT =
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

class FontUrlBlockedError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'FontUrlBlockedError';
  }
}

export async function selfHostFonts(
  cssUrls: string[],
  parsed: ParsedFontFace[],
  opts: { themeSlug: string; fetchImpl?: typeof fetch }
): Promise<FontSelfHostResult> {
  const fetchFn = opts.fetchImpl;
  if (!fetchFn) {
    return {
      assets: [],
      localizedCss: '',
      warnings: ['font self-hosting skipped: opts.fetchImpl is required'],
    };
  }

  const assets: AssetFile[] = [];
  const warnings: string[] = [];
  const downloaded = new Map<string, string>();
  const failed = new Set<string>();
  const usedFilenames = new Map<string, string>();
  const localizedParts: string[] = [];

  for (const cssUrl of cssUrls) {
    const css = await fetchFontCss(cssUrl, fetchFn, warnings);
    if (!css) continue;

    let localized = css.text;
    for (const face of parseFontFaces(css.text)) {
      const relPath = await downloadFontFace(face, {
        baseUrl: css.url,
        fetchFn,
        assets,
        downloaded,
        failed,
        usedFilenames,
        warnings,
      });

      if (relPath) {
        localized = localized.split(face.src).join(relPath);
      }
    }

    localizedParts.push(localized);
  }

  for (const face of parsed) {
    const relPath = await downloadFontFace(face, {
      fetchFn,
      assets,
      downloaded,
      failed,
      usedFilenames,
      warnings,
    });
    localizedParts.push(fontFaceCss(face, relPath ?? face.src));
  }

  return {
    assets,
    localizedCss: localizedParts.join('\n\n'),
    warnings,
  };
}

async function fetchFontCss(
  rawUrl: string,
  fetchFn: typeof fetch,
  warnings: string[]
): Promise<{ url: string; text: string } | null> {
  let url: URL;
  try {
    url = assertPublicHttpUrl(rawUrl);
  } catch (err) {
    warnings.push(`skipped font CSS ${quote(rawUrl)}: ${errorMessage(err)}`);
    return null;
  }

  try {
    // Standard fetch follows redirects internally; redirect-target
    // revalidation is unavailable through this fetch seam.
    const res = await fetchFn(url.toString(), {
      headers: { 'User-Agent': WOFF2_USER_AGENT },
    });

    if (!isSuccess(res)) {
      warnings.push(`skipped font CSS ${quote(url.toString())}: HTTP ${res.status}`);
      return null;
    }

    const text = await res.text();
    if (text.length === 0) {
      warnings.push(`skipped font CSS ${quote(url.toString())}: empty response body`);
      return null;
    }

    return { url: url.toString(), text };
  } catch (err) {
    warnings.push(`skipped font CSS ${quote(url.toString())}: ${errorMessage(err)}`);
    return null;
  }
}

interface DownloadFontFaceCtx {
  baseUrl?: string;
  fetchFn: typeof fetch;
  assets: AssetFile[];
  downloaded: Map<string, string>;
  failed: Set<string>;
  usedFilenames: Map<string, string>;
  warnings: string[];
}

async function downloadFontFace(face: ParsedFontFace, ctx: DownloadFontFaceCtx): Promise<string | null> {
  let fontUrl: URL;
  try {
    fontUrl = resolvePublicFontUrl(face.src, ctx.baseUrl);
  } catch (err) {
    ctx.warnings.push(`skipped font ${describeFace(face)}: ${errorMessage(err)}`);
    return null;
  }

  const absolute = fontUrl.toString();
  const existing = ctx.downloaded.get(absolute);
  if (existing) return existing;
  if (ctx.failed.has(absolute)) return null;

  try {
    const res = await ctx.fetchFn(absolute, {
      headers: { 'User-Agent': WOFF2_USER_AGENT },
    });

    if (!isSuccess(res)) {
      throw new Error(`HTTP ${res.status}`);
    }

    const bytes = new Uint8Array(await res.arrayBuffer());
    if (bytes.byteLength === 0) {
      throw new Error('empty response body');
    }

    const filename = allocateFilename(fontFilename(face, fontUrl), absolute, ctx.usedFilenames);
    const relPath = `${FONTS_DIR}/${filename}`;
    ctx.downloaded.set(absolute, relPath);
    ctx.assets.push({ relPath, bytes });
    return relPath;
  } catch (err) {
    ctx.failed.add(absolute);
    ctx.warnings.push(`skipped font ${describeFace(face, absolute)}: ${errorMessage(err)}`);
    return null;
  }
}

function resolvePublicFontUrl(rawUrl: string, baseUrl?: string): URL {
  if (!baseUrl) return assertPublicHttpUrl(rawUrl);

  let resolved: string;
  try {
    resolved = new URL(rawUrl, baseUrl).toString();
  } catch {
    throw new FontUrlBlockedError(`unparseable URL: ${truncate(rawUrl)}`);
  }

  return assertPublicHttpUrl(resolved);
}

function isSuccess(res: Response): boolean {
  return res.status >= 200 && res.status < 300;
}

function fontFilename(face: ParsedFontFace, url: URL): string {
  const basename = sanitizedUrlBasename(url);
  if (basename) return basename;

  const family = sanitizeFilenamePart(face.family) || 'font';
  const weight = sanitizeFilenamePart(face.weight ?? '400') || '400';
  const italic = face.style === 'italic' ? '-italic' : '';
  const ext = face.format === 'woff' ? 'woff' : 'woff2';
  return `${family}-${weight}${italic}.${ext}`;
}

function fontFaceCss(face: ParsedFontFace, src: string): string {
  const lines = [
    '@font-face {',
    `  font-family: ${cssString(face.family)};`,
    `  src: url(${cssString(src)})${face.format ? ` format(${cssString(face.format)})` : ''};`,
  ];

  if (face.weight) {
    lines.push(`  font-weight: ${face.weight};`);
  }
  if (face.style) {
    lines.push(`  font-style: ${face.style};`);
  }

  lines.push('}');
  return lines.join('\n');
}

function cssString(value: string): string {
  return `"${value.replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`;
}

function sanitizedUrlBasename(url: URL): string | null {
  const raw = url.pathname.split('/').pop() ?? '';
  const decoded = safeDecodeURIComponent(raw);
  const sanitized = decoded.replace(/[^A-Za-z0-9._-]+/g, '');
  return hasExtension(sanitized) ? sanitized : null;
}

function hasExtension(filename: string): boolean {
  const dot = filename.lastIndexOf('.');
  return dot > 0 && dot < filename.length - 1 && /^[A-Za-z0-9]+$/.test(filename.slice(dot + 1));
}

function sanitizeFilenamePart(value: string): string {
  return value.replace(/[^A-Za-z0-9_-]+/g, '');
}

function allocateFilename(preferred: string, absoluteUrl: string, used: Map<string, string>): string {
  const existing = used.get(preferred);
  if (!existing) {
    used.set(preferred, absoluteUrl);
    return preferred;
  }
  if (existing === absoluteUrl) return preferred;

  const { stem, ext } = splitExtension(preferred);
  for (let index = 2; ; index++) {
    const candidate = `${stem}-${index}${ext}`;
    const owner = used.get(candidate);
    if (!owner) {
      used.set(candidate, absoluteUrl);
      return candidate;
    }
    if (owner === absoluteUrl) return candidate;
  }
}

function splitExtension(filename: string): { stem: string; ext: string } {
  const dot = filename.lastIndexOf('.');
  if (dot <= 0) return { stem: filename, ext: '' };
  return { stem: filename.slice(0, dot), ext: filename.slice(dot) };
}

function safeDecodeURIComponent(value: string): string {
  try {
    return decodeURIComponent(value);
  } catch {
    return value;
  }
}

function assertPublicHttpUrl(rawUrl: string): URL {
  let url: URL;
  try {
    url = new URL(rawUrl);
  } catch {
    throw new FontUrlBlockedError(`unparseable URL: ${truncate(rawUrl)}`);
  }

  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    throw new FontUrlBlockedError(`disallowed URL scheme "${url.protocol}"`);
  }

  const host = url.hostname.toLowerCase();
  if (!host) {
    throw new FontUrlBlockedError('URL has no host');
  }

  const lit = literalIp(host);
  if (lit) {
    const internal = lit.kind === 'ipv4' ? isInternalIpv4(lit.value) : isInternalIpv6(lit.value);
    if (internal) {
      throw new FontUrlBlockedError(`internal/loopback IP address not allowed: ${host}`);
    }
    return url;
  }

  if (host === 'localhost' || host.endsWith('.localhost')) {
    throw new FontUrlBlockedError(`loopback hostname not allowed: ${host}`);
  }
  if (host.endsWith('.local')) {
    throw new FontUrlBlockedError(`mDNS/.local hostname not allowed: ${host}`);
  }
  if (!host.includes('.')) {
    throw new FontUrlBlockedError(`bare single-label hostname not allowed: ${host}`);
  }

  return url;
}

function literalIp(host: string): { kind: 'ipv4' | 'ipv6'; value: string } | null {
  let h = host;
  if (h.startsWith('[') && h.endsWith(']')) h = h.slice(1, -1);

  const pct = h.indexOf('%');
  if (pct >= 0) h = h.slice(0, pct);

  if (/^\d{1,3}(\.\d{1,3}){3}$/.test(h)) {
    const parts = h.split('.').map((part) => Number(part));
    if (parts.every((part) => part >= 0 && part <= 255)) {
      return { kind: 'ipv4', value: h };
    }
  }

  if (h.includes(':') && /^[0-9a-fA-F:]+$/.test(h)) {
    return { kind: 'ipv6', value: h.toLowerCase() };
  }

  return null;
}

function isInternalIpv4(ip: string): boolean {
  const [a, b] = ip.split('.').map((part) => Number(part));
  if (a === 0) return true;
  if (a === 127) return true;
  if (a === 10) return true;
  if (a === 172 && b >= 16 && b <= 31) return true;
  if (a === 192 && b === 168) return true;
  if (a === 169 && b === 254) return true;
  if (a === 100 && b >= 64 && b <= 127) return true;
  if (a >= 224) return true;
  return false;
}

function isInternalIpv6(ip: string): boolean {
  const value = ip.toLowerCase();
  if (value === '::1') return true;
  if (value === '::' || value === '0:0:0:0:0:0:0:0') return true;
  if (value.startsWith('fc') || value.startsWith('fd')) return true;
  if (value.startsWith('fe8') || value.startsWith('fe9') || value.startsWith('fea') || value.startsWith('feb')) {
    return true;
  }

  const dottedMapped = value.match(/::ffff:(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/);
  if (dottedMapped) return isInternalIpv4(dottedMapped[1]);

  const hexMapped = value.match(/^(?:::ffff:|0:0:0:0:0:ffff:)([0-9a-f]{1,4}):([0-9a-f]{1,4})$/);
  if (hexMapped) {
    const high = Number.parseInt(hexMapped[1], 16);
    const low = Number.parseInt(hexMapped[2], 16);
    const mappedIpv4 = [
      (high >> 8) & 255,
      high & 255,
      (low >> 8) & 255,
      low & 255,
    ].join('.');
    return isInternalIpv4(mappedIpv4);
  }

  return false;
}

function describeFace(face: ParsedFontFace, url?: string): string {
  const suffix = url ? ` ${quote(url)}` : ` ${quote(face.src)}`;
  return `${quote(face.family)}${suffix}`;
}

function quote(value: string): string {
  return `"${truncate(value)}"`;
}

function truncate(value: string): string {
  return value.length > 200 ? `${value.slice(0, 200)}...` : value;
}

function errorMessage(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}
