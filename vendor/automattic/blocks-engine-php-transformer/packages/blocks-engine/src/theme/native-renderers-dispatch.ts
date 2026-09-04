import { isUsableNativeImage, pickLeadImage } from './native-media.js';
import { renderCardGrid, renderCellGrid, renderFaq } from './native-renderers-grid.js';
import { renderImageRow, renderReviewGrid } from './native-renderers-section.js';
import { renderCover, renderMediaText, renderTextBand } from './native-renderers-text.js';
import type { NativeRenderCtx, NativeRenderOut } from './native-reconstruct-types.js';
import type { InteractionModel, SectionSpec, SectionSpecCell } from './section-spec.js';

export const NON_CELL_GRID_MODELS: ReadonlySet<InteractionModel> = new Set([
  'product-card-row',
  'project-card-grid',
  'blog-card-grid',
  'review-grid',
  'testimonial',
]);

export const FLATTEN_PRONE_MODELS: ReadonlySet<InteractionModel> = new Set([
  'static',
  'cta',
  'price-list',
  'app-download',
  'horizontal-showcase',
]);

export const MEDIA_LAYOUT_DENY: ReadonlySet<InteractionModel> = new Set([
  'gallery',
  'logo-strip',
  'color-block-grid',
  'marquee-strip',
  'product-card-row',
  'project-card-grid',
  'blog-card-grid',
  'review-grid',
  'testimonial',
]);

export function renderSection(section: SectionSpec, ctx: NativeRenderCtx): NativeRenderOut {
  // A section carrying re-captured FAQ pairs renders as an accordion regardless
  // of its geometric interaction model.
  if (section.faqs && section.faqs.length) return renderFaq(section);
  // A uniform multi-cell content grid: >=2 cells each carrying BOTH a title and
  // body (a genuine feature/column grid, not a hero's text|image split or a
  // single CTA). Card grids / reviews keep their specialized paths.
  // A uniform multi-cell content grid. The base trigger needs cells carrying BOTH a
  // heading and body (an unambiguous feature grid). RELAXED un-flatten: when the source
  // reported >=2 columns AND the model would otherwise flatten to a centered text band,
  // >=2 cells carrying ANY content (heading/body/image/icon/button) is enough — so a
  // numbered-card band whose cells are heading-only stops collapsing into one column. The
  // relaxed path is gated to FLATTEN_PRONE_MODELS so heroes/covers/media-text (which run
  // their own checks after this) are never swept into a card grid by column count alone.
  const cellHasHeadingAndBody = (cell: SectionSpecCell) => !!(cell.heading && cell.body.length > 0);
  const cellHasAnyContent = (cell: SectionSpecCell) =>
    !!(cell.heading || cell.body.length > 0 || cell.image || cell.icon || cell.button);
  if (
    section.cells &&
    !NON_CELL_GRID_MODELS.has(section.interactionModel) &&
    (section.cells.filter(cellHasHeadingAndBody).length >= 2 ||
      (FLATTEN_PRONE_MODELS.has(section.interactionModel) &&
        section.layout.columnCount >= 2 &&
        section.cells.filter(cellHasAnyContent).length >= 2))
  ) {
    return renderCellGrid(section, ctx);
  }
  // The source places a large image BESIDE the text (a 2-up media row) — render
  // media-text with the captured side, regardless of the geometric model, so the
  // arrangement isn't stacked. Skipped for gallery/card/review models (their
  // multi-image layouts own their rendering) and when there's no lead image or
  // text to pair. This is what makes a flat-Wix content tile (e.g. a product
  // page's photo|description row) reproduce its real two-column layout.
  if (
    section.mediaLayout &&
    !MEDIA_LAYOUT_DENY.has(section.interactionModel) &&
    pickLeadImage(section.images) &&
    (section.headings.length > 0 || (section.bodyText ?? []).length > 0)
  ) {
    ctx.mediaTextIndex++;
    return renderMediaText(section, section.mediaLayout === 'image-left', ctx);
  }
  switch (section.interactionModel) {
    case 'media-text': {
      const flip = ctx.mediaTextIndex % 2 === 1;
      ctx.mediaTextIndex++;
      return renderMediaText(section, flip, ctx);
    }
    case 'product-card-row':
      return renderCardGrid(section, true, ctx);
    case 'project-card-grid':
    case 'blog-card-grid':
      return renderCardGrid(section, false, ctx);
    case 'review-grid':
    case 'testimonial':
      return renderReviewGrid(section);
    case 'color-block-grid':
    case 'logo-strip':
    case 'gallery':
    case 'marquee-strip':
      return renderImageRow(section, ctx);
    case 'columns':
      // A two-up columns band: if it has both copy and one image, treat as media-text;
      // otherwise a centered text band.
      if (section.images.length === 1 && (section.headings.length || (section.bodyText ?? []).length)) {
        return renderMediaText(section, false, ctx);
      }
      return renderTextBand(section, ctx);
    case 'cover-with-headline': {
      const coverLead = pickLeadImage(section.images);
      // A FULL-BLEED hero with a wide background photo (≥1000px) is a
      // text-OVER-photo cover (the title sits on the image) — render it as a
      // wp:cover, not a side-by-side. Routing it to media-text produced the
      // common failure where the hero became a flat dark band with a dark
      // (often invisible) heading beside a shrunken photo. Mirrors the
      // animated-cover branch. Generic: keyed on the captured fullBleed flag +
      // image width, not any one site.
      if (coverLead && isUsableNativeImage(coverLead, ctx) && section.fullBleed && (coverLead.width || 0) >= 1000) {
        return renderCover(section, ctx);
      }
      // A non-full-bleed hero with a REAL lead photo renders as a 2-column
      // media-text (text | image). Without a photo it's a centered band.
      if (coverLead && (section.headings.length || (section.bodyText ?? []).length)) {
        const flip = ctx.mediaTextIndex % 2 === 1;
        ctx.mediaTextIndex++;
        return renderMediaText(section, flip, ctx);
      }
      return renderTextBand(section, ctx);
    }
    case 'animated-cover': {
      // A full-bleed hero cover needs a WIDE background photo (≥1000px). A
      // smaller content image is a media band (a mid-page story section), not a
      // cover — render it as media-text so it isn't turned into a full-bleed
      // text-over-photo band.
      const coverLead = pickLeadImage(section.images);
      if (coverLead && isUsableNativeImage(coverLead, ctx) && (coverLead.width || 0) >= 1000) {
        return renderCover(section, ctx);
      }
      if (coverLead && (section.headings.length || (section.bodyText ?? []).length)) {
        const flip = ctx.mediaTextIndex % 2 === 1;
        ctx.mediaTextIndex++;
        return renderMediaText(section, flip, ctx);
      }
      return renderTextBand(section, ctx);
    }
    case 'static':
    case 'cta':
    case 'price-list':
    case 'app-download':
    case 'horizontal-showcase':
    default:
      return renderTextBand(section, ctx);
  }
}
