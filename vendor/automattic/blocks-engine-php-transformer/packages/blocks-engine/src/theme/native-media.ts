import type { NativeRenderCtx, NativeRenderOut } from './native-reconstruct-types.js';
import { sanitizeSvgAsset } from './page-reconstruct-helpers.js';
import type { SectionSpecIcon, SectionSpecImage } from './section-spec.js';

export const MISSING_IMAGE_PLACEHOLDER = '[image unavailable — not captured]';
const MIN_LEAD_IMAGE_PX = 200;

export interface ResolvedNativeImage {
  url: string;
  alt: string;
  usable: boolean;
}

export interface NativeImageResolutionContext {
  mediaUrlMap?: Map<string, string>;
}

export interface IconImageBlockOptions {
  sizePx?: number;
  fill?: string;
  align?: 'left' | 'center' | 'right';
}

export function pickLeadImage(images: SectionSpecImage[]): SectionSpecImage | undefined {
  return images.find((image) => Math.min(image.width || 0, image.height || 0) >= MIN_LEAD_IMAGE_PX);
}

export function isWpMediaUrl(url: string): boolean {
  return /\/wp-content\/uploads\//i.test(url);
}

function mappedMediaUrl(
  image: SectionSpecImage | undefined,
  resolutionContext?: NativeImageResolutionContext,
): string | null {
  const mediaUrlMap = resolutionContext?.mediaUrlMap;
  if (!image || !mediaUrlMap?.size) return null;

  const keys = [image.url, image.sourceUrl].filter(Boolean);
  for (const key of keys) {
    const mapped = mediaUrlMap.get(key);
    if (mapped) return mapped;
  }
  return null;
}

export function resolveNativeImageUrl(
  image: SectionSpecImage | undefined,
  resolutionContext?: NativeImageResolutionContext,
): string | null {
  if (!image) return null;
  const mapped = mappedMediaUrl(image, resolutionContext);
  if (mapped) return mapped;
  return isWpMediaUrl(image.url) ? image.url : null;
}

export function isUsableNativeImage(
  image: SectionSpecImage | undefined,
  resolutionContext?: NativeImageResolutionContext,
): boolean {
  return resolveNativeImageUrl(image, resolutionContext) !== null;
}

export function recolorSvg(svg: string, hex: string): string {
  const stripped = svg
    .replace(/\sfill="(?!none")[^"]*"/gi, '')
    .replace(/\sstroke="(?!none")[^"]*"/gi, '');
  return stripped.replace(/<svg\b/i, `<svg fill="${hex}"`);
}

export function resolveImage(
  image: SectionSpecImage | undefined,
  out: NativeRenderOut,
  context: string,
  resolutionContext?: NativeImageResolutionContext,
): ResolvedNativeImage {
  if (!image) {
    out.flags.push(`${context}: no image in spec — placeholder emitted`);
    return { url: '', alt: MISSING_IMAGE_PLACEHOLDER, usable: false };
  }
  const url = resolveNativeImageUrl(image, resolutionContext);
  if (!url) {
    out.flags.push(`${context}: image not in WP library (${image.sourceUrl}) — placeholder emitted`);
    return { url: '', alt: MISSING_IMAGE_PLACEHOLDER, usable: false };
  }
  return { url, alt: image.alt || '', usable: true };
}

export function iconImageBlock(
  icon: SectionSpecIcon,
  out: NativeRenderOut,
  ctx: NativeRenderCtx,
  opts?: IconImageBlockOptions,
): string {
  const sizePx = opts?.sizePx ?? 48;
  if (icon.kind !== 'svg' || !icon.markup) return '';
  let svg = sanitizeSvgAsset(icon.markup);
  if (!svg || !/<svg[\s>]/i.test(svg)) return '';
  if (opts?.fill) svg = recolorSvg(svg, opts.fill);
  const path = `assets/icon-${ctx.iconCounter++}.svg`;
  out.iconAssets.push({ path, svg });
  const src = `<?php echo esc_url(get_theme_file_uri('${path}')); ?>`;
  const align = opts?.align ?? 'center';
  const alignAttr = align === 'center' ? ',"align":"center"' : align === 'right' ? ',"align":"right"' : '';
  const alignClass = align === 'center' ? ' aligncenter' : align === 'right' ? ' alignright' : '';
  return (
    `<!-- wp:image {"width":"${sizePx}px","height":"${sizePx}px","sizeSlug":"full"${alignAttr}} -->\n` +
    `<figure class="wp-block-image${alignClass} size-full is-resized"><img src="${src}" alt="" style="width:${sizePx}px;height:${sizePx}px"/></figure>\n` +
    `<!-- /wp:image -->`
  );
}
