import { describe, expect, expectTypeOf, it } from 'vitest';

import { brightness, nearestToken, type PaletteToken } from '../theme/native-color.js';
import { familyMatches, nearestFamily } from '../theme/native-fonts.js';
import {
  buttonJustify,
  centerOf,
  isDarkSection,
  isTintedSection,
  opaqueTintHex,
  responsiveFontSize,
  responsiveSpace,
  sectionPad,
  type SectionPadding,
} from '../theme/native-layout.js';
import {
  MISSING_IMAGE_PLACEHOLDER,
  iconImageBlock,
  isUsableNativeImage,
  isWpMediaUrl,
  pickLeadImage,
  recolorSvg,
  resolveImage,
  resolveNativeImageUrl,
  type IconImageBlockOptions,
  type NativeImageResolutionContext,
  type ResolvedNativeImage,
} from '../theme/native-media.js';
import {
  buttonBlock,
  column,
  columns,
  ctaButton,
  emptyNativeRenderOut,
  headingBlock,
  imageBlock,
  paragraphBlock,
  sectionButtons,
  typographyStyle,
  visibleText,
  wrapSection,
  type HeadingBlockOptions,
  type ImageBlockOptions,
  type NativeButtonInput,
  type ParagraphBlockOptions,
  type TypographyFragments,
  type WrapSectionOptions,
} from '../theme/native-block-builders.js';
import type { FontFamilyToken } from '../theme/page-reconstruct-helpers.js';
import type { NativeRenderCtx, NativeRenderOut } from '../theme/native-reconstruct-types.js';
import type { SectionSpec, SectionSpecIcon, SectionSpecImage } from '../theme/section-spec.js';

