import type { NativeRenderCtx, NativeRenderOut } from './native-reconstruct-types.js';
import type { SectionSpec, SectionSpecIcon, SectionSpecImage } from './section-spec.js';
import { escapeHtml } from '../escape.js';
import { normalizeCopy, sanitizeSvgAsset } from './page-reconstruct-helpers.js';
import { brightness, nearestToken } from './native-color.js';
import { buttonJustify, opaqueTintHex, responsiveFontSize, responsiveSpace } from './native-layout.js';
import {
  MISSING_IMAGE_PLACEHOLDER,
  recolorSvg,
  resolveImage,
  type NativeImageResolutionContext,
} from './native-media.js';

export interface TypographyFragments {
  attr: string;
  inline: string;
}

export interface HeadingBlockOptions {
  level?: number;
  center?: boolean;
  muted?: boolean;
  inverse?: boolean;
  sizePx?: number;
  fontFamily?: string | null;
  lineHeight?: number;
}

export interface ParagraphBlockOptions {
  center?: boolean;
  muted?: boolean;
  size?: string;
  inverse?: boolean;
  sizePx?: number;
  fontFamily?: string | null;
  lineHeight?: number;
}

export interface ImageBlockOptions {
  rounded?: boolean;
  align?: 'center' | null;
}

export interface NativeButtonInput {
  label: string;
  href?: string;
  background?: string | null;
  color?: string | null;
  icon?: SectionSpecIcon | null;
  iconAfter?: boolean;
}

export interface CtaButtonOptions {
  align?: 'left' | 'center';
}

export interface WrapSectionOptions {
  constrained?: string;
  wide?: string;
  center?: boolean;
  raised?: boolean;
  inverse?: boolean;
  bgColor?: string;
  padTopPx?: number;
  padBottomPx?: number;
  fullBleed?: boolean;
}

export function visibleText(html: string): string {
  return html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
}

export function emptyNativeRenderOut(): NativeRenderOut {
  return { markup: '', expectedText: [], bodyText: [], assets: [], flags: [], iconAssets: [] };
}

export function typographyStyle(fontCss: string, lineHeight?: number): TypographyFragments {
  const attrParts: string[] = [];
  const inlineParts: string[] = [];
  if (fontCss) {
    attrParts.push(`"fontSize":"${fontCss}"`);
    inlineParts.push(`font-size:${fontCss}`);
  }
  if (typeof lineHeight === 'number' && lineHeight >= 0.8 && lineHeight <= 2.4) {
    attrParts.push(`"lineHeight":"${lineHeight}"`);
    inlineParts.push(`line-height:${lineHeight}`);
  }
  return {
    attr: attrParts.length ? `"style":{"typography":{${attrParts.join(',')}}},` : '',
    inline: inlineParts.length ? ` style="${inlineParts.join(';')}"` : '',
  };
}

export function imageBlock(
  image: SectionSpecImage | undefined,
  out: NativeRenderOut,
  context: string,
  opts?: ImageBlockOptions,
  resolutionContext?: NativeImageResolutionContext,
): string {
  const options = opts ?? {};
  const resolved = resolveImage(image, out, context, resolutionContext);
  const roundStyle = options.rounded ? ',"style":{"border":{"radius":"12px"}}' : '';
  const roundClass = options.rounded ? ' has-custom-border' : '';
  const alignAttr = options.align === 'center' ? ',"align":"center"' : '';
  const alignClass = options.align === 'center' ? ' aligncenter' : '';
  if (!resolved.usable) {
    return (
      `<!-- wp:paragraph {"align":"center","textColor":"text-subtle","fontSize":"small"} -->\n` +
      `<p class="has-text-align-center has-text-subtle-color has-text-color has-small-font-size">${escapeHtml(
        MISSING_IMAGE_PLACEHOLDER,
      )}</p>\n<!-- /wp:paragraph -->`
    );
  }
  out.assets.push(resolved.url);
  const width = image && image.width && image.height ? Math.round(image.width) : 0;
  const widthAttr = width ? `,"width":"${width}px"` : '';
  const resizedClass = width ? ' is-resized' : '';
  const dimStyle = width ? `width:${width}px;max-width:100%;height:auto;` : '';
  const borderStyle = options.rounded ? 'border-radius:12px;' : '';
  const imgStyle = dimStyle || borderStyle ? ` style="${dimStyle}${borderStyle}"` : '';
  return (
    `<!-- wp:image {"sizeSlug":"large"${widthAttr}${alignAttr}${roundStyle}} -->\n` +
    `<figure class="wp-block-image${alignClass} size-large${roundClass}${resizedClass}"><img src="${escapeHtml(
      resolved.url,
    )}" alt="${escapeHtml(resolved.alt)}"${imgStyle}/></figure>\n` +
    `<!-- /wp:image -->`
  );
}

