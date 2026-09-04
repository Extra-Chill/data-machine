import { escapeHtml } from '../escape.js';
import {
  column,
  columns,
  emptyNativeRenderOut,
  headingBlock,
  imageBlock,
  paragraphBlock,
  sectionButtons,
  wrapSection,
} from './native-block-builders.js';
import { centerOf, isDarkSection, isTintedSection, sectionPad } from './native-layout.js';
import { pickLeadImage, resolveNativeImageUrl, type NativeImageResolutionContext } from './native-media.js';
import type { NativeRenderCtx, NativeRenderOut } from './native-reconstruct-types.js';
import type { SectionSpec, SectionSpecImage } from './section-spec.js';

export interface GalleryBlockOptions {
  sectionHeight?: number;
}

export function renderTextBand(section: SectionSpec, ctx: NativeRenderCtx): NativeRenderOut {
  const out = emptyNativeRenderOut();
  const parts: string[] = [];
  const dark = isDarkSection(section);
  section.headings.forEach((heading, index) =>
    parts.push(
      headingBlock(heading, out, {
        level: index === 0 ? 1 : 2,
        center: centerOf(section),
        inverse: dark,
        sizePx: section.headingSizes?.[index],
        fontFamily: section.headingFamilies?.[index] || undefined,
        lineHeight: section.headingLineHeights?.[index],
      }),
    ),
  );
  (section.bodyText ?? []).forEach((body, index) =>
    parts.push(
      paragraphBlock(body, out, {
        center: centerOf(section),
        inverse: dark,
        sizePx: section.bodyTextSizes?.[index],
        fontFamily: section.bodyFamilies?.[index] || undefined,
        lineHeight: section.bodyLineHeights?.[index],
      }),
    ),
  );
  parts.push(...sectionButtons(section, out, ctx));

  const lead = pickLeadImage(section.images);
  if (lead) {
    parts.push(
      imageBlock(
        lead,
        out,
        `${section.interactionModel}#${section.sectionIndex}`,
        {
          align: centerOf(section) ? 'center' : null,
          rounded: true,
        },
        ctx,
      ),
    );
  }

  const extra = section.images.filter(
    (image) =>
      image.url !== lead?.url &&
      resolveNativeImageUrl(image, ctx) !== null &&
      Math.min(image.width || 0, image.height || 0) >= 90,
  );
  if (extra.length >= 3) {
    const gallery = galleryBlock(extra, out, undefined, ctx);
    if (gallery) parts.push(gallery);
  } else {
    for (const image of extra.filter((candidate) => pickLeadImage([candidate]) === candidate)) {
      parts.push(
        imageBlock(
          image,
          out,
          `${section.interactionModel}#${section.sectionIndex}.extra`,
          {
            align: centerOf(section) ? 'center' : null,
            rounded: true,
          },
          ctx,
        ),
      );
    }
  }

  out.markup = wrapSection(parts.filter(Boolean), {
    constrained: '760px',
    center: centerOf(section),
    inverse: dark,
    raised: isTintedSection(section),
    bgColor: section.backgroundColor,
    ...sectionPad(section),
  });
  return out;
}

export function renderCover(section: SectionSpec, ctx: NativeRenderCtx): NativeRenderOut {
  const lead = pickLeadImage(section.images);
  const resolvedUrl = resolveNativeImageUrl(lead, ctx);
  if (!lead || !resolvedUrl) return renderTextBand(section, ctx);
  const out = emptyNativeRenderOut();
  out.assets.push(resolvedUrl);
  const inner: string[] = [];
  section.headings.forEach((heading, index) =>
    inner.push(
      headingBlock(heading, out, {
        level: index === 0 ? 1 : 2,
        center: centerOf(section),
        inverse: true,
        sizePx: section.headingSizes?.[index],
        fontFamily: section.headingFamilies?.[index] || undefined,
        lineHeight: section.headingLineHeights?.[index],
      }),
    ),
  );
  (section.bodyText ?? []).forEach((body, index) =>
    inner.push(
      paragraphBlock(body, out, {
        center: centerOf(section),
        inverse: true,
        sizePx: section.bodyTextSizes?.[index],
        fontFamily: section.bodyFamilies?.[index] || undefined,
        lineHeight: section.bodyLineHeights?.[index],
      }),
    ),
  );
  inner.push(...sectionButtons(section, out, ctx));
  const innerMarkup = inner.filter(Boolean).join('\n');
  const url = escapeHtml(resolvedUrl);
  const minHpx = Math.max(480, Math.min(Math.round(section.height || 520), 1000));
  const minVw = Math.round((minHpx / 1440) * 1000) / 10;
  out.markup =
    `<!-- wp:cover {"url":"${url}","dimRatio":40,"overlayColor":"surface-inverse","isUserOverlayColor":true,"minHeight":${minVw},"minHeightUnit":"vw","align":"full","style":{"spacing":{"margin":{"top":"0px"}}},"layout":{"type":"constrained"}} -->\n` +
    `<div class="wp-block-cover alignfull" style="margin-top:0px;min-height:${minVw}vw">` +
    `<img class="wp-block-cover__image-background" src="${url}" alt="${escapeHtml(lead.alt || '')}" data-object-fit="cover"/>` +
    `<span aria-hidden="true" class="wp-block-cover__background has-surface-inverse-background-color has-background-dim-40 has-background-dim"></span>\n` +
    `<div class="wp-block-cover__inner-container">\n${innerMarkup}\n</div>\n` +
    `</div>\n<!-- /wp:cover -->`;
  return out;
}

