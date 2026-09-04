import { escapeHtml } from '../escape.js';
import {
  column,
  columns,
  emptyNativeRenderOut,
  headingBlock,
  paragraphBlock,
  wrapSection,
} from './native-block-builders.js';
import { centerOf, isDarkSection, isTintedSection, sectionPad } from './native-layout.js';
import { normalizeCopy } from './page-reconstruct-helpers.js';
import { galleryBlock } from './native-renderers-text.js';
import type { NativeRenderCtx, NativeRenderOut } from './native-reconstruct-types.js';
import type { ExtractedReview, SectionSpec } from './section-spec.js';

export function renderReviewGrid(section: SectionSpec): NativeRenderOut {
  const out = emptyNativeRenderOut();
  const dark = isDarkSection(section);
  const bodyColor = dark ? 'text-inverse' : 'text-default';
  const mutedColor = dark ? 'text-inverse' : 'text-muted';
  const reviewP = (text: string, slug: string, small = false): string =>
    `<!-- wp:paragraph {"align":"center","textColor":"${slug}"${small ? ',"fontSize":"small"' : ''}} -->\n` +
    `<p class="has-text-align-center has-${slug}-color has-text-color${small ? ' has-small-font-size' : ''}">${escapeHtml(
      text,
    )}</p>\n<!-- /wp:paragraph -->`;

  const intro: string[] = [];
  section.headings
    .map((heading, originalIndex) => ({ heading, originalIndex }))
    .filter(({ heading }) => !/^\s*-/.test(heading))
    .slice(0, 1)
    .forEach(({ heading, originalIndex }) =>
      intro.push(
        headingBlock(heading, out, {
          level: 2,
          center: centerOf(section),
          inverse: dark,
          sizePx: section.headingSizes?.[originalIndex],
          fontFamily: section.headingFamilies?.[originalIndex] || undefined,
          lineHeight: section.headingLineHeights?.[originalIndex],
        }),
      ),
    );

  const reviews: ExtractedReview[] = section.reviews ?? [];
  const cards: string[] = [];
  if (reviews.length === 0) {
    const captured = (section.bodyText ?? []).map((body) => normalizeCopy(body)).filter(Boolean);
    if (captured.length > 0) {
      const parts: string[] = [];
      for (const line of captured) {
        out.bodyText.push(line);
        const isRating = /\d(?:\.\d)?\s*\/\s*5|rating|\breviews?\b/i.test(line) && line.length < 60;
        parts.push(reviewP(line, isRating ? mutedColor : bodyColor, isRating));
      }
      cards.push(column(parts.filter(Boolean)));
    } else {
      out.flags.push(
        `review-grid#${section.sectionIndex}: review band detected but no verbatim reviews captured — placeholder emitted`,
      );
      cards.push(column([reviewP('[reviews not captured]', dark ? 'text-inverse' : 'text-subtle')]));
    }
  } else {
    for (const review of reviews) {
      const parts: string[] = [];
      const starCount = Math.max(0, Math.min(5, Math.round(review.stars || 0)));
      if (starCount > 0) parts.push(reviewP('★'.repeat(starCount), 'accent-primary'));
      const quote = normalizeCopy(review.quote);
      if (quote) {
        out.bodyText.push(quote);
        parts.push(reviewP(quote, bodyColor));
      }
      if (review.author) {
        const author = normalizeCopy(review.author);
        out.bodyText.push(author);
        parts.push(reviewP(author, mutedColor, true));
      }
      cards.push(column(parts.filter(Boolean)));
    }
  }

  out.markup = wrapSection([...intro.filter(Boolean), columns(cards)], {
    wide: '1100px',
    inverse: dark,
    raised: !dark,
    bgColor: section.backgroundColor,
    ...sectionPad(section),
  });
  return out;
}

export function renderImageRow(section: SectionSpec, ctx?: NativeRenderCtx): NativeRenderOut {
  const out = emptyNativeRenderOut();
  const parts: string[] = [];
  section.headings.forEach((heading, index) =>
    parts.push(
      headingBlock(heading, out, {
        level: 2,
        center: centerOf(section),
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
        sizePx: section.bodyTextSizes?.[index],
        fontFamily: section.bodyFamilies?.[index] || undefined,
        lineHeight: section.bodyLineHeights?.[index],
      }),
    ),
  );
  const gallery = galleryBlock(section.images, out, { sectionHeight: section.height }, ctx);
  if (gallery) parts.push(gallery);
  out.markup = wrapSection(parts.filter(Boolean), {
    wide: '1100px',
    raised: isTintedSection(section),
    bgColor: section.backgroundColor,
    ...sectionPad(section),
  });
  return out;
}
