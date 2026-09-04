import {
  buttonBlock,
  column,
  columns,
  emptyNativeRenderOut,
  headingBlock,
  imageBlock,
  paragraphBlock,
  wrapSection,
} from './native-block-builders.js';
import { brightness, nearestToken } from './native-color.js';
import { nearestFamily } from './native-fonts.js';
import { buttonJustify, centerOf, isTintedSection, responsiveSpace, sectionPad } from './native-layout.js';
import { iconImageBlock, isUsableNativeImage } from './native-media.js';
import type { NativeRenderCtx, NativeRenderOut } from './native-reconstruct-types.js';
import { escapeHtml } from '../escape.js';
import { normalizeCopy } from './page-reconstruct-helpers.js';
import type { SectionSpec } from './section-spec.js';

const MIN_CELL_IMAGE_PX = 90;

export interface CardGroupPadding {
  top: number;
  right: number;
  bottom: number;
  left: number;
}

export function renderCardGrid(section: SectionSpec, withButtons: boolean, ctx?: NativeRenderCtx): NativeRenderOut {
  const out = emptyNativeRenderOut();
  const headings = dedupeAdjacent(section.headings);
  const bodyText = section.bodyText ?? [];
  const cardCount =
    section.images.length > 0 ? section.images.length : Math.min(headings.length, bodyText.length || headings.length);
  const cards: string[] = [];
  for (let i = 0; i < cardCount; i++) {
    const cardParts: string[] = [];
    if (section.images.length > 0) {
      cardParts.push(
        imageBlock(
          section.images[i],
          out,
          `${section.interactionModel}#${section.sectionIndex}.card${i}`,
          {
            rounded: true,
          },
          ctx,
        ),
      );
    }
    if (headings[i]) {
      cardParts.push(
        headingBlock(headings[i], out, {
          level: 3,
          center: centerOf(section),
          sizePx: section.headingSizes?.[i],
          fontFamily: section.headingFamilies?.[i] || undefined,
          lineHeight: section.headingLineHeights?.[i],
        }),
      );
    }
    if (bodyText[i]) {
      cardParts.push(
        paragraphBlock(bodyText[i], out, {
          center: centerOf(section),
          size: 'small',
          sizePx: section.bodyTextSizes?.[i],
          fontFamily: section.bodyFamilies?.[i] || undefined,
          lineHeight: section.bodyLineHeights?.[i],
        }),
      );
    }
    if (withButtons && section.buttonLabels[i]) {
      cardParts.push(buttonBlock(section.buttonLabels[i], out, { align: buttonJustify(section) }));
    }
    if (cardParts.filter(Boolean).length) cards.push(column(cardParts.filter(Boolean)));
  }
  const extra: string[] = [];
  for (let i = cardCount; i < bodyText.length; i++) {
    extra.push(paragraphBlock(bodyText[i], out, { center: centerOf(section) }));
  }
  out.markup = wrapSection([...extra.filter(Boolean), columns(cards)], {
    wide: '1100px',
    raised: isTintedSection(section),
    bgColor: section.backgroundColor,
    ...sectionPad(section),
  });
  return out;
}

export function renderFaq(section: SectionSpec): NativeRenderOut {
  const out = emptyNativeRenderOut();
  const parts: string[] = [];
  section.headings.slice(0, 1).forEach((heading, i) =>
    parts.push(
      headingBlock(heading, out, {
        level: 2,
        center: centerOf(section),
        sizePx: section.headingSizes?.[i],
        fontFamily: section.headingFamilies?.[i] || undefined,
        lineHeight: section.headingLineHeights?.[i],
      }),
    ),
  );
  const faqs = section.faqs ?? [];
  for (const faq of faqs) {
    const question = normalizeCopy(faq.question);
    if (!question) continue;
    out.expectedText.push(question);
    const answer = normalizeCopy(faq.answer);
    let answerBlock: string;
    if (answer) {
      out.bodyText.push(answer);
      answerBlock =
        `<!-- wp:paragraph {"textColor":"text-muted"} -->\n` +
        `<p class="has-text-muted-color has-text-color">${escapeHtml(answer)}</p>\n<!-- /wp:paragraph -->`;
    } else {
      out.flags.push(`faq#${section.sectionIndex}: answer for "${question}" not captured — placeholder emitted`);
      answerBlock =
        `<!-- wp:paragraph {"textColor":"text-subtle"} -->\n` +
        `<p class="has-text-subtle-color has-text-color">[answer not captured]</p>\n<!-- /wp:paragraph -->`;
    }
    parts.push(
      `<!-- wp:details -->\n<details class="wp-block-details"><summary>${escapeHtml(question)}</summary>\n` +
        `${answerBlock}\n</details>\n<!-- /wp:details -->`,
    );
  }
  out.markup = wrapSection(parts.filter(Boolean), {
    constrained: '760px',
    raised: isTintedSection(section),
    bgColor: section.backgroundColor,
    ...sectionPad(section),
  });
  return out;
}

