#!/usr/bin/env node

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const figmaRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const repoRoot = path.dirname(figmaRoot);
const matrixScript = path.join(figmaRoot, 'scripts', 'figma-fixture-matrix.php');

async function main() {
  const options = parseOptions(process.argv.slice(2));
  if (options.help) {
    printHelp();
    return;
  }

  const result = runFigmaFixtureMatrixBench({ args: process.argv.slice(2) });
  process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
  if ((result.metrics.failed_fixture_count || 0) > 0 || result.metadata.matrix_exit_code !== 0) {
    process.exitCode = result.metadata.matrix_exit_code || 1;
  }
}

export default function runFigmaFixtureMatrixBench(context = {}) {
  const args = Array.isArray(context.args) ? context.args : [];
  const options = parseOptions(args);
  const outputDir = path.resolve(options.outputDir || process.env.FIGMA_FIXTURE_MATRIX_OUTPUT_DIR || path.join(process.env.HOMEBOY_ARTIFACT_ROOT || os.tmpdir(), `figma-transformer-fixture-matrix-bench-${Date.now()}`));
  const matrixArgs = buildMatrixArgs(options, outputDir);
  const startedAt = process.hrtime.bigint();
  const runtime = spawnSync('php', [matrixScript, ...matrixArgs], {
    cwd: repoRoot,
    env: process.env,
    encoding: 'utf8',
    maxBuffer: 1024 * 1024 * 100,
  });
  const wallDurationMs = Number((process.hrtime.bigint() - startedAt) / 1000000n);
  const summaryPath = path.join(outputDir, 'summary.json');
  const summary = readJson(summaryPath) || parseLastJsonObject(runtime.stdout) || {};

  if (!summary || !Array.isArray(summary.fixtures)) {
    throw new Error(`Figma fixture matrix did not produce a readable summary at ${summaryPath}. stderr: ${runtime.stderr || '<empty>'}`);
  }

  return summarizeBench(summary, {
    outputDir,
    summaryPath,
    matrixArgs,
    wallDurationMs,
    exitCode: runtime.status ?? 1,
    signal: runtime.signal,
    stderr: runtime.stderr,
  });
}

function buildMatrixArgs(options, outputDir) {
  const matrixArgs = [];
  for (const fixture of fixturePaths(options)) {
    matrixArgs.push(`--fixture=${fixture}`);
  }
  if (options.fixtureDir) {
    matrixArgs.push(`--fixture-dir=${options.fixtureDir}`);
  }
  if (options.includeFixtureDir) {
    matrixArgs.push('--include-fixture-dir');
  }
  matrixArgs.push(`--output-dir=${outputDir}`);
  matrixArgs.push(...envPassthroughArgs());
  matrixArgs.push(...options.passthrough);
  return matrixArgs;
}

function fixturePaths(options) {
  const values = [...options.fixtures];
  values.push(...envList('FIGMA_FIXTURE_MATRIX_FIXTURES'));
  if (process.env.FIGMA_FIXTURE_MATRIX_FIXTURE) {
    values.push(process.env.FIGMA_FIXTURE_MATRIX_FIXTURE);
  }
  return unique(values.map((item) => item.trim()).filter(Boolean));
}

function envPassthroughArgs() {
  return envList('FIGMA_FIXTURE_MATRIX_ARGS');
}

function envList(name) {
  const value = process.env[name];
  if (!value) {
    return [];
  }
  const trimmed = value.trim();
  if (trimmed.startsWith('[')) {
    const decoded = JSON.parse(trimmed);
    if (!Array.isArray(decoded)) {
      throw new Error(`${name} must be a JSON array when it starts with '['.`);
    }
    return decoded.map(String);
  }
  return trimmed.split(path.delimiter).map((item) => item.trim()).filter(Boolean);
}

