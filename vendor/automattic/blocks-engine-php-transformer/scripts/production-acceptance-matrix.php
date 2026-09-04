#!/usr/bin/env php
<?php

declare(strict_types=1);

const ACCEPTANCE_SCHEMA = 'blocks-engine/figma-wordpress-acceptance/v2';
const SITE_PLAN_SCHEMA = 'blocks-engine/wordpress-site-plan/v2';
const FIXTURE_IDS = array('fse-pilot-build-theme', 'twenty-twenty-five-community', 'fisiostetic');
const PARITY_STAGES = array(
    'figma_html_desktop_parity' => 'figma_html',
    'figma_html_mobile_parity' => 'figma_html',
    'html_wordpress_desktop_parity' => 'html_wordpress',
    'html_wordpress_mobile_parity' => 'html_wordpress',
    'figma_wordpress_desktop_parity' => 'figma_wordpress',
    'figma_wordpress_mobile_parity' => 'figma_wordpress',
);
const STAGES = array('decode', 'normalize', 'emit', 'figma_html_desktop_parity', 'figma_html_mobile_parity', 'import', 'editor_validity', 'fallback', 'html_wordpress_desktop_parity', 'html_wordpress_mobile_parity', 'figma_wordpress_desktop_parity', 'figma_wordpress_mobile_parity', 'responsive_selection');

$autoload = dirname(__DIR__) . '/php-transformer/vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
}

$options = acceptance_options($argv);
if (isset($options['help'])) {
    acceptance_help();
    exit(0);
}

$manifestPath = $options['manifest'] ?? '';
if ('' === $manifestPath || !is_readable($manifestPath)) {
    fwrite(STDERR, "A readable --manifest=path is required. Run with --help for the contract.\n");
    exit(2);
}
$manifest = json_decode((string) file_get_contents($manifestPath), true);
if (!is_array($manifest)) {
    fwrite(STDERR, "Manifest must be valid JSON.\n");
    exit(2);
}

$output = $options['output'] ?? 'artifacts/figma-wordpress-acceptance';
$output = rtrim($output, '/');
if (!is_dir($output) && !mkdir($output, 0777, true) && !is_dir($output)) {
    fwrite(STDERR, "Unable to create output directory.\n");
    exit(2);
}

$profile = $options['profile'] ?? 'production';
if (!in_array($profile, array('production', 'manifest'), true)) {
    fwrite(STDERR, "--profile must be production or manifest.\n");
    exit(2);
}
$summary = array('schema' => ACCEPTANCE_SCHEMA, 'profile' => $profile, 'status' => 'failed', 'fixtures' => array(), 'failure_count' => 0);
$fixtures = is_array($manifest['fixtures'] ?? null) ? $manifest['fixtures'] : array();
$byId = array();
foreach ($fixtures as $fixture) {
    $id = is_array($fixture) && is_string($fixture['id'] ?? null) ? $fixture['id'] : '';
    if ('' === $id || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $id) || isset($byId[$id])) {
        fwrite(STDERR, "Fixture ids must be unique lowercase slugs.\n");
        exit(2);
    }
    $byId[$id] = $fixture;
}
$fixtureIds = 'production' === $profile ? FIXTURE_IDS : array_keys($byId);
if (empty($fixtureIds)) {
    fwrite(STDERR, "Manifest profile requires at least one fixture.\n");
    exit(2);
}

foreach ($fixtureIds as $fixtureId) {
    $fixture = $byId[$fixtureId] ?? array('id' => $fixtureId);
    $fixtureOutput = $output . '/fixtures/' . $fixtureId;
    if (!is_dir($fixtureOutput)) {
        mkdir($fixtureOutput, 0777, true);
    }
    $record = acceptance_fixture($fixture, $fixtureOutput, $output, isset($options['no_run_providers']));
    $summary['fixtures'][] = $record;
    $summary['failure_count'] += count($record['failures']);
}

