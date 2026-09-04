/**
 * Benchmark corpus loader.
 *
 * A "fixture" is one corpus case: an `id` (`<producer>/<case>`) and the
 * `SectionSpec[]` that drives reconstruct. "Producer" is the corpus family — the
 * first path segment of the id — which gives attribution its cross-producer
 * signal.
 *
 * All section-shaped corpora feed the same deterministic Tier-A entry
 * (`reconstructNativeAggregate`): single-section renderers (review/image/cover/
 * media-text/FAQ/card-grid/…) contribute one section per case; the whole-page
 * baseline contributes many sections per case (multi-section segmentation).
 */
import { nativeDispatchRendererCaseGroups } from '../../src/__fixtures__/native-dispatch-renderer-cases.js';
import { nativeSectionRendererCaseGroups } from '../../src/__fixtures__/native-section-renderer-cases.js';
import { nativeTextRendererCaseGroups } from '../../src/__fixtures__/native-text-renderer-cases.js';
import { reconstructBaselineCases } from '../../src/__fixtures__/page-reconstruct-dla-baseline.corpus.js';
import type { SectionSpec } from '../../src/theme/section-spec.js';

export interface BenchFixture {
  id: string;
  producer: string;
  specs: SectionSpec[];
}

/** A case carrying a single SectionSpec; producer = the renderer-group key. */
interface SectionCase {
  id: string;
  section: SectionSpec;
}

function sectionGroups(): Record<string, SectionCase[]> {
  // Only section-shaped groups (each case has `.section`). Sub-renderer groups
  // that don't map to a whole section (galleryBlock, cardGroup) are excluded.
  const section = nativeSectionRendererCaseGroups();
  const text = nativeTextRendererCaseGroups();
  const dispatch = nativeDispatchRendererCaseGroups();
  // `renderSection` is intentionally excluded: it tests dispatch ROUTING
  // (expectedRenderer/chromeSandwich), and some cases (e.g. footer chrome) render
  // nothing in the aggregate — wrong signal for a rendering-fidelity benchmark.
  return {
    renderReviewGrid: section.renderReviewGrid,
    renderImageRow: section.renderImageRow,
    renderTextBand: text.renderTextBand,
    renderCover: text.renderCover,
    renderMediaText: text.renderMediaText,
    renderCardGrid: dispatch.renderCardGrid,
    renderFaq: dispatch.renderFaq,
    renderCellGrid: dispatch.renderCellGrid,
  };
}

export function loadFixtures(): BenchFixture[] {
  const fixtures: BenchFixture[] = [];

  for (const [producer, cases] of Object.entries(sectionGroups())) {
    for (const testCase of cases) {
      fixtures.push({ id: `${producer}/${testCase.id}`, producer, specs: [testCase.section] });
    }
  }

  // Whole-page producer: each case is a full page of sections (multi-section
  // segmentation, coverage/fallback exercise).
  for (const testCase of reconstructBaselineCases) {
    fixtures.push({ id: `pageBaseline/${testCase.id}`, producer: 'pageBaseline', specs: testCase.sections });
  }

  return fixtures.sort((a, b) => a.id.localeCompare(b.id));
}