describe('native low-level helper frozen surface', () => {
  it('freezes the helper export names and runtime availability', () => {
    expect({
      nearestToken,
      brightness,
      familyMatches,
      nearestFamily,
      responsiveFontSize,
      responsiveSpace,
      sectionPad,
      centerOf,
      buttonJustify,
      isTintedSection,
      opaqueTintHex,
      isDarkSection,
      pickLeadImage,
      isWpMediaUrl,
      resolveNativeImageUrl,
      isUsableNativeImage,
      recolorSvg,
      resolveImage,
      iconImageBlock,
      visibleText,
      emptyNativeRenderOut,
      typographyStyle,
      imageBlock,
      headingBlock,
      paragraphBlock,
      buttonBlock,
      ctaButton,
      sectionButtons,
      column,
      columns,
      wrapSection,
    }).toEqual(
      expect.objectContaining(
        Object.fromEntries(
          [
            'nearestToken',
            'brightness',
            'familyMatches',
            'nearestFamily',
            'responsiveFontSize',
            'responsiveSpace',
            'sectionPad',
            'centerOf',
            'buttonJustify',
            'isTintedSection',
            'opaqueTintHex',
            'isDarkSection',
            'pickLeadImage',
            'isWpMediaUrl',
            'resolveNativeImageUrl',
            'isUsableNativeImage',
            'recolorSvg',
            'resolveImage',
            'iconImageBlock',
            'visibleText',
            'emptyNativeRenderOut',
            'typographyStyle',
            'imageBlock',
            'headingBlock',
            'paragraphBlock',
            'buttonBlock',
            'ctaButton',
            'sectionButtons',
            'column',
            'columns',
            'wrapSection',
          ].map((name) => [name, expect.any(Function)])
        )
      )
    );
    expect(MISSING_IMAGE_PLACEHOLDER).toBe('[image unavailable — not captured]');
  });

  it('freezes the helper type signatures', () => {
    expectTypeOf<PaletteToken>().toEqualTypeOf<{ slug: string; hex: string }>();
    expectTypeOf<SectionPadding>().toEqualTypeOf<{ padTopPx?: number; padBottomPx?: number }>();
    expectTypeOf<ResolvedNativeImage>().toEqualTypeOf<{ url: string; alt: string; usable: boolean }>();
    expectTypeOf<IconImageBlockOptions>().toEqualTypeOf<{
      sizePx?: number;
      fill?: string;
      align?: 'left' | 'center' | 'right';
    }>();
    expectTypeOf<TypographyFragments>().toEqualTypeOf<{ attr: string; inline: string }>();
    expectTypeOf<HeadingBlockOptions>().toEqualTypeOf<{
      level?: number;
      center?: boolean;
      muted?: boolean;
      inverse?: boolean;
      sizePx?: number;
      fontFamily?: string | null;
      lineHeight?: number;
    }>();
    expectTypeOf<ParagraphBlockOptions>().toEqualTypeOf<{
      center?: boolean;
      muted?: boolean;
      size?: string;
      inverse?: boolean;
      sizePx?: number;
      fontFamily?: string | null;
      lineHeight?: number;
    }>();
    expectTypeOf<ImageBlockOptions>().toEqualTypeOf<{ rounded?: boolean; align?: 'center' | null }>();
    expectTypeOf<NativeButtonInput>().toEqualTypeOf<{
      label: string;
      href?: string;
      background?: string | null;
      color?: string | null;
      icon?: SectionSpecIcon | null;
      iconAfter?: boolean;
    }>();
    expectTypeOf<WrapSectionOptions>().toEqualTypeOf<{
      constrained?: string;
      wide?: string;
      center?: boolean;
      raised?: boolean;
      inverse?: boolean;
      bgColor?: string;
      padTopPx?: number;
      padBottomPx?: number;
      fullBleed?: boolean;
    }>();

    expectTypeOf(nearestToken).toEqualTypeOf<(hex: string, tokens: PaletteToken[]) => string | null>();
    expectTypeOf(brightness).toEqualTypeOf<(hex: string) => number>();
    expectTypeOf(familyMatches).toEqualTypeOf<(computed: string, token: FontFamilyToken) => boolean>();
    expectTypeOf(nearestFamily).toEqualTypeOf<
      (computed: string | undefined, tokens: FontFamilyToken[]) => string | null
    >();
    expectTypeOf(responsiveFontSize).toEqualTypeOf<(px: number | undefined) => string>();
    expectTypeOf(responsiveSpace).toEqualTypeOf<(px: number) => string>();
    expectTypeOf(sectionPad).toEqualTypeOf<(section: SectionSpec) => SectionPadding>();
    expectTypeOf(centerOf).toEqualTypeOf<(section: SectionSpec) => boolean>();
    expectTypeOf(buttonJustify).toEqualTypeOf<(section: SectionSpec) => 'left' | 'center'>();
    expectTypeOf(isTintedSection).toEqualTypeOf<(section: SectionSpec) => boolean>();
    expectTypeOf(opaqueTintHex).toEqualTypeOf<(color: string | null | undefined) => string | null>();
    expectTypeOf(isDarkSection).toEqualTypeOf<(section: SectionSpec) => boolean>();
    expectTypeOf(pickLeadImage).toEqualTypeOf<(images: SectionSpecImage[]) => SectionSpecImage | undefined>();
    expectTypeOf(isWpMediaUrl).toEqualTypeOf<(url: string) => boolean>();
    expectTypeOf<NativeImageResolutionContext>().toEqualTypeOf<{ mediaUrlMap?: Map<string, string> }>();
    expectTypeOf(resolveNativeImageUrl).toEqualTypeOf<
      (image: SectionSpecImage | undefined, resolutionContext?: NativeImageResolutionContext) => string | null
    >();
    expectTypeOf(isUsableNativeImage).toEqualTypeOf<
      (image: SectionSpecImage | undefined, resolutionContext?: NativeImageResolutionContext) => boolean
    >();
    expectTypeOf(recolorSvg).toEqualTypeOf<(svg: string, hex: string) => string>();
    expectTypeOf(resolveImage).toEqualTypeOf<
      (
        image: SectionSpecImage | undefined,
        out: NativeRenderOut,
        context: string,
        resolutionContext?: NativeImageResolutionContext,
      ) => ResolvedNativeImage
    >();
    expectTypeOf(iconImageBlock).toEqualTypeOf<
      (
        icon: SectionSpecIcon,
        out: NativeRenderOut,
        ctx: NativeRenderCtx,
        opts?: IconImageBlockOptions,
      ) => string
    >();
    expectTypeOf(visibleText).toEqualTypeOf<(html: string) => string>();
    expectTypeOf(emptyNativeRenderOut).toEqualTypeOf<() => NativeRenderOut>();
    expectTypeOf(typographyStyle).toEqualTypeOf<
      (fontCss: string, lineHeight?: number) => TypographyFragments
    >();
    expectTypeOf(imageBlock).toEqualTypeOf<
      (
        image: SectionSpecImage | undefined,
        out: NativeRenderOut,
        context: string,
        opts?: ImageBlockOptions,
        resolutionContext?: NativeImageResolutionContext,
      ) => string
    >();
    expectTypeOf(headingBlock).toEqualTypeOf<
      (text: string, out: NativeRenderOut, opts?: HeadingBlockOptions) => string
    >();
    expectTypeOf(paragraphBlock).toEqualTypeOf<
      (text: string, out: NativeRenderOut, opts?: ParagraphBlockOptions) => string
    >();
    expectTypeOf(buttonBlock).toEqualTypeOf<
      (label: string, out: NativeRenderOut, opts?: { align?: 'left' | 'center' | 'right' }) => string
    >();
    expectTypeOf(ctaButton).toEqualTypeOf<
      (out: NativeRenderOut, ctx: NativeRenderCtx, button: NativeButtonInput, opts?: { align?: 'left' | 'center' }) => string
    >();
    expectTypeOf(sectionButtons).toEqualTypeOf<
      (section: SectionSpec, out: NativeRenderOut, ctx: NativeRenderCtx) => string[]
    >();
    expectTypeOf(column).toEqualTypeOf<(parts: string[], width?: string) => string>();
    expectTypeOf(columns).toEqualTypeOf<(cols: string[], opts?: { fullBleed?: boolean }) => string>();
    expectTypeOf(wrapSection).toEqualTypeOf<(parts: string[], opts: WrapSectionOptions) => string>();
  });
});
