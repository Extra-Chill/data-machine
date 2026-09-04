import { lookup as dnsLookup } from 'node:dns/promises';
import { extname } from 'node:path';

import type { StaticImgRef } from './assets-static.js';
import type { AssetFile } from './types.js';

/** Resolve a hostname to its IPs. Injectable so SSRF tests stay hermetic (no real DNS). */
export type HostLookup = (host: string) => Promise<Array<{ address: string; family: number }>>;

const defaultHostLookup: HostLookup = (host) => dnsLookup(host, { all: true });

export interface RemoteImageFetchConfig {
  timeoutMs: number;
  maxBytes: number;
  allowedSchemes: readonly string[];
}

export const DEFAULT_REMOTE_IMAGE_FETCH_CONFIG = {
  timeoutMs: 10_000,
  maxBytes: 10 * 1024 * 1024,
  allowedSchemes: ['http:', 'https:'],
} as const satisfies RemoteImageFetchConfig;

export interface RemoteImageFetchSuccess {
  ok: true;
  url: string;
  contentType: string;
  bytes: Uint8Array;
  warning?: never;
}

export interface RemoteImageFetchSkipped {
  ok: false;
  warning: string;
}

export type RemoteImageFetchResult = RemoteImageFetchSuccess | RemoteImageFetchSkipped;

export interface RemoteImagePage {
  slug: string;
  relPath?: string;
  html: string;
}

export interface RemoteImageAssetCollectionResult {
  assets: AssetFile[];
  imgRefsByPage: Record<string, StaticImgRef[]>;
  warnings: string[];
}

export interface RemoteImageAssetCollectionOptions {
  fetchImpl?: typeof fetch;
  config?: Partial<RemoteImageFetchConfig>;
  occupiedRelPaths?: Iterable<string>;
}

const IMG_DIR = 'assets/img';
const IMG_TAG_RE = /<img\b[^>]*>/gi;
const FIRST_SRC_ATTR_RE = /(\s+src\s*=\s*)(["'])([\s\S]*?)\2/i;

class RemoteImageBlockedError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'RemoteImageBlockedError';
  }
}

export async function fetchRemoteImage(
  rawUrl: string,
  opts: {
    fetchImpl?: typeof fetch;
    config?: Partial<RemoteImageFetchConfig>;
  }
): Promise<RemoteImageFetchResult> {
  const fetchFn = opts.fetchImpl;
  if (!fetchFn) {
    return { ok: false, warning: 'remote image fetch skipped: opts.fetchImpl is required' };
  }

  const config = normalizeConfig(opts.config);

  let url: URL;
  try {
    url = assertPublicImageUrl(rawUrl, config.allowedSchemes);
  } catch (err) {
    return { ok: false, warning: `remote image fetch skipped: ${errorMessage(err)}` };
  }

  const controller = new AbortController();
  let timedOut = false;
  let timeout: ReturnType<typeof setTimeout> | undefined;

  if (config.timeoutMs <= 0) {
    timedOut = true;
    controller.abort();
  } else {
    timeout = setTimeout(() => {
      timedOut = true;
      controller.abort();
    }, config.timeoutMs);
  }

  try {
    const res = await fetchFollowingSafeRedirects(
      fetchFn,
      url,
      config.allowedSchemes,
      controller.signal
    );

    if (!isSuccess(res)) {
      return {
        ok: false,
        warning: `remote image fetch skipped for ${quote(url.toString())}: HTTP ${res.status}`,
      };
    }

    const contentType = imageContentType(res);
    if (!contentType) {
      return {
        ok: false,
        warning: `remote image fetch skipped for ${quote(url.toString())}: content-type is not image/*`,
      };
    }

    const bytes = await readCappedBytes(res, config.maxBytes, () => controller.abort());
    return {
      ok: true,
      url: url.toString(),
      contentType,
      bytes,
    };
  } catch (err) {
    const prefix = timedOut
      ? `remote image fetch timed out after ${config.timeoutMs}ms`
      : 'remote image fetch failed';
    return {
      ok: false,
      warning: `${prefix} for ${quote(url.toString())}: ${errorMessage(err)}`,
    };
  } finally {
    if (timeout) clearTimeout(timeout);
  }
}

