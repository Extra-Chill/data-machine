<?php

declare(strict_types=1);

/**
 * @param array<int, array<string, mixed>> $fixtures
 * @return array<string, mixed>
 */
function matrix_quality_matrix(array $fixtures): array
{
    $keys = array(
        'missing_asset_nodes',
        'vector_placeholders',
        'image_block_count',
        'vector_image_fallbacks',
        'media_query_count',
        'fixed_width_over_desktop_count',
        'fixed_width_declaration_count',
        'fixed_width_without_responsive_override_count',
        'giant_fixed_section_count',
        'large_overflow_risk_count',
        'fallback_prone_form_island_count',
        'fallback_prone_svg_island_count',
        'fallback_prone_input_island_count',
        'invalid_list_child_count',
        'missing_semantic_role_count',
        'missing_emitted_text_nodes',
        'layout_mismatch_count',
        'render_style_mismatch_count',
        'link_targets_unresolved',
        'invalid_css_numeric_tokens',
        'breakpoint_override_leak_count',
        'large_absolute_offset_count',
        'large_css_offset_count',
        'large_negative_left_count',
        'off_canvas_visual_node_count',
        'clipped_visual_node_count',
        'fixed_width_over_desktop_uncovered_count',
        'uncomposed_vector_child_nodes',
        'dom_horizontal_overflow_count',
        'dom_viewport_width_leak_count',
        'dom_huge_vertical_spacing_count',
        'dom_collapsed_box_count',
        'dom_offscreen_box_count',
        'dom_missing_node_id_box_count',
        'dom_capture_valid_count',
        'dom_capture_invalid_count',
        'dom_css_loaded_count',
        'dom_css_not_loaded_count',
    );
    $totals = array_fill_keys($keys, 0);
    $qualityStatuses = array();
    $signalCounts = array();
    $riskBucketCounts = array_fill_keys(array('low', 'medium', 'high', 'critical', 'unknown'), 0);
    $riskCategoryTotals = array_fill_keys(array('responsive_coverage', 'absolute_scaffolding', 'text_wrapping_leaks', 'image_geometry_fidelity', 'form_validity', 'route_coverage', 'unsupported_vectors', 'rendered_dom_boxes', 'responsive_rendered_dom_boxes'), 0);
    $totalsByComparisonRole = array();
    $perFixtureReadiness = array();
    $coverageNumerator = 0;
    $coverageDenominator = 0;
    $routeCoverageNumerator = 0;
    $routeCoverageDenominator = 0;

    foreach ( $fixtures as $fixture ) {
        if ( ! is_array($fixture) ) {
            continue;
        }
        $summary = is_array($fixture['quality_summary'] ?? null) ? $fixture['quality_summary'] : array();
        $domSummary = matrix_fixture_dom_box_quality_summary($fixture);
        foreach ( $keys as $key ) {
            $totals[$key] += matrix_quality_summary_int($summary, $key) + matrix_quality_summary_int($domSummary, $key);
        }
        foreach ( matrix_fixture_dom_box_quality_summaries_by_comparison_role($fixture) as $role => $roleSummary ) {
            if ( ! isset($totalsByComparisonRole[$role]) ) {
                $totalsByComparisonRole[$role] = array_fill_keys($keys, 0);
            }
            foreach ( $keys as $key ) {
                $totalsByComparisonRole[$role][$key] += matrix_quality_summary_int($roleSummary, $key);
            }
        }
        $coverageNumerator += (int) ($summary['fixed_width_with_responsive_override_count'] ?? 0);
        $coverageDenominator += (int) ($summary['fixed_width_declaration_count'] ?? 0);
        $selectedRouteCount = max(
            count(is_array($fixture['selected_frame_ids'] ?? null) ? $fixture['selected_frame_ids'] : array()),
            matrix_fixture_emitted_route_count($fixture)
        );
        $omittedRouteCount = count(is_array($fixture['omitted_page_candidates'] ?? null) ? $fixture['omitted_page_candidates'] : array());
        $routeCoverageNumerator += $selectedRouteCount;
        $routeCoverageDenominator += $selectedRouteCount + $omittedRouteCount;
        $status = isset($fixture['quality_status']) && is_scalar($fixture['quality_status']) ? (string) $fixture['quality_status'] : 'unknown';
        $qualityStatuses[$status] = ($qualityStatuses[$status] ?? 0) + 1;
        foreach ( is_array($fixture['artifact_quality']['signals'] ?? null) ? $fixture['artifact_quality']['signals'] : array() as $signal ) {
            if ( ! is_array($signal) || ! isset($signal['code']) || ! is_scalar($signal['code']) ) {
                continue;
            }
            $code = (string) $signal['code'];
            $signalCounts[$code] = ($signalCounts[$code] ?? 0) + 1;
        }

        $readiness = is_array($fixture['visual_readiness'] ?? null) ? $fixture['visual_readiness'] : matrix_fixture_visual_readiness($fixture);
        $bucket = isset($readiness['visual_risk_bucket']) && is_scalar($readiness['visual_risk_bucket']) ? (string) $readiness['visual_risk_bucket'] : 'unknown';
        $riskBucketCounts[$bucket] = ($riskBucketCounts[$bucket] ?? 0) + 1;
        foreach ( $riskCategoryTotals as $category => $_count ) {
            $riskCategoryTotals[$category] += (int) ($readiness['risk_categories'][$category]['count'] ?? 0);
        }
        $perFixtureReadiness[] = array(
            'id' => isset($fixture['id']) && is_scalar($fixture['id']) ? (string) $fixture['id'] : '',
            'status' => $fixture['status'] ?? null,
            'readiness_score' => $readiness['readiness_score'] ?? null,
            'visual_risk_bucket' => $readiness['visual_risk_bucket'] ?? 'unknown',
            'route_coverage_ratio' => $readiness['route_coverage_ratio'] ?? null,
            'dom_css_loaded' => $domSummary['dom_css_loaded'] ?? null,
            'dom_capture_valid' => $domSummary['dom_capture_valid'] ?? null,
            'risk_categories' => $readiness['risk_categories'] ?? array(),
        );
    }

    ksort($qualityStatuses);
    ksort($signalCounts);
    ksort($riskBucketCounts);

    return array(
        'schema' => 'blocks-engine/figma-transformer/fixture-matrix-quality/v1',
        'fixture_count' => count($fixtures),
        'quality_status_counts' => $qualityStatuses,
        'signal_counts' => $signalCounts,
        'visual_risk_bucket_counts' => $riskBucketCounts,
        'risk_category_totals' => $riskCategoryTotals,
        'per_fixture_readiness' => $perFixtureReadiness,
        'effective_responsive_coverage_ratio' => $coverageDenominator > 0 ? round($coverageNumerator / $coverageDenominator, 3) : 1.0,
        'route_coverage_ratio' => $routeCoverageDenominator > 0 ? round($routeCoverageNumerator / $routeCoverageDenominator, 3) : 1.0,
        'totals' => $totals,
        'totals_by_comparison_role' => $totalsByComparisonRole,
    );
}

