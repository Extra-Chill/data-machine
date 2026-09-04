import type { SectionSpec } from './section-spec.js';

export interface SectionPadding {
  padTopPx?: number;
  padBottomPx?: number;
}

/**
 * A responsive font size that equals the captured px at a 1440px viewport and
 * scales down on mobile.
 */
export function responsiveFontSize(px: number | undefined): string {
  if (!px || px <= 0) return '';
  const floor = Math.min(px, Math.max(16, Math.round(px * 0.5)));
  const vw = (px / 14.4).toFixed(1);
  return `clamp(${floor}px, ${vw}vw, ${px}px)`;
}

export function responsiveSpace(px: number): string {
  const p = Math.max(0, Math.round(px));
  if (p < 24) return `${p}px`;
  const floor = Math.max(16, Math.round(p * 0.45));
  const vw = (p / 14.4).toFixed(2);
  return `clamp(${floor}px, ${vw}vw, ${p}px)`;
}

export function sectionPad(section: SectionSpec): SectionPadding {
  const t = section.layout?.padTopPx;
  const b = section.layout?.padBottomPx;
  return {
    ...(typeof t === 'number' ? { padTopPx: t } : {}),
    ...(typeof b === 'number' ? { padBottomPx: b } : {}),
  };
}

export function centerOf(section: SectionSpec): boolean {
  return section.textAlign == null ? true : section.textAlign === 'center';
}

export function buttonJustify(section: SectionSpec): 'left' | 'center' {
  return centerOf(section) ? 'center' : 'left';
}

export function isTintedSection(section: SectionSpec): boolean {
  const b = section.backgroundBrightness;
  if (b >= 245 || b < 100) return false;
  const m = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(section.backgroundColor || '');
  if (!m) return false;
  const sat = Math.max(+m[1], +m[2], +m[3]) - Math.min(+m[1], +m[2], +m[3]);
  return sat >= 25;
}

export function opaqueTintHex(color: string | null | undefined): string | null {
  if (!color) return null;
  const s = color.trim();
  const rgba = /rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*([\d.]+))?\s*\)/.exec(s);
  let r: number, g: number, b: number;
  if (rgba) {
    const alpha = rgba[4] === undefined ? 1 : Number(rgba[4]);
    if (alpha < 0.6) return null;
    [r, g, b] = [Number(rgba[1]), Number(rgba[2]), Number(rgba[3])];
  } else {
    const hex = /^#?([0-9a-f]{6})$/i.exec(s);
    if (!hex) return null;
    r = parseInt(hex[1].slice(0, 2), 16);
    g = parseInt(hex[1].slice(2, 4), 16);
    b = parseInt(hex[1].slice(4, 6), 16);
  }
  const bright = (r + g + b) / 3;
  if (bright >= 248) return null;
  const spread = Math.max(r, g, b) - Math.min(r, g, b);
  if (spread <= 6 && bright >= 230) return null;
  return '#' + [r, g, b].map((n) => n.toString(16).padStart(2, '0')).join('');
}

export function isDarkSection(section: SectionSpec): boolean {
  return section.backgroundBrightness < 100;
}