export function headingBlock(text: string, out: NativeRenderOut, opts?: HeadingBlockOptions): string {
  const options = opts ?? {};
  const normalized = normalizeCopy(text);
  if (!normalized) return '';
  out.expectedText.push(normalized);
  const level = options.level ?? 2;
  const centerAttr = options.center ? '"textAlign":"center",' : '';
  const centerClass = options.center ? ' has-text-align-center' : '';
  const colorSlug = options.inverse ? 'text-inverse' : options.muted ? 'text-muted' : 'text-default';
  const fontCss = responsiveFontSize(options.sizePx);
  const typography = typographyStyle(fontCss, options.lineHeight);
  const familySlug = options.fontFamily || 'display';
  return (
    `<!-- wp:heading {${centerAttr}${typography.attr}"level":${level},"fontFamily":"${familySlug}","textColor":"${colorSlug}"} -->\n` +
    `<h${level} class="wp-block-heading${centerClass} has-${colorSlug}-color has-text-color has-${familySlug}-font-family"${typography.inline}>${escapeHtml(
      normalized,
    )}</h${level}>\n<!-- /wp:heading -->`
  );
}

export function paragraphBlock(text: string, out: NativeRenderOut, opts?: ParagraphBlockOptions): string {
  const options = opts ?? {};
  const normalized = normalizeCopy(text);
  if (!normalized) return '';
  out.bodyText.push(normalized);
  const centerAttr = options.center ? '"align":"center",' : '';
  const centerClass = options.center ? 'has-text-align-center ' : '';
  const colorSlug = options.inverse ? 'text-inverse' : options.muted === false ? 'text-default' : 'text-muted';
  const px = options.sizePx && options.sizePx >= 11 && options.sizePx <= 32 ? options.sizePx : 0;
  const fontCss = responsiveFontSize(px);
  const typography = typographyStyle(fontCss, options.lineHeight);
  const sizeAttr = !fontCss && options.size ? `"fontSize":"${options.size}",` : '';
  const sizeClass = !fontCss && options.size ? ` has-${options.size}-font-size` : '';
  const familyAttr = options.fontFamily ? `"fontFamily":"${options.fontFamily}",` : '';
  const familyClass = options.fontFamily ? ` has-${options.fontFamily}-font-family` : '';
  return (
    `<!-- wp:paragraph {${centerAttr}${typography.attr}${sizeAttr}${familyAttr}"textColor":"${colorSlug}"} -->\n` +
    `<p class="${centerClass}has-${colorSlug}-color has-text-color${sizeClass}${familyClass}"${typography.inline}>${escapeHtml(
      normalized,
    )}</p>\n<!-- /wp:paragraph -->`
  );
}