export async function collectRemoteImageAssets(
  pages: RemoteImagePage[],
  opts: RemoteImageAssetCollectionOptions
): Promise<RemoteImageAssetCollectionResult> {
  if (!opts.fetchImpl) {
    return {
      assets: [],
      imgRefsByPage: {},
      warnings: [],
    };
  }

  const assets: AssetFile[] = [];
  const warnings: string[] = [];
  const imgRefsByPage: Record<string, StaticImgRef[]> = {};
  const relPathOwners = new Map<string, string>();
  const relPathByUrl = new Map<string, string>();
  const failedUrls = new Set<string>();

  for (const relPath of opts.occupiedRelPaths ?? []) {
    relPathOwners.set(relPath, '<occupied>');
  }

  for (const page of sortedPages(pages)) {
    const refs: StaticImgRef[] = [];
    const seenOnPage = new Set<string>();

    for (const ref of extractRemoteImgSrcRefs(page.html)) {
      const resolved = normalizeRemoteImageRef(ref);
      if (!resolved) continue;

      let themeRel = relPathByUrl.get(resolved);
      if (!themeRel && !failedUrls.has(resolved)) {
        const fetched = await fetchRemoteImage(resolved, {
          fetchImpl: opts.fetchImpl,
          config: opts.config,
        });

        if (fetched.ok) {
          themeRel = allocateThemeRel(
            preferredImageFilename(new URL(fetched.url), fetched.contentType),
            fetched.url,
            relPathOwners
          );
          relPathByUrl.set(fetched.url, themeRel);
          assets.push({
            relPath: themeRel,
            bytes: fetched.bytes,
          });
        } else {
          failedUrls.add(resolved);
          warnings.push(`skipped remote image ${quote(resolved)}: ${fetched.warning}`);
        }
      }

      if (!themeRel) continue;

      const pageRefKey = `${ref}\0${themeRel}`;
      if (seenOnPage.has(pageRefKey)) continue;
      seenOnPage.add(pageRefKey);
      refs.push({
        ref,
        themeRel,
        sourcePath: resolved,
      });
    }

    if (refs.length > 0) {
      imgRefsByPage[page.slug] = refs;
    }
  }

  return {
    assets: sortAssetFiles(assets),
    imgRefsByPage,
    warnings,
  };
}

function normalizeConfig(config?: Partial<RemoteImageFetchConfig>): RemoteImageFetchConfig {
  return {
    timeoutMs: normalizeNonNegativeInteger(
      config?.timeoutMs,
      DEFAULT_REMOTE_IMAGE_FETCH_CONFIG.timeoutMs
    ),
    maxBytes: normalizeNonNegativeInteger(
      config?.maxBytes,
      DEFAULT_REMOTE_IMAGE_FETCH_CONFIG.maxBytes
    ),
    allowedSchemes: normalizeAllowedSchemes(
      config?.allowedSchemes ?? DEFAULT_REMOTE_IMAGE_FETCH_CONFIG.allowedSchemes
    ),
  };
}

function normalizeNonNegativeInteger(value: number | undefined, fallback: number): number {
  if (value === undefined || !Number.isFinite(value)) return fallback;
  return Math.max(0, Math.floor(value));
}

function normalizeAllowedSchemes(schemes: readonly string[]): readonly string[] {
  const allowed = new Set<string>();

  for (const scheme of schemes) {
    const normalized = scheme.toLowerCase().endsWith(':')
      ? scheme.toLowerCase()
      : `${scheme.toLowerCase()}:`;
    if (normalized === 'http:' || normalized === 'https:') {
      allowed.add(normalized);
    }
  }

  return [...allowed].sort();
}