function summarizeBench(summary, runtime) {
  const fixtures = summary.fixtures;
  const metrics = {
    total_duration_ms: numberOr(runtime.wallDurationMs),
    matrix_duration_ms: sumMetric(fixtures, 'duration_ms'),
    inspect_duration_ms: sumMetric(fixtures, 'inspect_duration_ms'),
    fixture_count: fixtures.length,
    passed_fixture_count: fixtures.filter((fixture) => fixture.status === 'completed' || fixture.status === 'inspected').length,
    failed_fixture_count: fixtures.filter((fixture) => !['completed', 'inspected', 'planned'].includes(String(fixture.status || ''))).length,
    vector_placeholder_count: sumQuality(fixtures, vectorPlaceholderCount),
    missing_asset_count: sumQuality(fixtures, missingAssetCount),
    fixed_width_without_responsive_override_count: sumSummary(fixtures, 'fixed_width_without_responsive_override_count'),
    giant_fixed_section_count: sumSummary(fixtures, 'giant_fixed_section_count'),
    large_overflow_risk_count: sumSummary(fixtures, 'large_overflow_risk_count'),
    fallback_prone_html_island_count: sumSummary(fixtures, 'fallback_prone_form_island_count') + sumSummary(fixtures, 'fallback_prone_svg_island_count') + sumSummary(fixtures, 'fallback_prone_input_island_count'),
    invalid_list_child_count: sumSummary(fixtures, 'invalid_list_child_count'),
    missing_semantic_role_count: sumSummary(fixtures, 'missing_semantic_role_count'),
    effective_responsive_coverage_ratio: numberOr(summary.quality_matrix?.effective_responsive_coverage_ratio, aggregateResponsiveCoverage(fixtures)),
  };

  for (const fixture of fixtures) {
    const id = metricId(fixture.id || path.basename(String(fixture.path || 'fixture')));
    if (Number.isFinite(Number(fixture.duration_ms))) {
      metrics[`fixture_${id}_duration_ms`] = Number(fixture.duration_ms);
    }
    if (Number.isFinite(Number(fixture.inspect_duration_ms))) {
      metrics[`fixture_${id}_inspect_duration_ms`] = Number(fixture.inspect_duration_ms);
    }
    if (Number.isFinite(Number(fixture.vector_placeholders))) {
      metrics[`fixture_${id}_vector_placeholder_count`] = Number(fixture.vector_placeholders);
    }
    const fixtureMissingAssets = missingAssetCount(fixture);
    if (fixtureMissingAssets > 0) {
      metrics[`fixture_${id}_missing_asset_count`] = fixtureMissingAssets;
    }
    for (const key of ['fixed_width_without_responsive_override_count', 'giant_fixed_section_count', 'large_overflow_risk_count', 'invalid_list_child_count', 'missing_semantic_role_count']) {
      const value = summaryValue(fixture, key);
      if (value > 0) {
        metrics[`fixture_${id}_${key}`] = value;
      }
    }
  }

  return {
    metrics,
    artifacts: artifactMap(summary, runtime.summaryPath),
    metadata: {
      schema: 'blocks-engine/figma-transformer/fixture-matrix-bench/v1',
      output_dir: runtime.outputDir,
      matrix_summary: runtime.summaryPath,
      matrix_args: runtime.matrixArgs,
      matrix_exit_code: runtime.exitCode,
      matrix_signal: runtime.signal || null,
      stderr: runtime.stderr || '',
      fixture_statuses: Object.fromEntries(fixtures.map((fixture) => [String(fixture.id || fixture.path), fixture.status || null])),
    },
  };
}

function artifactMap(summary, summaryPath) {
  const artifacts = {
    summary: { path: summaryPath },
    output_dir: { path: String(summary.output_dir || path.dirname(summaryPath)) },
  };
  for (const fixture of summary.fixtures || []) {
    const id = metricId(fixture.id || path.basename(String(fixture.path || 'fixture')));
    for (const [key, value] of Object.entries({
      [`fixture_${id}_inspect`]: fixture.inspect_path,
      [`fixture_${id}_result`]: fixture.result_path,
      [`fixture_${id}_artifact_dir`]: fixture.artifact_dir,
    })) {
      if (typeof value === 'string' && value) {
        artifacts[key] = { path: value };
      }
    }
  }
  return artifacts;
}

function vectorPlaceholderCount(fixture) {
  return numberOr(fixture.vector_placeholders || fixture.artifact_quality?.vectors?.placeholders || fixture.quality_summary?.vector_placeholders);
}

