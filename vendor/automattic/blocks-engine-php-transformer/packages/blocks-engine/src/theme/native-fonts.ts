import type { FontFamilyToken } from './page-reconstruct-helpers.js';

function familyHash(s: string): string | null {
  const m = s.match(/[0-9a-f]{10,}/i);
  return m ? m[0].toLowerCase() : null;
}

export function familyMatches(computed: string, token: FontFamilyToken): boolean {
  const first = (token.family || '').split(',')[0].replace(/["']/g, '').trim().toLowerCase();
  const slug = (token.slug || '').toLowerCase();
  if (first && first === computed) return true;
  const ch = familyHash(computed);
  if (ch) {
    const th = familyHash(token.family) || familyHash(slug);
    if (th && (th.includes(ch) || ch.includes(th))) return true;
  }
  if (first && first.length >= 4 && (computed.includes(first) || first.includes(computed))) return true;
  return false;
}

export function nearestFamily(computed: string | undefined, tokens: FontFamilyToken[]): string | null {
  if (!computed || tokens.length === 0) return null;
  const c = computed.replace(/["']/g, '').trim().toLowerCase();
  if (!c || c === 'inherit' || c === 'sans-serif' || c === 'serif') return null;
  const display = tokens.find((t) => t.slug === 'display');
  const body = tokens.find((t) => t.slug === 'body');
  if (body && familyMatches(c, body)) return 'body';
  if (display && familyMatches(c, display)) return 'display';
  if (body) return 'body';
  return null;
}
