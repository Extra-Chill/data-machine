import { sanitize } from '../sanitize.js';
import type { FixResult } from '../pool/types.js';
import {
  CONVERT_REPORT_SCHEMA,
  FALLBACK_INVENTORY_CAP,
  type ConversionFinding,
  type ConvertReport,
} from './schema.js';
import { HTML_FINDING_CHAR_CAP } from './limits.js';

export interface BuildReportInput {
  inputHtml: string;
  blockMarkup: string;
  fixResult: FixResult;
  transformDurationMs: number;
}

function truncateSnippet(html: string): string {
  return Array.from(sanitize(html)).slice(0, HTML_FINDING_CHAR_CAP).join('');
}

function hasWarningOrError(findings: ConversionFinding[]): boolean {
  return findings.some(
    (finding) => finding.severity === 'warning' || finding.severity === 'error',
  );
}

function hasRealContent(html: string): boolean {
  return /\S/u.test(html);
}

function stripTags(html: string): string {
  return html
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/giu, '')
    .replace(/<style\b[^>]*>[\s\S]*?<\/style>/giu, '')
    .replace(/<[^>]+>/gu, ' ')
    .replace(/\s+/gu, ' ')
    .trim();
}

function excerpt(value: string): string {
  return truncateSnippet(value).replace(/\s+/gu, ' ').trim();
}

function countMatches(value: string, pattern: RegExp): number {
  return Array.from(value.matchAll(pattern)).length;
}

function findHeroImageLayeringRisk(inputHtml: string): ConversionFinding | undefined {
  const heroRegion =
    inputHtml.match(
      /<(?:section|div)\b[^>]*data-figma-node-name="[^"]*(?:home|hero|frame)[^"]*"[^>]*>[\s\S]{0,12000}?<\/\s*(?:section|div)>/iu,
    )?.[0] ?? inputHtml.slice(0, 12000);
  const imageBeforeBand =
    /data-figma-node-name="[^"]*(?:image|photo|bitmap|screen shot|pic|pexels|visit|doctor|field|sunset)[^"]*"[\s\S]{0,2500}?data-figma-node-name="[^"]*(?:rectangle|bg|background|band)[^"]*"[\s\S]{0,1200}?<(?:path|rect)\b[^>]*\bfill="(?:#[0-9a-f]{3,8}|rgba?\([^)]*\))"/iu.exec(
      heroRegion,
    );

  if (!imageBeforeBand) {
    return undefined;
  }

  return {
    code: 'hero_image_layering_risk',
    severity: 'warning',
    message:
      'Hero-like image markup is followed by a colored vector band, which can hide or flatten the image layer.',
    selector: '[data-figma-node-name*="hero"], [data-figma-node-name*="home"]',
    snippet: excerpt(imageBeforeBand[0]),
  };
}

function findBodyTextPromotedToHeading(inputHtml: string): ConversionFinding | undefined {
  for (const match of inputHtml.matchAll(/<h([1-6])\b([^>]*)>([\s\S]*?)<\/h\1>/giu)) {
    const attrs = match[2] ?? '';
    const text = stripTags(match[3] ?? '');
    const nameLooksBody =
      /data-figma-node-name="[^"]*(?:body|supporting text|text|lorem|description)[^"]*"/iu.test(
        attrs,
      );
    const textLooksBody = text.length >= 80 || /[.!?]\s+[A-Z0-9]/u.test(text);
    if (Number(match[1]) >= 5 && nameLooksBody && textLooksBody) {
      return {
        code: 'body_text_promoted_to_heading',
        severity: 'warning',
        message: 'Paragraph-like body copy was emitted as a low-level heading.',
        selector: `h${match[1]}`,
        snippet: excerpt(match[0]),
      };
    }
  }

  return undefined;
}