export function buttonBlock(
  label: string,
  out: NativeRenderOut,
  opts?: { align?: 'left' | 'center' | 'right' },
): string {
  const normalized = normalizeCopy(label);
  if (!normalized) return '';
  out.expectedText.push(normalized);
  const justify = opts?.align ?? 'center';
  const justifyClass = ` is-content-justification-${justify}`;
  return (
    `<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"${justify}"}} -->\n` +
    `<div class="wp-block-buttons${justifyClass}">\n` +
    `<!-- wp:button {"backgroundColor":"accent-primary","textColor":"text-inverse"} -->\n` +
    `<div class="wp-block-button"><a class="wp-block-button__link has-text-inverse-color has-accent-primary-background-color has-text-color has-background wp-element-button">${escapeHtml(
      normalized,
    )}</a></div>\n` +
    `<!-- /wp:button -->\n</div>\n<!-- /wp:buttons -->`
  );
}

export function ctaButton(
  out: NativeRenderOut,
  ctx: NativeRenderCtx,
  button: NativeButtonInput,
  opts?: CtaButtonOptions,
): string {
  const normalized = normalizeCopy(button.label);
  if (!normalized && !button.icon) return '';
  if (normalized) out.expectedText.push(normalized);
  const justify = opts?.align ?? 'center';
  const justifyClass = ` is-content-justification-${justify}`;
  const bgToken = button.background ? nearestToken(button.background, ctx.paletteTokens) : null;
  const textToken = button.color ? nearestToken(button.color, ctx.paletteTokens) : null;
  const bg = bgToken ?? 'accent-primary';
  let textColor: string;
  if (textToken) {
    textColor = textToken;
  } else {
    const bgHex = ctx.paletteTokens.find((token) => token.slug === bg)?.hex;
    textColor = bgHex && brightness(bgHex) >= 140 ? 'text-default' : 'text-inverse';
  }

  let iconImg = '';
  if (button.icon && button.icon.kind === 'svg' && button.icon.markup) {
    let svg = sanitizeSvgAsset(button.icon.markup);
    if (svg && /<svg[\s>]/i.test(svg)) {
      const fillHex = button.color ? rgbToHex(button.color) : null;
      if (fillHex) svg = recolorSvg(svg, fillHex);
      const path = `assets/icon-${ctx.iconCounter++}.svg`;
      out.iconAssets.push({ path, svg });
      const src = `<?php echo esc_url(get_theme_file_uri('${path}')); ?>`;
      const margin = button.iconAfter ? 'margin-left:8px' : 'margin-right:8px';
      iconImg = `<img src="${src}" alt="" style="width:18px;height:18px;vertical-align:middle;${margin}"/>`;
    }
  }
  const href = button.href ? ` href="${escapeHtml(button.href)}"` : '';
  const inner = button.iconAfter ? `${escapeHtml(normalized)}${iconImg}` : `${iconImg}${escapeHtml(normalized)}`;
  return (
    `<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"${justify}"}} -->\n` +
    `<div class="wp-block-buttons${justifyClass}">\n` +
    `<!-- wp:button {"backgroundColor":"${bg}","textColor":"${textColor}"} -->\n` +
    `<div class="wp-block-button"><a class="wp-block-button__link has-${textColor}-color has-${bg}-background-color has-text-color has-background wp-element-button"${href}>${inner}</a></div>\n` +
    `<!-- /wp:button -->\n</div>\n<!-- /wp:buttons -->`
  );
}

export function sectionButtons(section: SectionSpec, out: NativeRenderOut, ctx: NativeRenderCtx): string[] {
  const align = buttonJustify(section);
  if (section.buttons && section.buttons.length) {
    return section.buttons.map((button) => ctaButton(out, ctx, button, { align })).filter(Boolean);
  }
  return section.buttonLabels.map((label) => ctaButton(out, ctx, { label }, { align })).filter(Boolean);
}

export function column(parts: string[], width?: string): string {
  const widthAttr = width ? `{"width":"${width}"}` : '';
  const widthStyle = width ? ` style="flex-basis:${width}"` : '';
  return (
    `<!-- wp:column ${widthAttr} -->\n` +
    `<div class="wp-block-column"${widthStyle}>\n${parts.join('\n')}\n</div>\n` +
    `<!-- /wp:column -->`
  );
}

