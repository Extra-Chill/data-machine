export type InternalLinkMap = Map<string, string>;

export function rewriteMediaUrls(
  input: string,
  mapping: Map<string, string>,
  opts: { onMissing?: (sourceUrl: string) => void } = {},
): string {
  if (!input || mapping.size === 0) return input;

  const aliasIndex = buildMediaAliasIndex(mapping);
  const replacements = new Map(mapping);
  const seen = new Set<string>();
  const candidates = collectMediaCandidates(input);
  for (const candidate of candidates) {
    if (seen.has(candidate)) continue;
    seen.add(candidate);

    const local = resolveLocalUrl(candidate, mapping, aliasIndex);
    if (local) {
      replacements.set(candidate, local);
    } else if (opts.onMissing) {
      opts.onMissing(candidate);
    }
  }

  let out = input;
  const ordered = [...replacements.entries()]
    .filter(([source]) => source)
    .sort((a, b) => b[0].length - a[0].length);
  for (const [source, local] of ordered) {
    out = out.replace(new RegExp(escapeRegex(source), 'g'), () => local);
  }

  return out;
}

const URL_LIKE = /https?:\/\/[^\s"'<>\\]+/g;

function collectMediaCandidates(input: string): string[] {
  const candidates: string[] = [];
  const attrPatterns: RegExp[] = [
    /<img[^>]*\bsrc\s*=\s*["']([^"']+)["']/gi,
    /<a[^>]*\bhref\s*=\s*["']([^"']+\.(?:jpe?g|png|gif|webp|svg|avif|mp4|webm|pdf))["']/gi,
    /\bsrcset\s*=\s*["']([^"']+)["']/gi,
    /"src"\s*:\s*"([^"]+)"/g,
    /"url"\s*:\s*"([^"]+)"/g,
  ];
  for (const re of attrPatterns) {
    let m: RegExpExecArray | null;
    while ((m = re.exec(input)) !== null) {
      const value = m[1];
      if (re.source.includes('srcset')) {
        let urlMatch: RegExpExecArray | null;
        const urlRe = new RegExp(URL_LIKE.source, 'g');
        while ((urlMatch = urlRe.exec(value)) !== null) {
          candidates.push(urlMatch[0]);
        }
      } else {
        candidates.push(value);
      }
    }
  }

  return candidates
    .map((c) => c.match(URL_LIKE)?.[0] ?? c)
    .filter((c) => /^https?:\/\//i.test(c));
}

interface MediaAliasRecord {
  local: string;
  score: number;
}

function buildMediaAliasIndex(mapping: Map<string, string>): Map<string, MediaAliasRecord> {
  const out = new Map<string, MediaAliasRecord>();
  for (const [source, local] of mapping.entries()) {
    const score = mediaVariantScore(source);
    for (const key of mediaAliasKeys(source)) {
      const existing = out.get(key);
      if (!existing || score > existing.score) {
        out.set(key, { local, score });
      }
    }
  }
  return out;
}

function resolveLocalUrl(
  source: string,
  mapping: Map<string, string>,
  aliasIndex: Map<string, MediaAliasRecord>,
): string | undefined {
  const exact = mapping.get(source);
  if (exact) return exact;

  for (const key of mediaAliasKeys(source)) {
    const aliased = aliasIndex.get(key);
    if (aliased) return aliased.local;
  }

  return undefined;
}

function mediaAliasKeys(source: string): string[] {
  const keys: string[] = [];
  const parsed = parseHttpUrl(source);
  if (!parsed) return keys;

  if (parsed.hostname === 'static.wixstatic.com') {
    const asset = wixMediaAssetId(parsed);
    if (asset) keys.push(`wix:${asset}`);
  }

  return keys;
}

function wixMediaAssetId(url: URL): string | undefined {
  const parts = url.pathname.split('/').filter(Boolean);
  const mediaIndex = parts.indexOf('media');
  if (mediaIndex === -1 || mediaIndex + 1 >= parts.length) return undefined;
  return decodeURIComponent(parts[mediaIndex + 1]);
}

function mediaVariantScore(source: string): number {
  const match = source.match(/\bw_(\d+),h_(\d+)/i);
  if (!match) return 0;
  return Number(match[1]) * Number(match[2]);
}

function parseHttpUrl(source: string): URL | undefined {
  try {
    const parsed = new URL(source);
    if (!/^https?:$/i.test(parsed.protocol)) return undefined;
    return parsed;
  } catch {
    return undefined;
  }
}

function escapeRegex(input: string): string {
  return input.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

const SKIP_SCHEME = /^(?:mailto:|tel:|javascript:|data:|sms:|geo:|callto:)/i;

interface Candidate {
  key: string | null;
  fragment: string;
  internalRelative: boolean;
}

function normalizePath(pathname: string): string {
  let p = pathname;
  try {
    p = decodeURIComponent(p);
  } catch {
    // Leave malformed percent sequences as-is.
  }
  p = p.replace(/\.html?$/i, '');
  if (!p.startsWith('/')) p = '/' + p;
  if (p !== '/') p = p.replace(/\/+$/, '');
  if (p === '') p = '/';
  return p.toLowerCase();
}

function normalizeHost(host: string): string {
  return host.toLowerCase().replace(/^www\./, '');
}

function analyzeHref(rawHref: string): Candidate {
  const href = rawHref.trim();
  const none: Candidate = { key: null, fragment: '', internalRelative: false };
  if (!href || SKIP_SCHEME.test(href)) return none;
  if (href.startsWith('#')) return none;

  if (/^https?:\/\//i.test(href) || href.startsWith('//')) {
    let url: URL;
    try {
      url = new URL(href.startsWith('//') ? `https:${href}` : href);
    } catch {
      return none;
    }
    const key = `${normalizeHost(url.hostname)}${normalizePath(url.pathname)}`;
    return { key, fragment: url.hash, internalRelative: false };
  }

  const hashIdx = href.indexOf('#');
  const fragment = hashIdx >= 0 ? href.slice(hashIdx) : '';
  let pathPart = hashIdx >= 0 ? href.slice(0, hashIdx) : href;
  const queryIdx = pathPart.indexOf('?');
  if (queryIdx >= 0) pathPart = pathPart.slice(0, queryIdx);
  pathPart = pathPart.replace(/^(?:\.\.?\/)+/, '');
  return { key: normalizePath(pathPart), fragment, internalRelative: true };
}

export function rewriteInternalLinks(
  input: string,
  map: InternalLinkMap,
  opts: { onMissing?: (href: string) => void } = {},
): string {
  if (!input || map.size === 0) return input;

  const warned = new Set<string>();
  return input.replace(/\bhref\s*=\s*(["'])([^"']*)\1/gi, (whole, quote: string, value: string) => {
    const { key, fragment, internalRelative } = analyzeHref(value);
    if (key === null) return whole;
    const target = map.get(key);
    if (target) {
      return `href=${quote}${target}${fragment}${quote}`;
    }
    if (internalRelative && opts.onMissing && !warned.has(value)) {
      warned.add(value);
      opts.onMissing(value);
    }
    return whole;
  });
}