export function renderMediaText(section: SectionSpec, flip: boolean, ctx: NativeRenderCtx): NativeRenderOut {
  const out = emptyNativeRenderOut();
  const textParts: string[] = [];
  section.headings.forEach((heading, index) =>
    textParts.push(
      headingBlock(heading, out, {
        level: 2,
        sizePx: section.headingSizes?.[index],
        fontFamily: section.headingFamilies?.[index] || undefined,
        lineHeight: section.headingLineHeights?.[index],
      }),
    ),
  );
  (section.bodyText ?? []).forEach((body, index) =>
    textParts.push(
      paragraphBlock(body, out, {
        sizePx: section.bodyTextSizes?.[index],
        fontFamily: section.bodyFamilies?.[index] || undefined,
        lineHeight: section.bodyLineHeights?.[index],
      }),
    ),
  );
  textParts.push(...sectionButtons(section, out, ctx));

  const lead = pickLeadImage(section.images) ?? section.images[0];
  const imageMarkup = imageBlock(lead, out, `media-text#${section.sectionIndex}`, { rounded: true }, ctx);
  const textColumn = column(textParts.filter(Boolean), '55%');
  const imageColumn = column([imageMarkup], '45%');
  const columnBlocks = flip ? [imageColumn, textColumn] : [textColumn, imageColumn];
  const blocks: string[] = [columns(columnBlocks)];

  const extra = section.images.filter(
    (image) =>
      image.url !== lead?.url &&
      resolveNativeImageUrl(image, ctx) !== null &&
      Math.min(image.width || 0, image.height || 0) >= 90,
  );
  if (extra.length >= 3) {
    const gallery = galleryBlock(extra, out, undefined, ctx);
    if (gallery) blocks.push(gallery);
  } else {
    for (const image of extra.filter((candidate) => pickLeadImage([candidate]) === candidate)) {
      blocks.push(
        imageBlock(
          image,
          out,
          `media-text#${section.sectionIndex}.extra`,
          { align: 'center', rounded: true },
          ctx,
        ),
      );
    }
  }

  out.markup = wrapSection(blocks, {
    wide: '1100px',
    raised: isTintedSection(section),
    bgColor: section.backgroundColor,
    ...sectionPad(section),
  });
  return out;
}

export function galleryBlock(
  images: SectionSpecImage[],
  out: NativeRenderOut,
  opts?: GalleryBlockOptions,
  resolutionContext?: NativeImageResolutionContext,
): string {
  const usable = images
    .map((image) => ({ image, url: resolveNativeImageUrl(image, resolutionContext) }))
    .filter((entry): entry is { image: SectionSpecImage; url: string } => entry.url !== null);
  if (usable.length === 0) return '';
  const columnsCount = Math.min(4, usable.length);
  const sized = usable.filter(({ image }) => image.width && image.height);
  const landscape = sized.filter(({ image }) => image.width >= image.height);
  const basis = (landscape.length ? landscape : sized).map(
    ({ image }) => (image.height * Math.min(560, image.width)) / image.width,
  );
  const sortedHeights = basis.slice().sort((a, b) => a - b);
  const rowHeight = sortedHeights.length
    ? Math.max(140, Math.min(460, Math.round(sortedHeights[Math.floor(sortedHeights.length / 2)])))
    : 0;
  const figures = usable.map(({ image, url }) => {
    out.assets.push(url);
    if (image.width && image.height && rowHeight) {
      const width = Math.max(80, Math.min(900, Math.round((rowHeight * image.width) / image.height)));
      return (
        `<!-- wp:image {"width":"${width}px","height":"${rowHeight}px","sizeSlug":"large","linkDestination":"none"} -->\n` +
        `<figure class="wp-block-image size-large is-resized"><img src="${escapeHtml(url)}" alt="${escapeHtml(image.alt || '')}" style="width:${width}px;height:${rowHeight}px"/></figure>\n` +
        `<!-- /wp:image -->`
      );
    }
    return (
      `<!-- wp:image {"sizeSlug":"large","linkDestination":"none"} -->\n` +
      `<figure class="wp-block-image size-large"><img src="${escapeHtml(url)}" alt="${escapeHtml(image.alt || '')}"/></figure>\n` +
      `<!-- /wp:image -->`
    );
  });
  const multiRow = !!(opts?.sectionHeight && rowHeight && opts.sectionHeight >= rowHeight * 2);
  if (multiRow) {
    return (
      `<!-- wp:gallery {"columns":${columnsCount},"imageCrop":true,"linkTo":"none","sizeSlug":"large"} -->\n` +
      `<figure class="wp-block-gallery has-nested-images columns-${columnsCount} is-cropped">\n${figures.join('\n')}\n</figure>\n` +
      `<!-- /wp:gallery -->`
    );
  }
  return (
    `<!-- wp:gallery {"columns":${columnsCount},"imageCrop":false,"linkTo":"none","sizeSlug":"large","className":"is-gallery-scroller"} -->\n` +
    `<figure class="wp-block-gallery has-nested-images columns-${columnsCount} is-gallery-scroller">\n${figures.join('\n')}\n</figure>\n` +
    `<!-- /wp:gallery -->`
  );
}