function assertPublicImageUrl(rawUrl: string, allowedSchemes: readonly string[]): URL {
  let url: URL;
  try {
    url = new URL(rawUrl);
  } catch {
    throw new RemoteImageBlockedError(`unparseable URL: ${truncate(rawUrl)}`);
  }

  if (!allowedSchemes.includes(url.protocol) || !isHttpScheme(url.protocol)) {
    throw new RemoteImageBlockedError(`disallowed URL scheme "${url.protocol}"`);
  }

  const rawHost = url.hostname.toLowerCase();
  const host = rawHost.endsWith('.') ? rawHost.slice(0, -1) : rawHost;
  if (!host) {
    throw new RemoteImageBlockedError('URL has no host');
  }

  const lit = literalIp(host);
  if (lit) {
    const internal = lit.kind === 'ipv4' ? isInternalIpv4(lit.value) : isInternalIpv6(lit.value);
    if (internal) {
      throw new RemoteImageBlockedError(`internal/loopback IP address not allowed: ${host}`);
    }
    return url;
  }

  if (host === 'localhost' || host.endsWith('.localhost')) {
    throw new RemoteImageBlockedError(`loopback hostname not allowed: ${host}`);
  }
  if (host.endsWith('.local')) {
    throw new RemoteImageBlockedError(`mDNS/.local hostname not allowed: ${host}`);
  }
  if (!host.includes('.')) {
    throw new RemoteImageBlockedError(`bare single-label hostname not allowed: ${host}`);
  }

  return url;
}

function isHttpScheme(protocol: string): boolean {
  return protocol === 'http:' || protocol === 'https:';
}

const MAX_IMAGE_REDIRECTS = 5;

function isRedirectStatus(status: number): boolean {
  return status === 301 || status === 302 || status === 303 || status === 307 || status === 308;
}

/**
 * Fetch following redirects MANUALLY so every hop's target is re-validated through
 * assertPublicImageUrl. Default fetch redirect-following would silently follow a
 * public URL to e.g. http://169.254.169.254/ (cloud metadata) — an SSRF the initial
 * string guard cannot see. With redirect:'manual' we read each Location and re-guard
 * it before continuing. A blocked hop throws RemoteImageBlockedError (caught upstream
 * → graceful placeholder). Mocked fetchImpls can return 3xx + Location to exercise this.
 */
async function fetchFollowingSafeRedirects(
  fetchFn: typeof fetch,
  startUrl: URL,
  allowedSchemes: readonly string[],
  signal: AbortSignal
): Promise<Response> {
  let current = startUrl;
  for (let hop = 0; ; hop++) {
    const res = await fetchFn(current.toString(), { signal, redirect: 'manual' });
    if (!isRedirectStatus(res.status)) return res;

    const location = res.headers.get('location');
    if (!location) return res; // 3xx without Location — treated as a non-success upstream.
    if (hop >= MAX_IMAGE_REDIRECTS) {
      throw new RemoteImageBlockedError(`too many redirects (> ${MAX_IMAGE_REDIRECTS})`);
    }

    let next: URL;
    try {
      next = new URL(location, current);
    } catch {
      throw new RemoteImageBlockedError(`unparseable redirect target: ${truncate(location)}`);
    }
    current = assertPublicImageUrl(next.toString(), allowedSchemes);
  }
}

/**
 * Public check for whether a RESOLVED IP address is internal/loopback/link-local,
 * reusing the same tables as the string guard. Used by the default-fetch wrapper to
 * validate DNS-name targets against their resolved IPs (closes the DNS-name → private
 * IP vector the string-only guard misses). family is the Node dns lookup family (4|6).
 */
export function isInternalResolvedIp(address: string, family: number): boolean {
  return family === 6 ? isInternalIpv6(address.toLowerCase()) : isInternalIpv4(address);
}

