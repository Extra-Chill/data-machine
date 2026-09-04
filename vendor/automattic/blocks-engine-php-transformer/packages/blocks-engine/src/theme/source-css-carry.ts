export interface SourceCssCarryOptions {
  /**
   * Source CSS is carried by default. Consumers that already carry the same CSS
   * through their own compatibility layer can opt out to avoid duplicate output.
   */
  carrySourceCss?: boolean;
}

export interface ThemeAssemblySourceCssInput {
  sourceCss?: string;
}

export function hasCarriedSourceCss(sourceCss: string | undefined): boolean {
  return (sourceCss ?? '').trim().length > 0;
}

export function shouldCarrySourceCss(
  sourceCss: string | undefined,
  options: SourceCssCarryOptions | undefined
): boolean {
  return options?.carrySourceCss !== false && hasCarriedSourceCss(sourceCss);
}

export function appendCarriedSourceCss(styleCss: string, sourceCss: string | undefined): string {
  if (!hasCarriedSourceCss(sourceCss)) return styleCss;
  const prefix = styleCss.endsWith('\n') ? styleCss : `${styleCss}\n`;
  return `${prefix}${sourceCss}`;
}