function findHeadingInsideListItem(inputHtml: string): ConversionFinding | undefined {
  const match = /<li\b[^>]*>[\s\S]{0,1200}?<h([1-6])\b[\s\S]*?<\/h\1>[\s\S]{0,200}?<\/li>/iu.exec(
    inputHtml,
  );
  if (!match) {
    return undefined;
  }

  return {
    code: 'heading_inside_list_item',
    severity: 'warning',
    message: 'List item content was promoted to heading markup, which often indicates a typography/list-structure anomaly.',
    selector: `li h${match[1]}`,
    snippet: excerpt(match[0]),
  };
}

function findScaffoldNoiseCandidate(inputHtml: string): ConversionFinding | undefined {
  const nodeNames = Array.from(
    inputHtml.matchAll(/data-figma-node-name="([^"]*)"/giu),
    (match) => match[1] ?? '',
  );
  if (nodeNames.length < 40) {
    return undefined;
  }

  const scaffoldCount = nodeNames.filter((name) =>
    /^(?:frame|group|rectangle|vector|path|mask|oval|ellipse|polygon|combined shape|bg|background|line|rule|dot|object|shape)(?:\b|\s|\d|-)/iu.test(
      name.trim(),
    ),
  ).length;
  const ratio = scaffoldCount / nodeNames.length;
  if (scaffoldCount < 30 || ratio < 0.35) {
    return undefined;
  }

  return {
    code: 'scaffold_noise_candidate',
    severity: 'warning',
    message: 'Figma scaffold nodes dominate the output and may be filter candidates before review.',
    selector: '[data-figma-node-name]',
    totalNodes: nodeNames.length,
    scaffoldNodes: scaffoldCount,
    scaffoldRatio: Number(ratio.toFixed(2)),
  };
}

function findSvgDenseRegion(inputHtml: string): ConversionFinding | undefined {
  let best: { region: string; svgCount: number; snippet: string } | undefined;
  for (const match of inputHtml.matchAll(
    /<(section|header|footer|article)\b[^>]*>[\s\S]*?<\/\1>/giu,
  )) {
    const html = match[0];
    const svgCount = countMatches(html, /<svg\b/giu);
    if (svgCount >= 12 && (!best || svgCount > best.svgCount)) {
      best = { region: match[1] ?? 'region', svgCount, snippet: html };
    }
  }

  if (!best) {
    return undefined;
  }

  return {
    code: 'svg_dense_region',
    severity: 'warning',
    message: 'A single semantic region contains many inline SVGs, indicating decorative vector density that may need consolidation.',
    selector: best.region,
    svgCount: best.svgCount,
    snippet: excerpt(best.snippet),
  };
}

function findRouteSelfLinkOddity(inputHtml: string): ConversionFinding | undefined {
  const pagePath = inputHtml.match(/data-page-path="([^"]+)"/iu)?.[1] ?? 'index.html';
  for (const match of inputHtml.matchAll(/<a\b([^>]*)>([\s\S]*?)<\/a>/giu)) {
    const attrs = match[1] ?? '';
    const href = attrs.match(/\bhref="([^"]+)"/iu)?.[1];
    if (!href || href.startsWith('#')) {
      continue;
    }

    const text = stripTags(match[2] ?? '');
    const selfLink = href === pagePath || (pagePath === 'index.html' && href === 'index.html');
    const nodeRoute = /data-figma-link-type="node"/iu.test(attrs);
    const looksLikeLogo = /^(?:home|logo|agency|client logo|the baseplate)$/iu.test(text);
    if (selfLink && nodeRoute && text && !looksLikeLogo) {
      return {
        code: 'route_self_link_oddity',
        severity: 'warning',
        message: 'A node-route link points back to the current page instead of a section anchor or distinct route.',
        selector: `a[href="${href}"]`,
        snippet: excerpt(match[0]),
      };
    }
  }

  return undefined;
}

