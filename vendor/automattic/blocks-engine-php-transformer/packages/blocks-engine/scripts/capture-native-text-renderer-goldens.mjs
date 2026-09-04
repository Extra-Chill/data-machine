#!/usr/bin/env node
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const DLA_COMMIT = '1e393c535850ee1a9482f83459f779d0e225b027';
const DEFAULT_DLA_ROOT = '/Users/matt/projects/a8c/data-liberation-agent';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const packageRoot = path.resolve(__dirname, '..');
const fixtureUrl = pathToFileURL(path.join(packageRoot, 'src/__fixtures__/native-text-renderer-cases.ts')).href;
const outputPath = path.join(packageRoot, 'src/__fixtures__/native-text-renderer-dla-goldens.json');

const args = new Set(process.argv.slice(2));
const shouldWrite = args.has('--write');
const shouldCheck = args.has('--check');
const shouldStdout = args.has('--stdout') || (!shouldWrite && !shouldCheck);
const dlaRoot = process.env.DLA_ROOT || DEFAULT_DLA_ROOT;

function git(args) {
  return execFileSync('git', ['-C', dlaRoot, ...args], { encoding: 'utf8' }).trim();
}

function stableJson(value) {
  return `${JSON.stringify(value, null, 2)}\n`;
}

function stripFinalBodyNewline(markup) {
  return markup.endsWith('\n') ? markup.slice(0, -1) : markup;
}

function extractGallery(markup) {
  const match = markup.match(/<!-- wp:gallery\b[\s\S]*?<!-- \/wp:gallery -->/);
  if (!match) throw new Error('DLA reconstruct output did not contain a wp:gallery block');
  return match[0];
}

function emptyOut() {
  return { markup: '', expectedText: [], bodyText: [], assets: [], flags: [], iconAssets: [] };
}

function capture(id, output) {
  return { id, output };
}

function reconstructOptions() {
  return {
    patternSlug: 'native-text-renderer-parity/single-section',
    title: 'Native Text Renderer Parity',
    paletteTokens: [
      { slug: 'text-default', hex: '#102030' },
      { slug: 'text-inverse', hex: '#f8fafc' },
      { slug: 'text-muted', hex: '#4b5563' },
      { slug: 'text-subtle', hex: '#6b7280' },
      { slug: 'accent-primary', hex: '#008060' },
      { slug: 'surface-base', hex: '#ffffff' },
      { slug: 'surface-raised', hex: '#e8eff1' },
      { slug: 'surface-inverse', hex: '#111827' },
    ],
    fontFamilies: [
      { slug: 'display', family: 'Caldera Display, serif' },
      { slug: 'body', family: 'Caldera, sans-serif' },
    ],
    sourceUrl: 'https://example.test/native-text-renderer-parity',
    slug: 'native-text-renderer-parity',
  };
}

function renderOutFromResult(result) {
  if (result.sectionsRendered !== 1) {
    throw new Error(`Expected exactly one rendered section, got ${result.sectionsRendered}`);
  }
  if (result.fallbackDiagnostics.length > 0) {
    throw new Error(`Expected native renderer output, got fallback diagnostics: ${JSON.stringify(result.fallbackDiagnostics)}`);
  }
  return {
    markup: stripFinalBodyNewline(result.body),
    expectedText: result.expectedText,
    bodyText: result.bodyText,
    assets: result.expectedAssets,
    flags: result.provenanceFlags,
    iconAssets: result.iconAssets,
  };
}

const currentCommit = git(['rev-parse', 'HEAD']);
if (currentCommit !== DLA_COMMIT) {
  throw new Error(`DLA checkout must be at ${DLA_COMMIT}; got ${currentCommit}`);
}

const { nativeTextRendererCaseGroups, NATIVE_TEXT_RENDERER_DERIVATION } = await import(fixtureUrl);
const { reconstructPagePattern } = await import(
  pathToFileURL(path.join(dlaRoot, 'src/lib/replicate/page-reconstruct.ts')).href
);

const cases = nativeTextRendererCaseGroups();
const renderSectionCase = (entry) =>
  capture(entry.id, renderOutFromResult(reconstructPagePattern([entry.section], reconstructOptions())));

const renderGalleryCase = (entry) => {
  const section = {
    sectionIndex: 90,
    interactionModel: 'gallery',
    top: 0,
    height: entry.opts?.sectionHeight ?? 240,
    headings: [],
    bodyText: [],
    buttonLabels: [],
    images: entry.images,
    icons: [],
    backgroundBrightness: 255,
    backgroundColor: 'rgb(255, 255, 255)',
    gradient: null,
    gradientSource: null,
    motionProfile: { motionClass: 'none', signals: [], animatedElements: 0 },
    dividerAbove: null,
    dividerBelow: null,
    layout: {
      containerWidth: 1100,
      padding: '0px',
      childLayout: 'grid',
      columnCount: 4,
      gap: '16px',
    },
  };
  const result = reconstructPagePattern([section], reconstructOptions());
  return capture(entry.id, {
    returnValue: extractGallery(stripFinalBodyNewline(result.body)),
    out: { ...emptyOut(), assets: result.expectedAssets },
  });
};

const parityFile = {
  version: 1,
  derivation: NATIVE_TEXT_RENDERER_DERIVATION,
  renderers: {
    renderTextBand: cases.renderTextBand.map(renderSectionCase),
    renderCover: cases.renderCover.map(renderSectionCase),
    renderMediaText: cases.renderMediaText.map(renderSectionCase),
    galleryBlock: cases.galleryBlock.map(renderGalleryCase),
  },
};

const json = stableJson(parityFile);

if (shouldCheck) {
  const existing = readFileSync(outputPath, 'utf8');
  if (existing !== json) {
    throw new Error(`${outputPath} is out of date with DLA ${DLA_COMMIT}`);
  }
}

if (shouldWrite) writeFileSync(outputPath, json);
if (shouldStdout) process.stdout.write(json);
