import { walkBlocks } from '../block-tree.js';
import { createHash } from 'node:crypto';
import { HTML_FINDING_CHAR_CAP } from '../report/limits.js';
import { FALLBACK_INVENTORY_CAP } from '../report/schema.js';
import { sanitize } from '../sanitize.js';
import { bootstrap } from './bootstrap.js';
import { requireWp } from './require-wp.js';

export type HtmlIsland = {
  index: number;
  html: string;
};

export type HtmlIslandOccurrence = {
  fingerprint: string;
  html: string;
  count: number;
};

export type CanonicalizeResult = {
  html: string;
  changed: boolean;
  fixedIssues: string[];
  blockCount: number;
  htmlIslands: HtmlIsland[];
  htmlIslandCount: number;
  htmlIslandOccurrences: HtmlIslandOccurrence[];
  htmlIslandDistinctCount: number;
  htmlIslandOccurrencesTruncated: boolean;
  degraded: boolean;
};

type BlockAttributes = Record<string, unknown>;

type ValidationIssue =
  | string
  | {
      message?: unknown;
      args?: unknown[];
    };

type ParsedBlock = {
  name?: string | null;
  attributes: BlockAttributes;
  innerBlocks?: ParsedBlock[];
  isValid?: boolean;
  validationIssues?: ValidationIssue[];
  originalContent?: string;
  __unstableBlockSource?: {
    attrs?: BlockAttributes;
  };
};

type RawBlock = {
  blockName: string | null;
  attrs: BlockAttributes;
  innerBlocks: RawBlock[];
  innerHTML: string;
  innerContent: Array<string | null>;
};

type WordpressBlocks = {
  parse: (html: string) => ParsedBlock[];
  serialize: (blocks: ParsedBlock[]) => string;
  createBlock: (
    name: string,
    attributes?: BlockAttributes,
    innerBlocks?: ParsedBlock[],
  ) => ParsedBlock;
  getBlockAttributes: (
    name: string,
    sourceHtml: string,
    rawAttrs: BlockAttributes,
  ) => BlockAttributes;
};

type BlockSerializationParser = {
  parse: (html: string) => RawBlock[];
};

type WordpressRuntime = WordpressBlocks & {
  parseBlockGrammar: (html: string) => RawBlock[];
};

let wordpressRuntime: WordpressRuntime | undefined;

function loadWordPress(): WordpressRuntime {
  bootstrap();

  if (wordpressRuntime) {
    return wordpressRuntime;
  }

  const {
    parse,
    serialize,
    createBlock,
    getBlockAttributes,
  } = requireWp('@wordpress/blocks') as unknown as WordpressBlocks;
  const { parse: parseBlockGrammar } = requireWp(
    '@wordpress/block-serialization-default-parser',
  ) as unknown as BlockSerializationParser;

  wordpressRuntime = {
    parse,
    serialize,
    createBlock,
    getBlockAttributes,
    parseBlockGrammar,
  };

  return wordpressRuntime;
}

function parseAttributes(attrString: string | undefined): Record<string, string> {
  const attrs: Record<string, string> = {};
  if (!attrString) return attrs;

  const doubleQuotePattern = /(\S+)="([^"]*)"/g;
  let match;
  while ((match = doubleQuotePattern.exec(attrString)) !== null) {
    attrs[match[1]] = match[2];
  }

  const singleQuotePattern = /(\S+)='([^']*)'/g;
  while ((match = singleQuotePattern.exec(attrString)) !== null) {
    if (!(match[1] in attrs)) {
      attrs[match[1]] = match[2];
    }
  }

  return attrs;
}

function mergeAttributes(
  outerAttrs: string | undefined,
  innerAttrs: string | undefined,
): Record<string, string> {
  const outer = parseAttributes(outerAttrs);
  const inner = parseAttributes(innerAttrs);
  const merged = { ...outer };

  for (const [key, value] of Object.entries(inner)) {
    if (key === 'class') {
      const outerClasses = (outer.class || '').split(/\s+/).filter(Boolean);
      const innerClasses = value.split(/\s+/).filter(Boolean);
      const allClasses = [...new Set([...outerClasses, ...innerClasses])];
      merged.class = allClasses.join(' ');
    } else if (key === 'style') {
      const outerStyles = (outer.style || '').split(';').filter(Boolean);
      const innerStyles = value.split(';').filter(Boolean);

      const styleMap: Record<string, string> = {};
      for (const style of [...outerStyles, ...innerStyles]) {
        const colonIdx = style.indexOf(':');
        if (colonIdx > 0) {
          const prop = style.substring(0, colonIdx).trim();
          const val = style.substring(colonIdx + 1).trim();
          styleMap[prop] = val;
        }
      }

      merged.style = Object.entries(styleMap)
        .map(([prop, val]) => `${prop}:${val}`)
        .join(';');
    } else if (!(key in outer)) {
      merged[key] = value;
    }
  }

  return merged;
}