function findDuplicateCanvasChrome(inputHtml: string): ConversionFinding | undefined {
  const articleHeadings = Array.from(
    inputHtml.matchAll(/<article\b[\s\S]*?<h[1-6]\b[^>]*>([\s\S]*?)<\/h[1-6]>[\s\S]*?<\/article>/giu),
    (match) => stripTags(match[1] ?? '').toLowerCase(),
  ).filter(Boolean);
  const duplicateHeading = articleHeadings.find(
    (heading, index) => articleHeadings.indexOf(heading) !== index,
  );
  const duplicateFeaturedRegions = countMatches(inputHtml, /data-figma-node-name="Featured Posts"/giu) >= 2;
  if (!duplicateHeading || !duplicateFeaturedRegions) {
    return undefined;
  }

  return {
    code: 'duplicate_canvas_chrome',
    severity: 'warning',
    message: 'Repeated canvas/card chrome contains duplicate article headings and may represent duplicated design-state scaffolding.',
    selector: '[data-figma-node-name="Featured Posts"]',
    duplicateText: duplicateHeading,
  };
}

function findSplitWordHeading(inputHtml: string): ConversionFinding | undefined {
  for (const match of inputHtml.matchAll(/<h([1-6])\b[^>]*>([\s\S]*?)<\/h\1>/giu)) {
    const inner = match[2] ?? '';
    if (/[A-Za-z]{2,}\s*\n\s*[a-z]{1,3}\b/u.test(inner)) {
      return {
        code: 'split_word_heading',
        severity: 'warning',
        message: 'Heading text contains a mid-word line break artifact.',
        selector: `h${match[1]}`,
        snippet: excerpt(match[0]),
      };
    }
  }

  return undefined;
}

function deriveHtmlQualityDiagnostics(inputHtml: string): ConversionFinding[] {
  return [
    findHeroImageLayeringRisk(inputHtml),
    findBodyTextPromotedToHeading(inputHtml),
    findHeadingInsideListItem(inputHtml),
    findScaffoldNoiseCandidate(inputHtml),
    findSvgDenseRegion(inputHtml),
    findRouteSelfLinkOddity(inputHtml),
    findDuplicateCanvasChrome(inputHtml),
    findSplitWordHeading(inputHtml),
  ].filter((finding): finding is ConversionFinding => finding !== undefined);
}

export function buildReport({
  inputHtml,
  blockMarkup,
  fixResult,
  transformDurationMs,
}: BuildReportInput): ConvertReport {
  const keptIslands = fixResult.htmlIslands.slice(0, FALLBACK_INVENTORY_CAP);
  const outputBytes = Buffer.byteLength(blockMarkup, 'utf8');
  const fallbacks: ConversionFinding[] = keptIslands.map((island) => ({
    code: 'unconverted_html',
    severity: 'warning',
    message: 'Unconverted HTML fallback preserved.',
    selector: `core/html[${island.index}]`,
    snippet: truncateSnippet(island.html),
  }));

  const diagnostics: ConversionFinding[] = fixResult.fixedIssues.map((issue) => ({
    code: 'normalized_markup',
    severity: 'info',
    message: issue,
  }));

  if (fixResult.degraded) {
    diagnostics.push({
      code: 'conversion_degraded',
      severity: 'warning',
      message: 'Conversion completed with degraded worker results.',
    });
  }

  const kept = keptIslands.length;
  const total = fixResult.htmlIslandCount;
  if (total > kept) {
    diagnostics.push({
      code: 'fallback_inventory_truncated',
      severity: 'info',
      message: 'Fallback inventory truncated.',
      total,
      kept,
    });
  }

  if (hasRealContent(inputHtml) && (fixResult.blockCount === 0 || outputBytes === 0)) {
    diagnostics.push({
      code: 'content_dropped',
      severity: 'warning',
      message: 'Input HTML contained content, but conversion produced an empty block result.',
    });
  }

  diagnostics.push(...deriveHtmlQualityDiagnostics(inputHtml));

  return {
    schema: CONVERT_REPORT_SCHEMA,
    status:
      total > 0 || hasWarningOrError(diagnostics) ? 'success_with_warnings' : 'success',
    blockMarkup,
    fallbacks,
    diagnostics,
    metrics: {
      inputBytes: Buffer.byteLength(inputHtml, 'utf8'),
      outputBytes,
      blockCount: fixResult.blockCount,
      fallbackCount: total,
      diagnosticCount: diagnostics.length,
      transformDurationMs,
    },
  };
}