/**
 * @param array<string, mixed> $fixture
 * @return array<string, mixed>
 */
function matrix_fixture_visual_readiness(array $fixture): array
{
    $summary = is_array($fixture['quality_summary'] ?? null) ? $fixture['quality_summary'] : array();
    $selectedRouteCount = max(
        count(is_array($fixture['selected_frame_ids'] ?? null) ? $fixture['selected_frame_ids'] : array()),
        matrix_fixture_emitted_route_count($fixture)
    );
    $omittedRouteCount = count(is_array($fixture['omitted_page_candidates'] ?? null) ? $fixture['omitted_page_candidates'] : array());
    $transformRiskOmissionCount = count(array_filter(
        is_array($fixture['omitted_page_candidates'] ?? null) ? $fixture['omitted_page_candidates'] : array(),
        static function (mixed $omission): bool {
            if ( ! is_array($omission) ) {
                return true;
            }

            $reason = isset($omission['reason']) && is_scalar($omission['reason']) ? (string) $omission['reason'] : '';
            return 'outside_page_cap' !== $reason && ! str_starts_with($reason, 'covered_by_selected_');
        }
    ));
    $routeCoverageRatio = ($selectedRouteCount + $omittedRouteCount) > 0 ? round($selectedRouteCount / ($selectedRouteCount + $omittedRouteCount), 3) : 1.0;

    $categories = array(
        'responsive_coverage' => matrix_risk_category(
            matrix_quality_summary_int($summary, 'fixed_width_without_responsive_override_count')
                + matrix_quality_summary_int($summary, 'fixed_width_over_desktop_uncovered_count')
                + (true === ($summary['desktop_canvas_without_responsive_breakpoints'] ?? null) ? 1 : 0),
            array('fixed_width_without_responsive_override_count', 'fixed_width_over_desktop_uncovered_count', 'desktop_canvas_without_responsive_breakpoints')
        ),
        'absolute_scaffolding' => matrix_risk_category(
            matrix_quality_summary_int($summary, 'large_absolute_offset_count')
                + matrix_quality_summary_int($summary, 'large_css_offset_count')
                + matrix_quality_summary_int($summary, 'large_negative_left_count')
                + matrix_quality_summary_int($summary, 'off_canvas_visual_node_count')
                + matrix_quality_summary_int($summary, 'clipped_visual_node_count')
                + matrix_quality_summary_int($summary, 'giant_fixed_section_count')
                + matrix_quality_summary_int($summary, 'large_overflow_risk_count'),
            array('large_absolute_offset_count', 'large_css_offset_count', 'large_negative_left_count', 'off_canvas_visual_node_count', 'clipped_visual_node_count', 'giant_fixed_section_count', 'large_overflow_risk_count')
        ),
        'text_wrapping_leaks' => matrix_risk_category(
            matrix_quality_summary_int($summary, 'missing_emitted_text_nodes')
                + matrix_quality_summary_int($summary, 'breakpoint_override_leak_count')
                + matrix_quality_summary_int($summary, 'layout_mismatch_count')
                + matrix_quality_summary_int($summary, 'render_style_mismatch_count'),
            array('missing_emitted_text_nodes', 'breakpoint_override_leak_count', 'layout_mismatch_count', 'render_style_mismatch_count')
        ),
        'image_geometry_fidelity' => matrix_risk_category(
            matrix_quality_summary_int($summary, 'missing_asset_nodes')
                + matrix_quality_summary_int($summary, 'vector_image_fallbacks')
                + matrix_quality_summary_int($summary, 'image_heavy_landmark_candidates'),
            array('missing_asset_nodes', 'vector_image_fallbacks', 'image_heavy_landmark_candidates')
        ),
        'form_validity' => matrix_risk_category(
            matrix_quality_summary_int($summary, 'fallback_prone_form_island_count')
                + matrix_quality_summary_int($summary, 'fallback_prone_input_island_count')
                + matrix_quality_summary_int($summary, 'invalid_list_child_count')
                + matrix_quality_summary_int($summary, 'missing_semantic_role_count'),
            array('fallback_prone_form_island_count', 'fallback_prone_input_island_count', 'invalid_list_child_count', 'missing_semantic_role_count')
        ),
        'route_coverage' => matrix_risk_category(
            matrix_quality_summary_int($summary, 'link_targets_unresolved') + $transformRiskOmissionCount,
            array('link_targets_unresolved', 'omitted_page_candidates')
        ),
        'unsupported_vectors' => matrix_risk_category(
            matrix_quality_summary_int($summary, 'vector_placeholders')
                + matrix_quality_summary_int($summary, 'uncomposed_vector_child_nodes')
                + ((float) ($summary['vector_decode_coverage_ratio'] ?? 1.0) < 1.0 ? 1 : 0),
            array('vector_placeholders', 'uncomposed_vector_child_nodes', 'vector_decode_coverage_ratio')
        ),
        'rendered_dom_boxes' => matrix_dom_box_risk_category(matrix_fixture_dom_box_quality_summary_for_role($fixture, 'source_layout')),
        'responsive_rendered_dom_boxes' => matrix_dom_box_risk_category(matrix_fixture_dom_box_quality_summary_for_role($fixture, 'responsive_evidence')),
    );

    $riskPoints = 0;
    foreach ( $categories as $category ) {
        $riskPoints += min(25, (int) ($category['count'] ?? 0));
    }
    $readinessScore = max(0, 100 - $riskPoints);

    return array(
        'schema' => 'blocks-engine/figma-transformer/fixture-visual-readiness/v1',
        'readiness_score' => $readinessScore,
        'visual_risk_bucket' => matrix_visual_risk_bucket($readinessScore),
        'route_coverage_ratio' => $routeCoverageRatio,
        'risk_categories' => $categories,
    );
}