export function renderCellGrid(section: SectionSpec, ctx: NativeRenderCtx): NativeRenderOut {
  const out = emptyNativeRenderOut();
  const cells = section.cells ?? [];
  const cellHeadSet = new Set(cells.map((cell) => normalizeCopy(cell.heading ?? '')).filter(Boolean));
  const intro: string[] = [];
  section.headings.forEach((heading, i) => {
    if (cellHeadSet.has(normalizeCopy(heading))) return;
    intro.push(
      headingBlock(heading, out, {
        level: i === 0 ? 2 : 3,
        center: centerOf(section),
        sizePx: section.headingSizes?.[i],
        fontFamily: section.headingFamilies?.[i] || undefined,
        lineHeight: section.headingLineHeights?.[i],
      }),
    );
  });
  const cols: string[] = [];
  for (const cell of cells) {
    const cardToken = cell.background ? nearestToken(cell.background, ctx.paletteTokens) : null;
    const cardDark = cell.background ? brightness(cell.background) < 140 : false;
    const cellCenter = cell.align ? cell.align === 'center' : centerOf(section);
    const iconAlign = cell.iconAlign ?? (cellCenter ? 'center' : 'left');
    const parts: string[] = [];
    if (cell.icon) {
      parts.push(iconImageBlock(cell.icon, out, ctx, { align: iconAlign, ...(cardDark ? { fill: '#ffffff' } : {}) }));
    }
    if (
      cell.image &&
      isUsableNativeImage(cell.image, ctx) &&
      Math.min(cell.image.width || 0, cell.image.height || 0) >= MIN_CELL_IMAGE_PX
    ) {
      parts.push(imageBlock(cell.image, out, `cell#${section.sectionIndex}`, { rounded: true }, ctx));
    }
    const cellHeadFamily = nearestFamily(cell.headingFamily, ctx.fontFamilies) || undefined;
    const cellBodyFamily = nearestFamily(cell.bodyFamily, ctx.fontFamilies) || undefined;
    if (cell.heading) {
      parts.push(
        headingBlock(cell.heading, out, {
          level: 3,
          center: cellCenter,
          inverse: cardDark,
          sizePx: cell.headingSize,
          fontFamily: cellHeadFamily,
          lineHeight: cell.headingLineHeight,
        }),
      );
    }
    for (const body of cell.body) {
      parts.push(
        paragraphBlock(body, out, {
          center: cellCenter,
          size: 'small',
          inverse: cardDark,
          fontFamily: cellBodyFamily,
          lineHeight: cell.bodyLineHeight,
        }),
      );
    }
    if (cell.button) parts.push(buttonBlock(cell.button, out, { align: cellCenter ? 'center' : 'left' }));
    const kept = parts.filter(Boolean);
    if (!kept.length) continue;
    cols.push(column(cardToken ? [cardGroup(kept, cardToken, cardDark, cell.radius ?? 0, cell.padding ?? null)] : kept));
  }
  const claimedImageUrls = new Set(cells.map((cell) => cell.image?.url).filter(Boolean));
  for (const image of section.images ?? []) {
    if (claimedImageUrls.has(image.url)) continue;
    if (!isUsableNativeImage(image, ctx) || Math.min(image.width || 0, image.height || 0) < MIN_CELL_IMAGE_PX) continue;
    cols.push(column([imageBlock(image, out, `cell-media#${section.sectionIndex}`, { rounded: true }, ctx)]));
  }
  const fullBleed = (section.layout?.containerWidth ?? 0) >= 1380;
  out.markup = wrapSection([...intro.filter(Boolean), columns(cols, { fullBleed })], {
    ...(fullBleed ? { fullBleed: true } : { wide: '1100px' }),
    raised: isTintedSection(section),
    bgColor: section.backgroundColor,
    ...sectionPad(section),
  });
  return out;
}

export function cardGroup(
  parts: string[],
  bgToken: string,
  dark: boolean,
  radius: number,
  padding: CardGroupPadding | null,
): string {
  const textToken = dark ? 'text-inverse' : 'text-default';
  const r = radius > 0 ? Math.min(radius, 32) : 0;
  const cssLen = (value: string): string =>
    value.startsWith('var:preset|spacing|') ? `var(--wp--preset--spacing--${value.split('|').pop()})` : value;
  const side = (px: number | undefined): string =>
    typeof px === 'number' ? responsiveSpace(Math.max(8, Math.min(96, px))) : 'var:preset|spacing|40';
  const pt = side(padding?.top);
  const pr = side(padding?.right);
  const pb = side(padding?.bottom);
  const pl = side(padding?.left);
  return (
    `<!-- wp:group {"className":"is-replica-card","style":{"spacing":{"padding":{"top":"${pt}","bottom":"${pb}","left":"${pl}","right":"${pr}"}},"border":{"radius":"${r}px"}},"backgroundColor":"${bgToken}","textColor":"${textToken}","layout":{"type":"constrained"}} -->\n` +
    `<div class="wp-block-group is-replica-card has-${textToken}-color has-${bgToken}-background-color has-text-color has-background" style="border-radius:${r}px;padding-top:${cssLen(pt)};padding-right:${cssLen(pr)};padding-bottom:${cssLen(pb)};padding-left:${cssLen(pl)}">\n${parts.join('\n')}\n</div>\n` +
    `<!-- /wp:group -->`
  );
}

function dedupeAdjacent(arr: string[]): string[] {
  const out: string[] = [];
  for (const value of arr) {
    if (out.length === 0 || normalizeCopy(out[out.length - 1]) !== normalizeCopy(value)) out.push(value);
  }
  return out;
}