function missingAssetCount(fixture) {
  const direct = firstNumber([
    fixture.missing_asset_count,
    fixture.missing_assets_count,
    fixture.quality_summary?.missing_asset_count,
    fixture.quality_summary?.missing_assets,
    fixture.artifact_quality?.missing_asset_count,
    fixture.artifact_quality?.missing_assets_count,
    fixture.artifact_quality?.summary?.missing_asset_count,
    fixture.artifact_quality?.summary?.missing_assets,
  ]);
  if (direct !== null) {
    return direct;
  }
  const codes = Array.isArray(fixture.diagnostic_codes) ? fixture.diagnostic_codes : [];
  return codes.filter((code) => String(code).includes('missing_asset')).length;
}

function sumMetric(fixtures, key) {
  return fixtures.reduce((total, fixture) => total + numberOr(fixture[key]), 0);
}

function sumQuality(fixtures, getter) {
  return fixtures.reduce((total, fixture) => total + getter(fixture), 0);
}

function sumSummary(fixtures, key) {
  return fixtures.reduce((total, fixture) => total + summaryValue(fixture, key), 0);
}

function summaryValue(fixture, key) {
  return numberOr(fixture.quality_summary?.[key] ?? fixture.artifact_quality?.summary?.[key]);
}

function aggregateResponsiveCoverage(fixtures) {
  let covered = 0;
  let total = 0;
  for (const fixture of fixtures) {
    covered += summaryValue(fixture, 'fixed_width_with_responsive_override_count');
    total += summaryValue(fixture, 'fixed_width_declaration_count');
  }
  return total > 0 ? Number((covered / total).toFixed(3)) : 1;
}

function firstNumber(values) {
  for (const value of values) {
    const number = Number(value);
    if (Number.isFinite(number)) {
      return number;
    }
  }
  return null;
}

function numberOr(value, fallback = 0) {
  const number = Number(value);
  return Number.isFinite(number) ? number : fallback;
}

function metricId(value) {
  return String(value).toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'fixture';
}

function unique(values) {
  return [...new Set(values)];
}

function readJson(filePath) {
  try {
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch {
    return null;
  }
}

function parseLastJsonObject(text) {
  if (!text) {
    return null;
  }
  const start = text.lastIndexOf('\n{');
  const jsonText = start === -1 ? text.trim() : text.slice(start + 1).trim();
  try {
    return JSON.parse(jsonText);
  } catch {
    return null;
  }
}

function parseOptions(args) {
  const options = {
    fixtures: [],
    passthrough: [],
    includeFixtureDir: false,
    help: false,
  };
  for (const arg of args) {
    if (arg === '--help' || arg === '-h') {
      options.help = true;
      continue;
    }
    if (arg === '--include-fixture-dir') {
      options.includeFixtureDir = true;
      continue;
    }
    if (arg.startsWith('--fixture=')) {
      options.fixtures.push(arg.slice('--fixture='.length));
      continue;
    }
    if (arg.startsWith('--fixture-dir=')) {
      options.fixtureDir = arg.slice('--fixture-dir='.length);
      continue;
    }
    if (arg.startsWith('--output-dir=')) {
      options.outputDir = arg.slice('--output-dir='.length);
      continue;
    }
    options.passthrough.push(arg);
  }
  return options;
}

function printHelp() {
  process.stdout.write(`Usage:\n  node figma-transformer/bench/figma-fixture-matrix.bench.mjs [matrix options]\n\nInputs:\n  --fixture=/path/file.fig               Repeatable explicit fixture path.\n  --fixture-dir=/path/to/fixtures        Discover fixtures through the matrix script.\n  --output-dir=/path                     Artifact directory.\n  FIGMA_FIXTURE_MATRIX_FIXTURES          JSON array, or ${path.delimiter}-delimited fixture paths.\n  FIGMA_FIXTURE_MATRIX_FIXTURE           Single fixture path.\n  FIGMA_FIXTURE_MATRIX_ARGS              JSON array, or ${path.delimiter}-delimited extra matrix args.\n\nAll other CLI args are passed through to figma-fixture-matrix.php.\n`);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  main().catch((error) => {
    process.stderr.write(`${error.stack || error.message}\n`);
    process.exitCode = 1;
  });
}
