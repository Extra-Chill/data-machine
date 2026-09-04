#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$figmaRoot = $root . '/figma-transformer';
require_once __DIR__ . '/figma-fixture-selection.php';
require_once __DIR__ . '/figma-fixture-matrix-quality.php';
require_once __DIR__ . '/figma-fixture-matrix-acceptance.php';

$options = matrix_options($argv);
if ( true === ($options['help'] ?? false) ) {
    matrix_print_help();
    exit(0);
}

$fixtureDir = $options['fixture_dir'] ?? ($figmaRoot . '/fixtures');
$defaultOutputRoot = getenv('HOMEBOY_ARTIFACT_ROOT') ?: sys_get_temp_dir();
$outputDir = $options['output_dir'] ?? ($defaultOutputRoot . '/figma-transformer-fixture-matrix-' . gmdate('Ymd-His'));
$zstdCommand = $options['zstd_command'] ?? (getenv('FIGMA_TRANSFORMER_ZSTD_COMMAND') ?: matrix_default_zstd_command());
$maxNodes = (int) ($options['max_nodes'] ?? 5000);
$maxPages = (int) ($options['max_pages'] ?? 3);
$inspectLimit = (int) ($options['inspect_limit'] ?? 100);
$dryRun = true === ($options['dry_run'] ?? false);
$inspectOnly = true === ($options['inspect_only'] ?? false);
$captureDomBoxes = true === ($options['capture_dom_boxes'] ?? false);
$homeboyCommand = (string) ($options['homeboy_command'] ?? (getenv('HOMEBOY_COMMAND') ?: 'homeboy'));
$domBoxProviderCommand = (string) ($options['dom_box_provider_command'] ?? (getenv('HOMEBOY_DOM_BOX_CAPTURE_COMMAND') ?: ''));
$only = isset($options['only']) ? array_filter(array_map('trim', explode(',', (string) $options['only']))) : array();
$adHocFixtures = matrix_list_option($options['fixture'] ?? array());
$fixtureDiscoveryEnabled = empty($adHocFixtures) || true === ($options['include_fixture_dir'] ?? false);
$fontCssPassthrough = matrix_font_css_passthrough($options);
$evidenceOptions = matrix_evidence_options($options);
$selectionLock = matrix_selection_lock_options($options);

$fixtures = $fixtureDiscoveryEnabled ? matrix_discover_fixtures($fixtureDir) : array();
foreach ( $adHocFixtures as $fixturePath ) {
    if ( ! is_file($fixturePath) || ! is_readable($fixturePath) ) {
        fwrite(STDERR, "Explicit fixture is not readable: {$fixturePath}\n");
        fwrite(STDERR, "Use --fixture=/absolute/path/to/file.fig, or omit --fixture to discover fixtures from --fixture-dir.\n");
        exit(1);
    }

    $fixtures[] = matrix_fixture_from_path($fixturePath, true);
}

$fixtures = matrix_unique_fixtures($fixtures);

if ( isset($options['frame_ids']) ) {
    $globalFrameIds = array_values(array_filter(array_map('trim', explode(',', (string) $options['frame_ids']))));
    foreach ( $fixtures as $index => $fixture ) {
        $fixtures[$index]['mode'] = 'transform';
        $fixtures[$index]['frame_ids'] = $globalFrameIds;
        if ( isset($options['entry_frame_id']) ) {
            $fixtures[$index]['entry_frame_id'] = (string) $options['entry_frame_id'];
        }
        $fixtures[$index]['selection_source'] = 'manual_frame_ids';
    }
} elseif ( ! empty($selectionLock['records']) ) {
    foreach ( $fixtures as $index => $fixture ) {
        $fixtureId = (string) ($fixture['id'] ?? '');
        if ( '' === $fixtureId || ! isset($selectionLock['records'][$fixtureId]) ) {
            continue;
        }

        $lockRecord = $selectionLock['records'][$fixtureId];
        $fixtures[$index]['mode'] = 'transform';
        $fixtures[$index]['frame_ids'] = $lockRecord['frame_ids'];
        if ( isset($lockRecord['entry_frame_id']) ) {
            $fixtures[$index]['entry_frame_id'] = $lockRecord['entry_frame_id'];
        }
        $fixtures[$index]['selection_source'] = 'selection_lock';
    }
}

if ( ! empty($only) ) {
    $fixtures = array_values(array_filter($fixtures, static fn (array $fixture): bool => in_array($fixture['id'], $only, true)));
}

if ( empty($fixtures) ) {
    fwrite(STDERR, "No fixtures selected.\n");
    fwrite(STDERR, "Use --fixture=/absolute/path/to/file.fig for explicit fixture paths, or --fixture-dir=/path/to/fixtures for discovery. Run with --help for examples.\n");
    exit(1);
}

if ( $captureDomBoxes && ! $dryRun ) {
    matrix_preflight_homeboy_command($homeboyCommand);
    matrix_preflight_dom_box_provider_command($domBoxProviderCommand, $root);
}

$summary = array(
    'schema' => 'blocks-engine/figma-transformer/fixture-matrix/v1',
    'fixture_dir' => $fixtureDir,
    'output_dir' => $outputDir,
    'zstd_command' => $zstdCommand,
    'max_nodes' => $maxNodes,
    'max_pages' => $maxPages,
    'inspect_limit' => $inspectLimit,
    'dry_run' => $dryRun,
    'inspect_only' => $inspectOnly,
    'capture_dom_boxes' => $captureDomBoxes,
    'homeboy_command' => $captureDomBoxes ? $homeboyCommand : null,
    'dom_box_provider_command_configured' => $captureDomBoxes ? '' !== $domBoxProviderCommand : null,
    'font_css' => $fontCssPassthrough['summary'],
    'evidence' => $evidenceOptions['summary'],
    'selection' => $selectionLock['summary'],
    'fixtures' => array(),
);

if ( ! $dryRun && ! is_dir($outputDir) && ! mkdir($outputDir, 0777, true) && ! is_dir($outputDir) ) {
    fwrite(STDERR, "Unable to create output directory: {$outputDir}\n");
    exit(1);
}