function serializeAttributes(attrs: Record<string, string>): string {
  const parts = [];
  for (const [key, value] of Object.entries(attrs)) {
    parts.push(`${key}="${value}"`);
  }
  return parts.length > 0 ? ' ' + parts.join(' ') : '';
}

function fixNestedParagraphs(htmlContent: string): string {
  if (!htmlContent.includes('<!-- wp:paragraph')) {
    return htmlContent;
  }

  const wpParagraphBlockPattern =
    /(<!-- wp:paragraph[^>]*-->)([\s\S]*?)(<!-- \/wp:paragraph -->)/g;
  const nestedPPattern =
    /<p(\s[^>]*)?>(\s*)<p(\s[^>]*)?>([^]*?)<\/p>(\s*)<\/p>/gi;

  let result = htmlContent;
  let totalFixCount = 0;

  result = result.replace(
    wpParagraphBlockPattern,
    (
      _fullMatch: string,
      openComment: string,
      blockContent: string,
      closeComment: string,
    ) => {
      let fixedContent = blockContent;
      let prevContent;
      let blockFixCount = 0;

      do {
        prevContent = fixedContent;
        fixedContent = fixedContent.replace(
          nestedPPattern,
          (
            _match: string,
            outerAttrs: string | undefined,
            _ws1: string,
            innerAttrs: string | undefined,
            innerContent: string,
            _ws2: string,
          ) => {
            blockFixCount++;
            const mergedAttrs = mergeAttributes(outerAttrs, innerAttrs);
            const attrString = serializeAttributes(mergedAttrs);
            return `<p${attrString}>${innerContent}</p>`;
          },
        );
      } while (fixedContent !== prevContent);

      totalFixCount += blockFixCount;
      return `${openComment}${fixedContent}${closeComment}`;
    },
  );

  if (totalFixCount > 0) {
    console.error(
      `[ParagraphFixer] Fixed ${totalFixCount} nested <p> tag(s) in WordPress paragraph blocks`,
    );
  }

  return result;
}

// Did parse() alter or drop any attribute the document's comment delimiter
// declared? Comment attrs are plain JSON, so stringify equality is exact.
function commentAttrsAltered(
  parsedAttrs: BlockAttributes,
  rawAttrs: BlockAttributes,
): boolean {
  return Object.keys(rawAttrs).some(
    (key) => JSON.stringify(parsedAttrs[key]) !== JSON.stringify(rawAttrs[key]),
  );
}

