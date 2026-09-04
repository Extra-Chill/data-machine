<?php

declare(strict_types=1);

require_once __DIR__ . '/figma-script-utils.php';

/**
 * Build a compact route/page identity report from a figma-transformer result JSON.
 *
 * Usage:
 *   php figma-transformer/scripts/figma-route-identity-report.php result.json [--output=report.json]
 */

try {
    $input = $argv[1] ?? '';
    if ( '' === $input || '--help' === $input || '-h' === $input ) {
        fwrite(STDERR, "Usage: figma-route-identity-report.php <transform-result.json> [--output=<path>]\n");
        exit('' === $input ? 1 : 0);
    }

    $options = array();
    foreach ( array_slice($argv, 2) as $argument ) {
        if ( str_starts_with($argument, '--output=') ) {
            $options['output'] = substr($argument, strlen('--output='));
        }
    }

    $path = blocks_engine_figma_script_require_input_path($input);
    $json = file_get_contents($path);
    if ( false === $json ) {
        throw new RuntimeException("Unable to read input JSON: {$path}");
    }

    $result = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    if ( ! is_array($result) ) {
        throw new RuntimeException('Input JSON did not decode to an object.');
    }

    $report = blocks_engine_figma_route_identity_report($result, $path);
    blocks_engine_figma_script_output_json($report, $options['output'] ?? null, array(
        'page_count' => $report['summary']['page_count'],
        'duplicate_route_draft_frame_count' => $report['summary']['duplicate_route_draft_frame_count'],
        'unresolved_link_count' => $report['summary']['unresolved_link_count'],
        'implicit_route_link_count' => $report['summary']['implicit_route_link_count'],
    ));
} catch ( Throwable $error ) {
    blocks_engine_figma_script_fail($error);
}

