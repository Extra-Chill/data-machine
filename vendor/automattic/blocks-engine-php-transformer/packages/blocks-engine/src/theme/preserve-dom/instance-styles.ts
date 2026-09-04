import { createHash } from 'node:crypto';

/**
 * Canonicalize a CSS declaration string for content-addressed dedup: split on
 * ';', trim each declaration, collapse internal whitespace, normalize the
 * `prop: value` spacing, drop empties, rejoin with ';'. So 'a: 1px ;  b:2px'
 * and 'a:1px;b:2px' produce the same key.
 */
export function normalizeDeclarations(style: string): string {
  return style
    .split(';')
    .map((decl) => decl.trim().replace(/\s+/g, ' '))
    .filter(Boolean)
    .map((decl) => {
      const i = decl.indexOf(':');
      if (i < 0) return decl;
      return `${decl.slice(0, i).trim()}:${decl.slice(i + 1).trim()}`;
    })
    .join(';');
}

export class InstanceStyleSheet {
  private readonly rules = new Map<string, string>();

  classFor(style: string | undefined | null): string | null {
    const decls = normalizeDeclarations(style ?? '');
    if (!decls) return null;
    const cls = `lib-i${createHash('sha1').update(decls).digest('hex').slice(0, 10)}`;
    this.rules.set(cls, decls);
    return cls;
  }

  get size(): number {
    return this.rules.size;
  }

  toCss(): string {
    return [...this.rules.entries()]
      .sort((a, b) => (a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : 0))
      .map(([cls, decls]) => `.${cls}{${decls}}`)
      .join('\n');
  }
}
