<?php

declare(strict_types=1);

const MATRIX_ACCEPTANCE_READINESS_SCHEMA = 'blocks-engine/figma-transformer/acceptance-readiness/v1';
const MATRIX_ACCEPTANCE_STAGE_SCHEMA = 'blocks-engine/figma-wordpress-stage-evidence/v1';

/**
 * Projects completed Figma transforms into the Figma-owned portion of the
 * production acceptance contract. WordPress stages deliberately remain absent.
 *
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function matrix_acceptance_readiness(string $fixtureId, string $fixturePath, array $result, string $artifactRoot, string $resultPath): array
{
    $sourceHash = is_file($fixturePath) ? hash_file('sha256', $fixturePath) : false;
    $sourceHash = is_string($sourceHash) ? $sourceHash : '';
    $resultReference = matrix_acceptance_reference($resultPath, $artifactRoot);
    $diagnostics = is_array($result['source_reports']['figma']['html']['transform_diagnostics'] ?? null)
        ? $result['source_reports']['figma']['html']['transform_diagnostics']
        : array();
    $quality = is_array($diagnostics['artifact_quality']['summary'] ?? null)
        ? $diagnostics['artifact_quality']['summary']
        : array();
    $metrics = is_array($result['metrics'] ?? null) ? $result['metrics'] : array();
    $pages = is_array($result['source_reports']['figma']['pages'] ?? null) ? $result['source_reports']['figma']['pages'] : array();

    // These are distinct canonical facts: empty decoded text, unresolved source
    // assets, vector decode placeholders, normalized nodes, and emitted coverage.
    $decodeMetrics = array(
        'missing_text_count' => matrix_acceptance_int($quality, 'empty_decoded_text_nodes'),
        'missing_asset_count' => matrix_acceptance_int($quality, 'missing_asset_nodes'),
        'vector_placeholder_count' => matrix_acceptance_int($quality, 'vector_placeholders'),
    );
    $normalizeMetrics = array('normalized_node_count' => matrix_acceptance_int($metrics, 'node_count'));
    $emitMetrics = array(
        'emitted_route_count' => matrix_acceptance_int($metrics, 'page_count'),
        'missing_emitted_asset_count' => matrix_acceptance_nested_int($quality, array('source_loss_coverage', 'domains', 'images', 'node_coverage', 'uncovered_source_nodes')),
        'missing_emitted_text_count' => matrix_acceptance_int($quality, 'missing_emitted_text_nodes'),
    );

    $stages = array(
        'decode' => matrix_acceptance_metric_stage($fixtureId, $sourceHash, 'decode', $decodeMetrics, $resultReference, 'decode_incomplete_output'),
        'normalize' => matrix_acceptance_metric_stage($fixtureId, $sourceHash, 'normalize', $normalizeMetrics, $resultReference, 'normalize_missing_nodes'),
        'emit' => matrix_acceptance_metric_stage($fixtureId, $sourceHash, 'emit', $emitMetrics, $resultReference, 'emit_incomplete_artifact'),
    );
    $responsive = matrix_acceptance_responsive_stage($fixtureId, $sourceHash, $pages, $resultReference);
    $stages['responsive_selection'] = $responsive;
    $parity = matrix_acceptance_parity_stages($fixtureId, $sourceHash, $result, $artifactRoot, $resultReference, $responsive);
    $stages += $parity;

    return array(
        'schema' => MATRIX_ACCEPTANCE_READINESS_SCHEMA,
        'status' => matrix_acceptance_all_passed($stages) ? 'passed' : 'unavailable',
        'stages' => $stages,
    );
}

/** @param array<string, mixed> $readiness */
function matrix_write_acceptance_readiness(array $readiness, string $fixtureOutputDir): array
{
    $directory = $fixtureOutputDir . '/acceptance-readiness';
    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    $paths = array();
    foreach ($readiness['stages'] as $stage => $evidence) {
        $path = $directory . '/' . $stage . '.json';
        file_put_contents($path, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $paths[$stage] = $path;
    }
    return $paths;
}

/** @param array<string, mixed> $metrics */
function matrix_acceptance_metric_stage(string $fixtureId, string $sourceHash, string $stage, array $metrics, string $resultReference, string $failure): array
{
    $passed = 'decode' === $stage
        ? 0 === $metrics['missing_text_count'] && 0 === $metrics['missing_asset_count'] && 0 === $metrics['vector_placeholder_count']
        : ('normalize' === $stage
            ? $metrics['normalized_node_count'] > 0
            : $metrics['emitted_route_count'] > 0 && 0 === $metrics['missing_emitted_asset_count'] && 0 === $metrics['missing_emitted_text_count']);
    return matrix_acceptance_stage($fixtureId, $sourceHash, $stage, $passed ? 'passed' : 'failed', $passed ? 'ok' : $failure, $metrics, array($resultReference));
}

/** @param array<string, mixed> $pages */
function matrix_acceptance_responsive_stage(string $fixtureId, string $sourceHash, array $pages, string $resultReference): array
{
    $selection = $pages['selection_source'] ?? null;
    $routes = array();
    foreach (is_array($pages['pages'] ?? null) ? $pages['pages'] : array() as $page) {
        if (!is_array($page) || !is_array($page['variants'] ?? null)) {
            continue;
        }
        $desktop = null;
        $mobile = null;
        foreach ($page['variants'] as $variant) {
            if (!is_array($variant) || !is_string($variant['frame_id'] ?? null) || '' === $variant['frame_id']) {
                continue;
            }
            if ('desktop' === ($variant['device_hint'] ?? null)) {
                $desktop = $variant;
            }
            if ('mobile' === ($variant['device_hint'] ?? null)) {
                $mobile = $variant;
            }
        }
        if (is_array($desktop) && is_array($mobile) && is_numeric($desktop['viewport_width'] ?? null) && is_numeric($mobile['viewport_width'] ?? null) && (int) $mobile['viewport_width'] < (int) $desktop['viewport_width']) {
            $routes[] = array('output_route' => (string) ($page['path'] ?? ''), 'desktop_source_frame' => $desktop['frame_id'], 'mobile_source_frame' => $mobile['frame_id'], 'breakpoint_min_width' => (int) $mobile['viewport_width'], 'breakpoint_max_width' => (int) $desktop['viewport_width']);
        }
    }
    if (!in_array($selection, array('dev_status', 'heuristic'), true) || empty($routes)) {
        $reason = 'heuristic' === $selection ? 'responsive_selection_mobile_source_unavailable' : 'responsive_selection_incomplete_boundaries';
        return matrix_acceptance_stage($fixtureId, $sourceHash, 'responsive_selection', 'failed', $reason, array(), array($resultReference));
    }
    $evidence = matrix_acceptance_stage($fixtureId, $sourceHash, 'responsive_selection', 'passed', 'ok', array(), array($resultReference));
    $evidence['selection_source'] = 'dev_status' === $selection ? 'dev_status' : 'heuristic_fallback';
    $evidence['responsive_routes'] = $routes;
    return $evidence;
}

/** @param array<string, mixed> $result @param array<string, mixed> $responsive */
function matrix_acceptance_parity_stages(string $fixtureId, string $sourceHash, array $result, string $artifactRoot, string $resultReference, array $responsive): array
{
    $parity = is_array($result['parity'] ?? null) ? $result['parity'] : array();
    $breakpoints = is_array($parity['breakpoints'] ?? null) ? $parity['breakpoints'] : array();
    $stages = array();
    foreach (array('desktop', 'mobile') as $viewport) {
        $stage = 'figma_html_' . $viewport . '_parity';
        $breakpoint = matrix_acceptance_parity_breakpoint($breakpoints, $viewport);
        $source = matrix_acceptance_reference((string) ($breakpoint['source']['screenshot_path'] ?? ''), $artifactRoot);
        $rendered = matrix_acceptance_reference((string) ($breakpoint['generated']['screenshot_path'] ?? ''), $artifactRoot);
        $diff = matrix_acceptance_reference((string) ($breakpoint['artifacts']['report_path'] ?? ''), $artifactRoot);
        $proof = '' !== $source && '' !== $rendered && '' !== $diff && is_file($artifactRoot . '/' . $source) && is_file($artifactRoot . '/' . $rendered) && is_file($artifactRoot . '/' . $diff);
        $report = $proof ? json_decode((string) file_get_contents($artifactRoot . '/' . $diff), true) : null;
        $reportMetrics = is_array($report['metrics'] ?? null) ? $report['metrics'] : $report;
        $exactDiff = is_array($reportMetrics) && is_int($reportMetrics['pixel_difference_count'] ?? null) && is_int($reportMetrics['geometry_difference_count'] ?? null);
        $zeroDiff = $exactDiff && 0 === $reportMetrics['pixel_difference_count'] && 0 === $reportMetrics['geometry_difference_count'];
        $mobileUnavailable = 'mobile' === $viewport && 'passed' !== ($responsive['status'] ?? null);
        $reason = !$proof ? $stage . '_missing_screenshots' : (!$exactDiff ? $stage . '_missing_metrics' : (!$zeroDiff ? $stage . '_nonzero_difference' : ($mobileUnavailable ? 'responsive_selection_mobile_source_unavailable' : 'ok')));
        $evidence = matrix_acceptance_stage($fixtureId, $sourceHash, $stage, 'ok' === $reason ? 'passed' : 'failed', $reason, is_array($reportMetrics) ? $reportMetrics : array(), array_values(array_filter(array($resultReference, $source, $rendered, $diff))));
        $evidence['comparison'] = 'figma_html';
        if ($proof) {
            $evidence['source_screenshot'] = $source;
            $evidence['rendered_screenshot'] = $rendered;
            $evidence['diff_report'] = $diff;
            $evidence['artifact_sha256'] = array(
                'source_screenshot' => hash_file('sha256', $artifactRoot . '/' . $source),
                'rendered_screenshot' => hash_file('sha256', $artifactRoot . '/' . $rendered),
                'diff_report' => hash_file('sha256', $artifactRoot . '/' . $diff),
            );
        }
        $stages[$stage] = $evidence;
    }
    return $stages;
}

/** @param array<int, mixed> $breakpoints @return array<string, mixed> */
function matrix_acceptance_parity_breakpoint(array $breakpoints, string $viewport): array
{
    foreach ($breakpoints as $breakpoint) {
        if (is_array($breakpoint) && $viewport === ($breakpoint['viewport']['device_hint'] ?? null)) {
            return $breakpoint;
        }
    }
    return array();
}

/** @param array<string, mixed> $metrics @param array<int, string> $references */
function matrix_acceptance_stage(string $fixtureId, string $sourceHash, string $stage, string $status, string $reason, array $metrics, array $references): array
{
    return array_filter(array('schema' => MATRIX_ACCEPTANCE_STAGE_SCHEMA, 'status' => $status, 'reason_code' => $reason, 'fixture_id' => $fixtureId, 'stage' => $stage, 'source_sha256' => $sourceHash, 'metrics' => $metrics, 'references' => array_values(array_filter($references))), static fn (mixed $value): bool => array() !== $value);
}

function matrix_acceptance_int(array $values, string $key): int { return is_int($values[$key] ?? null) ? $values[$key] : -1; }
function matrix_acceptance_nested_int(array $values, array $keys): int { foreach ($keys as $key) { if (!is_array($values) || !array_key_exists($key, $values)) { return -1; } $values = $values[$key]; } return is_int($values) ? $values : -1; }
function matrix_acceptance_reference(string $path, string $root): string { if ('' === $path) { return ''; } $root = rtrim(str_replace('\\', '/', $root), '/'); $path = str_replace('\\', '/', $path); return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : (!str_starts_with($path, '/') && !str_contains($path, '..') ? $path : ''); }
function matrix_acceptance_all_passed(array $stages): bool { foreach ($stages as $stage) { if ('passed' !== ($stage['status'] ?? null)) { return false; } } return true; }
