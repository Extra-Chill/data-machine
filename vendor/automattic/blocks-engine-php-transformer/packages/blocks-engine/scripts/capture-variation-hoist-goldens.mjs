#!/usr/bin/env node
import { execFileSync, spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const DEFAULT_DLA_ROOT = '/Users/matt/projects/a8c/data-liberation-agent';
const TSX_BOOTSTRAP_ENV = 'BLOCKS_ENGINE_VARIATION_HOIST_GOLDENS_TSX';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const packageRoot = path.resolve(__dirname, '..');
const corpusUrl = pathToFileURL(
  path.join(packageRoot, 'src/__tests__/fixtures/variation-hoist-corpus.js')
).href;
const outputPath = path.join(packageRoot, 'src/__tests__/fixtures/variation-hoist-dla-goldens.json');

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

const {
  DLA_VARIATION_HOIST_BLOB,
  DLA_VARIATION_HOIST_COMMIT,
  DLA_VARIATION_HOIST_PATH,
  runVariationHoistParity,
} = await import(corpusUrl);

const args = new Set(process.argv.slice(2));
const shouldWrite = args.has('--write');
const shouldCheck = args.has('--check');
const shouldStdout = args.has('--stdout') || (!shouldWrite && !shouldCheck);
const dlaRoot = process.env.DLA_ROOT || DEFAULT_DLA_ROOT;

const explicitModeCount = [shouldWrite, shouldCheck, args.has('--stdout')].filter(Boolean).length;
if (explicitModeCount > 1) {
  throw new Error('Use only one mode: --write, --check, or --stdout.');
}

function git(gitArgs) {
  return execFileSync('git', ['-C', dlaRoot, ...gitArgs], { encoding: 'utf8' }).trim();
}

function stableJson(value) {
  return `${JSON.stringify(value, null, 2)}\n`;
}

function verifyDlaOracle() {
  const currentCommit = git(['rev-parse', 'HEAD']);
  if (currentCommit !== DLA_VARIATION_HOIST_COMMIT) {
    throw new Error(`DLA checkout must be at ${DLA_VARIATION_HOIST_COMMIT}; got ${currentCommit}`);
  }

  const headBlob = git(['rev-parse', `HEAD:${DLA_VARIATION_HOIST_PATH}`]);
  if (headBlob !== DLA_VARIATION_HOIST_BLOB) {
    throw new Error(
      `DLA ${DLA_VARIATION_HOIST_PATH} HEAD blob must be ${DLA_VARIATION_HOIST_BLOB}; got ${headBlob}`
    );
  }

  const liveBlob = git(['hash-object', DLA_VARIATION_HOIST_PATH]);
  if (liveBlob !== DLA_VARIATION_HOIST_BLOB) {
    throw new Error(
      `DLA ${DLA_VARIATION_HOIST_PATH} working tree blob must be ${DLA_VARIATION_HOIST_BLOB}; got ${liveBlob}`
    );
  }

  const requiredStatus = git([
    'status',
    '--porcelain',
    '--',
    DLA_VARIATION_HOIST_PATH,
    'src/lib/replicate/form-blocks.ts',
  ]);
  if (requiredStatus) {
    throw new Error(`DLA required variation-hoist sources must be clean:\n${requiredStatus}`);
  }
}

function report(mode, parityFile) {
  process.stderr.write(
    `variation-hoist goldens ${mode}: DLA commit=${DLA_VARIATION_HOIST_COMMIT} blob=${DLA_VARIATION_HOIST_BLOB} cases=${parityFile.cases.length}\n`
  );
}

verifyDlaOracle();

const dlaVariationHoist = await import(pathToFileURL(path.join(dlaRoot, DLA_VARIATION_HOIST_PATH)).href);
const parityFile = runVariationHoistParity({
  hoistVariations: dlaVariationHoist.hoistVariations,
  applyHoistSwaps: dlaVariationHoist.applyHoistSwaps,
});
const json = stableJson(parityFile);

if (shouldCheck) {
  const existing = readFileSync(outputPath, 'utf8');
  if (existing !== json) {
    throw new Error(`${outputPath} is out of date with DLA blob ${DLA_VARIATION_HOIST_BLOB}`);
  }
  report('check', parityFile);
}

if (shouldWrite) {
  writeFileSync(outputPath, json);
  report('write', parityFile);
}

if (shouldStdout) {
  process.stdout.write(json);
  report('stdout', parityFile);
}