$summary['status'] = 0 === $summary['failure_count'] ? 'passed' : 'failed';
file_put_contents($output . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit('passed' === $summary['status'] ? 0 : 1);

function acceptance_options(array $argv): array {
    $options = array();
    foreach (array_slice($argv, 1) as $argument) {
        if ('--help' === $argument || '-h' === $argument) {
            $options['help'] = true;
        } elseif ('--no-run-providers' === $argument) {
            $options['no_run_providers'] = true;
        } elseif (str_starts_with($argument, '--') && str_contains($argument, '=')) {
            [$key, $value] = explode('=', substr($argument, 2), 2);
            $options[str_replace('-', '_', $key)] = $value;
        }
    }
    return $options;
}

function acceptance_fixture(array $fixture, string $fixtureOutput, string $artifactRoot, bool $skipProviders): array {
    $id = is_string($fixture['id'] ?? null) ? $fixture['id'] : 'unknown';
    $record = array('id' => $id, 'status' => 'failed', 'stages' => array(), 'failures' => array());
    $input = is_string($fixture['fig'] ?? null) ? $fixture['fig'] : '';
    if ('' === $input || !is_readable($input)) {
        $record['failures'][] = acceptance_failure('decode', 'decode_missing_input');
    }
    $sourceHash = is_readable($input) ? hash_file('sha256', $input) : '';

    $commands = is_array($fixture['provider_commands'] ?? null) ? $fixture['provider_commands'] : array();
    if (isset($fixture['figma_matrix_command'])) {
        $commands = array_merge(array('figma_matrix' => $fixture['figma_matrix_command']), $commands);
    }
    foreach ($commands as $name => $command) {
        if (!$skipProviders && is_string($command) && '' !== trim($command)) {
            $expanded = strtr($command, array('{fixture_output}' => escapeshellarg($fixtureOutput), '{fig}' => escapeshellarg($input)));
            exec($expanded, $ignored, $exitCode);
            if (0 !== $exitCode) {
                $stage = 'figma_matrix' === $name ? 'decode' : (in_array($name, STAGES, true) ? $name : 'import');
                $record['failures'][] = acceptance_failure($stage, $stage . '_provider_failed');
            }
        }
    }

    $evidence = is_array($fixture['evidence'] ?? null) ? $fixture['evidence'] : array();
    foreach (STAGES as $stage) {
        $path = is_string($evidence[$stage] ?? null) ? $evidence[$stage] : '';
        $stageRecord = acceptance_stage($stage, $path, $artifactRoot, $id, $sourceHash);
        $record['stages'][$stage] = $stageRecord;
        if ('passed' !== $stageRecord['status']) {
            $record['failures'][] = acceptance_failure($stage, $stageRecord['reason_code']);
        }
    }

    $sitePlan = is_string($fixture['site_plan'] ?? null) ? $fixture['site_plan'] : '';
    $sitePlanReason = acceptance_site_plan_reason($sitePlan);
    if (null !== $sitePlanReason) {
        $record['failures'][] = acceptance_failure('import', $sitePlanReason);
    }
    $record['status'] = empty($record['failures']) ? 'passed' : 'failed';
    return $record;
}

function acceptance_stage(string $stage, string $path, string $output, string $fixtureId, string $sourceHash): array {
    $missing = $stage . '_missing_evidence';
    if ('' === $path || !is_readable($path)) {
        return array('status' => 'failed', 'reason_code' => $missing);
    }
    $evidence = json_decode((string) file_get_contents($path), true);
    if (!is_array($evidence) || 'blocks-engine/figma-wordpress-stage-evidence/v1' !== ($evidence['schema'] ?? null)) {
        return array('status' => 'failed', 'reason_code' => $stage . '_invalid_evidence');
    }
    if ($fixtureId !== ($evidence['fixture_id'] ?? null)) {
        return array('status' => 'failed', 'reason_code' => $stage . '_fixture_mismatch');
    }
    if ($stage !== ($evidence['stage'] ?? null)) {
        return array('status' => 'failed', 'reason_code' => $stage . '_stage_mismatch');
    }
    if ('' === $sourceHash || $sourceHash !== ($evidence['source_sha256'] ?? null)) {
        return array('status' => 'failed', 'reason_code' => $stage . '_source_hash_mismatch');
    }
    if ('passed' !== ($evidence['status'] ?? null)) {
        return array('status' => 'failed', 'reason_code' => $stage . '_failed');
    }
    if (!acceptance_references_valid($evidence['references'] ?? null, $output)) {
        return array('status' => 'failed', 'reason_code' => $stage . '_unresolvable_evidence');
    }
    if (isset(PARITY_STAGES[$stage]) && !acceptance_screenshot_proof($evidence, $output)) {
        return array('status' => 'failed', 'reason_code' => $stage . '_missing_screenshots');
    }
    if (isset(PARITY_STAGES[$stage]) && PARITY_STAGES[$stage] !== ($evidence['comparison'] ?? null)) {
        return array('status' => 'failed', 'reason_code' => $stage . '_comparison_mismatch');
    }
    $semanticReason = acceptance_stage_semantic_reason($stage, $evidence, $output);
    if (null !== $semanticReason) {
        return array('status' => 'failed', 'reason_code' => $semanticReason);
    }
    if ('fallback' === $stage && !is_int($evidence['fallback_count'] ?? null)) {
        return array('status' => 'failed', 'reason_code' => 'fallback_missing_count');
    }
    if ('fallback' === $stage && 0 !== $evidence['fallback_count']) {
        return array('status' => 'failed', 'reason_code' => 'fallback_blocks_present');
    }
    return array('status' => 'passed', 'reason_code' => 'ok', 'references' => array_values($evidence['references']));
}

function acceptance_stage_semantic_reason(string $stage, array $evidence, string $output): ?string {
    $metrics = is_array($evidence['metrics'] ?? null) ? $evidence['metrics'] : null;
    if (null === $metrics && !in_array($stage, array('fallback', 'responsive_selection'), true)) {
        return $stage . '_missing_metrics';
    }
    if ('decode' === $stage && (!acceptance_zero_metric($metrics, 'missing_text_count') || !acceptance_zero_metric($metrics, 'missing_asset_count') || !acceptance_zero_metric($metrics, 'vector_placeholder_count'))) {
        return 'decode_incomplete_output';
    }
    if ('normalize' === $stage && !acceptance_positive_metric($metrics, 'normalized_node_count')) {
        return 'normalize_missing_nodes';
    }
    if ('emit' === $stage && (!acceptance_positive_metric($metrics, 'emitted_route_count') || !acceptance_zero_metric($metrics, 'missing_emitted_asset_count') || !acceptance_zero_metric($metrics, 'missing_emitted_text_count'))) {
        return 'emit_incomplete_artifact';
    }
    if ('import' === $stage && (!acceptance_positive_metric($metrics, 'imported_route_count') || true !== ($evidence['isolated_fresh_wordpress_import'] ?? null) || !is_string($evidence['provider_identity'] ?? null) || '' === $evidence['provider_identity'] || !is_string($evidence['runtime_identity'] ?? null) || '' === $evidence['runtime_identity'])) {
        return 'import_incomplete_materialization';
    }
    if ('editor_validity' === $stage && (!acceptance_positive_metric($metrics, 'parsed_block_count') || !acceptance_positive_metric($metrics, 'native_editable_block_count') || !acceptance_zero_metric($metrics, 'invalid_block_count'))) {
        return 'editor_validity_invalid_blocks';
    }
    if (isset(PARITY_STAGES[$stage])) {
        return acceptance_parity_reason($stage, $evidence, $metrics, $output);
    }
    if ('responsive_selection' === $stage && !acceptance_responsive_selection($evidence)) {
        return 'responsive_selection_invalid_routes';
    }
    return null;
}

function acceptance_zero_metric(?array $metrics, string $key): bool {
    return is_array($metrics) && isset($metrics[$key]) && is_int($metrics[$key]) && 0 === $metrics[$key];
}

function acceptance_positive_metric(?array $metrics, string $key): bool {
    return is_array($metrics) && isset($metrics[$key]) && is_int($metrics[$key]) && $metrics[$key] > 0;
}

function acceptance_parity_reason(string $stage, array $evidence, ?array $metrics, string $output): ?string {
    $report = json_decode((string) file_get_contents($output . '/' . $evidence['diff_report']), true);
    $reportMetrics = is_array($report['metrics'] ?? null) ? $report['metrics'] : $report;
    $metrics = is_array($reportMetrics) ? $reportMetrics : $metrics;
    if (!is_array($metrics) || !isset($metrics['pixel_difference_count'], $metrics['geometry_difference_count']) || !is_int($metrics['pixel_difference_count']) || !is_int($metrics['geometry_difference_count'])) {
        return $stage . '_missing_metrics';
    }
    return 0 === $metrics['pixel_difference_count'] && 0 === $metrics['geometry_difference_count'] ? null : $stage . '_nonzero_difference';
}

function acceptance_site_plan_reason(string $path): ?string {
    if ('' === $path || !is_readable($path)) {
        return 'import_missing_site_plan';
    }
    $plan = json_decode((string) file_get_contents($path), true);
    if (!is_array($plan) || SITE_PLAN_SCHEMA !== ($plan['schema'] ?? null) || !class_exists('Automattic\\BlocksEngine\\PhpTransformer\\WordPressSitePlan\\WordPressSitePlan')) {
        return 'import_invalid_site_plan';
    }
    try {
        \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan::assertValid($plan);
        return !empty($plan['pages']) && !empty($plan['routes']) ? null : 'import_empty_site_plan';
    } catch (Throwable) {
        return 'import_invalid_site_plan';
    }
}

function acceptance_screenshot_proof(array $evidence, string $output): bool {
    foreach (array('source_screenshot', 'rendered_screenshot', 'diff_report') as $key) {
        if (!is_string($evidence[$key] ?? null) || !acceptance_reference($evidence[$key]) || !is_file($output . '/' . $evidence[$key])) {
            return false;
        }
    }
    return true;
}

function acceptance_responsive_selection(array $evidence): bool {
    if (!in_array($evidence['selection_source'] ?? null, array('dev_status', 'heuristic_fallback'), true) || !is_array($evidence['responsive_routes'] ?? null) || empty($evidence['responsive_routes'])) {
        return false;
    }
    foreach ($evidence['responsive_routes'] as $route) {
        if (!is_array($route) || !is_string($route['output_route'] ?? null) || '' === $route['output_route'] || !is_string($route['desktop_source_frame'] ?? null) || '' === $route['desktop_source_frame'] || !is_string($route['mobile_source_frame'] ?? null) || '' === $route['mobile_source_frame'] || !is_int($route['breakpoint_min_width'] ?? null) || !is_int($route['breakpoint_max_width'] ?? null) || $route['breakpoint_min_width'] >= $route['breakpoint_max_width']) {
            return false;
        }
    }
    return true;
}

function acceptance_references_valid(mixed $references, string $output): bool {
    if (!is_array($references) || empty($references)) {
        return false;
    }
    foreach ($references as $reference) {
        if (!is_string($reference) || !acceptance_reference($reference) || !is_file($output . '/' . $reference)) {
            return false;
        }
    }
    return true;
}

function acceptance_reference(string $reference): bool {
    return '' !== $reference && !str_starts_with($reference, '/') && !preg_match('#^[A-Za-z]:[\\\\/]#', $reference) && !preg_match('#^(?:https?://)?(?:localhost|127\\.0\\.0\\.1)#i', $reference) && !str_contains($reference, '..');
}

function acceptance_failure(string $stage, string $reason): array {
    return array('stage' => $stage, 'reason_code' => $reason);
}

function acceptance_help(): void {
    echo <<<'HELP'
Usage: php scripts/production-acceptance-matrix.php --manifest=acceptance.json [--profile=production|manifest] [--output=artifacts/figma-wordpress-acceptance]

The manifest supplies private .fig inputs and generic external provider commands. Provider commands may use {fig} and {fixture_output}; they must write versioned stage evidence and a wordpress-site-plan/v2 JSON file. The generated summary contains only repository-relative evidence references, never input paths or URLs.

The default production profile requires fixture ids: fse-pilot-build-theme, twenty-twenty-five-community, fisiostetic.
The manifest profile evaluates every supplied fixture id and supports arbitrary .fig files.
Required stages: decode, normalize, emit, figma_html_desktop_parity, figma_html_mobile_parity, import, editor_validity, fallback, html_wordpress_desktop_parity, html_wordpress_mobile_parity, figma_wordpress_desktop_parity, figma_wordpress_mobile_parity, responsive_selection.
HELP;
}