/**
 * @param array<string, mixed> $fixture
 */
function matrix_fixture_emitted_route_count(array $fixture): int
{
    $paths = array();

    foreach ( is_array($fixture['dom_box_capture']['entrypoints'] ?? null) ? $fixture['dom_box_capture']['entrypoints'] : array() as $entrypoint ) {
        if ( is_scalar($entrypoint) && '' !== (string) $entrypoint ) {
            $paths[(string) $entrypoint] = true;
        }
    }

    $selection = is_array($fixture['transform_selection'] ?? null) ? $fixture['transform_selection'] : array();
    foreach ( is_array($selection['selected_frames'] ?? null) ? $selection['selected_frames'] : array() as $frame ) {
        if ( ! is_array($frame) ) {
            continue;
        }
        if ( isset($frame['path']) && is_scalar($frame['path']) && '' !== (string) $frame['path'] ) {
            $paths[(string) $frame['path']] = true;
        }
        foreach ( is_array($frame['template_aliases'] ?? null) ? $frame['template_aliases'] : array() as $alias ) {
            if ( is_scalar($alias) && '' !== (string) $alias ) {
                $paths[(string) $alias] = true;
            }
        }
    }

    return count($paths);
}

/**
 * @param array<string, mixed> $fixture
 * @return array<string, mixed>
 */
