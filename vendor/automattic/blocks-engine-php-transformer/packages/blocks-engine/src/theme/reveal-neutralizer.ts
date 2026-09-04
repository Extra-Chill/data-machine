export const REVEAL_NEUTRALIZER_SELECTORS = [
  '.reveal',
  '.reveal-up',
  '.reveal-left',
  '.reveal-right',
  '.reveal-scale',
  '.reveal-stagger > *',
  '[data-reveal]',
] as const;

export function buildRevealNeutralizerCss(
  selectors: readonly string[] = REVEAL_NEUTRALIZER_SELECTORS
): string {
  return `/* wp-compat: reveal gates need JS that is not carried yet, so render them visible */
${selectors.join(',\n')} {
  opacity: 1 !important;
  transform: none !important;
}
`;
}

export const REVEAL_NEUTRALIZER_CSS = buildRevealNeutralizerCss();
