#!/usr/bin/env node
import { execFileSync, spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const DLA_COMMIT = '1e393c535850ee1a9482f83459f779d0e225b027';
const DEFAULT_DLA_ROOT = '/Users/matt/projects/a8c/data-liberation-agent';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const packageRoot = path.resolve(__dirname, '..');
const fixtureUrl = pathToFileURL(path.join(packageRoot, 'src/__fixtures__/native-section-renderer-cases.ts')).href;
const outputPath = path.join(packageRoot, 'src/__fixtures__/native-section-renderer-dla-goldens.json');
const TSX_BOOTSTRAP_ENV = 'BLOCKS_ENGINE_NATIVE_SECTION_GOLDENS_TSX';

function hasTsxLoader() {
  return process.execArgv.some((arg, index, argv) => {
    if (arg === '--import') return argv[index + 1]?.includes('tsx');
    return arg.startsWith('--import=') && arg.includes('tsx');
  });
}

if (!process.env[TSX_BOOTSTRAP_ENV] && !hasTsxLoader()) {
  const result = spawnSync(process.execPath, ['--import', 'tsx', fileURLToPath(import.meta.url), ...process.argv.slice(2)], {
    cwd: packageRoot,
    env: { ...process.env, [TSX_BOOTSTRAP_ENV]: '1' },
    stdio: 'inherit',
  });
  if (result.error) throw result.error;
  process.exit(result.status ?? 1);
}

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

function clone(value) {
  if (value === undefined) return value;
  return JSON.parse(JSON.stringify(value));
}

function stripFinalBodyNewline(markup) {
  return markup.endsWith('\n') ? markup.slice(0, -1) : markup;
}

function capture(id, output) {
  return { id, output };
}

function reconstructOptions() {
  return {
    patternSlug: 'native-section-renderer-parity/single-section',
    title: 'Native Section Renderer Parity',
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
    sourceUrl: 'https://example.test/native-section-renderer-parity',
    slug: 'native-section-renderer-parity',
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

const dirty = git(['status', '--porcelain']);
if (dirty) {
  throw new Error(`DLA checkout must be clean at ${DLA_COMMIT}; status:\n${dirty}`);
}

const { nativeSectionRendererCaseGroups, NATIVE_SECTION_RENDERER_DERIVATION } = await import(fixtureUrl);
const { reconstructPagePattern } = await import(
  pathToFileURL(path.join(dlaRoot, 'src/lib/replicate/page-reconstruct.ts')).href
);

const cases = nativeSectionRendererCaseGroups();
const renderSectionCase = (entry) =>
  capture(entry.id, renderOutFromResult(reconstructPagePattern([clone(entry.section)], reconstructOptions())));

const parityFile = {
  version: 1,
  derivation: NATIVE_SECTION_RENDERER_DERIVATION,
  renderers: {
    renderReviewGrid: cases.renderReviewGrid.map(renderSectionCase),
    renderImageRow: cases.renderImageRow.map(renderSectionCase),
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