function matrix_fixture_dom_box_quality_summary(array $fixture): array
{
    if ( is_array($fixture['dom_box_quality']['summary'] ?? null) ) {
        return $fixture['dom_box_quality']['summary'];
    }

    return array();
}

/**
 * @param array<string, mixed> $fixture
 * @return array<string, array<string, mixed>>
 */
function matrix_fixture_dom_box_quality_summaries_by_comparison_role(array $fixture): array
{
    $summaries = $fixture['dom_box_quality']['summary_by_comparison_role'] ?? array();
    if ( ! is_array($summaries) ) {
        return array();
    }

    return array_filter($summaries, static fn (mixed $summary): bool => is_array($summary));
}

/**
 * @param array<string, mixed> $fixture
 * @return array<string, mixed>
 */
function matrix_fixture_dom_box_quality_summary_for_role(array $fixture, string $role): array
{
    $summaries = matrix_fixture_dom_box_quality_summaries_by_comparison_role($fixture);
    if ( 'source_layout' === $role && ! empty($summaries) ) {
        return matrix_merge_dom_box_quality_summaries(array_values(array_filter(array(
            $summaries['source_layout'] ?? null,
            $summaries['unclassified'] ?? null,
        ), static fn (mixed $summary): bool => is_array($summary))));
    }

    if ( ! empty($summaries) ) {
        return is_array($summaries[$role] ?? null) ? $summaries[$role] : array();
    }

    // Legacy reports without per-role summaries represent source-layout evidence.
    return 'source_layout' === $role ? matrix_fixture_dom_box_quality_summary($fixture) : array();
}

/**
 * @param array<int, array<string, mixed>> $summaries
 * @return array<string, mixed>
 */
function matrix_merge_dom_box_quality_summaries(array $summaries): array
{
    if ( 1 === count($summaries) ) {
        return $summaries[0];
    }

    $merged = matrix_dom_box_empty_summary();
    foreach ( $summaries as $summary ) {
        foreach ( $merged as $key => $value ) {
            if ( is_int($value) ) {
                $merged[$key] += matrix_quality_summary_int($summary, $key);
            }
        }
    }
    $merged = array_merge($merged, matrix_dom_box_validity_summary(
        (int) $merged['page_count'],
        (int) $merged['dom_capture_invalid_count'],
        (int) $merged['dom_css_not_loaded_count']
    ));
    $merged['risk_bucket'] = matrix_dom_box_risk_bucket((int) $merged['risk_score']);

    return $merged;
}

/**
 * @return array<string, mixed>|null
 */
function matrix_dom_box_quality_report(string $path): ?array
{
    if ( ! is_readable($path) ) {
        return null;
    }

    $report = json_decode((string) file_get_contents($path), true);
    if ( ! is_array($report) ) {
        return null;
    }

    return matrix_analyze_dom_box_report($report, $path);
}

/**
 * @param array<string, mixed> $report
 * @return array<string, mixed>
 */