//
// Comment-delimiter attrs are authoritative (this mirrors what WordPress
// persists for the block). parse() can silently lose them on two paths:
//
//   1. Invalid blocks: built-in "validation fixes" (fixCustomClassname /
//      ariaLabel / anchor) re-derive those attrs from the STALE inner HTML -
//      deleting author-intent values that only exist in the comment attrs
//      (e.g. a hoisted "is-style-lib-*" className whose inner HTML still
//      carries the pre-hoist inline styles).
//   2. Deprecation hijack: an eligible deprecated version (core/paragraph)
//      can "successfully migrate" the block - marking it VALID while eating
//      comment attrs absent from the deprecated schema (className,
//      fontFamily) and swallowing the whole outer markup into `content`.
//
// `rawBlock` is this block's counterpart from the grammar-level parser
// (verbatim comment attrs, untouched by either path). When parse() altered
// any declared attr, re-derive the attribute set from the original inner
// HTML + raw comment attrs via getBlockAttributes() - the same call
// parse() itself makes BEFORE validation fixes / deprecations run. Sourced
// attributes (content etc.) never appear in comment attrs, so author intent
// can't clobber them; createBlock() drops keys unknown to the current schema.
//
function fixBlockRecursively(
  block: ParsedBlock,
  rawBlock: RawBlock | undefined,
  getBlockAttributes: WordpressBlocks['getBlockAttributes'],
  createBlock: WordpressBlocks['createBlock'],
): { block: ParsedBlock; wasFixed: boolean } {
  const fixedInnerBlocks = [];

  if (block.innerBlocks && block.innerBlocks.length > 0) {
    const rawInner = (rawBlock && rawBlock.innerBlocks) || [];
    let rawIndex = 0;
    for (const innerBlock of block.innerBlocks) {
      // Align the raw pointer: parse() drops raw nodes that produce no block
      // (whitespace-only freeform, unregistered types), so pair strictly by
      // block name and skip raw nodes that have no parsed counterpart. If
      // alignment is lost we simply stop pairing - same behavior as before.
      while (
        rawIndex < rawInner.length &&
        rawInner[rawIndex].blockName !== innerBlock.name
      ) {
        rawIndex++;
      }
      const rawInnerBlock =
        rawIndex < rawInner.length ? rawInner[rawIndex++] : undefined;
      const result = fixBlockRecursively(
        innerBlock,
        rawInnerBlock,
        getBlockAttributes,
        createBlock,
      );
      fixedInnerBlocks.push(result.block);
    }
  }

  if (!block.name) {
    return { block, wasFixed: false };
  }

  let attributes = block.attributes;
  const rawCommentAttrs =
    (rawBlock && rawBlock.blockName === block.name && rawBlock.attrs) ||
    (block.__unstableBlockSource && block.__unstableBlockSource.attrs) ||
    null;
  if (rawCommentAttrs && commentAttrsAltered(block.attributes, rawCommentAttrs)) {
    const sourceHtml =
      typeof block.originalContent === 'string'
        ? block.originalContent
        : (rawBlock && rawBlock.innerHTML) || '';
    attributes = getBlockAttributes(block.name, sourceHtml, rawCommentAttrs);
  }

  const fixedBlock = createBlock(
    block.name,
    attributes,
    fixedInnerBlocks.length > 0 ? fixedInnerBlocks : undefined,
  );

  return { block: fixedBlock, wasFixed: true };
}

function formatValidationIssue(issue: ValidationIssue): string {
  if (typeof issue === 'string') {
    return issue;
  }

  if (typeof issue.message === 'string') {
    return issue.message;
  }

  if (Array.isArray(issue.args) && typeof issue.args[0] === 'string') {
    const template = issue.args[0];
    const values = issue.args.slice(1).map((value) => {
      if (typeof value === 'string') return value;
      if (Array.isArray(value) && value.every(Array.isArray)) {
        return '[' + value.map((attr) => attr[0]).join(', ') + ']';
      }
      if (typeof value === 'object' && value !== null) return '{...}';
      return String(value);
    });
    let msg = template;
    values.forEach((value) => {
      msg = msg.replace(/%[os]/, value);
    });
    return msg;
  }

  return JSON.stringify(issue);
}

function collectIssues(blockList: ParsedBlock[], fixedIssues: string[]): void {
  for (const block of blockList) {
    if (!block.isValid) {
      const blockName = block.name || 'unknown';
      const blockIssues = block.validationIssues || [];
      if (blockIssues.length > 0) {
        for (const issue of blockIssues) {
          const msg = formatValidationIssue(issue);
          fixedIssues.push(`${blockName}: ${msg}`);
        }
      } else {
        fixedIssues.push(`${blockName}: Block marked as invalid`);
      }
    }
    if (block.innerBlocks && block.innerBlocks.length > 0) {
      collectIssues(block.innerBlocks, fixedIssues);
    }
  }
}

function truncateHtmlIsland(html: string): string {
  return Array.from(html).slice(0, HTML_FINDING_CHAR_CAP).join('');
}