/**
 * Wrap a fetch impl so DNS-name targets are validated against their RESOLVED IPs before
 * connecting — closes the "public hostname that resolves to a private IP" SSRF that the
 * string-only guard cannot see. Literal IPs are already validated by assertPublicImageUrl,
 * so only real hostnames are resolved. Applied per call, so each redirect hop (fetched
 * individually) is validated too. Only the DEFAULT (globalThis.fetch) path is wrapped;
 * an injected fetchImpl owns its own transport/SSRF posture.
 *
 * Residual: TOCTOU/DNS-rebinding between this lookup and the kernel connect is not closed
 * (that needs a connect-time `lookup` hook on the dispatcher); acceptable for this tool's
 * primary use (converting the operator's own captured sites), documented in the spec.
 */
export function createPublicHostGuardedFetch(
  baseFetch: typeof fetch,
  lookup: HostLookup = defaultHostLookup
): typeof fetch {
  return (async (input: Parameters<typeof fetch>[0], init?: Parameters<typeof fetch>[1]) => {
    const target =
      typeof input === 'string'
        ? input
        : input instanceof URL
          ? input.toString()
          : (input as Request).url;
    let host = '';
    try {
      host = new URL(target).hostname.replace(/^\[|\]$/g, '').toLowerCase();
    } catch {
      host = '';
    }
    if (host && !literalIp(host)) {
      const results = await lookup(host);
      for (const { address, family } of results) {
        if (isInternalResolvedIp(address, family)) {
          throw new RemoteImageBlockedError(`host ${host} resolves to internal IP ${address}`);
        }
      }
    }
    return baseFetch(input, init);
  }) as typeof fetch;
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

  if (h.includes(':') && /^[0-9a-f:]+$/i.test(h)) {
    return { kind: 'ipv6', value: h.toLowerCase() };
  }

  return null;
}

function isInternalIpv4(ip: string): boolean {
  const [a, b] = ip.split('.').map((part) => Number(part));
  if (a === 0) return true;
  if (a === 10) return true;
  if (a === 127) return true;
  if (a === 169 && b === 254) return true;
  if (a === 172 && b >= 16 && b <= 31) return true;
  if (a === 192 && b === 168) return true;
  if (a === 100 && b >= 64 && b <= 127) return true;
  if (a >= 224) return true;
  return false;
}