function matrix_analyze_dom_box_report(array $report, string $sourcePath = ''): array
{
    $pageReports = array();
    $summary = matrix_dom_box_empty_summary();
    $summaryByComparisonRole = array();

    foreach ( is_array($report['entrypoints'] ?? null) ? $report['entrypoints'] : array() as $entrypoint ) {
        if ( ! is_array($entrypoint) ) {
            continue;
        }

        $pageReport = matrix_analyze_dom_box_entrypoint($entrypoint);
        $pageReports[] = $pageReport;
        foreach ( $summary as $key => $value ) {
            if ( is_int($value) && isset($pageReport['summary'][$key]) && is_numeric($pageReport['summary'][$key]) ) {
                $summary[$key] += (int) $pageReport['summary'][$key];
            }
        }
        $role = isset($pageReport['comparison_role']) && is_scalar($pageReport['comparison_role']) && '' !== (string) $pageReport['comparison_role']
            ? (string) $pageReport['comparison_role']
            : 'unclassified';
        if ( ! isset($summaryByComparisonRole[$role]) ) {
            $summaryByComparisonRole[$role] = matrix_dom_box_empty_summary();
        }
        $summaryByComparisonRole[$role]['page_count']++;
        foreach ( $summaryByComparisonRole[$role] as $key => $value ) {
            if ( is_int($value) && isset($pageReport['summary'][$key]) && is_numeric($pageReport['summary'][$key]) ) {
                $summaryByComparisonRole[$role][$key] += (int) $pageReport['summary'][$key];
            }
        }
    }

    $summary['page_count'] = count($pageReports);
    $summary = array_merge($summary, matrix_dom_box_validity_summary(
        (int) $summary['page_count'],
        (int) $summary['dom_capture_invalid_count'],
        (int) $summary['dom_css_not_loaded_count']
    ));
    $summary['risk_bucket'] = matrix_dom_box_risk_bucket((int) $summary['risk_score']);
    foreach ( $summaryByComparisonRole as &$roleSummary ) {
        $roleSummary['page_count'] = (int) $roleSummary['page_count'];
        $roleSummary = array_merge($roleSummary, matrix_dom_box_validity_summary(
            (int) $roleSummary['page_count'],
            (int) $roleSummary['dom_capture_invalid_count'],
            (int) $roleSummary['dom_css_not_loaded_count']
        ));
        $roleSummary['risk_bucket'] = matrix_dom_box_risk_bucket((int) $roleSummary['risk_score']);
    }
    unset($roleSummary);

    return array_filter(array(
        'schema' => 'blocks-engine/figma-transformer/dom-box-quality/v1',
        'source_path' => $sourcePath ?: null,
        'summary' => $summary,
        'summary_by_comparison_role' => $summaryByComparisonRole,
        'pages' => $pageReports,
    ), static fn (mixed $value): bool => null !== $value);
}

/**
 * @param array<string, mixed> $entrypoint
 * @return array<string, mixed>
 */
