import * as cheerio from 'cheerio';
import type { SectionSpec } from './section-spec.js';

export interface CapturedSectionContent {
  text: string[];
  images: string[];
}

export interface CoverageResult {
  textCoverage: number;
  missingImages: string[];
  lost: boolean;
}

export const TEXT_FLOOR = 0.5;

export function foldText(value: string): string {
  return value
    .replace(/[\u2018\u2019\u201b]/g, "'")
    .replace(/[\u201c\u201d]/g, '"')
    .replace(/[\u2013\u2014\u2012]/g, '-')
    .replace(/\u2026/g, '...')
    .replace(/\s+/g, ' ')
    .trim()
    .toLowerCase();
}

function normalizedText(value: string | null | undefined): string | null {
  const folded = foldText(value ?? '');
  return folded.length > 0 ? folded : null;
}

function addText(values: string[], value: string | null | undefined): void {
  const normalized = normalizedText(value);
  if (!normalized) return;

  values.push(normalized);
}

function imageUrl(image: SectionSpec['images'][number]): string | null {
  const url = image.url.trim();
  if (url) return url;

  const sourceUrl = image.sourceUrl.trim();
  return sourceUrl ? sourceUrl : null;
}

export function captureSectionContent(spec: SectionSpec): CapturedSectionContent {
  const text: string[] = [];

  for (const heading of spec.headings) addText(text, heading);
  for (const body of spec.bodyText) addText(text, body);
  for (const label of spec.buttonLabels) addText(text, label);
  for (const button of spec.buttons ?? []) addText(text, button.label);

  const images = spec.images
    .map(imageUrl)
    .filter((url): url is string => url !== null);

  return { text, images };
}

export function measureConvertedCoverage(
  captured: CapturedSectionContent,
  convertedMarkup: string
): CoverageResult {
  const haystack = foldText(cheerio.load(convertedMarkup, null, false).root().text());
  const texts = captured.text.map(foldText).filter((text) => text.length > 0);
  const present = texts.filter((text) => haystack.includes(text)).length;
  const textCoverage = texts.length === 0 ? 1 : present / texts.length;

  const basename = (url: string): string =>
    (url.split(/[?#]/)[0].split('/').pop() || '').toLowerCase();
  const markupLc = convertedMarkup.toLowerCase();
  const missingImages = captured.images.filter((url) => {
    const base = basename(url);
    return base ? !markupLc.includes(base) : !!url && !markupLc.includes(url.toLowerCase());
  });

  const lost = missingImages.length > 0 || textCoverage < TEXT_FLOOR;

  return { textCoverage, missingImages, lost };
}

export function measureSectionCoverage(
  captured: CapturedSectionContent,
  renderedMarkup: string
): CoverageResult {
  const haystack = foldText(cheerio.load(renderedMarkup, null, false).root().text());
  const texts = captured.text.map(foldText).filter((text) => text.length > 0);
  const present = texts.filter((text) => haystack.includes(text)).length;
  const textCoverage = texts.length === 0 ? 1 : present / texts.length;

  const images = captured.images.map((url) => url.trim()).filter((url) => url.length > 0);
  const missingImages = images.filter((url) => !renderedMarkup.includes(url));
  const lost = missingImages.length > 0 || textCoverage < TEXT_FLOOR;

  return { textCoverage, missingImages, lost };
}