/**
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function blocks_engine_figma_route_identity_report(array $result, string $inputPath): array
{
    $html = is_array($result['source_reports']['figma']['html'] ?? null) ? $result['source_reports']['figma']['html'] : array();
    $pagePlan = is_array($html['page_plan'] ?? null) ? $html['page_plan'] : array();
    $plannedPages = is_array($pagePlan['pages'] ?? null) ? $pagePlan['pages'] : array();
    $renderedPages = is_array($html['pages'] ?? null) ? $html['pages'] : array();

    $pages = array();
    $pathByFrameId = array();
    $pathBySlug = array();
    foreach ( $plannedPages as $index => $page ) {
        if ( ! is_array($page) ) {
            continue;
        }
        $identity = is_array($page['source_frame_identity'] ?? null) ? $page['source_frame_identity'] : array();
        $variants = is_array($page['variants'] ?? null) ? $page['variants'] : array();
        $frameId = (string) ($page['frame_id'] ?? '');
        $path = (string) ($page['path'] ?? '');
        $slug = (string) ($page['slug'] ?? '');
        $variantFrameIds = array_values(array_filter(array_map(
            static fn (mixed $variant): string => is_array($variant) ? (string) ($variant['frame_id'] ?? '') : '',
            $variants
        )));

        if ( '' !== $frameId && '' !== $path ) {
            $pathByFrameId[$frameId] = $path;
        }
        foreach ( $variantFrameIds as $variantFrameId ) {
            if ( '' !== $variantFrameId && '' !== $path ) {
                $pathByFrameId[$variantFrameId] = $path;
            }
        }
        if ( '' !== $slug && '' !== $path ) {
            $pathBySlug[$slug] = $path;
        }

        $pages[] = array(
            'index' => $index,
            'frame_id' => $frameId,
            'name' => (string) ($page['name'] ?? ''),
            'slug' => $slug,
            'path' => $path,
            'entrypoint' => true === ($page['entrypoint'] ?? false),
            'page_type' => (string) ($page['page_type'] ?? ''),
            'figma_page_name' => $page['figma_page_name'] ?? null,
            'section_name' => $page['section_name'] ?? null,
            'responsive' => true === ($page['responsive'] ?? false),
            'variant_frame_ids' => $variantFrameIds,
            'source_identity' => array(
                'selected_frame_id' => $identity['selected_frame_id'] ?? null,
                'primary_frame_id' => $identity['primary_frame_id'] ?? null,
                'selected_is_primary' => $identity['selected_is_primary'] ?? null,
                'device_hint' => $identity['device_hint'] ?? null,
            ),
        );
    }

    $diagnostics = blocks_engine_figma_route_identity_unique_diagnostics(array_merge(
        is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : array(),
        is_array($pagePlan['diagnostics'] ?? null) ? $pagePlan['diagnostics'] : array()
    ));
    $duplicateRouteDrafts = array();
    $duplicateDrafts = array();
    foreach ( $diagnostics as $diagnostic ) {
        if ( ! is_array($diagnostic) ) {
            continue;
        }
        $code = (string) ($diagnostic['code'] ?? '');
        if ( 'duplicate_route_draft_frames' === $code ) {
            $duplicateRouteDrafts[] = array(
                'route_identity' => $diagnostic['route_identity'] ?? null,
                'canonical_frame_id' => $diagnostic['canonical_frame_id'] ?? null,
                'canonical_path' => is_string($diagnostic['canonical_frame_id'] ?? null) ? ($pathByFrameId[$diagnostic['canonical_frame_id']] ?? null) : null,
                'draft_frame_ids' => array_values(is_array($diagnostic['draft_frame_ids'] ?? null) ? $diagnostic['draft_frame_ids'] : array()),
            );
        }
        if ( 'duplicate_draft_frames' === $code ) {
            $duplicateDrafts[] = array(
                'canonical_frame_id' => $diagnostic['canonical_frame_id'] ?? null,
                'canonical_path' => is_string($diagnostic['canonical_frame_id'] ?? null) ? ($pathByFrameId[$diagnostic['canonical_frame_id']] ?? null) : null,
                'draft_frame_ids' => array_values(is_array($diagnostic['draft_frame_ids'] ?? null) ? $diagnostic['draft_frame_ids'] : array()),
                'device_hint' => $diagnostic['device_hint'] ?? null,
                'width' => $diagnostic['width'] ?? null,
            );
        }
    }

    $linksByPage = array();
    $routeTargetCounts = array();
    $unresolvedTargets = array();
    $implicitRouteUnresolvedTargets = array();
    $implicitRouteLinkCount = 0;
    $nodeLinkCount = 0;
    $urlLinkCount = 0;
    $anchorsEmitted = 0;
    foreach ( $renderedPages as $page ) {
        if ( ! is_array($page) ) {
            continue;
        }
        $links = is_array($page['transform_diagnostics']['links'] ?? null) ? $page['transform_diagnostics']['links'] : array();
        $pagePath = (string) ($page['path'] ?? ($page['output_path'] ?? ''));
        $routeTargets = is_array($links['route_targets'] ?? null) ? $links['route_targets'] : array();
        foreach ( $routeTargets as $routeTarget ) {
            if ( ! is_array($routeTarget) ) {
                continue;
            }
            $path = (string) ($routeTarget['path'] ?? '');
            if ( '' !== $path ) {
                $routeTargetCounts[$path] = ($routeTargetCounts[$path] ?? 0) + 1;
            }
        }

        $pageUnresolved = is_array($links['unresolved_targets'] ?? null) ? $links['unresolved_targets'] : array();
        foreach ( $pageUnresolved as $target ) {
            if ( is_array($target) ) {
                $target['page_path'] = $pagePath;
                $unresolvedTargets[] = $target;
            }
        }
        $pageImplicitUnresolved = is_array($links['implicit_route_unresolved_targets'] ?? null) ? $links['implicit_route_unresolved_targets'] : array();
        foreach ( $pageImplicitUnresolved as $target ) {
            if ( is_array($target) ) {
                $target['page_path'] = $pagePath;
                $implicitRouteUnresolvedTargets[] = $target;
            }
        }

        $implicitRouteLinkCount += (int) ($links['implicit_route_links'] ?? 0);
        $nodeLinkCount += (int) ($links['node_links'] ?? 0);
        $urlLinkCount += (int) ($links['url_links'] ?? 0);
        $anchorsEmitted += (int) ($links['anchors_emitted'] ?? 0);
        $linksByPage[] = array(
            'path' => $pagePath,
            'sources_found' => (int) ($links['sources_found'] ?? 0),
            'anchors_emitted' => (int) ($links['anchors_emitted'] ?? 0),
            'node_links' => (int) ($links['node_links'] ?? 0),
            'url_links' => (int) ($links['url_links'] ?? 0),
            'implicit_route_links' => (int) ($links['implicit_route_links'] ?? 0),
            'implicit_route_self_suppressed' => (int) ($links['implicit_route_self_suppressed'] ?? 0),
            'implicit_route_unresolved' => (int) ($links['implicit_route_unresolved'] ?? 0),
            'unresolved' => (int) ($links['unresolved'] ?? 0),
            'route_targets' => $routeTargets,
        );
    }
    ksort($routeTargetCounts);

    return array(
        'schema' => 'blocks-engine/figma-transformer/route-identity-report/v1',
        'input_file' => basename($inputPath),
        'input_sha256' => hash_file('sha256', $inputPath) ?: null,
        'status' => $result['status'] ?? null,
        'summary' => array(
            'page_count' => count($pages),
            'candidate_count' => (int) ($pagePlan['candidate_count'] ?? 0),
            'selection_source' => $pagePlan['selection_source'] ?? null,
            'duplicate_draft_frame_count' => array_sum(array_map(static fn (array $entry): int => count($entry['draft_frame_ids']), $duplicateDrafts)),
            'duplicate_route_draft_frame_count' => array_sum(array_map(static fn (array $entry): int => count($entry['draft_frame_ids']), $duplicateRouteDrafts)),
            'anchors_emitted' => $anchorsEmitted,
            'node_link_count' => $nodeLinkCount,
            'url_link_count' => $urlLinkCount,
            'implicit_route_link_count' => $implicitRouteLinkCount,
            'implicit_route_unresolved_count' => count($implicitRouteUnresolvedTargets),
            'unresolved_link_count' => count($unresolvedTargets),
            'unique_route_target_count' => count($routeTargetCounts),
        ),
        'pages' => $pages,
        'path_by_frame_id' => $pathByFrameId,
        'path_by_slug' => $pathBySlug,
        'duplicate_draft_frames' => $duplicateDrafts,
        'duplicate_route_draft_frames' => $duplicateRouteDrafts,
        'route_target_counts' => $routeTargetCounts,
        'links_by_page' => $linksByPage,
        'unresolved_targets' => $unresolvedTargets,
        'implicit_route_unresolved_targets' => $implicitRouteUnresolvedTargets,
    );
}

/**
 * @param array<int, mixed> $diagnostics
 * @return array<int, array<string, mixed>>
 */
function blocks_engine_figma_route_identity_unique_diagnostics(array $diagnostics): array
{
    $unique = array();
    $seen = array();
    foreach ( $diagnostics as $diagnostic ) {
        if ( ! is_array($diagnostic) ) {
            continue;
        }
        $key = json_encode(array(
            $diagnostic['code'] ?? null,
            $diagnostic['canonical_frame_id'] ?? null,
            $diagnostic['route_identity'] ?? null,
            $diagnostic['draft_frame_ids'] ?? null,
            $diagnostic['frame_ids'] ?? null,
        ));
        if ( ! is_string($key) || isset($seen[$key]) ) {
            continue;
        }
        $seen[$key] = true;
        $unique[] = $diagnostic;
    }
    return $unique;
}