function matrix_analyze_dom_box_entrypoint(array $entrypoint): array
{
    $viewport = is_array($entrypoint['viewport'] ?? null) ? $entrypoint['viewport'] : array();
    $viewportWidth = isset($viewport['width']) && is_numeric($viewport['width']) ? (float) $viewport['width'] : 1440.0;
    $viewportHeight = isset($viewport['height']) && is_numeric($viewport['height']) ? (float) $viewport['height'] : 900.0;
    $elements = is_array($entrypoint['elements'] ?? null) ? array_values($entrypoint['elements']) : array();
    $unidentified = is_array($entrypoint['unidentified_elements'] ?? null) ? array_values($entrypoint['unidentified_elements']) : array();
    $summary = matrix_dom_box_empty_summary();
    $findings = array();
    $previousBottom = null;
    $domCssLoaded = true === ($entrypoint['dom_css_loaded'] ?? null);
    $domCaptureValid = true === ($entrypoint['dom_capture_valid'] ?? null) && $domCssLoaded;
    $comparisonRole = isset($entrypoint['comparison_role']) && is_scalar($entrypoint['comparison_role']) && '' !== (string) $entrypoint['comparison_role']
        ? (string) $entrypoint['comparison_role']
        : 'unclassified';
    $sourceFrame = is_array($entrypoint['source_frame'] ?? null) ? $entrypoint['source_frame'] : array();

    if ( $domCaptureValid ) {
        $summary['dom_capture_valid_count'] = 1;
    } else {
        $summary['dom_capture_invalid_count'] = 1;
    }
    if ( $domCssLoaded ) {
        $summary['dom_css_loaded_count'] = 1;
    } else {
        $summary['dom_css_not_loaded_count'] = 1;
    }
    $summary = array_merge($summary, matrix_dom_box_validity_summary(
        1,
        (int) $summary['dom_capture_invalid_count'],
        (int) $summary['dom_css_not_loaded_count']
    ));

    if ( ! $domCaptureValid ) {
        $summary['risk_bucket'] = 'low';
        return array_filter(array(
            'page_path' => isset($entrypoint['page_path']) && is_scalar($entrypoint['page_path']) ? (string) $entrypoint['page_path'] : '',
            'viewport' => $viewport,
            'comparison_role' => $comparisonRole,
            'source_frame' => $sourceFrame,
            'summary' => $summary,
            'stylesheet_status' => is_array($entrypoint['stylesheet_status'] ?? null) ? $entrypoint['stylesheet_status'] : null,
            'findings' => array(matrix_dom_box_finding('dom_capture_invalid', array(), array('dom_css_loaded' => $domCssLoaded))),
        ), static fn (mixed $value): bool => null !== $value);
    }

    usort($elements, static function (mixed $a, mixed $b): int {
        $aRect = is_array($a) && is_array($a['boundingClientRect'] ?? null) ? $a['boundingClientRect'] : array();
        $bRect = is_array($b) && is_array($b['boundingClientRect'] ?? null) ? $b['boundingClientRect'] : array();
        return ((float) ($aRect['top'] ?? 0)) <=> ((float) ($bRect['top'] ?? 0));
    });

    foreach ( $elements as $element ) {
        if ( ! is_array($element) ) {
            continue;
        }

        $summary['dom_element_count']++;
        $rect = is_array($element['boundingClientRect'] ?? null) ? $element['boundingClientRect'] : array();
        $left = matrix_dom_box_number($rect, 'left', matrix_dom_box_number($rect, 'x', 0.0));
        $right = matrix_dom_box_number($rect, 'right', $left + matrix_dom_box_number($rect, 'width', 0.0));
        $top = matrix_dom_box_number($rect, 'top', matrix_dom_box_number($rect, 'y', 0.0));
        $bottom = matrix_dom_box_number($rect, 'bottom', $top + matrix_dom_box_number($rect, 'height', 0.0));
        $width = matrix_dom_box_number($rect, 'width', max(0.0, $right - $left));
        $height = matrix_dom_box_number($rect, 'height', max(0.0, $bottom - $top));
        $textMetrics = is_array($element['text_metrics'] ?? null) ? $element['text_metrics'] : array();
        $scrollWidth = matrix_dom_box_number($textMetrics, 'scroll_width', 0.0);
        $clientWidth = matrix_dom_box_number($textMetrics, 'client_width', 0.0);
        $nodeId = isset($element['node_id']) && is_scalar($element['node_id']) ? trim((string) $element['node_id']) : '';
        $node = matrix_dom_box_node_summary($element);

        if ( $right > $viewportWidth + 1.0 || $left < -1.0 || ($scrollWidth > 0.0 && $clientWidth > 0.0 && $scrollWidth > $clientWidth + 1.0) ) {
            $summary['dom_horizontal_overflow_count']++;
            $findings[] = matrix_dom_box_finding('dom_horizontal_overflow', $node, array('left' => $left, 'right' => $right, 'viewport_width' => $viewportWidth, 'scroll_width' => $scrollWidth, 'client_width' => $clientWidth));
        }

        if ( $width > $viewportWidth + 1.0 || $right > $viewportWidth + 24.0 ) {
            $summary['dom_viewport_width_leak_count']++;
            $findings[] = matrix_dom_box_finding('dom_viewport_width_leak', $node, array('width' => $width, 'right' => $right, 'viewport_width' => $viewportWidth));
        }

        if ( matrix_dom_box_is_unexpected_collapse($element, $width, $height) ) {
            $summary['dom_collapsed_box_count']++;
            $findings[] = matrix_dom_box_finding('dom_collapsed_box', $node, array('width' => $width, 'height' => $height));
        }

        if ( $left < -1.0 || $right < -1.0 || $top < -1.0 || $left > $viewportWidth + 1.0 ) {
            $summary['dom_offscreen_box_count']++;
            $findings[] = matrix_dom_box_finding('dom_offscreen_box', $node, array('left' => $left, 'right' => $right, 'top' => $top, 'viewport_width' => $viewportWidth));
        }

        if ( '' === $nodeId ) {
            $summary['dom_missing_node_id_box_count']++;
            $findings[] = matrix_dom_box_finding('dom_missing_node_id_box', $node, array());
        }

        if ( null !== $previousBottom && $top - $previousBottom > $viewportHeight ) {
            $summary['dom_huge_vertical_spacing_count']++;
            $findings[] = matrix_dom_box_finding('dom_huge_vertical_spacing', $node, array('gap' => round($top - $previousBottom, 3), 'viewport_height' => $viewportHeight));
        }
        $previousBottom = null === $previousBottom ? $bottom : max($previousBottom, $bottom);
    }

    foreach ( $unidentified as $element ) {
        if ( ! is_array($element) ) {
            continue;
        }
        $summary['dom_missing_node_id_box_count']++;
        $findings[] = matrix_dom_box_finding('dom_missing_node_id_box', matrix_dom_box_node_summary($element), array('source' => 'unidentified_elements'));
    }

    $summary['risk_score'] = $summary['dom_horizontal_overflow_count']
        + $summary['dom_viewport_width_leak_count']
        + $summary['dom_huge_vertical_spacing_count']
        + $summary['dom_collapsed_box_count']
        + $summary['dom_offscreen_box_count']
        + $summary['dom_missing_node_id_box_count'];
    $summary['risk_bucket'] = matrix_dom_box_risk_bucket((int) $summary['risk_score']);

    return array(
        'page_path' => isset($entrypoint['page_path']) && is_scalar($entrypoint['page_path']) ? (string) $entrypoint['page_path'] : '',
        'viewport' => $viewport,
        'comparison_role' => $comparisonRole,
        'source_frame' => $sourceFrame,
        'summary' => $summary,
        'stylesheet_status' => is_array($entrypoint['stylesheet_status'] ?? null) ? $entrypoint['stylesheet_status'] : null,
        'findings' => array_slice($findings, 0, 50),
    );
}

