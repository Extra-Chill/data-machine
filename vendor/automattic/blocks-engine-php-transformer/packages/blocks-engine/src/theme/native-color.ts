export interface PaletteToken {
  slug: string;
  hex: string;
}

function parseHex(color: string): [number, number, number] | null {
  const s = color.trim();
  const rgb = /^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i.exec(s);
  if (rgb) return [Number(rgb[1]), Number(rgb[2]), Number(rgb[3])];
  const m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(s);
  if (!m) return null;
  let h = m[1];
  if (h.length === 3) h = h.split('').map((c) => c + c).join('');
  return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
}

export function nearestToken(hex: string, tokens: PaletteToken[]): string | null {
  const c = parseHex(hex);
  if (!c) return null;
  let best: string | null = null;
  let bestD = Infinity;
  for (const t of tokens) {
    const tc = parseHex(t.hex);
    if (!tc) continue;
    const d = (c[0] - tc[0]) ** 2 + (c[1] - tc[1]) ** 2 + (c[2] - tc[2]) ** 2;
    if (d < bestD) {
      bestD = d;
      best = t.slug;
    }
  }
  return best;
}

export function brightness(hex: string): number {
  const c = parseHex(hex);
  if (!c) return 255;
  return Math.round(0.299 * c[0] + 0.587 * c[1] + 0.114 * c[2]);
}
