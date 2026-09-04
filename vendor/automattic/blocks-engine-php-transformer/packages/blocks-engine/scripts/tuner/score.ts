/**
 * Scoring core for the reconstruct fidelity benchmark.
 *
 * Pure + deterministic: parses block markup with the no-DOM WordPress
 * serialization parser, measures the produced tree against an expected tree
 * (structure + content), and exposes the coverage + suiteHash primitives. No
 * Gutenberg boot, no network. Ported in shape from humanmade/block-runner's
 * tuner score core, written from scratch against our own types — block-runner is
 * NOT a dependency.
 */
import { createHash } from 'node:crypto';
import { parse } from '@wordpress/block-serialization-default-parser';
import { foldText } from '../../src/theme/section-coverage.js';

export interface ExpectedNode {
  block: string;
  contains?: string;
  children?: ExpectedNode[];
}

export interface ParsedNode {
  name: string;
  attrs: Record<string, unknown>;
  innerHTML: string;
  children: ParsedNode[];
}

export interface Tally {
  structureTotal: number;
  structureMatched: number;
  contentTotal: number;
  contentMatched: number;
  misses: string[];
}

type RawBlock = ReturnType<typeof parse>[number];

/** Parse block markup into a normalized tree, dropping freeform/whitespace nodes. */
export function parseToTree(markup: string): ParsedNode[] {
  return normalize(parse(markup));
}

function normalize(blocks: RawBlock[]): ParsedNode[] {
  const out: ParsedNode[] = [];
  for (const block of blocks) {
    if (!block.blockName) continue; // freeform leak / inter-block whitespace
    out.push({
      name: block.blockName,
      attrs: (block.attrs as Record<string, unknown> | null) ?? {},
      innerHTML: typeof block.innerHTML === 'string' ? block.innerHTML : '',
      children: normalize(block.innerBlocks ?? []),
    });
  }
  return out;
}

/**
 * Text a `contains` assertion can match: a block's attribute values (where core
 * blocks keep paragraph/heading content, button text, image url/alt) plus its own
 * inner HTML. The serialization parser's `innerHTML` is wrapper-only — child block
 * content is excluded — so this captures a container's OWN text (a details
 * `<summary>`, a quote `<cite>`, wrapper classes) without leaking descendant block
 * text up to a parent.
 */
export function blockText(node: ParsedNode): string {
  return `${JSON.stringify(node.attrs ?? {})} ${node.innerHTML}`;
}

/** Find the produced block matching `exp` among `candidates`, then recurse in order. */
export function matchNode(
  exp: ExpectedNode,
  candidates: ParsedNode[],
  tally: Tally,
  pathLabel: string,
): ParsedNode | undefined {
  tally.structureTotal += 1;
  const match = candidates.find((block) => block.name === exp.block);
  const here = `${pathLabel} > ${exp.block}`;

  if (!match) {
    tally.misses.push(`expected ${exp.block} (${pathLabel}) — not found`);
    countMissedSubtree(exp, tally);
    return undefined;
  }

  tally.structureMatched += 1;

  if (exp.contains !== undefined) {
    tally.contentTotal += 1;
    // An image-asset `contains` (a filename) asserts that an image LANDED here,
    // not which file the producer named — satisfy it by the presence of an image
    // source; keep exact substring matching for real text.
    const ok = isAssetAssertion(exp.contains)
      ? hasImageSource(match)
      : blockText(match).includes(exp.contains);
    if (ok) {
      tally.contentMatched += 1;
    } else {
      tally.misses.push(`${here} — expected to contain "${exp.contains}"`);
    }
  }

  if (exp.children?.length) {
    let cursor = 0;
    const kids = match.children;
    for (const child of exp.children) {
      const window = kids.slice(cursor);
      const found = matchNode(child, window, tally, here);
      if (found) cursor += window.indexOf(found) + 1;
    }
  }

  return match;
}

/** Empty tally for a fresh scoring pass. */
export function emptyTally(): Tally {
  return { structureTotal: 0, structureMatched: 0, contentTotal: 0, contentMatched: 0, misses: [] };
}

/** Match a list of top-level expected nodes, in order, against the output tree. */
export function scoreTree(expected: ExpectedNode[], output: ParsedNode[], label = 'root'): Tally {
  const tally = emptyTally();
  let cursor = 0;
  for (const node of expected) {
    const window = output.slice(cursor);
    const found = matchNode(node, window, tally, label);
    if (found) cursor += window.indexOf(found) + 1;
  }
  return tally;
}

/** Derive a structure-only expected tree (names + nesting) from a parsed output tree. */
export function parsedToExpected(nodes: ParsedNode[]): ExpectedNode[] {
  return nodes.map((node) => {
    const expected: ExpectedNode = { block: node.name };
    if (node.children.length > 0) expected.children = parsedToExpected(node.children);
    return expected;
  });
}

function countMissedSubtree(exp: ExpectedNode, tally: Tally): void {
  if (exp.contains !== undefined) tally.contentTotal += 1;
  for (const child of exp.children ?? []) {
    tally.structureTotal += 1;
    countMissedSubtree(child, tally);
  }
}

function isAssetAssertion(contains: string): boolean {
  return /\.(png|jpe?g|svg|webp|gif|avif)$/i.test(contains);
}

function hasImageSource(node: ParsedNode): boolean {
  const attrs = node.attrs ?? {};
  for (const key of ['url', 'mediaUrl', 'src']) {
    const value = attrs[key];
    if (typeof value === 'string' && value.length > 0) return true;
  }
  if (/<img[^>]+src=/i.test(node.innerHTML)) return true;
  return node.children.some(hasImageSource);
}

/**
 * Coverage = fraction of the expected visible words that survive into the output.
 * Catches silent content loss (text dropped entirely) — distinct from structure
 * (wrong blocks). `expectedText` comes from the reconstruct aggregate's deduped
 * `expectedText`.
 */
export function coverage(expectedText: string[], outputMarkup: string): number {
  const words = [...new Set(expectedText.flatMap((text) => foldText(text).match(/[a-z0-9]{3,}/g) ?? []))];
  if (words.length === 0) return 1;
  const out = visibleText(outputMarkup);
  return words.filter((word) => out.includes(word)).length / words.length;
}

function visibleText(markup: string): string {
  const stripped = markup.replace(/<!--[\s\S]*?-->/g, ' ').replace(/<[^>]+>/g, ' ');
  return foldText(stripped);
}

export interface ScoreAxes {
  structurePct: number;
  contentPct: number;
  valid: boolean;
}

/** Blend the axes into a 0–100 score: structure-dominant, halved when invalid. */
export function scoreFromAxes({ structurePct, contentPct, valid }: ScoreAxes): number {
  let score = 0.75 * structurePct + 0.25 * contentPct;
  if (!valid) score *= 0.5;
  return Math.round(score * 100);
}

/** Order-sensitive hash of the suite's identifying parts. */
export function suiteHash(parts: string[]): string {
  const hash = createHash('sha256');
  for (const part of parts) {
    hash.update(part);
    hash.update('\0');
  }
  return `sha256:${hash.digest('hex').slice(0, 12)}`;
}

export function pct(matched: number, total: number): number {
  return total === 0 ? 1 : matched / total;
}