/**
 * @return array<string, int|string>
 */
function matrix_dom_box_empty_summary(): array
{
    return array(
        'page_count' => 0,
        'dom_element_count' => 0,
        'dom_horizontal_overflow_count' => 0,
        'dom_viewport_width_leak_count' => 0,
        'dom_huge_vertical_spacing_count' => 0,
        'dom_collapsed_box_count' => 0,
        'dom_offscreen_box_count' => 0,
        'dom_missing_node_id_box_count' => 0,
        'dom_capture_valid_count' => 0,
        'dom_capture_invalid_count' => 0,
        'dom_css_loaded_count' => 0,
        'dom_css_not_loaded_count' => 0,
        'risk_score' => 0,
        'risk_bucket' => 'low',
    );
}

/**
 * @return array{dom_capture_valid: bool, dom_css_loaded: bool}
 */
function matrix_dom_box_validity_summary(int $pageCount, int $captureInvalidCount, int $cssNotLoadedCount): array
{
    return array(
        'dom_capture_valid' => $pageCount > 0 && 0 === $captureInvalidCount,
        'dom_css_loaded' => $pageCount > 0 && 0 === $cssNotLoadedCount,
    );
}

/**
 * @param array<string, mixed> $values
 */
function matrix_dom_box_number(array $values, string $key, float $fallback): float
{
    return isset($values[$key]) && is_numeric($values[$key]) ? (float) $values[$key] : $fallback;
}

/**
 * @param array<string, mixed> $element
 */
function matrix_dom_box_is_unexpected_collapse(array $element, float $width, float $height): bool
{
    $collapsedWidth = $width <= 1.0;
    $collapsedHeight = $height <= 1.0;
    if ( ! $collapsedWidth && ! $collapsedHeight ) {
        return false;
    }

    $source = is_array($element['source'] ?? null) ? $element['source'] : array();
    $sourceType = isset($source['node_type']) && is_scalar($source['node_type']) ? strtoupper(trim((string) $source['node_type'])) : '';
    $isVisibleGeometry = in_array($sourceType, array('LINE', 'VECTOR'), true)
        && true === ($element['visibility']['visible'] ?? null);
    if ( ! $isVisibleGeometry || $collapsedWidth === $collapsedHeight ) {
        return true;
    }

    $orthogonalDimension = $collapsedWidth ? $height : $width;
    if ( $orthogonalDimension <= 1.0 ) {
        return true;
    }

    $sourceDimensions = is_array($source['visual_dimensions'] ?? null) ? $source['visual_dimensions'] : array();
    $collapsedAxis = $collapsedWidth ? 'width' : 'height';
    $sourceAxis = isset($sourceDimensions[$collapsedAxis]) && is_numeric($sourceDimensions[$collapsedAxis])
        ? (float) $sourceDimensions[$collapsedAxis]
        : null;
    $domAxis = $collapsedWidth ? $width : $height;
    if ( null === $sourceAxis || ! is_finite($sourceAxis) || $sourceAxis < 0.0 ) {
        return true;
    }

    if ( abs($sourceAxis - $domAxis) <= 0.5 ) {
        return false;
    }

    // A zero-sized source axis may round up to one visible DOM pixel.
    return ! (0.0 === $sourceAxis && $domAxis > 0.0 && $domAxis <= 1.0);
}