export function columns(cols: string[], opts?: { fullBleed?: boolean }): string {
  const options = opts ?? {};
  if (cols.length === 0) return '';
  const attr = options.fullBleed
    ? `"verticalAlignment":"center","align":"full","style":{"spacing":{"blockGap":"0"}}`
    : `"verticalAlignment":"center"`;
  const cls = options.fullBleed
    ? 'wp-block-columns alignfull are-vertically-aligned-center'
    : 'wp-block-columns are-vertically-aligned-center';
  return (
    `<!-- wp:columns {${attr}} -->\n` +
    `<div class="${cls}">\n${cols.join('\n')}\n</div>\n` +
    `<!-- /wp:columns -->`
  );
}

export function wrapSection(parts: string[], opts: WrapSectionOptions): string {
  const body = parts.filter(Boolean).join('\n');
  if (!body) return '';
  const layout = opts.fullBleed
    ? `"layout":{"type":"default"}`
    : opts.constrained
      ? `"layout":{"type":"constrained","contentSize":"${opts.constrained}"}`
      : opts.wide
        ? `"layout":{"type":"constrained","wideSize":"${opts.wide}"}`
        : `"layout":{"type":"constrained"}`;
  const hpadJson = opts.fullBleed
    ? `"left":"0","right":"0"`
    : `"left":"var:preset|spacing|40","right":"var:preset|spacing|40"`;
  const hpadL = opts.fullBleed ? '0px' : 'var(--wp--preset--spacing--40)';
  const hpadR = hpadL;
  const customBg = !opts.inverse ? opaqueTintHex(opts.bgColor) : null;
  const colorAttr = opts.inverse
    ? '"backgroundColor":"surface-inverse","textColor":"text-inverse",'
    : customBg
      ? ''
      : opts.raised
        ? '"backgroundColor":"surface-raised",'
        : '';
  const styleColor = !opts.inverse && customBg ? `"color":{"background":"${customBg}"},` : '';
  const bgClass = opts.inverse
    ? ' has-surface-inverse-background-color has-text-inverse-color has-text-color has-background'
    : customBg
      ? ' has-background'
      : opts.raised
        ? ' has-surface-raised-background-color has-background'
        : '';
  const bgInlineStyle = !opts.inverse && customBg ? `background-color:${customBg};` : '';
  const topVal = typeof opts.padTopPx === 'number' ? responsiveSpace(opts.padTopPx) : 'var:preset|spacing|60';
  const botVal =
    typeof opts.padBottomPx === 'number' ? responsiveSpace(opts.padBottomPx) : 'var:preset|spacing|60';
  const cssLen = (value: string): string =>
    value.startsWith('var:preset|spacing|') ? `var(--wp--preset--spacing--${value.split('|').pop()})` : value;
  return (
    `<!-- wp:group {"tagName":"section","align":"full",${colorAttr}"style":{${styleColor}"spacing":{"margin":{"top":"0","bottom":"0"},"padding":{"top":"${topVal}","bottom":"${botVal}",${hpadJson}},"blockGap":"var:preset|spacing|40"}},${layout}} -->\n` +
    `<section class="wp-block-group alignfull${bgClass}" style="margin-top:0;margin-bottom:0;${bgInlineStyle}padding-top:${cssLen(topVal)};padding-right:${hpadR};padding-bottom:${cssLen(botVal)};padding-left:${hpadL}">\n` +
    `${body}\n` +
    `</section>\n<!-- /wp:group -->`
  );
}

function rgbToHex(rgb: string): string | null {
  const match = rgb.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
  if (!match) return null;
  const h = (value: string): string =>
    Math.max(0, Math.min(255, parseInt(value, 10))).toString(16).padStart(2, '0');
  return `#${h(match[1])}${h(match[2])}${h(match[3])}`;
}