function collectInventory(rawBlocks: RawBlock[]): {
  blockCount: number;
  htmlIslands: HtmlIsland[];
  htmlIslandCount: number;
  htmlIslandOccurrences: HtmlIslandOccurrence[];
  htmlIslandDistinctCount: number;
  htmlIslandOccurrencesTruncated: boolean;
} {
  let blockCount = 0;
  let htmlIslandCount = 0;
  const htmlIslands: HtmlIsland[] = [];
  const distinctOccurrenceHashes = new Set<string>();
  const sampledOccurrences = new Map<string, { html: string; count: number }>();

  walkBlocks(rawBlocks, (block) => {
    blockCount++;

    if (block.blockName !== 'core/html') {
      return;
    }

    const island = {
      index: htmlIslandCount,
      html: truncateHtmlIsland(block.innerHTML || ''),
    };
    const sanitizedHtml = sanitize(block.innerHTML || '');
    const fingerprint = createHash('sha256').update(sanitizedHtml).digest('hex');
    distinctOccurrenceHashes.add(fingerprint);
    if (sampledOccurrences.has(fingerprint) || sampledOccurrences.size < FALLBACK_INVENTORY_CAP) {
      const occurrence = sampledOccurrences.get(fingerprint);
      sampledOccurrences.set(fingerprint, {
        html: occurrence?.html ?? truncateHtmlIsland(sanitizedHtml),
        count: (occurrence?.count ?? 0) + 1,
      });
    }
    if (htmlIslands.length < FALLBACK_INVENTORY_CAP) {
      htmlIslands.push(island);
    }
    htmlIslandCount++;
  });

  return {
    blockCount,
    htmlIslands,
    htmlIslandCount,
    htmlIslandOccurrences: [...sampledOccurrences].map(([fingerprint, occurrence]) => ({ fingerprint, ...occurrence })),
    htmlIslandDistinctCount: distinctOccurrenceHashes.size,
    htmlIslandOccurrencesTruncated: distinctOccurrenceHashes.size > sampledOccurrences.size,
  };
}

function formatCaughtError(error: unknown): string {
  if (error instanceof Error && error.message) {
    return error.message;
  }
  if (typeof error === 'string') {
    return error;
  }
  return String(error);
}

export function canonicalize(markup: string): CanonicalizeResult {
  const {
    parse,
    serialize,
    createBlock,
    getBlockAttributes,
    parseBlockGrammar,
  } = loadWordPress();

  try {
    const preFixedContent = fixNestedParagraphs(markup);
    const blocks = parse(preFixedContent);
    // Grammar-level parse of the same content: verbatim comment attrs,
    // untouched by validation fixes or deprecation migrations.
    const rawBlocks = parseBlockGrammar(preFixedContent);

    const fixedIssues: string[] = [];
    collectIssues(blocks, fixedIssues);

    let rawIndex = 0;
    const fixedBlocks = blocks.map((block) => {
      while (
        rawIndex < rawBlocks.length &&
        rawBlocks[rawIndex].blockName !== block.name
      ) {
        rawIndex++;
      }
      const rawBlock =
        rawIndex < rawBlocks.length ? rawBlocks[rawIndex++] : undefined;
      return fixBlockRecursively(
        block,
        rawBlock,
        getBlockAttributes,
        createBlock,
      ).block;
    });

    let fixedHtml = serialize(fixedBlocks);

    const beforeParaFix = fixedHtml;
    fixedHtml = fixNestedParagraphs(fixedHtml);
    if (preFixedContent !== markup || fixedHtml !== beforeParaFix) {
      fixedIssues.push('core/paragraph: Nested paragraph tags detected and removed');
    }

    const wasChanged = fixedHtml !== preFixedContent;

    if (fixedIssues.length > 0) {
      console.error(`[BlockFixer] Found ${fixedIssues.length} invalid block(s)`);
      for (const issue of fixedIssues) {
        console.error(`  - ${issue}`);
      }
    }

    if (wasChanged) {
      console.error(
        `[BlockFixer] HTML normalized (re-serialized ${blocks.length} block(s))`,
      );
    }

    const inventory = collectInventory(parseBlockGrammar(fixedHtml));

    return {
      html: fixedHtml,
      changed: wasChanged,
      fixedIssues,
      ...inventory,
      degraded: false,
    };
  } catch (error) {
    console.error('[BlockFixer] Error fixing blocks:', error);
    const errorMessage = formatCaughtError(error);
    return {
      html: markup,
      changed: false,
      fixedIssues: [`canonicalize degraded: ${errorMessage}`],
      blockCount: 0,
      htmlIslands: [],
      htmlIslandCount: 0,
      htmlIslandOccurrences: [],
      htmlIslandDistinctCount: 0,
      htmlIslandOccurrencesTruncated: false,
      degraded: true,
    };
  }
}