/**
 * @param array<string, mixed> $element
 * @return array<string, mixed>
 */
function matrix_dom_box_node_summary(array $element): array
{
    $source = is_array($element['source'] ?? null) ? $element['source'] : array();
    return array_filter(array(
        'id' => isset($element['node_id']) && is_scalar($element['node_id']) ? (string) $element['node_id'] : null,
        'name' => isset($element['node_name']) && is_scalar($element['node_name']) ? (string) $element['node_name'] : null,
        'selector' => isset($element['selector']) && is_scalar($element['selector']) ? (string) $element['selector'] : null,
        'tag' => isset($element['tag']) && is_scalar($element['tag']) ? (string) $element['tag'] : null,
        'source_node_type' => isset($source['node_type']) && is_scalar($source['node_type']) ? (string) $source['node_type'] : null,
        'source_visual_dimensions' => is_array($source['visual_dimensions'] ?? null) ? $source['visual_dimensions'] : null,
    ), static fn (mixed $value): bool => null !== $value && '' !== $value);
}

/**
 * @param array<string, mixed> $node
 * @param array<string, mixed> $metrics
 * @return array<string, mixed>
 */
function matrix_dom_box_finding(string $code, array $node, array $metrics): array
{
    return array_filter(array(
        'code' => $code,
        'node' => $node,
        'metrics' => $metrics,
    ), static fn (mixed $value): bool => array() !== $value);
}

function matrix_dom_box_risk_bucket(int $riskScore): string
{
    if ( $riskScore >= 25 ) {
        return 'critical';
    }
    if ( $riskScore >= 10 ) {
        return 'high';
    }
    if ( $riskScore >= 1 ) {
        return 'medium';
    }

    return 'low';
}

function matrix_visual_risk_bucket(int $readinessScore): string
{
    if ( $readinessScore >= 90 ) {
        return 'low';
    }
    if ( $readinessScore >= 75 ) {
        return 'medium';
    }
    if ( $readinessScore >= 50 ) {
        return 'high';
    }

    return 'critical';
}

/**
 * @param array<int, string> $signals
 * @return array{count: int, signals: array<int, string>}
 */
function matrix_risk_category(int $count, array $signals): array
{
    return array(
        'count' => $count,
        'signals' => $signals,
    );
}

/**
 * @param array<string, mixed> $domSummary
 * @return array{count: int, signals: array<int, string>}
 */
function matrix_dom_box_risk_category(array $domSummary): array
{
    $signals = array('dom_horizontal_overflow_count', 'dom_viewport_width_leak_count', 'dom_huge_vertical_spacing_count', 'dom_collapsed_box_count', 'dom_offscreen_box_count', 'dom_missing_node_id_box_count');
    if ( false === ($domSummary['dom_capture_valid'] ?? true) ) {
        return matrix_risk_category(0, array_merge(array('dom_capture_invalid'), $signals));
    }

    return matrix_risk_category(
        matrix_quality_summary_int($domSummary, 'dom_horizontal_overflow_count')
            + matrix_quality_summary_int($domSummary, 'dom_viewport_width_leak_count')
            + matrix_quality_summary_int($domSummary, 'dom_huge_vertical_spacing_count')
            + matrix_quality_summary_int($domSummary, 'dom_collapsed_box_count')
            + matrix_quality_summary_int($domSummary, 'dom_offscreen_box_count')
            + matrix_quality_summary_int($domSummary, 'dom_missing_node_id_box_count'),
        $signals
    );
}

/**
 * @param array<string, mixed> $summary
 */
function matrix_quality_summary_int(array $summary, string $key): int
{
    if ( isset($summary[$key]) && is_numeric($summary[$key]) ) {
        return (int) $summary[$key];
    }

    if ( isset($summary['html_artifact']) && is_array($summary['html_artifact']) && isset($summary['html_artifact'][$key]) && is_numeric($summary['html_artifact'][$key]) ) {
        return (int) $summary['html_artifact'][$key];
    }

    return 0;
}
