#!/usr/bin/env node
import { execFileSync, spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const DLA_COMMIT = '1e393c535850ee1a9482f83459f779d0e225b027';
const DEFAULT_DLA_ROOT = '/Users/matt/projects/a8c/data-liberation-agent';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const packageRoot = path.resolve(__dirname, '..');
const fixtureUrl = pathToFileURL(path.join(packageRoot, 'src/__fixtures__/native-dispatch-renderer-cases.ts')).href;
const outputPath = path.join(packageRoot, 'src/__fixtures__/native-dispatch-renderer-dla-goldens.json');
const TSX_BOOTSTRAP_ENV = 'BLOCKS_ENGINE_NATIVE_DISPATCH_GOLDENS_TSX';

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

function normalizeCopy(value) {
  return String(value ?? '')
    .replace(/­/g, '')
    .replace(/[​-‍﻿]/g, '')
    .replace(/\s+/g, ' ')
    .trim();
}

function reconstructOptions() {
  return {
    patternSlug: 'native-dispatch-renderer-parity/single-section',
    title: 'Native Dispatch Renderer Parity',
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
    sourceUrl: 'https://example.test/native-dispatch-renderer-parity',
    slug: 'native-dispatch-renderer-parity',
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

function sentinelSection(sectionIndex, label) {
  return {
    sectionIndex,
    interactionModel: 'static',
    top: sectionIndex * 100,
    height: 360,
    headings: [`${label} sentinel heading`],
    bodyText: [`${label} sentinel body`],
    buttonLabels: [],
    images: [],
    icons: [],
    backgroundBrightness: 255,
    backgroundColor: 'rgb(255, 255, 255)',
    gradient: null,
    gradientSource: null,
    motionProfile: { motionClass: 'none', signals: [], animatedElements: 0 },
    dividerAbove: null,
    dividerBelow: null,
    layout: { containerWidth: 900, padding: '0px', childLayout: 'stack', columnCount: 1, gap: '20px' },
  };
}

function extractSectionGroup(markup, marker) {
  const groups = markup.match(/<!-- wp:group \{"tagName":"section"[\s\S]*?<\/section>\n<!-- \/wp:group -->/g) ?? [];
  const found = groups.find((group) => group.includes(marker));
  if (!found) throw new Error(`Could not find sandwiched section group containing ${marker}`);
  return found;
}

function countFilter(values, wanted) {
  const budget = new Map();
  for (const value of wanted.map(normalizeCopy).filter(Boolean)) {
    budget.set(value, (budget.get(value) ?? 0) + 1);
  }
  const out = [];
  for (const value of values) {
    const key = normalizeCopy(value);
    const left = budget.get(key) ?? 0;
    if (left > 0) {
      out.push(value);
      budget.set(key, left - 1);
    }
  }
  return out;
}

function sandwichedRenderOut(section, result) {
  if (result.sectionsRendered !== 3) {
    throw new Error(`Expected three rendered sections for chrome-sandwich case, got ${result.sectionsRendered}`);
  }
  if (result.fallbackDiagnostics.length > 0) {
    throw new Error(`Expected native renderer output, got fallback diagnostics: ${JSON.stringify(result.fallbackDiagnostics)}`);
  }
  const marker = section.headings[0] || section.bodyText[0] || String(section.sectionIndex);
  return {
    markup: extractSectionGroup(stripFinalBodyNewline(result.body), marker),
    expectedText: countFilter(result.expectedText, [...section.headings, ...(section.buttonLabels ?? [])]),
    bodyText: countFilter(result.bodyText, section.bodyText ?? []),
    assets: countFilter(result.expectedAssets, (section.images ?? []).map((image) => image.url)),
    flags: result.provenanceFlags.filter((flag) => flag.includes(`#${section.sectionIndex}`)),
    iconAssets: result.iconAssets.filter((asset) => asset.path.includes(`icon-${section.sectionIndex}`)),
  };
}

function extractCardGroup(markup) {
  const match = /<!-- wp:group \{"className":"is-replica-card"[\s\S]*?<!-- \/wp:group -->/.exec(markup);
  if (!match) throw new Error('Could not extract is-replica-card group from DLA output');
  return match[0];
}

const currentCommit = git(['rev-parse', 'HEAD']);
if (currentCommit !== DLA_COMMIT) {
  throw new Error(`DLA checkout must be at ${DLA_COMMIT}; got ${currentCommit}`);
}

const dirty = git(['status', '--porcelain']);
if (dirty) {
  throw new Error(`DLA checkout must be clean at ${DLA_COMMIT}; status:\n${dirty}`);
}

const { nativeDispatchRendererCaseGroups, NATIVE_DISPATCH_RENDERER_DERIVATION } = await import(fixtureUrl);
const { reconstructPagePattern } = await import(
  pathToFileURL(path.join(dlaRoot, 'src/lib/replicate/page-reconstruct.ts')).href
);

const cases = nativeDispatchRendererCaseGroups();
const renderSectionCase = (entry) => {
  const section = clone(entry.section);
  if (entry.chromeSandwich) {
    const result = reconstructPagePattern(
      [sentinelSection(9001, 'before'), section, sentinelSection(9002, 'after')],
      reconstructOptions(),
    );
    return capture(entry.id, sandwichedRenderOut(section, result));
  }
  return capture(entry.id, renderOutFromResult(reconstructPagePattern([section], reconstructOptions())));
};
const renderCardGroupCase = (entry) => {
  const result = reconstructPagePattern([clone(entry.dlaSection)], reconstructOptions());
  return capture(entry.id, {
    returnValue: extractCardGroup(stripFinalBodyNewline(result.body)),
  });
};

const parityFile = {
  version: 1,
  derivation: NATIVE_DISPATCH_RENDERER_DERIVATION,
  renderers: {
    renderCardGrid: cases.renderCardGrid.map(renderSectionCase),
    renderFaq: cases.renderFaq.map(renderSectionCase),
    renderCellGrid: cases.renderCellGrid.map(renderSectionCase),
    cardGroup: cases.cardGroup.map(renderCardGroupCase),
    renderSection: cases.renderSection.map(renderSectionCase),
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
