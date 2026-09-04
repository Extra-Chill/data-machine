export interface ConversionContext {
  url: string;
  mediaMap?: Record<string, string>;
}

export type Converter = (
  html: string,
  ctx: ConversionContext,
) => string | null;

export interface RecipeRule {
  match: string;
  block: string;
  attrs?: Record<string, unknown>;
  inner?: 'innerHtml' | 'text' | 'images' | 'drop';
}

export type HtmlFallback = string | ((html: string) => string);
