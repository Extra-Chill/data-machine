import type { HoistedVariation, HoistPage, HoistResult } from '../../theme/index.js';

export const DLA_VARIATION_HOIST_COMMIT: string;
export const DLA_VARIATION_HOIST_PATH: string;
export const DLA_VARIATION_HOIST_BLOB: string;
export const VARIATION_HOIST_DERIVATION: string;

export interface VariationHoistCase {
  id: string;
  pages: HoistPage[];
  options?: { minInstances?: number };
  swapMarkup: string;
}

export interface VariationHoistImpl {
  hoistVariations(pagesIn: HoistPage[], opts?: { minInstances?: number }): HoistResult;
  applyHoistSwaps(markup: string, variations: HoistedVariation[]): string;
}

export function variationHoistCases(): VariationHoistCase[];
export function runVariationHoistParity(impl: VariationHoistImpl): unknown;
