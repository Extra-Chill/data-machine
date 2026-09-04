import { describe, expect, expectTypeOf, it } from 'vitest';

import {
  galleryBlock,
  renderCover,
  renderMediaText,
  renderTextBand,
  type GalleryBlockOptions,
} from '../theme/native-renderers-text.js';
import type { NativeImageResolutionContext } from '../theme/native-media.js';
import type { NativeRenderCtx, NativeRenderOut } from '../theme/native-reconstruct-types.js';
import type { SectionSpec, SectionSpecImage } from '../theme/section-spec.js';

describe('native text renderer frozen surface', () => {
  it('freezes the renderer export names and runtime availability', () => {
    expect({
      galleryBlock,
      renderCover,
      renderMediaText,
      renderTextBand,
    }).toEqual(
      expect.objectContaining({
        galleryBlock: expect.any(Function),
        renderCover: expect.any(Function),
        renderMediaText: expect.any(Function),
        renderTextBand: expect.any(Function),
      })
    );
  });

  it('freezes the renderer type signatures', () => {
    expectTypeOf<GalleryBlockOptions>().toEqualTypeOf<{ sectionHeight?: number }>();
    expectTypeOf(renderTextBand).toEqualTypeOf<
      (section: SectionSpec, ctx: NativeRenderCtx) => NativeRenderOut
    >();
    expectTypeOf(renderCover).toEqualTypeOf<
      (section: SectionSpec, ctx: NativeRenderCtx) => NativeRenderOut
    >();
    expectTypeOf(renderMediaText).toEqualTypeOf<
      (section: SectionSpec, flip: boolean, ctx: NativeRenderCtx) => NativeRenderOut
    >();
    expectTypeOf(galleryBlock).toEqualTypeOf<
      (
        images: SectionSpecImage[],
        out: NativeRenderOut,
        opts?: GalleryBlockOptions,
        ctx?: NativeImageResolutionContext,
      ) => string
    >();
  });
});
