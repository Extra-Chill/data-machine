import {
  CONVERSION_FINDING_SEVERITIES,
  CONVERT_REPORT_SCHEMA,
  CONVERT_REPORT_STATUSES,
  type ConversionFinding,
  type ConvertReport,
  type ConversionMetrics,
} from './schema.js';

const REQUIRED_REPORT_KEYS = [
  'schema',
  'status',
  'blockMarkup',
  'fallbacks',
  'diagnostics',
  'metrics',
] as const;

const REQUIRED_METRIC_KEYS = [
  'inputBytes',
  'outputBytes',
  'blockCount',
  'fallbackCount',
  'diagnosticCount',
  'transformDurationMs',
] as const;

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function assertMetricNumber(
  metrics: Record<string, unknown>,
  key: keyof ConversionMetrics,
): void {
  if (
    typeof metrics[key] !== 'number' ||
    !Number.isFinite(metrics[key]) ||
    metrics[key] < 0
  ) {
    throw new Error(`Convert report metrics.${key} must be a finite non-negative number`);
  }
}

function assertFinding(value: unknown, path: string): asserts value is ConversionFinding {
  if (!isRecord(value)) {
    throw new Error(`Convert report ${path} must be an object`);
  }
  if (typeof value.code !== 'string') {
    throw new Error(`Convert report ${path}.code must be a string`);
  }
  if (
    typeof value.severity !== 'string' ||
    !CONVERSION_FINDING_SEVERITIES.includes(
      value.severity as (typeof CONVERSION_FINDING_SEVERITIES)[number],
    )
  ) {
    throw new Error(`Convert report ${path}.severity is outside the frozen contract`);
  }
  for (const key of ['message', 'selector', 'snippet'] as const) {
    if (key in value && typeof value[key] !== 'string') {
      throw new Error(`Convert report ${path}.${key} must be a string`);
    }
  }
}

function assertFindingArray(value: unknown, key: 'fallbacks' | 'diagnostics'): void {
  if (!Array.isArray(value)) {
    throw new Error(`Convert report ${key} must be an array`);
  }
  value.forEach((finding, index) => assertFinding(finding, `${key}[${index}]`));
}

function assertMetrics(value: unknown): asserts value is ConversionMetrics {
  if (!isRecord(value)) {
    throw new Error('Convert report metrics must be an object');
  }
  for (const key of REQUIRED_METRIC_KEYS) {
    if (!(key in value)) {
      throw new Error(`Convert report metrics missing required key: ${key}`);
    }
    assertMetricNumber(value, key);
  }
}

export function assertConvertReport(report: unknown): asserts report is ConvertReport {
  if (!isRecord(report)) {
    throw new Error('Convert report must be an object');
  }

  for (const key of REQUIRED_REPORT_KEYS) {
    if (!(key in report)) {
      throw new Error(`Convert report missing required key: ${key}`);
    }
  }

  if (report.schema !== CONVERT_REPORT_SCHEMA) {
    throw new Error('Convert report schema is outside the frozen contract');
  }
  if (
    typeof report.status !== 'string' ||
    !CONVERT_REPORT_STATUSES.includes(report.status as (typeof CONVERT_REPORT_STATUSES)[number])
  ) {
    throw new Error('Convert report status is outside the frozen contract');
  }
  if (typeof report.blockMarkup !== 'string') {
    throw new Error('Convert report blockMarkup must be a string');
  }

  assertFindingArray(report.fallbacks, 'fallbacks');
  assertFindingArray(report.diagnostics, 'diagnostics');
  assertMetrics(report.metrics);
}