foreach ( $fixtures as $fixture ) {
    $fixturePath = (string) ($fixture['path'] ?? ($fixtureDir . '/' . $fixture['file']));
    $record = array(
        'id' => $fixture['id'],
        'mode' => $fixture['mode'] ?? 'auto',
        'path' => $fixturePath,
        'exists' => is_file($fixturePath),
    );

    if ( ! is_file($fixturePath) ) {
        $record['status'] = 'missing_fixture';
        $summary['fixtures'][] = $record;
        continue;
    }

    $fixtureOutputDir = $outputDir . '/' . $fixture['id'];
    $inspectPath = $outputDir . '/' . $fixture['id'] . '-inspect.json';
    $resultPath = $outputDir . '/' . $fixture['id'] . '-result.json';
    $inspectCommand = matrix_inspect_command($figmaRoot, $fixturePath, $inspectPath, $zstdCommand, $inspectLimit);
    $record['inspect_command'] = $inspectCommand;
    $record['inspect_path'] = $inspectPath;
    $record['result_path'] = $resultPath;
    $record['artifact_dir'] = $fixtureOutputDir;
    $record['evidence'] = matrix_fixture_evidence($evidenceOptions, $fixture, array());

    if ( $dryRun ) {
        $record['status'] = 'planned';
        $record['selection'] = $fixture['selection_source'] ?? (isset($fixture['frame_ids']) ? 'manual_frame_ids' : 'auto_from_inspection');
        $hasDryRunFrameIds = isset($fixture['frame_ids']) && is_array($fixture['frame_ids']);
        $dryRunFrameIds = $hasDryRunFrameIds ? $fixture['frame_ids'] : array('<selected-frame-ids>');
        $dryRunEntryFrameId = isset($fixture['entry_frame_id']) ? (string) $fixture['entry_frame_id'] : null;
        $record['evidence'] = matrix_fixture_evidence($evidenceOptions, $fixture, matrix_dry_run_pages($dryRunFrameIds));
        if ( $captureDomBoxes ) {
            $record['dom_box_capture'] = array(
                'status' => 'planned',
                'command' => matrix_dom_box_capture_command($homeboyCommand, $domBoxProviderCommand, $fixtureOutputDir, array('<generated-html-entrypoints>'), $fixtureOutputDir . '/dom-boxes.json'),
                'report_path' => $fixtureOutputDir . '/dom-boxes.json',
            );
        }
        $record['command'] = matrix_transform_command($figmaRoot, $fixturePath, $dryRunFrameIds, $dryRunEntryFrameId, $fixtureOutputDir, $resultPath, $zstdCommand, $maxNodes, $fontCssPassthrough['arguments'], $record['evidence']['transform_arguments'] ?? array());
        $summary['fixtures'][] = $record;
        continue;
    }

    $startedAt = microtime(true);
    $hasPresetFrameIds = isset($fixture['frame_ids']) && is_array($fixture['frame_ids']);
    if ( $hasPresetFrameIds ) {
        $inspection = array();
        $record['inspection'] = array('status' => 'skipped_preset_frame_ids');
        $record['inspect_duration_ms'] = 0;
        $frameIds = $fixture['frame_ids'];
    } else {
        passthru($inspectCommand, $inspectExitCode);
        $record['inspect_exit_code'] = $inspectExitCode;
        $record['inspect_duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
        if ( 0 !== $inspectExitCode || ! is_file($inspectPath) ) {
            $record['status'] = 'inspect_failed';
            $summary['fixtures'][] = $record;
            continue;
        }

        $inspection = json_decode((string) file_get_contents($inspectPath), true);
        $record['inspection'] = matrix_inspection_summary(is_array($inspection) ? $inspection : array());
        $frameIds = matrix_select_frame_ids(is_array($inspection) ? $inspection : array(), $maxPages);
    }
    $record['selection'] = $fixture['selection_source'] ?? (isset($fixture['frame_ids']) ? 'manual_frame_ids' : 'auto_from_inspection');
    $record['selected_frame_ids'] = $frameIds;
    $record['selected_frames'] = matrix_selected_frame_records(is_array($inspection) ? $inspection : array(), $frameIds);
    if ( isset($fixture['entry_frame_id']) ) {
        $record['entry_frame_id'] = (string) $fixture['entry_frame_id'];
    }
    $record['omitted_page_candidates'] = matrix_omitted_page_candidate_records(is_array($inspection) ? $inspection : array(), $frameIds);
    if ( ! isset($record['entry_frame_id']) ) {
        $record['entry_frame_id'] = (string) ($frameIds[0] ?? '');
    }
    $record['evidence'] = matrix_fixture_evidence($evidenceOptions, $fixture, $record['selected_frames']);

    if ( $inspectOnly ) {
        $record['status'] = 'inspected';
        $summary['fixtures'][] = $record;
        continue;
    }

    if ( empty($frameIds) ) {
        $record['status'] = 'no_frame_candidates';
        $summary['fixtures'][] = $record;
        continue;
    }

    $command = matrix_transform_command($figmaRoot, $fixturePath, $frameIds, $record['entry_frame_id'] ?? null, $fixtureOutputDir, $resultPath, $zstdCommand, $maxNodes, $fontCssPassthrough['arguments'], $captureDomBoxes ? array() : ($record['evidence']['transform_arguments'] ?? array()));
    $record['command'] = $command;
    passthru($command, $exitCode);
    $record['exit_code'] = $exitCode;
    $record['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
    $record['status'] = 0 === $exitCode ? 'completed' : 'failed';

    if ( 0 === $exitCode && $captureDomBoxes ) {
        $domBoxesPath = $fixtureOutputDir . '/dom-boxes.json';
        $entrypoints = matrix_html_entrypoints($fixtureOutputDir);
        $captureTargets = matrix_dom_box_capture_targets($resultPath, $entrypoints);
        $captureCommand = matrix_dom_box_capture_command($homeboyCommand, $domBoxProviderCommand, $fixtureOutputDir, $entrypoints, $domBoxesPath, $captureTargets);
        $record['dom_box_capture'] = array(
            'status' => empty($entrypoints) ? 'no_html_entrypoints' : 'running',
            'command' => $captureCommand,
            'report_path' => $domBoxesPath,
            'entrypoints' => $entrypoints,
            'targets' => $captureTargets,
        );
        if ( ! empty($entrypoints) ) {
            passthru($captureCommand, $captureExitCode);
            $record['dom_box_capture']['exit_code'] = $captureExitCode;
            $record['dom_box_capture']['exists'] = is_file($domBoxesPath);
            $record['dom_box_capture']['status'] = 0 === $captureExitCode && is_file($domBoxesPath) ? 'completed' : 'failed';
            if ( 'failed' === $record['dom_box_capture']['status'] ) {
                $record['status'] = 'dom_box_capture_failed';
            }
            if ( 0 === $captureExitCode && is_file($domBoxesPath) ) {
                matrix_annotate_dom_box_capture($domBoxesPath, $captureTargets);
                $domBoxQualityPath = $fixtureOutputDir . '/dom-box-quality.json';
                $domBoxQuality = matrix_dom_box_quality_report($domBoxesPath);
                if ( is_array($domBoxQuality) ) {
                    file_put_contents($domBoxQualityPath, json_encode($domBoxQuality, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
                    $record['dom_box_quality'] = $domBoxQuality;
                    $record['dom_box_capture']['quality_report_path'] = $domBoxQualityPath;
                }
                $capturedEvidenceOptions = matrix_merge_evidence_templates($evidenceOptions, array(
                    'dom_boxes_path' => $domBoxesPath,
                ));
                $capturedEvidence = matrix_fixture_evidence($capturedEvidenceOptions, $fixture, $record['selected_frames']);
                $rerunResultPath = $outputDir . '/' . $fixture['id'] . '-result-with-dom-boxes.json';
                $rerunCommand = matrix_transform_command($figmaRoot, $fixturePath, $frameIds, $record['entry_frame_id'] ?? null, $fixtureOutputDir, $rerunResultPath, $zstdCommand, $maxNodes, $fontCssPassthrough['arguments'], $capturedEvidence['transform_arguments'] ?? array());
                $record['dom_box_rerun_command'] = $rerunCommand;
                passthru($rerunCommand, $rerunExitCode);
                $record['dom_box_rerun_exit_code'] = $rerunExitCode;
                if ( 0 === $rerunExitCode && is_file($rerunResultPath) ) {
                    $resultPath = $rerunResultPath;
                    $record['result_path'] = $resultPath;
                    $record['exit_code'] = $rerunExitCode;
                    $record['evidence'] = $capturedEvidence;
                    $record['status'] = 'completed';
                } else {
                    $record['status'] = 'dom_box_rerun_failed';
                }
            }
        }
    }

    $record['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

    if ( 'completed' === $record['status'] && is_file($resultPath) ) {
        $result = json_decode((string) file_get_contents($resultPath), true);
        if ( is_array($result) ) {
            $result = matrix_attach_evidence_to_result($result, $record['evidence']);
            file_put_contents($resultPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $record['result_status'] = $result['status'] ?? null;
            $record['parity'] = matrix_parity_summary(is_array($result['parity'] ?? null) ? $result['parity'] : array());
            $record['metrics'] = $result['metrics'] ?? array();
            $record['acceptance_readiness'] = matrix_acceptance_readiness($record['id'], $fixturePath, $result, $outputDir, $resultPath);
            $record['acceptance_readiness']['stage_paths'] = matrix_write_acceptance_readiness($record['acceptance_readiness'], $fixtureOutputDir);
            $diagnostics = $result['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
            if ( is_array($diagnostics) ) {
                $record['diagnostic_codes'] = $diagnostics['diagnostic_codes'] ?? array();
                $record['vector_placeholders'] = $diagnostics['vectors']['placeholders'] ?? null;
                $record['generated_svg_assets'] = $diagnostics['generated_svg_assets'] ?? null;
                $record['artifact_quality'] = $diagnostics['artifact_quality'] ?? null;
                $record['quality_status'] = $diagnostics['artifact_quality']['quality_status'] ?? null;
                $record['quality_summary'] = $diagnostics['artifact_quality']['summary'] ?? null;
                $record['transform_selection'] = $diagnostics['selection'] ?? null;
                if ( is_array($record['parity'] ?? null) && is_array($record['quality_summary']) ) {
                    $record['parity']['layout_mismatch_count'] = $record['quality_summary']['layout_mismatch_count'] ?? $record['parity']['layout_mismatch_count'];
                    $record['parity']['layout_mismatch_status'] = $record['quality_summary']['layout_mismatch_status'] ?? null;
                }
                $record['visual_readiness'] = matrix_fixture_visual_readiness($record);
            }
        }
    }

    $summary['fixtures'][] = $record;
}

if ( ! $dryRun ) {
    $summary['quality_matrix'] = matrix_quality_matrix($summary['fixtures']);
    file_put_contents($outputDir . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

function matrix_options(array $argv): array
{
    $options = array();
    foreach ( array_slice($argv, 1) as $arg ) {
        if ( '--dry-run' === $arg ) {
            $options['dry_run'] = true;
            continue;
        }

        if ( '--inspect-only' === $arg ) {
            $options['inspect_only'] = true;
            continue;
        }

        if ( '--capture-dom-boxes' === $arg ) {
            $options['capture_dom_boxes'] = true;
            continue;
        }

        if ( '--help' === $arg || '-h' === $arg ) {
            $options['help'] = true;
            continue;
        }

        if ( '--include-fixture-dir' === $arg ) {
            $options['include_fixture_dir'] = true;
            continue;
        }

        if ( ! str_starts_with($arg, '--') || ! str_contains($arg, '=') ) {
            continue;
        }

        [$name, $value] = explode('=', substr($arg, 2), 2);
        $key = str_replace('-', '_', $name);
        $key = array(
            'dom_box_command' => 'dom_box_provider_command',
            'homeboy_bin'     => 'homeboy_command',
        )[$key] ?? $key;
        if ( 'fixture' === $key ) {
            $options[$key] ??= array();
            $options[$key][] = $value;
            continue;
        }

        $options[$key] = $value;
    }

    return $options;
}

function matrix_print_help(): void
{
    echo <<<'HELP'
Usage:
  php scripts/figma-fixture-matrix.php [options]

Fixture selection:
  --fixture=/path/to/file.fig       Run one explicit fixture path. Repeat for multiple fixtures.
                                    When provided, fixture-dir discovery is disabled by default.
  --fixture-dir=/path/to/fixtures   Discover *.fig fixtures from this directory. Defaults to ./fixtures.
  --include-fixture-dir             Combine --fixture paths with --fixture-dir discovery.
  --only=id[,id]                    Filter selected fixtures by fixture id.
  --selection-lock=/path/to.json    Reuse locked frame ids from a prior matrix summary.
  --frame-ids=id[,id]               Force frame ids for every selected fixture.
  --entry-frame-id=id               Force the entry frame id when using --frame-ids.

Run modes:
  --dry-run                         Print planned commands without running transforms.
  --inspect-only                    Inspect fixtures without running transforms.
  --capture-dom-boxes               Capture DOM boxes after transform and rerun with evidence.

Output and tooling:
  --output-dir=/path                Matrix artifact directory. Defaults under HOMEBOY_ARTIFACT_ROOT or temp.
  --zstd-command=/path/to/zstd      zstd binary or command.
  --max-nodes=5000                  Transform node budget per fixture.
  --max-pages=3                     Auto-selected frame/page limit.
  --inspect-limit=100               Inspect frame listing limit.
  --homeboy-command=/path           Homeboy command for DOM box capture.
  --homeboy-bin=/path               Alias for --homeboy-command.
  --dom-box-provider-command=cmd    Provider command passed to HOMEBOY_DOM_BOX_CAPTURE_COMMAND.
  --dom-box-command=cmd             Alias for --dom-box-provider-command.
  --font-css=css                    Inline CSS passed through to transformer.
  --font-css-file=/path             CSS file passed through to transformer.
  --parity-report=/path             Evidence path template. Supports {fixture}, {id}, {frame_id}, {page}, {slug}.
  --dom-boxes=/path                 Evidence path template for DOM boxes.
  --layout-report=/path             Evidence path template for layout report.
  --layout-mismatch-report=/path    Evidence path template for layout mismatch report.
  --render-evidence=/path           Evidence path template for no-screenshot render/style evidence.
  --source-screenshot=/path         Source screenshot path template for later pixel parity.
  --generated-screenshot=/path      Generated screenshot path template for later pixel parity.
  --diff-image=/path                Diff image path template for later pixel parity.
  --help, -h                        Show this help text.

Examples:
  php scripts/figma-fixture-matrix.php --help
  php scripts/figma-fixture-matrix.php --dry-run --fixture=/tmp/patched-fixtures/home.fig
  php scripts/figma-fixture-matrix.php --dry-run --fixture-dir=/tmp/fixture-corpus
  php scripts/figma-fixture-matrix.php --dry-run --fixture=/tmp/home.fig --include-fixture-dir
  php scripts/figma-fixture-matrix.php --fixture=/tmp/home.fig --capture-dom-boxes --dom-box-provider-command='node php-transformer/tools/visual-parity/bin/dom-box-provider.mjs'

HELP;
}

/**
 * @param mixed $value
 * @return array<int, string>
 */
function matrix_list_option(mixed $value): array
{
    if ( is_array($value) ) {
        return array_values(array_filter(array_map('strval', $value), static fn (string $item): bool => '' !== trim($item)));
    }

    if ( is_scalar($value) && '' !== trim((string) $value) ) {
        return array((string) $value);
    }

    return array();
}

/**
 * @param array<string, mixed> $options
 * @return array{records: array<string, array{frame_ids: array<int, string>, entry_frame_id?: string}>, summary: array<string, mixed>}
 */
function matrix_selection_lock_options(array $options): array
{
    if ( ! isset($options['selection_lock']) || ! is_scalar($options['selection_lock']) || '' === trim((string) $options['selection_lock']) ) {
        return array(
            'records' => array(),
            'summary' => array(
                'mode' => 'auto_from_inspection',
            ),
        );
    }

    $path = (string) $options['selection_lock'];
    if ( ! is_file($path) || ! is_readable($path) ) {
        fwrite(STDERR, "Selection lock is not readable: {$path}\n");
        exit(1);
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if ( ! is_array($decoded) ) {
        fwrite(STDERR, "Selection lock is not valid JSON: {$path}\n");
        exit(1);
    }

    $records = matrix_selection_lock_records($decoded);
    return array(
        'records' => $records,
        'summary' => array(
            'mode' => 'locked_frame_ids',
            'path' => $path,
            'fixture_count' => count($records),
        ),
    );
}

/**
 * @param array<string, mixed> $decoded
 * @return array<string, array{frame_ids: array<int, string>, entry_frame_id?: string}>
 */
function matrix_selection_lock_records(array $decoded): array
{
    $records = array();
    $fixtures = is_array($decoded['fixtures'] ?? null) ? $decoded['fixtures'] : $decoded;

    foreach ( $fixtures as $key => $fixture ) {
        if ( ! is_array($fixture) ) {
            continue;
        }

        $fixtureId = isset($fixture['id']) && is_scalar($fixture['id']) ? (string) $fixture['id'] : (is_string($key) ? $key : '');
        $frameIds = array();
        if ( is_array($fixture['selected_frame_ids'] ?? null) ) {
            $frameIds = $fixture['selected_frame_ids'];
        } elseif ( is_array($fixture['frame_ids'] ?? null) ) {
            $frameIds = $fixture['frame_ids'];
        }

        $frameIds = array_values(array_filter(array_map('strval', $frameIds), static fn (string $frameId): bool => '' !== trim($frameId)));
        if ( '' === $fixtureId || empty($frameIds) ) {
            continue;
        }

        $record = array('frame_ids' => $frameIds);
        if ( isset($fixture['entry_frame_id']) && is_scalar($fixture['entry_frame_id']) && '' !== (string) $fixture['entry_frame_id'] ) {
            $record['entry_frame_id'] = (string) $fixture['entry_frame_id'];
        }
        $records[$fixtureId] = $record;
    }

    return $records;
}

/**
 * @param array<string, mixed> $options
 * @return array{arguments: array<int, string>, summary: array<string, mixed>}
 */
function matrix_font_css_passthrough(array $options): array
{
    $arguments = array();
    $summary = array('source' => 'none');

    if ( isset($options['font_css']) && is_scalar($options['font_css']) && '' !== (string) $options['font_css'] ) {
        $fontCss = (string) $options['font_css'];
        $arguments[] = '--font-css=' . escapeshellarg($fontCss);
        $summary = array(
            'source' => 'inline',
            'length' => strlen($fontCss),
        );
    }

    if ( isset($options['font_css_file']) && is_scalar($options['font_css_file']) && '' !== (string) $options['font_css_file'] ) {
        $fontCssFile = (string) $options['font_css_file'];
        $arguments[] = '--font-css-file=' . escapeshellarg($fontCssFile);
        $summary = array(
            'source' => 'file',
            'path' => $fontCssFile,
            'exists' => is_file($fontCssFile),
            'readable' => is_readable($fontCssFile),
        );
    }

    return array(
        'arguments' => $arguments,
        'summary' => $summary,
    );
}

/**
 * @param array<string, mixed> $options
 * @return array{templates: array<string, string>, summary: array<string, mixed>}
 */
function matrix_evidence_options(array $options): array
{
    $templates = array();
    foreach ( matrix_evidence_option_keys() as $optionKey => $templateKey ) {
        if ( isset($options[$optionKey]) && is_scalar($options[$optionKey]) && '' !== (string) $options[$optionKey] ) {
            $templates[$templateKey] = (string) $options[$optionKey];
        }
    }

    return matrix_evidence_options_from_templates($templates);
}

/**
 * @return array<string, string>
 */
function matrix_evidence_option_keys(): array
{
    return array(
        'parity_report' => 'parity_report_path',
        'parity_report_path' => 'parity_report_path',
        'dom_boxes' => 'dom_boxes_path',
        'dom_boxes_path' => 'dom_boxes_path',
        'layout_report' => 'layout_report_path',
        'layout_report_path' => 'layout_report_path',
        'layout_mismatch_report' => 'layout_mismatch_report_path',
        'layout_mismatch_report_path' => 'layout_mismatch_report_path',
        'render_evidence' => 'render_evidence_path',
        'render_evidence_path' => 'render_evidence_path',
        'source_screenshot' => 'source_screenshot_path',
        'source_screenshot_path' => 'source_screenshot_path',
        'generated_screenshot' => 'generated_screenshot_path',
        'generated_screenshot_path' => 'generated_screenshot_path',
        'diff_image' => 'diff_image_path',
        'diff_image_path' => 'diff_image_path',
    );
}

/**
 * @param array<string, string> $templates
 * @return array{templates: array<string, string>, summary: array<string, mixed>}
 */
function matrix_evidence_options_from_templates(array $templates): array
{
    return array(
        'templates' => $templates,
        'summary' => empty($templates) ? array('source' => 'none') : array(
            'source' => 'runner_paths',
            'templates' => $templates,
            'template_tokens' => matrix_evidence_template_tokens(),
        ),
    );
}

/**
 * @return array<int, string>
 */
function matrix_evidence_template_tokens(): array
{
    return array('{fixture}', '{id}', '{frame_id}', '{page}', '{slug}');
}

/**
 * @param array{templates: array<string, string>, summary: array<string, mixed>} $evidenceOptions
 * @param array<string, string> $templates
 * @return array{templates: array<string, string>, summary: array<string, mixed>}
 */
function matrix_merge_evidence_templates(array $evidenceOptions, array $templates): array
{
    return matrix_evidence_options_from_templates(array_merge($evidenceOptions['templates'], $templates));
}

/**
 * @return array<int, array<string, mixed>>
 */
function matrix_discover_fixtures(string $fixtureDir): array
{
    $paths = glob(rtrim($fixtureDir, '/') . '/*.fig') ?: array();
    sort($paths, SORT_NATURAL | SORT_FLAG_CASE);

    return array_map(static fn (string $path): array => matrix_fixture_from_path($path, false), $paths);
}

/**
 * @param array<int, array<string, mixed>> $fixtures
 * @return array<int, array<string, mixed>>
 */
function matrix_unique_fixtures(array $fixtures): array
{
    $unique = array();
    foreach ( $fixtures as $fixture ) {
        $path = isset($fixture['path']) && is_scalar($fixture['path']) ? (string) $fixture['path'] : '';
        $key = (string) ($fixture['id'] ?? '') . '|' . ($path ? (realpath($path) ?: $path) : '');
        $unique[$key] = $fixture;
    }

    return array_values($unique);
}

/**
 * @return array<string, mixed>
 */
function matrix_fixture_from_path(string $path, bool $adHoc): array
{
    $id = matrix_fixture_id($path);
    return array(
        'id' => $id,
        'file' => basename($path),
        'path' => $path,
        'mode' => 'auto',
        'inspect_limit' => 40,
        'ad_hoc' => $adHoc,
    );
}

function matrix_fixture_id(string $path): string
{
    $base = preg_replace('/\.fig$/i', '', basename($path)) ?? basename($path);
    $id = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $base));
    return trim($id, '-') ?: 'fixture';
}

function matrix_default_zstd_command(): string
{
    foreach ( array('/opt/homebrew/bin/zstd', '/usr/local/bin/zstd', '/usr/bin/zstd') as $candidate ) {
        if ( is_file($candidate) && is_executable($candidate) ) {
            return $candidate;
        }
    }

    $path = trim((string) shell_exec('command -v zstd 2>/dev/null'));
    return '' !== $path ? $path : 'zstd';
}

function matrix_preflight_homeboy_command(string $homeboyCommand): void
{
    $homeboyCommand = trim($homeboyCommand);
    if ( '' === $homeboyCommand ) {
        fwrite(STDERR, "DOM box capture requires a Homeboy command. Set --homeboy-command, --homeboy-bin, or HOMEBOY_COMMAND to a runnable homeboy binary.\n");
        exit(1);
    }

    if ( str_contains($homeboyCommand, DIRECTORY_SEPARATOR) ) {
        if ( is_file($homeboyCommand) && is_executable($homeboyCommand) ) {
            return;
        }

        fwrite(STDERR, "DOM box capture requires a runnable Homeboy command, but configured path is not executable: {$homeboyCommand}\nSet --homeboy-command, --homeboy-bin, or HOMEBOY_COMMAND to the active homeboy binary before rerunning.\n");
        exit(1);
    }

    $resolved = trim((string) shell_exec('command -v ' . escapeshellarg($homeboyCommand) . ' 2>/dev/null'));
    if ( '' !== $resolved ) {
        return;
    }

    fwrite(STDERR, "DOM box capture requires a runnable Homeboy command, but '{$homeboyCommand}' was not found on PATH.\nSet --homeboy-command, --homeboy-bin, or HOMEBOY_COMMAND to the active homeboy binary before rerunning.\n");
    exit(1);
}

function matrix_preflight_dom_box_provider_command(string $domBoxProviderCommand, string $root): void
{
    $domBoxProviderCommand = trim($domBoxProviderCommand);
    if ( '' === $domBoxProviderCommand ) {
        fwrite(STDERR, "DOM box capture requires a provider command. Set --dom-box-provider-command, --dom-box-command, or HOMEBOY_DOM_BOX_CAPTURE_COMMAND.\n");
        fwrite(STDERR, "Canonical provider: --dom-box-provider-command='node php-transformer/tools/visual-parity/bin/dom-box-provider.mjs'\n");
        fwrite(STDERR, "Install provider dependencies first: npm ci --prefix php-transformer/tools/visual-parity && npm --prefix php-transformer/tools/visual-parity run install:browsers\n");
        exit(1);
    }

    if ( ! matrix_dom_box_provider_supports_preflight($domBoxProviderCommand) ) {
        return;
    }

    $previousDirectory = getcwd();
    if ( false === $previousDirectory || ! chdir($root) ) {
        fwrite(STDERR, "Unable to preflight DOM box provider from repository root: {$root}\n");
        exit(1);
    }

    exec($domBoxProviderCommand . ' --preflight 2>&1', $output, $exitCode);
    chdir($previousDirectory);

    if ( 0 === $exitCode ) {
        return;
    }

    fwrite(STDERR, "DOM box provider preflight failed before fixture transforms started.\n");
    fwrite(STDERR, "Provider command: {$domBoxProviderCommand}\n");
    fwrite(STDERR, implode("\n", $output) . "\n");
    fwrite(STDERR, "Install provider dependencies first: npm ci --prefix php-transformer/tools/visual-parity && npm --prefix php-transformer/tools/visual-parity run install:browsers\n");
    exit(1);
}

function matrix_dom_box_provider_supports_preflight(string $domBoxProviderCommand): bool
{
    return str_contains($domBoxProviderCommand, 'dom-box-provider.mjs') || str_contains($domBoxProviderCommand, 'blocks-engine-dom-box-provider');
}

function matrix_inspect_command(string $figmaRoot, string $fixturePath, string $resultPath, string $zstdCommand, int $inspectLimit): string
{
    $parts = array(
        escapeshellarg(PHP_BINARY),
        '-d',
        'memory_limit=1536M',
        escapeshellarg($figmaRoot . '/bin/figma-transformer'),
        escapeshellarg($fixturePath),
        '--zstd-command=' . escapeshellarg($zstdCommand),
    );

    $parts[] = '--inspect-frames=' . $inspectLimit;

    return implode(' ', $parts) . ' > ' . escapeshellarg($resultPath);
}

/**
 * @param array<int, string> $frameIds
 * @param array<int, string> $fontCssArguments
 * @param array<int, string> $evidenceArguments
 */
function matrix_transform_command(string $figmaRoot, string $fixturePath, array $frameIds, ?string $entryFrameId, string $fixtureOutputDir, string $resultPath, string $zstdCommand, int $maxNodes, array $fontCssArguments = array(), array $evidenceArguments = array()): string
{
    $parts = array(
        escapeshellarg(PHP_BINARY),
        '-d',
        'memory_limit=1536M',
        escapeshellarg($figmaRoot . '/bin/figma-transformer'),
        escapeshellarg($fixturePath),
        '--zstd-command=' . escapeshellarg($zstdCommand),
        '--multi-page',
        '--frame-ids=' . escapeshellarg(implode(',', $frameIds)),
        '--max-nodes=' . $maxNodes,
        '--output-dir=' . escapeshellarg($fixtureOutputDir),
    );

    if ( null !== $entryFrameId && '' !== $entryFrameId ) {
        $parts[] = '--entry-frame-id=' . escapeshellarg($entryFrameId);
    }

    array_push($parts, ...$fontCssArguments);
    array_push($parts, ...$evidenceArguments);

    return implode(' ', $parts) . ' > ' . escapeshellarg($resultPath);
}

/**
 * @param array{templates: array<string, string>, summary: array<string, mixed>} $evidenceOptions
 * @param array<string, mixed> $fixture
 * @param array<int, array<string, mixed>> $pages
 * @return array<string, mixed>
 */
function matrix_fixture_evidence(array $evidenceOptions, array $fixture, array $pages): array
{
    $templates = $evidenceOptions['templates'];
    if ( empty($templates) ) {
        return array('source' => 'none', 'transform_arguments' => array());
    }

    $fixturePaths = array();
    foreach ( $templates as $key => $template ) {
        $fixturePaths[$key] = matrix_resolve_evidence_template($template, $fixture, array());
    }

    $pageRecords = array();
    foreach ( $pages as $page ) {
        $pagePaths = array();
        foreach ( $templates as $key => $template ) {
            $pagePaths[$key] = matrix_resolve_evidence_template($template, $fixture, $page);
        }
        $pageRecords[] = array(
            'frame_id' => (string) ($page['id'] ?? $page['frame_id'] ?? ''),
            'name' => (string) ($page['name'] ?? ''),
            'slug' => (string) ($page['slug'] ?? ''),
            'paths' => matrix_evidence_path_records($pagePaths),
        );
    }

    return array(
        'source' => 'runner_paths',
        'paths' => matrix_evidence_path_records($fixturePaths),
        'pages' => $pageRecords,
        'transform_arguments' => matrix_evidence_transform_arguments($fixturePaths),
    );
}

/**
 * @param array<int, string> $frameIds
 * @return array<int, array<string, mixed>>
 */
function matrix_dry_run_pages(array $frameIds): array
{
    return array_map(static fn (string $frameId): array => array('id' => $frameId, 'name' => $frameId, 'slug' => matrix_slug($frameId)), $frameIds);
}

/**
 * @param array<string, mixed> $fixture
 * @param array<string, mixed> $page
 */
function matrix_resolve_evidence_template(string $template, array $fixture, array $page): string
{
    $frameId = (string) ($page['frame_id'] ?? $page['id'] ?? '');
    $tokens = array(
        '{fixture}' => (string) ($fixture['id'] ?? ''),
        '{id}' => (string) ($fixture['id'] ?? ''),
        '{frame_id}' => '' !== $frameId ? $frameId : (string) ($fixture['entry_frame_id'] ?? ''),
        '{page}' => (string) ($page['name'] ?? ''),
        '{slug}' => (string) ($page['slug'] ?? ('' !== $frameId ? matrix_slug($frameId) : (string) ($fixture['id'] ?? ''))),
    );

    return strtr($template, $tokens);
}

/**
 * @param array<string, string> $paths
 * @return array<string, array<string, mixed>>
 */
function matrix_evidence_path_records(array $paths): array
{
    $records = array();
    foreach ( $paths as $key => $path ) {
        $records[$key] = array(
            'path' => $path,
            'exists' => is_file($path),
            'readable' => is_readable($path),
        );
    }

    return $records;
}

/**
 * @param array<string, string> $paths
 * @return array<int, string>
 */
function matrix_evidence_transform_arguments(array $paths): array
{
    $arguments = array();
    foreach ( matrix_evidence_transform_argument_prefixes() as $key => $argument ) {
        if ( isset($paths[$key]) && '' !== $paths[$key] ) {
            $arguments[] = $argument . escapeshellarg($paths[$key]);
        }
    }

    return $arguments;
}

/**
 * @return array<string, string>
 */
function matrix_evidence_transform_argument_prefixes(): array
{
    return array(
        'parity_report_path' => '--parity-report-path=',
        'dom_boxes_path' => '--parity-dom-boxes-path=',
        'layout_report_path' => '--parity-layout-report-path=',
        'layout_mismatch_report_path' => '--parity-layout-mismatch-report-path=',
        'render_evidence_path' => '--parity-render-evidence-path=',
        'source_screenshot_path' => '--parity-source-screenshot-path=',
        'generated_screenshot_path' => '--parity-generated-screenshot-path=',
        'diff_image_path' => '--parity-diff-image-path=',
    );
}

/**
 * @return array<int, string>
 */
function matrix_html_entrypoints(string $root): array
{
    if ( ! is_dir($root) ) {
        return array();
    }

    $entrypoints = array();
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ( $iterator as $file ) {
        if ( ! $file instanceof SplFileInfo || ! $file->isFile() || 'html' !== strtolower($file->getExtension()) ) {
            continue;
        }
        $path = $file->getPathname();
        $relative = ltrim(substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1), DIRECTORY_SEPARATOR);
        $entrypoints[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    usort($entrypoints, static function (string $a, string $b): int {
        if ( 'index.html' === $a ) {
            return 'index.html' === $b ? 0 : -1;
        }
        if ( 'index.html' === $b ) {
            return 1;
        }
        return strnatcasecmp($a, $b);
    });

    return $entrypoints;
}

/**
 * @param array<int, string> $entrypoints
 */
function matrix_dom_box_capture_command(string $homeboyCommand, string $domBoxProviderCommand, string $root, array $entrypoints, string $reportPath, array $captureTargets = array()): string
{
    $parts = array(
        escapeshellarg($homeboyCommand),
        'tunnel',
        'artifact-origin',
        'dom-boxes',
        '--root=' . escapeshellarg($root),
        '--report=' . escapeshellarg($reportPath),
    );

    foreach ( $entrypoints as $entrypoint ) {
        $parts[] = '--entrypoint=' . escapeshellarg($entrypoint);
    }

    $command = implode(' ', $parts);
    if ( '' === $domBoxProviderCommand ) {
        return $command;
    }

    $environment = 'HOMEBOY_DOM_BOX_CAPTURE_COMMAND=' . escapeshellarg($domBoxProviderCommand)
        . ' HOMEBOY_DOM_BOX_NODE_ID_ATTR=' . escapeshellarg('data-figma-node-id')
        . ' HOMEBOY_DOM_BOX_NODE_NAME_ATTR=' . escapeshellarg('data-figma-node-name,data-figma-name');
    if ( ! empty($captureTargets) ) {
        $environment .= ' HOMEBOY_DOM_BOX_CAPTURE_TARGETS_JSON=' . escapeshellarg((string) json_encode($captureTargets, JSON_UNESCAPED_SLASHES));
    }

    return $environment . ' ' . $command;
}

/**
 * Build one native source-layout capture and retain responsive variants as
 * separately labeled evidence for each emitted page.
 *
 * @param array<int, string> $entrypoints
 * @return array<int, array<string, mixed>>
 */
function matrix_dom_box_capture_targets(string $resultPath, array $entrypoints): array
{
    if ( ! is_file($resultPath) ) {
        return array();
    }
    $result = json_decode((string) file_get_contents($resultPath), true);
    if ( ! is_array($result) ) {
        return array();
    }

    $pagePlan = $result['source_reports']['figma']['pages']['pages'] ?? array();
    $htmlPages = $result['source_reports']['figma']['html']['pages'] ?? array();
    $planByFrame = array();
    foreach ( is_array($pagePlan) ? $pagePlan : array() as $page ) {
        if ( is_array($page) && isset($page['frame_id']) && is_scalar($page['frame_id']) ) {
            $planByFrame[(string) $page['frame_id']] = $page;
        }
    }

    $entrypointSet = array_fill_keys(array_map(static fn (string $path): string => ltrim($path, '/'), $entrypoints), true);
    $targets = array();
    foreach ( is_array($htmlPages) ? $htmlPages : array() as $htmlPage ) {
        if ( ! is_array($htmlPage) ) {
            continue;
        }
        $frameId = isset($htmlPage['frame_id']) && is_scalar($htmlPage['frame_id']) ? (string) $htmlPage['frame_id'] : '';
        $page = $planByFrame[$frameId] ?? array();
        $paths = array_merge(array((string) ($htmlPage['path'] ?? '')), is_array($htmlPage['template_aliases'] ?? null) ? $htmlPage['template_aliases'] : array());
        $variants = is_array($page['variants'] ?? null) && ! empty($page['variants'])
            ? $page['variants']
            : array(array('frame_id' => $frameId, 'viewport_width' => $page['width'] ?? null, 'primary' => true));

        foreach ( $paths as $path ) {
            $path = ltrim((string) $path, '/');
            if ( '' === $path || ! isset($entrypointSet[$path]) ) {
                continue;
            }
            foreach ( $variants as $variant ) {
                if ( ! is_array($variant) || ! is_numeric($variant['viewport_width'] ?? null) || (float) $variant['viewport_width'] <= 0 ) {
                    continue;
                }
                $primary = true === ($variant['primary'] ?? false) || (string) ($variant['frame_id'] ?? '') === $frameId;
                $width = max(1, (int) round((float) $variant['viewport_width']));
                $targets[] = array(
                    'page_path' => $path,
                    'viewport' => array('width' => $width, 'height' => 900),
                    'source_frame' => array('id' => (string) ($variant['frame_id'] ?? $frameId), 'width' => $width),
                    'comparison_role' => $primary ? 'source_layout' : 'responsive_evidence',
                );
            }
        }
    }

    return $targets;
}

/**
 * Homeboy normalizes provider output to its stable DOM-box schema. Reattach the
 * matrix-owned source relationship to that normalized evidence by path/viewport.
 *
 * @param array<int, array<string, mixed>> $captureTargets
 */
function matrix_annotate_dom_box_capture(string $reportPath, array $captureTargets): void
{
    if ( empty($captureTargets) || ! is_file($reportPath) ) {
        return;
    }
    $report = json_decode((string) file_get_contents($reportPath), true);
    if ( ! is_array($report) || ! is_array($report['entrypoints'] ?? null) ) {
        return;
    }

    $usedTargets = array();
    foreach ( $report['entrypoints'] as $index => $entrypoint ) {
        if ( ! is_array($entrypoint) ) {
            continue;
        }
        $pagePath = ltrim((string) ($entrypoint['page_path'] ?? ''), '/');
        $viewportWidth = is_numeric($entrypoint['viewport']['width'] ?? null) ? (int) round((float) $entrypoint['viewport']['width']) : null;
        foreach ( $captureTargets as $targetIndex => $target ) {
            if ( isset($usedTargets[$targetIndex]) || ! is_array($target) ) {
                continue;
            }
            $targetPath = ltrim((string) ($target['page_path'] ?? ''), '/');
            $targetWidth = is_numeric($target['viewport']['width'] ?? null) ? (int) round((float) $target['viewport']['width']) : null;
            if ( $pagePath !== $targetPath || $viewportWidth !== $targetWidth ) {
                continue;
            }
            $report['entrypoints'][$index]['source_frame'] = is_array($target['source_frame'] ?? null) ? $target['source_frame'] : array();
            $report['entrypoints'][$index]['comparison_role'] = (string) ($target['comparison_role'] ?? 'responsive_evidence');
            $usedTargets[$targetIndex] = true;
            break;
        }
    }
    $report['capture_targets'] = $captureTargets;
    file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

function matrix_slug(string $value): string
{
    $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $value));
    return trim($slug, '-') ?: 'page';
}

/**
 * @param array<string, mixed> $inspection
 * @return array<string, mixed>
 */
function matrix_inspection_summary(array $inspection): array
{
    return array(
        'status' => $inspection['status'] ?? null,
        'node_count' => $inspection['node_count'] ?? null,
        'candidate_count' => $inspection['candidate_count'] ?? null,
        'returned_count' => $inspection['returned_count'] ?? null,
    );
}

/**
 * @param array<string, mixed> $result
 * @param array<string, mixed> $evidence
 * @return array<string, mixed>
 */
function matrix_attach_evidence_to_result(array $result, array $evidence): array
{
    if ( 'runner_paths' !== ($evidence['source'] ?? null) ) {
        return $result;
    }

    $parity = is_array($result['parity'] ?? null) ? $result['parity'] : array();
    $parity['artifacts'] = is_array($parity['artifacts'] ?? null) ? $parity['artifacts'] : array();
    $parity['layout_diagnostics'] = is_array($parity['layout_diagnostics'] ?? null) ? $parity['layout_diagnostics'] : array();
    $paths = is_array($evidence['paths'] ?? null) ? $evidence['paths'] : array();

    foreach ( matrix_evidence_artifact_keys() as $pathKey => $artifactKey ) {
        $path = matrix_evidence_record_path($paths[$pathKey] ?? null);
        if ( '' !== $path ) {
            $parity['artifacts'][$artifactKey] = $path;
        }
    }

    matrix_attach_screenshot_candidate($parity, $paths, 'source_screenshot_path', 'source', 'screenshot');
    matrix_attach_screenshot_candidate($parity, $paths, 'generated_screenshot_path', 'generated', 'screenshot');
    matrix_attach_screenshot_candidate($parity, $paths, 'diff_image_path', 'diff', 'image');

    $parityReport = matrix_read_json_evidence($paths['parity_report_path'] ?? null);
    if ( is_array($parityReport) ) {
        $parity = matrix_merge_parity_report($parity, $parityReport);
    } elseif ( 'not_run' === ($parity['status'] ?? 'not_run') && '' !== matrix_evidence_record_path($paths['parity_report_path'] ?? null) ) {
        $parity['status'] = 'pending';
        $parity['reason'] = 'parity_report_path_supplied';
    }

    $layoutReport = matrix_read_json_evidence($paths['layout_report_path'] ?? null);
    if ( ! is_array($layoutReport) ) {
        $layoutReport = matrix_read_json_evidence($paths['layout_mismatch_report_path'] ?? null);
    }
    if ( is_array($layoutReport) ) {
        $parity['layout_diagnostics'] = array_merge($parity['layout_diagnostics'], matrix_layout_summary($layoutReport));
    }

    $renderEvidence = matrix_read_json_evidence($paths['render_evidence_path'] ?? null);
    if ( is_array($renderEvidence) ) {
        $parity['render_style_evidence'] = matrix_render_style_evidence_summary($renderEvidence);
    } elseif ( '' !== matrix_evidence_record_path($paths['render_evidence_path'] ?? null) ) {
        $parity['render_style_evidence'] = array('status' => 'pending');
    }

    $result['parity'] = $parity;
    return $result;
}

/**
 * @return array<string, string>
 */
function matrix_evidence_artifact_keys(): array
{
    return array(
        'parity_report_path' => 'report_path',
        'dom_boxes_path' => 'dom_boxes_path',
        'layout_report_path' => 'layout_report_path',
        'layout_mismatch_report_path' => 'layout_mismatch_report_path',
        'render_evidence_path' => 'render_evidence_path',
    );
}

/**
 * @param array<string, mixed> $parity
 * @param array<string, mixed> $paths
 */
function matrix_attach_screenshot_candidate(array &$parity, array $paths, string $pathKey, string $section, string $prefix): void
{
    $record = is_array($paths[$pathKey] ?? null) ? $paths[$pathKey] : null;
    $path = matrix_evidence_record_path($record);
    if ( '' === $path || ! is_array($record) ) {
        return;
    }

    $parity[$section] = is_array($parity[$section] ?? null) ? $parity[$section] : array();
    $parity[$section][$prefix . '_path'] = $path;
    $parity[$section][$prefix . '_exists'] = true === ($record['exists'] ?? false);
    $parity[$section][$prefix . '_readable'] = true === ($record['readable'] ?? false);

    if ( false === $parity[$section][$prefix . '_exists'] && 'not_run' === ($parity['status'] ?? 'not_run') ) {
        $parity['status'] = 'pending';
        $parity['reason'] = $parity['reason'] ?? 'screenshot_evidence_configured';
    }

    if ( ! isset($parity['visual_pixel_status']) ) {
        $parity['visual_pixel_status'] = 'not_run';
    }
}

/**
 * @param mixed $record
 */
function matrix_evidence_record_path(mixed $record): string
{
    if ( is_array($record) && isset($record['path']) && is_scalar($record['path']) ) {
        return (string) $record['path'];
    }

    return '';
}

/**
 * @param mixed $record
 * @return array<string, mixed>|null
 */
function matrix_read_json_evidence(mixed $record): ?array
{
    $path = matrix_evidence_record_path($record);
    if ( '' === $path || ! is_readable($path) ) {
        return null;
    }

    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

/**
 * @param array<string, mixed> $parity
 * @param array<string, mixed> $report
 * @return array<string, mixed>
 */
function matrix_merge_parity_report(array $parity, array $report): array
{
    foreach ( array('status', 'reason', 'viewport') as $key ) {
        if ( isset($report[$key]) ) {
            $parity[$key] = $report[$key];
        }
    }

    foreach ( array('source', 'generated', 'diff', 'diff_summary', 'metrics') as $key ) {
        if ( isset($report[$key]) && is_array($report[$key]) ) {
            $parity[$key] = array_merge(is_array($parity[$key] ?? null) ? $parity[$key] : array(), $report[$key]);
        }
    }

    if ( isset($report['breakpoints']) && is_array($report['breakpoints']) ) {
        $parity['breakpoints'] = array_values($report['breakpoints']);
    }

    foreach ( array('pixel_mismatch_count', 'pixel_mismatch_ratio') as $key ) {
        if ( isset($report[$key]) && is_numeric($report[$key]) ) {
            $parity['metrics'][$key] = str_contains((string) $report[$key], '.') ? (float) $report[$key] : (int) $report[$key];
            $parity['diff_summary'][$key] = $parity['metrics'][$key];
        }
    }

    return $parity;
}

/**
 * @param array<string, mixed> $report
 * @return array<string, mixed>
 */
function matrix_layout_summary(array $report): array
{
    $mismatches = is_array($report['mismatches'] ?? null) ? array_values($report['mismatches']) : (is_array($report['diagnostics'] ?? null) ? array_values($report['diagnostics']) : array());
    $topNodes = $report['top_nodes'] ?? $report['top_mismatches'] ?? matrix_layout_top_nodes($mismatches);
    $summary = is_array($report['summary'] ?? null) ? $report['summary'] : array();
    $suspectedCauses = $summary['suspected_causes'] ?? $report['suspected_causes'] ?? array();

    return array(
        'status' => $report['status'] ?? null,
        'mismatch_count' => matrix_first_numeric($summary, array('diagnostic_count', 'layout_mismatch_count', 'mismatch_count', 'count')) ?? matrix_first_numeric($report, array('layout_mismatch_count', 'mismatch_count', 'count')) ?? count($mismatches),
        'top_nodes' => is_array($topNodes) ? array_slice(array_values($topNodes), 0, 5) : array(),
        'suspected_causes' => is_array($suspectedCauses) ? array_slice(array_values($suspectedCauses), 0, 5) : array(),
    );
}

/**
 * @param array<string, mixed> $report
 * @return array<string, mixed>
 */
function matrix_render_style_evidence_summary(array $report): array
{
    $summary = is_array($report['summary'] ?? null) ? $report['summary'] : array();
    $status = isset($report['status']) && is_scalar($report['status']) ? (string) $report['status'] : null;
    if ( null === $status && isset($summary['status']) && is_scalar($summary['status']) ) {
        $status = (string) $summary['status'];
    }

    return array_filter(array(
        'status' => $status ?? 'completed',
        'source' => 'render_evidence',
        'diagnostic_count' => matrix_first_numeric($summary, array('diagnostic_count', 'render_diagnostic_count', 'count')) ?? matrix_first_numeric($report, array('diagnostic_count', 'render_diagnostic_count', 'count')),
    ), static fn (mixed $value): bool => null !== $value);
}

/**
 * @param array<int, mixed> $mismatches
 * @return array<int, array<string, mixed>>
 */
function matrix_layout_top_nodes(array $mismatches): array
{
    $nodes = array();
    foreach ( array_slice($mismatches, 0, 5) as $mismatch ) {
        if ( ! is_array($mismatch) ) {
            continue;
        }
        $node = is_array($mismatch['node'] ?? null) ? $mismatch['node'] : $mismatch;
        $nodes[] = array_filter(array(
            'id' => isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : null,
            'name' => isset($node['name']) && is_scalar($node['name']) ? (string) $node['name'] : null,
            'type' => isset($node['type']) && is_scalar($node['type']) ? (string) $node['type'] : null,
            'code' => isset($mismatch['code']) && is_scalar($mismatch['code']) ? (string) $mismatch['code'] : null,
            'max_delta' => isset($mismatch['max_delta']) && is_numeric($mismatch['max_delta']) ? (float) $mismatch['max_delta'] : null,
        ), static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    return $nodes;
}

/**
 * @param array<string, mixed> $values
 * @param array<int, string> $keys
 */
function matrix_first_numeric(array $values, array $keys): int|float|null
{
    foreach ( $keys as $key ) {
        if ( isset($values[$key]) && is_numeric($values[$key]) ) {
            return str_contains((string) $values[$key], '.') ? (float) $values[$key] : (int) $values[$key];
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $parity
 * @return array<string, mixed>
 */
function matrix_parity_summary(array $parity): array
{
    $metrics = is_array($parity['metrics'] ?? null) ? $parity['metrics'] : array();
    $diffSummary = is_array($parity['diff_summary'] ?? null) ? $parity['diff_summary'] : array();
    $layout = is_array($parity['layout_diagnostics'] ?? null) ? $parity['layout_diagnostics'] : array();
    $layoutEvidence = is_array($parity['layout_evidence'] ?? null) ? $parity['layout_evidence'] : array();
    $renderStyleEvidence = is_array($parity['render_style_evidence'] ?? null) ? $parity['render_style_evidence'] : array();
    $pixelMismatchCount = $metrics['pixel_mismatch_count'] ?? $diffSummary['pixel_mismatch_count'] ?? null;
    $pixelMismatchRatio = $metrics['pixel_mismatch_ratio'] ?? $diffSummary['pixel_mismatch_ratio'] ?? null;
    $layoutMismatchCount = $layout['mismatch_count'] ?? null;
    $layoutMismatchStatus = isset($layoutEvidence['status']) ? (string) $layoutEvidence['status'] : (is_numeric($layoutMismatchCount) ? (0 === (int) $layoutMismatchCount ? 'pass' : 'fail') : 'not_run');

    return array(
        'status' => $parity['status'] ?? 'not_run',
        'visual_pixel_status' => $parity['visual_pixel_status'] ?? (null !== $pixelMismatchCount || null !== $pixelMismatchRatio ? ($parity['status'] ?? 'not_run') : 'not_run'),
        'pixel_mismatch_count' => $pixelMismatchCount,
        'pixel_mismatch_ratio' => $pixelMismatchRatio,
        'layout_evidence' => empty($layoutEvidence) ? array('status' => $layoutMismatchStatus) : $layoutEvidence,
        'render_style_evidence' => empty($renderStyleEvidence) ? array('status' => 'not_run') : $renderStyleEvidence,
        'layout_mismatch_count' => $layoutMismatchCount,
        'layout_mismatch_status' => $layoutMismatchStatus,
        'layout_top_nodes' => is_array($layout['top_nodes'] ?? null) ? array_slice($layout['top_nodes'], 0, 5) : array(),
        'layout_suspected_causes' => is_array($layout['suspected_causes'] ?? null) ? array_slice($layout['suspected_causes'], 0, 5) : array(),
    );
}

/**
 * @param array<string, mixed> $inspection
 * @param array<int, string> $frameIds
 * @return array<int, array<string, mixed>>
 */
function matrix_selected_frame_records(array $inspection, array $frameIds): array
{
    $byId = array();
    foreach ( is_array($inspection['candidates'] ?? null) ? $inspection['candidates'] : array() as $candidate ) {
        if ( ! is_array($candidate) || ! isset($candidate['id']) || ! is_scalar($candidate['id']) ) {
            continue;
        }

        $byId[(string) $candidate['id']] = array(
            'id' => (string) $candidate['id'],
            'name' => (string) ($candidate['name'] ?? ''),
            'page' => (string) ($candidate['page']['name'] ?? ''),
            'width' => $candidate['width'] ?? null,
            'height' => $candidate['height'] ?? null,
            'score' => $candidate['score'] ?? null,
            'rank' => matrix_candidate_rank($candidate),
            'bucket' => matrix_candidate_bucket($candidate),
            'device_hint' => $candidate['device_hint'] ?? null,
            'sibling_group_key' => $candidate['sibling_group_key'] ?? null,
            'responsive_siblings' => is_array($candidate['responsive_siblings'] ?? null) ? $candidate['responsive_siblings'] : array(),
            'selection_reasons' => matrix_candidate_selection_reasons($candidate),
        );
    }

    return array_values(array_filter(array_map(static fn (string $id): ?array => $byId[$id] ?? null, $frameIds)));
}
