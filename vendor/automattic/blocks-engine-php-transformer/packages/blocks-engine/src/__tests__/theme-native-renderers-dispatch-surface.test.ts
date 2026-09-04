import { describe, expect, expectTypeOf, it } from 'vitest';

import {
  FLATTEN_PRONE_MODELS,
  MEDIA_LAYOUT_DENY,
  NON_CELL_GRID_MODELS,
  renderSection,
} from '../theme/native-renderers-dispatch.js';
import {
  cardGroup,
  renderCardGrid,
  renderCellGrid,
  renderFaq,
  type CardGroupPadding,
} from '../theme/native-renderers-grid.js';
import type { NativeRenderCtx, NativeRenderOut } from '../theme/native-reconstruct-types.js';
import type { InteractionModel, SectionSpec } from '../theme/section-spec.js';

describe('native renderer dispatcher frozen surface', () => {
  it('freezes the renderer export names and runtime availability', () => {
    expect({
      FLATTEN_PRONE_MODELS,
      MEDIA_LAYOUT_DENY,
      NON_CELL_GRID_MODELS,
      cardGroup,
      renderCardGrid,
      renderCellGrid,
      renderFaq,
      renderSection,
    }).toEqual(
      expect.objectContaining({
        FLATTEN_PRONE_MODELS: expect.any(Set),
        MEDIA_LAYOUT_DENY: expect.any(Set),
        NON_CELL_GRID_MODELS: expect.any(Set),
        cardGroup: expect.any(Function),
        renderCardGrid: expect.any(Function),
        renderCellGrid: expect.any(Function),
        renderFaq: expect.any(Function),
        renderSection: expect.any(Function),
      }),
    );
  });

  it('freezes the renderer type signatures', () => {
    expectTypeOf<CardGroupPadding>().toEqualTypeOf<{
      top: number;
      right: number;
      bottom: number;
      left: number;
    }>();
    expectTypeOf(NON_CELL_GRID_MODELS).toEqualTypeOf<ReadonlySet<InteractionModel>>();
    expectTypeOf(FLATTEN_PRONE_MODELS).toEqualTypeOf<ReadonlySet<InteractionModel>>();
    expectTypeOf(MEDIA_LAYOUT_DENY).toEqualTypeOf<ReadonlySet<InteractionModel>>();
    expectTypeOf(renderCardGrid).toEqualTypeOf<
      (section: SectionSpec, withButtons: boolean, ctx?: NativeRenderCtx) => NativeRenderOut
    >();
    expectTypeOf(renderFaq).toEqualTypeOf<(section: SectionSpec) => NativeRenderOut>();
    expectTypeOf(renderCellGrid).toEqualTypeOf<
      (section: SectionSpec, ctx: NativeRenderCtx) => NativeRenderOut
    >();
    expectTypeOf(cardGroup).toEqualTypeOf<
      (parts: string[], bgToken: string, dark: boolean, radius: number, padding: CardGroupPadding | null) => string
    >();
    expectTypeOf(renderSection).toEqualTypeOf<
      (section: SectionSpec, ctx: NativeRenderCtx) => NativeRenderOut
    >();
  });
});
