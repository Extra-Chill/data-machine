#!/usr/bin/env node
import { execFileSync, spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const DEFAULT_DLA_ROOT = '/Users/matt/projects/a8c/data-liberation-agent';
const TSX_BOOTSTRAP_ENV = 'BLOCKS_ENGINE_REGION_AUDIT_GOLDENS_TSX';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const packageRoot = path.resolve(__dirname, '..');
const corpusUrl = pathToFileURL(path.join(packageRoot, 'src/__fixtures__/region-audit-corpus.ts')).href;
const outputPath = path.join(packageRoot, 'src/__fixtures__/region-audit-dla-goldens.json');

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
  DLA_REGION_AUDIT_BLOB,
  DLA_REGION_AUDIT_COMMIT,
  DLA_REGION_AUDIT_PATH,
  DLA_REGION_CENSUS_BLOB,
  DLA_REGION_CENSUS_PATH,
  DLA_SECTION_SELECTOR_BLOB,
  DLA_SECTION_SELECTOR_PATH,
  runRegionAuditParity,
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

function verifyBlob(pathName, expectedBlob) {
  const headBlob = git(['rev-parse', `HEAD:${pathName}`]);
  if (headBlob !== expectedBlob) {
    throw new Error(`DLA ${pathName} HEAD blob must be ${expectedBlob}; got ${headBlob}`);
  }

  const liveBlob = git(['hash-object', pathName]);
  if (liveBlob !== expectedBlob) {
    throw new Error(`DLA ${pathName} working tree blob must be ${expectedBlob}; got ${liveBlob}`);
  }
}

function verifyDlaOracle() {
  const currentCommit = git(['rev-parse', 'HEAD']);
  if (currentCommit !== DLA_REGION_AUDIT_COMMIT) {
    throw new Error(`DLA checkout must be at ${DLA_REGION_AUDIT_COMMIT}; got ${currentCommit}`);
  }

  verifyBlob(DLA_REGION_AUDIT_PATH, DLA_REGION_AUDIT_BLOB);
  verifyBlob(DLA_REGION_CENSUS_PATH, DLA_REGION_CENSUS_BLOB);
  verifyBlob(DLA_SECTION_SELECTOR_PATH, DLA_SECTION_SELECTOR_BLOB);

  const requiredStatus = git([
    'status',
    '--porcelain',
    '--',
    DLA_REGION_AUDIT_PATH,
    DLA_REGION_CENSUS_PATH,
    DLA_SECTION_SELECTOR_PATH,
  ]);
  if (requiredStatus) {
    throw new Error(`DLA required region-audit sources must be clean:\n${requiredStatus}`);
  }
}

function report(mode, parityFile) {
  process.stderr.write(
    `region-audit goldens ${mode}: DLA commit=${DLA_REGION_AUDIT_COMMIT} ` +
      `regionAudit=${DLA_REGION_AUDIT_BLOB} regionCensus=${DLA_REGION_CENSUS_BLOB} ` +
      `sectionSelector=${DLA_SECTION_SELECTOR_BLOB} cases=${parityFile.cases.length}\n`
  );
}

verifyDlaOracle();

const dlaAudit = await import(pathToFileURL(path.join(dlaRoot, DLA_REGION_AUDIT_PATH)).href);
const dlaCensus = await import(pathToFileURL(path.join(dlaRoot, DLA_REGION_CENSUS_PATH)).href);
const parityFile = runRegionAuditParity({
  reconcileRegions: dlaAudit.reconcileRegions,
  extractSourceLandmarksFromHtml: dlaCensus.extractSourceLandmarksFromHtml,
  selectorForHtmlRoot: dlaCensus.selectorForHtmlRoot,
  landmarkRoleForHtmlRoot: dlaCensus.landmarkRoleForHtmlRoot,
});
const json = stableJson(parityFile);

if (shouldCheck) {
  const existing = readFileSync(outputPath, 'utf8');
  if (existing !== json) {
    throw new Error(`${outputPath} is out of date with DLA region-audit blobs`);
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
