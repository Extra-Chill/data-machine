import { describe, expect, expectTypeOf, it } from 'vitest';

import { renderImageRow, renderReviewGrid } from '../theme/native-renderers-section.js';
import type { NativeRenderCtx, NativeRenderOut } from '../theme/native-reconstruct-types.js';
import type { SectionSpec } from '../theme/section-spec.js';

describe('native section renderer frozen surface', () => {
  it('freezes the renderer export names and runtime availability', () => {
    expect({
      renderImageRow,
      renderReviewGrid,
    }).toEqual(
      expect.objectContaining({
        renderImageRow: expect.any(Function),
        renderReviewGrid: expect.any(Function),
      })
    );
  });

  it('freezes the renderer type signatures', () => {
    expectTypeOf(renderReviewGrid).toEqualTypeOf<(section: SectionSpec) => NativeRenderOut>();
    expectTypeOf(renderImageRow).toEqualTypeOf<(section: SectionSpec, ctx?: NativeRenderCtx) => NativeRenderOut>();
  });
});