function isInternalIpv6(ip: string): boolean {
  const value = ip.toLowerCase();
  if (value === '::' || value === '0:0:0:0:0:0:0:0') return true;
  if (value === '::1' || value === '0:0:0:0:0:0:0:1') return true;
  if (value.startsWith('fc') || value.startsWith('fd')) return true;
  if (value.startsWith('fe8') || value.startsWith('fe9') || value.startsWith('fea') || value.startsWith('feb')) {
    return true;
  }
  if (value.startsWith('ff')) return true;

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

function isSuccess(res: Response): boolean {
  return res.status >= 200 && res.status < 300;
}

function imageContentType(res: Response): string | null {
  const header = res.headers.get('content-type') ?? '';
  const contentType = header.split(';', 1)[0]?.trim().toLowerCase() ?? '';
  if (!contentType.startsWith('image/')) return null;
  // SVG is active content: it can embed <script> and event handlers. Self-hosting an
  // attacker-supplied SVG and serving it same-origin from the theme is a stored-XSS
  // vector, so refuse to carry it (degrades to the placeholder like any other miss).
  if (contentType === 'image/svg+xml') return null;
  return contentType;
}

async function readCappedBytes(
  res: Response,
  maxBytes: number,
  abortFetch: () => void
): Promise<Uint8Array> {
  if (res.body) {
    const reader = res.body.getReader();
    const chunks: Uint8Array[] = [];
    let total = 0;

    try {
      for (;;) {
        const { done, value } = await reader.read();
        if (done) break;
        if (!value) continue;

        const chunk = toUint8Array(value);
        total += chunk.byteLength;
        if (total > maxBytes) {
          abortFetch();
          throw new Error(`image response exceeds max bytes (${maxBytes})`);
        }
        chunks.push(chunk);
      }
    } finally {
      reader.releaseLock();
    }

    return concatBytes(chunks, total);
  }

  const bytes = new Uint8Array(await res.arrayBuffer());
  if (bytes.byteLength > maxBytes) {
    abortFetch();
    throw new Error(`image response exceeds max bytes (${maxBytes})`);
  }
  return bytes;
}

function toUint8Array(value: Uint8Array): Uint8Array {
  return value instanceof Uint8Array ? value : new Uint8Array(value);
}

function concatBytes(chunks: Uint8Array[], total: number): Uint8Array {
  if (chunks.length === 1) return chunks[0];

  const out = new Uint8Array(total);
  let offset = 0;
  for (const chunk of chunks) {
    out.set(chunk, offset);
    offset += chunk.byteLength;
  }
  return out;
}

function sortedPages<T extends RemoteImagePage>(pages: T[]): T[] {
  return [...pages].sort((a, b) => {
    const rel = (a.relPath ?? '').localeCompare(b.relPath ?? '');
    if (rel !== 0) return rel;
    return a.slug.localeCompare(b.slug);
  });
}

function extractRemoteImgSrcRefs(html: string): string[] {
  const refs: string[] = [];

  for (const tagMatch of html.matchAll(IMG_TAG_RE)) {
    const srcAttr = FIRST_SRC_ATTR_RE.exec(tagMatch[0]);
    const ref = srcAttr?.[3];
    if (ref && normalizeRemoteImageRef(ref)) refs.push(ref);
  }

  return refs;
}

function normalizeRemoteImageRef(ref: string): string | null {
  const decoded = decodeHtmlRef(ref).trim();
  if (!/^https?:\/\//i.test(decoded)) return null;

  try {
    const url = new URL(decoded);
    url.hash = '';
    return url.toString();
  } catch {
    return null;
  }
}

function preferredImageFilename(url: URL, contentType: string): string {
  const basename = sanitizedUrlBasename(url);
  if (basename) return basename;

  const ext = imageExtension(contentType);
  return `image.${ext}`;
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

function imageExtension(contentType: string): string {
  const subtype = contentType.split('/', 2)[1] ?? '';
  switch (subtype) {
    case 'jpeg':
    case 'pjpeg':
      return 'jpg';
    case 'svg+xml':
      return 'svg';
    case 'x-icon':
    case 'vnd.microsoft.icon':
      return 'ico';
    case 'tiff':
      return 'tif';
    default: {
      const clean = subtype.replace(/\+xml$/, '').replace(/[^A-Za-z0-9]+/g, '');
      return clean || 'bin';
    }
  }
}

function allocateThemeRel(
  preferredFilename: string,
  sourceUrl: string,
  relPathOwners: Map<string, string>
): string {
  const preferred = `${IMG_DIR}/${preferredFilename}`;
  const existing = relPathOwners.get(preferred);
  if (!existing) {
    relPathOwners.set(preferred, sourceUrl);
    return preferred;
  }
  if (existing === sourceUrl) return preferred;

  const ext = extname(preferred);
  const base = ext ? preferred.slice(0, -ext.length) : preferred;
  for (let index = 2; ; index++) {
    const candidate = `${base}-${index}${ext}`;
    const owner = relPathOwners.get(candidate);
    if (!owner) {
      relPathOwners.set(candidate, sourceUrl);
      return candidate;
    }
    if (owner === sourceUrl) return candidate;
  }
}

function sortAssetFiles(files: AssetFile[]): AssetFile[] {
  return [...files].sort((a, b) => a.relPath.localeCompare(b.relPath));
}

function decodeHtmlRef(ref: string): string {
  return ref
    .replace(/&amp;/gi, '&')
    .replace(/&#38;/g, '&')
    .replace(/&#x26;/gi, '&');
}

function safeDecodeURIComponent(value: string): string {
  try {
    return decodeURIComponent(value);
  } catch {
    return value;
  }
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
