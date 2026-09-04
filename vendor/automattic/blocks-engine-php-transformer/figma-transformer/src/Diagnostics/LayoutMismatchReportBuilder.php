<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Diagnostics;

/**
 * Compares source Figma visual boxes with runner-supplied generated DOM boxes.
 */
final class LayoutMismatchReportBuilder
{
    public const SCHEMA = 'blocks-engine/figma-transformer/layout-mismatch-report/v1';
    public const DOM_BOXES_SCHEMA = 'homeboy/static-artifact-dom-boxes/v1';

    /**
     * @param array<string, mixed> $htmlSourceReport
     * @param array<string, mixed> $evidence
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function build(array $htmlSourceReport, array $evidence = array(), array $options = array()): array
    {
        $threshold = $this->numericOption($options, 'threshold', 24.0);
        $sizeThreshold = $this->numericOption($options, 'size_threshold', $threshold);
        $limit = max(1, (int) $this->numericOption($options, 'limit', 100.0));
        $sourceNodes = $this->sourceNodesById($htmlSourceReport);
        $comparison = $this->comparisonContext($htmlSourceReport, $evidence, $options);
        if ( ! empty($comparison['source_nodes']) ) {
            $sourceNodes = $comparison['source_nodes'];
        }
        $generatedNodes = $this->generatedNodesById($evidence, $comparison['entrypoint_index']);
        $diagnostics = array();
        $warnings = array();
        $matched = 0;
        $unmatchedSource = 0;

        foreach ( $sourceNodes as $nodeId => $sourceNode ) {
            if ( ! $this->hasTrustworthySourcePosition($sourceNode) ) {
                $warnings[] = $this->sourceGeometryConfidenceDiagnostic($sourceNode);
            }
        }

        foreach ( true === $comparison['comparable'] ? $sourceNodes : array() as $nodeId => $sourceNode ) {
            $hasTrustworthyPosition = $this->hasTrustworthySourcePosition($sourceNode);
            $generatedNode = $generatedNodes[$nodeId] ?? null;
            if ( null === $generatedNode ) {
                $unmatchedSource++;
                continue;
            }

            $generatedBox = $this->boxFromNode($generatedNode);
            $sourceBox = null === $generatedBox ? $this->boxFromNode($sourceNode) : $this->sourceBoxForGeneratedBox($sourceNode, $generatedBox);
            if ( null === $sourceBox || null === $generatedBox ) {
                continue;
            }

            $matched++;
            $delta = $this->delta($sourceBox, $generatedBox);
            $positionMismatch = $hasTrustworthyPosition && (abs($delta['x']) > $threshold || abs($delta['y']) > $threshold);
            $sizeMismatch = abs($delta['width']) > $sizeThreshold || abs($delta['height']) > $sizeThreshold;
            $outsideParent = $hasTrustworthyPosition ? $this->outsideGeneratedParent($sourceNode, $generatedNode, $sourceNodes, $generatedNodes, $threshold) : null;
            if ( ! $positionMismatch && ! $sizeMismatch && null === $outsideParent ) {
                continue;
            }

            $context = $this->diagnosticContext($sourceNode, $generatedNode, $sourceNodes, $generatedNodes);

            if ( $positionMismatch || $sizeMismatch ) {
                $diagnostics[] = $this->diagnostic(
                    $positionMismatch ? 'misplaced_element' : 'element_size_mismatch',
                    $sourceNode,
                    $generatedNode,
                    $sourceBox,
                    $generatedBox,
                    $delta,
                    $threshold,
                    $sizeThreshold,
                    $context
                );
            }

            if ( $positionMismatch && $sizeMismatch ) {
                $diagnostics[] = $this->diagnostic('element_size_mismatch', $sourceNode, $generatedNode, $sourceBox, $generatedBox, $delta, $threshold, $sizeThreshold, $context);
            }

            if ( null !== $outsideParent ) {
                $diagnostics[] = array_merge(
                    $this->diagnostic('element_outside_parent_bounds', $sourceNode, $generatedNode, $sourceBox, $generatedBox, $delta, $threshold, $sizeThreshold, $context),
                    array('parent' => $outsideParent)
                );
            }
        }

        usort(
            $diagnostics,
            static fn (array $left, array $right): int => (($right['max_delta'] ?? 0) <=> ($left['max_delta'] ?? 0)) ?: strcmp((string) ($left['node']['id'] ?? ''), (string) ($right['node']['id'] ?? ''))
        );

        $totalDiagnostics = $diagnostics;
        $diagnostics = array_slice($totalDiagnostics, 0, $limit);
        $codeCounts = array();
        foreach ( $totalDiagnostics as $diagnostic ) {
            $code = (string) ($diagnostic['code'] ?? '');
            if ( '' !== $code ) {
                $codeCounts[$code] = ($codeCounts[$code] ?? 0) + 1;
            }
        }
        ksort($codeCounts);
        $warningCodeCounts = array();
        foreach ( $warnings as $warning ) {
            $code = (string) ($warning['code'] ?? '');
            if ( '' !== $code ) {
                $warningCodeCounts[$code] = ($warningCodeCounts[$code] ?? 0) + 1;
            }
        }
        ksort($warningCodeCounts);

        return array(
            'schema' => self::SCHEMA,
            'input_schema' => isset($evidence['schema']) && is_scalar($evidence['schema']) ? (string) $evidence['schema'] : self::DOM_BOXES_SCHEMA,
            'status' => empty($generatedNodes) ? 'not_run' : (true !== $comparison['comparable'] ? 'not_comparable' : (empty($diagnostics) ? 'pass' : 'fail')),
            'comparison' => $comparison['evidence'],
            'threshold' => $threshold,
            'size_threshold' => $sizeThreshold,
            'summary' => array(
                'source_node_count' => count($sourceNodes),
                'generated_node_count' => count($generatedNodes),
                'matched_node_count' => $matched,
                'unmatched_source_node_count' => $unmatchedSource,
                'diagnostic_count' => count($totalDiagnostics),
                'reported_diagnostic_count' => count($diagnostics),
                'truncated' => count($diagnostics) < count($totalDiagnostics),
                'code_counts' => $codeCounts,
                'warning_count' => count($warnings),
                'warning_code_counts' => $warningCodeCounts,
                'clusters' => $this->diagnosticClusters($totalDiagnostics),
                'suspected_causes' => $this->suspectedCauseSummary($totalDiagnostics),
                'font_rendering' => $this->fontRenderingSummary($htmlSourceReport, $generatedNodes),
            ),
            'diagnostics' => $diagnostics,
            'warnings' => $warnings,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function diagnosticClusters(array $diagnostics): array
    {
        return array(
            'parent_delta' => $this->parentDeltaClusters($diagnostics),
            'repeated_position_delta' => $this->repeatedPositionDeltaClusters($diagnostics),
            'node_pattern' => $this->nodePatternClusters($diagnostics),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function parentDeltaClusters(array $diagnostics): array
    {
        $groups = array();
        foreach ( $diagnostics as $diagnostic ) {
            $node = is_array($diagnostic['node'] ?? null) ? $diagnostic['node'] : array();
            $parentId = isset($node['parent_id']) && is_scalar($node['parent_id']) && '' !== (string) $node['parent_id'] ? (string) $node['parent_id'] : '(root)';
            $this->addClusterDiagnostic($groups, $parentId, $diagnostic, array('parent_id' => $parentId));
        }

        return $this->clusterValues($groups);
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function repeatedPositionDeltaClusters(array $diagnostics): array
    {
        $groups = array();
        foreach ( $diagnostics as $diagnostic ) {
            $delta = is_array($diagnostic['delta'] ?? null) ? $diagnostic['delta'] : array();
            if ( ! is_numeric($delta['x'] ?? null) || ! is_numeric($delta['y'] ?? null) ) {
                continue;
            }

            $x = (int) round((float) $delta['x']);
            $y = (int) round((float) $delta['y']);
            $key = 'x:' . $x . '|y:' . $y;
            $this->addClusterDiagnostic($groups, $key, $diagnostic, array('delta' => array('x' => $x, 'y' => $y)));
        }

        return array_values(array_filter(
            $this->clusterValues($groups),
            static fn (array $cluster): bool => 1 < (int) ($cluster['count'] ?? 0)
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function nodePatternClusters(array $diagnostics): array
    {
        $groups = array();
        foreach ( $diagnostics as $diagnostic ) {
            $node = is_array($diagnostic['node'] ?? null) ? $diagnostic['node'] : array();
            $type = isset($node['type']) && is_scalar($node['type']) && '' !== (string) $node['type'] ? (string) $node['type'] : '(unknown)';
            $namePattern = $this->namePattern(isset($node['name']) && is_scalar($node['name']) ? (string) $node['name'] : '');
            $key = $type . '|' . $namePattern;
            $this->addClusterDiagnostic($groups, $key, $diagnostic, array('type' => $type, 'name_pattern' => $namePattern));
        }

        return $this->clusterValues($groups);
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function suspectedCauseSummary(array $diagnostics): array
    {
        $groups = array();
        foreach ( $diagnostics as $diagnostic ) {
            foreach ( $this->suspectedCausesForDiagnostic($diagnostic) as $cause ) {
                $this->addClusterDiagnostic($groups, $cause, $diagnostic, array('cause' => $cause));
            }
        }

        $causes = $this->clusterValues($groups);
        usort(
            $causes,
            fn (array $left, array $right): int => $this->suspectedCausePriority((string) ($left['cause'] ?? '')) <=> $this->suspectedCausePriority((string) ($right['cause'] ?? ''))
                ?: ((int) ($right['count'] ?? 0) <=> (int) ($left['count'] ?? 0))
                ?: ((float) ($right['max_delta'] ?? 0.0) <=> (float) ($left['max_delta'] ?? 0.0))
        );

        return $causes;
    }

    private function suspectedCausePriority(string $cause): int
    {
        return match ( $cause ) {
            'source-overflow', 'generated-vs-source-clipping', 'clipping' => 0,
            'parent-visual-map-mismatch' => 1,
            'same-size-position-shift', 'absolute-offset' => 2,
            'zero-size-source-box' => 3,
            'root-fill' => 4,
            'text-height' => 5,
            'vector-shell-wrapper-offset' => 6,
            'icon/vector-bounds' => 7,
            default => 99,
        };
    }

    /**
     * @param array<string, mixed> $diagnostic
     * @return array<int, string>
     */
    private function suspectedCausesForDiagnostic(array $diagnostic): array
    {
        $causes = array();
        $code = isset($diagnostic['code']) && is_scalar($diagnostic['code']) ? (string) $diagnostic['code'] : '';
        $node = is_array($diagnostic['node'] ?? null) ? $diagnostic['node'] : array();
        $type = strtoupper(isset($node['type']) && is_scalar($node['type']) ? (string) $node['type'] : '');
        $name = strtolower(isset($node['name']) && is_scalar($node['name']) ? (string) $node['name'] : '');
        $delta = is_array($diagnostic['delta'] ?? null) ? $diagnostic['delta'] : array();
        $threshold = is_numeric($diagnostic['threshold'] ?? null) ? (float) $diagnostic['threshold'] : 0.0;
        $sizeThreshold = is_numeric($diagnostic['size_threshold'] ?? null) ? (float) $diagnostic['size_threshold'] : $threshold;
        $deltaX = is_numeric($delta['x'] ?? null) ? (float) $delta['x'] : 0.0;
        $deltaY = is_numeric($delta['y'] ?? null) ? (float) $delta['y'] : 0.0;
        $deltaWidth = is_numeric($delta['width'] ?? null) ? (float) $delta['width'] : 0.0;
        $deltaHeight = is_numeric($delta['height'] ?? null) ? (float) $delta['height'] : 0.0;
        $sourceBox = is_array($diagnostic['source_box'] ?? null) ? $diagnostic['source_box'] : array();
        $parentDelta = is_array($diagnostic['parent_delta'] ?? null) ? $diagnostic['parent_delta'] : array();
        $positionShift = max(abs($deltaX), abs($deltaY));
        $sizeShift = max(abs($deltaWidth), abs($deltaHeight));

        if ( 'element_outside_parent_bounds' === $code ) {
            $parent = is_array($diagnostic['parent'] ?? null) ? $diagnostic['parent'] : array();
            $sourceOverflow = is_array($parent['source_overflow'] ?? null) ? $parent['source_overflow'] : array();
            $sourceOverflowMax = max(array_map(static fn (mixed $value): float => is_numeric($value) ? (float) $value : 0.0, $sourceOverflow) ?: array(0.0));
            if ( 0.0 < $sourceOverflowMax ) {
                $causes[] = 'source-overflow';
            } else {
                $causes[] = 'generated-vs-source-clipping';
                $causes[] = 'clipping';
            }
        }

        if ( 'misplaced_element' === $code && $positionShift > $threshold && $sizeShift <= $sizeThreshold ) {
            $causes[] = 'same-size-position-shift';
        }

        if ( 'misplaced_element' === $code && $positionShift > max($threshold, $sizeShift) ) {
            $causes[] = 'absolute-offset';
        }

        if ( is_numeric($parentDelta['x'] ?? null) && is_numeric($parentDelta['y'] ?? null) && $positionShift > $threshold ) {
            $parentDeltaX = (float) $parentDelta['x'];
            $parentDeltaY = (float) $parentDelta['y'];
            if ( max(abs($parentDeltaX), abs($parentDeltaY)) > $threshold && abs($deltaX - $parentDeltaX) <= 2.0 && abs($deltaY - $parentDeltaY) <= 2.0 ) {
                $causes[] = 'parent-visual-map-mismatch';
            }
        }

        if ( $this->isNearZeroBox($sourceBox) && ($positionShift > $threshold || $sizeShift > $sizeThreshold) ) {
            $causes[] = 'zero-size-source-box';
        }

        if ( 'TEXT' === $type && abs($deltaHeight) > $sizeThreshold ) {
            $causes[] = 'text-height';
        }

        if ( '' === (string) ($node['parent_id'] ?? '') && (abs($deltaWidth) > $sizeThreshold || abs($deltaHeight) > $sizeThreshold) ) {
            $causes[] = 'root-fill';
        }

        if ( ('VECTOR' === $type || str_contains($name, 'icon')) && (abs($deltaWidth) > $sizeThreshold || abs($deltaHeight) > $sizeThreshold) ) {
            $causes[] = 'icon/vector-bounds';
        }

        if ( ('VECTOR' === $type || 'BOOLEAN_OPERATION' === $type) && 'misplaced_element' === $code && $positionShift > $threshold && $sizeShift <= $sizeThreshold ) {
            $causes[] = 'vector-shell-wrapper-offset';
        }

        return array_values(array_unique($causes));
    }

    /**
     * @param array<string, mixed> $box
     */
    private function isNearZeroBox(array $box): bool
    {
        $width = is_numeric($box['width'] ?? null) ? abs((float) $box['width']) : null;
        $height = is_numeric($box['height'] ?? null) ? abs((float) $box['height']) : null;

        return null !== $width && null !== $height && ($width <= 1.0 || $height <= 1.0);
    }

    /**
     * @param array<string, array<string, mixed>> $groups
     * @param array<string, mixed> $diagnostic
     * @param array<string, mixed> $fields
     */
    private function addClusterDiagnostic(array &$groups, string $key, array $diagnostic, array $fields): void
    {
        if ( ! isset($groups[$key]) ) {
            $groups[$key] = array_merge($fields, array(
                'count' => 0,
                'max_delta' => 0.0,
                'average_delta' => array('x' => 0.0, 'y' => 0.0),
                'code_counts' => array(),
                'sample_nodes' => array(),
                '_delta_x_total' => 0.0,
                '_delta_y_total' => 0.0,
            ));
        }

        $delta = is_array($diagnostic['delta'] ?? null) ? $diagnostic['delta'] : array();
        $deltaX = is_numeric($delta['x'] ?? null) ? (float) $delta['x'] : 0.0;
        $deltaY = is_numeric($delta['y'] ?? null) ? (float) $delta['y'] : 0.0;
        $groups[$key]['count']++;
        $groups[$key]['_delta_x_total'] += $deltaX;
        $groups[$key]['_delta_y_total'] += $deltaY;
        $groups[$key]['max_delta'] = max((float) $groups[$key]['max_delta'], is_numeric($diagnostic['max_delta'] ?? null) ? (float) $diagnostic['max_delta'] : 0.0);

        $code = isset($diagnostic['code']) && is_scalar($diagnostic['code']) ? (string) $diagnostic['code'] : '';
        if ( '' !== $code ) {
            $groups[$key]['code_counts'][$code] = ($groups[$key]['code_counts'][$code] ?? 0) + 1;
        }

        $node = is_array($diagnostic['node'] ?? null) ? $diagnostic['node'] : array();
        if ( count($groups[$key]['sample_nodes']) < 3 ) {
            $groups[$key]['sample_nodes'][] = array_filter(array(
                'id' => isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : null,
                'name' => isset($node['name']) && is_scalar($node['name']) ? (string) $node['name'] : null,
                'type' => isset($node['type']) && is_scalar($node['type']) ? (string) $node['type'] : null,
            ), static fn (mixed $value): bool => null !== $value && '' !== $value);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $groups
     * @return array<int, array<string, mixed>>
     */
    private function clusterValues(array $groups): array
    {
        $clusters = array_values(array_map(
            static function (array $cluster): array {
                $count = max(1, (int) $cluster['count']);
                $cluster['average_delta'] = array(
                    'x' => round((float) $cluster['_delta_x_total'] / $count, 2),
                    'y' => round((float) $cluster['_delta_y_total'] / $count, 2),
                );
                ksort($cluster['code_counts']);
                unset($cluster['_delta_x_total'], $cluster['_delta_y_total']);
                return $cluster;
            },
            $groups
        ));

        usort(
            $clusters,
            static fn (array $left, array $right): int => ((int) ($right['count'] ?? 0) <=> (int) ($left['count'] ?? 0)) ?: ((float) ($right['max_delta'] ?? 0.0) <=> (float) ($left['max_delta'] ?? 0.0))
        );

        return array_slice($clusters, 0, 10);
    }

    private function namePattern(string $name): string
    {
        $pattern = strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/\d+/', '#', $name) ?? '') ?? ''));
        $pattern = trim(preg_replace('/[^a-z#]+/', ' ', $pattern) ?? '');

        return '' === $pattern ? '(unnamed)' : $pattern;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function numericOption(array $values, string $key, float $default): float
    {
        return isset($values[$key]) && is_numeric($values[$key]) ? max(0.0, (float) $values[$key]) : $default;
    }

    /**
     * @param array<string, mixed> $htmlSourceReport
     * @return array<string, array<string, mixed>>
     */
    private function sourceNodesById(array $htmlSourceReport): array
    {
        $nodes = is_array($htmlSourceReport['visual_node_map'] ?? null) ? $htmlSourceReport['visual_node_map'] : array();
        return $this->nodesById($nodes);
    }

    /**
     * @param array<string, mixed> $htmlSourceReport
     * @param array<string, array<string, mixed>> $generatedNodes
     * @return array<string, mixed>
     */
    private function fontRenderingSummary(array $htmlSourceReport, array $generatedNodes): array
    {
        $sourceByNode = array();
        foreach ( is_array($htmlSourceReport['node_style_diagnostics'] ?? null) ? $htmlSourceReport['node_style_diagnostics'] : array() as $diagnostic ) {
            if ( ! is_array($diagnostic) ) {
                continue;
            }
            $node = is_array($diagnostic['node'] ?? null) ? $diagnostic['node'] : array();
            $expected = is_array($diagnostic['expected'] ?? null) ? $diagnostic['expected'] : array();
            $nodeId = isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '';
            $family = isset($expected['font_family']) && is_scalar($expected['font_family']) ? trim((string) $expected['font_family'], " \t\n\r\0\x0B\"") : '';
            if ( '' !== $nodeId && '' !== $family ) {
                $sourceByNode[$nodeId] = $family;
            }
        }

        $matched = 0;
        $computedFamilyMismatches = array();
        $fontCheckFailures = array();
        foreach ( $sourceByNode as $nodeId => $sourceFamily ) {
            if ( ! isset($generatedNodes[$nodeId]) ) {
                continue;
            }
            $generatedNode = $generatedNodes[$nodeId];
            $computedFamily = $this->computedFontFamily($generatedNode);
            $fontCheck = $this->fontCheckValue($generatedNode);
            if ( null === $computedFamily && null === $fontCheck ) {
                continue;
            }

            $matched++;
            if ( null !== $computedFamily && ! $this->computedFamilyContains($computedFamily, $sourceFamily) ) {
                $computedFamilyMismatches[] = array('node_id' => $nodeId, 'source_font_family' => $sourceFamily, 'computed_font_family' => $computedFamily);
            }
            if ( false === $fontCheck ) {
                $fontCheckFailures[] = array('node_id' => $nodeId, 'source_font_family' => $sourceFamily, 'document_fonts_check' => false);
            }
        }

        return array(
            'status' => 0 === $matched ? 'not_evaluated' : (empty($computedFamilyMismatches) && empty($fontCheckFailures) ? 'pass' : 'warn'),
            'source_font_family_count' => count(is_array($htmlSourceReport['font_families'] ?? null) ? $htmlSourceReport['font_families'] : array()),
            'source_font_usage' => is_array($htmlSourceReport['font_usage'] ?? null) ? $htmlSourceReport['font_usage'] : array(),
            'evidence_node_count' => $matched,
            'computed_font_family_mismatch_count' => count($computedFamilyMismatches),
            'document_fonts_check_failure_count' => count($fontCheckFailures),
            'computed_font_family_mismatches' => array_slice($computedFamilyMismatches, 0, 10),
            'document_fonts_check_failures' => array_slice($fontCheckFailures, 0, 10),
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    private function computedFontFamily(array $node): ?string
    {
        foreach ( array('computed_font_family', 'font_family', 'fontFamily') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== trim((string) $node[$key]) ) {
                return trim((string) $node[$key]);
            }
        }
        foreach ( array('computed_style', 'computedStyle', 'style') as $key ) {
            $style = is_array($node[$key] ?? null) ? $node[$key] : array();
            foreach ( array('font-family', 'fontFamily', 'font_family') as $styleKey ) {
                if ( isset($style[$styleKey]) && is_scalar($style[$styleKey]) && '' !== trim((string) $style[$styleKey]) ) {
                    return trim((string) $style[$styleKey]);
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function fontCheckValue(array $node): ?bool
    {
        foreach ( array('document_fonts_check', 'font_check', 'font_loaded') as $key ) {
            if ( is_bool($node[$key] ?? null) ) {
                return $node[$key];
            }
        }

        return null;
    }

    private function computedFamilyContains(string $computedFamily, string $sourceFamily): bool
    {
        $sourceFamily = strtolower(trim($sourceFamily, " \t\n\r\0\x0B\"'"));
        foreach ( explode(',', strtolower($computedFamily)) as $family ) {
            if ( trim($family, " \t\n\r\0\x0B\"'") === $sourceFamily ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, array<string, mixed>>
     */
    private function generatedNodesById(array $evidence, ?int $entrypointIndex = null): array
    {
        $nodes = array();
        foreach ( array('boxes', 'nodes', 'dom_boxes', 'elements') as $key ) {
            if ( is_array($evidence[$key] ?? null) ) {
                $nodes = array_merge($nodes, $evidence[$key]);
            }
        }

        foreach ( is_array($evidence['entrypoints'] ?? null) ? $evidence['entrypoints'] : array() as $index => $entrypoint ) {
            if ( null !== $entrypointIndex && $index !== $entrypointIndex ) {
                continue;
            }
            if ( is_array($entrypoint) && is_array($entrypoint['elements'] ?? null) ) {
                $nodes = array_merge($nodes, $entrypoint['elements']);
            }
        }

        if ( empty($nodes) && is_array($evidence['generated']['boxes'] ?? null) ) {
            $nodes = $evidence['generated']['boxes'];
        }

        return $this->nodesById($nodes);
    }

    /**
     * Positional source coordinates are only meaningful at their source width.
     * A responsive capture needs its own supplied source geometry; a variant
     * frame identifier alone does not make the primary visual map comparable.
     *
     * @param array<string, mixed> $htmlSourceReport
     * @param array<string, mixed> $evidence
     * @param array<string, mixed> $options
     * @return array{comparable:bool,entrypoint_index:int|null,evidence:array<string,mixed>,source_nodes:array<string,array<string,mixed>>}
     */
    private function comparisonContext(array $htmlSourceReport, array $evidence, array $options): array
    {
        $sourceFrameId = $this->scalarString($options['source_frame_id'] ?? null)
            ?? $this->scalarString($htmlSourceReport['source_frame_identity']['primary_frame_id'] ?? null);
        $sourceWidth = $this->numericValue($options['source_frame_width'] ?? null)
            ?? $this->numericValue($htmlSourceReport['source_frame_identity']['width'] ?? null);
        $pagePath = $this->scalarString($options['page_path'] ?? null);
        $entrypoints = is_array($evidence['entrypoints'] ?? null) ? $evidence['entrypoints'] : array();
        $entrypointIndex = null;
        $capture = array();

        foreach ( $entrypoints as $index => $entrypoint ) {
            if ( ! is_array($entrypoint) || ! $this->samePagePath($pagePath, $this->scalarString($entrypoint['page_path'] ?? null)) ) {
                continue;
            }
            $entrypointSource = is_array($entrypoint['source_frame'] ?? null) ? $entrypoint['source_frame'] : array();
            if ( null !== $sourceFrameId && $sourceFrameId === $this->scalarString($entrypointSource['id'] ?? null) ) {
                $entrypointIndex = $index;
                $capture = $entrypoint;
                break;
            }
            if ( null === $entrypointIndex && 'responsive_evidence' !== ($entrypoint['comparison_role'] ?? null) ) {
                $entrypointIndex = $index;
                $capture = $entrypoint;
            }
        }

        if ( null === $entrypointIndex ) {
            foreach ( $entrypoints as $index => $entrypoint ) {
                if ( is_array($entrypoint) && $this->samePagePath($pagePath, $this->scalarString($entrypoint['page_path'] ?? null)) ) {
                    $entrypointIndex = $index;
                    $capture = $entrypoint;
                    break;
                }
            }
        }

        if ( null === $entrypointIndex && 1 === count($entrypoints) && is_array($entrypoints[0] ?? null) ) {
            $entrypointIndex = 0;
            $capture = $entrypoints[0];
        }

        $viewport = is_array($capture['viewport'] ?? null) ? $capture['viewport'] : (is_array($evidence['viewport'] ?? null) ? $evidence['viewport'] : array());
        $captureWidth = $this->numericValue($viewport['width'] ?? null);
        $captureFrameId = $this->scalarString($capture['source_frame']['id'] ?? null);
        $widthsMatch = null === $sourceWidth || null === $captureWidth || abs($sourceWidth - $captureWidth) < 0.5;
        $variantSourceNodes = $this->variantSourceNodes($capture, $options, $captureFrameId);
        $hasVariantSourceGeometry = ! empty($variantSourceNodes);
        $comparable = $widthsMatch || $hasVariantSourceGeometry;
        $reason = $comparable ? ($widthsMatch ? 'matching_source_width' : 'responsive_source_geometry_supplied') : 'incompatible_viewport_width';

        return array(
            'comparable' => $comparable,
            'entrypoint_index' => $entrypointIndex,
            'source_nodes' => $variantSourceNodes,
            'evidence' => array_filter(array(
                'status' => $comparable ? 'comparable' : 'not_comparable',
                'position_status' => $comparable ? 'comparable' : 'not_comparable',
                'size_status' => $comparable ? 'comparable' : 'not_comparable',
                'reason' => $reason,
                'source_frame_id' => $sourceFrameId,
                'source_frame_width' => $sourceWidth,
                'captured_viewport' => empty($viewport) ? null : $viewport,
                'captured_entrypoint_index' => $entrypointIndex,
                'responsive_evidence_capture_count' => count(array_filter($entrypoints, static fn (mixed $entrypoint): bool => is_array($entrypoint) && 'responsive_evidence' === ($entrypoint['comparison_role'] ?? null))),
            ), static fn (mixed $value): bool => null !== $value),
        );
    }

    /**
     * @param array<string, mixed> $capture
     * @param array<string, mixed> $options
     * @return array<string, array<string, mixed>>
     */
    private function variantSourceNodes(array $capture, array $options, ?string $captureFrameId): array
    {
        $nodes = is_array($capture['source_visual_node_map'] ?? null) ? $capture['source_visual_node_map'] : array();
        if ( empty($nodes) && null !== $captureFrameId ) {
            $maps = is_array($options['source_frame_variant_visual_node_maps'] ?? null) ? $options['source_frame_variant_visual_node_maps'] : array();
            $nodes = is_array($maps[$captureFrameId] ?? null) ? $maps[$captureFrameId] : array();
        }

        return $this->nodesById($nodes);
    }

    private function numericValue(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function scalarString(mixed $value): ?string
    {
        return is_scalar($value) && '' !== trim((string) $value) ? (string) $value : null;
    }

    private function samePagePath(?string $expected, ?string $actual): bool
    {
        return null === $expected || null === $actual || ltrim($expected, '/') === ltrim($actual, '/');
    }

    /**
     * @param array<int, mixed> $nodes
     * @return array<string, array<string, mixed>>
     */
    private function nodesById(array $nodes): array
    {
        $byId = array();
        foreach ( $nodes as $node ) {
            if ( ! is_array($node) ) {
                continue;
            }

            $id = $this->nodeId($node);
            if ( '' === $id ) {
                continue;
            }

            $node['id'] = $id;
            $byId[$id] = $node;
        }

        ksort($byId);
        return $byId;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeId(array $node): string
    {
        foreach ( array('id', 'node_id', 'figma_node_id', 'data_figma_node_id', 'data-figma-node-id') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
                return (string) $node[$key];
            }
        }

        if ( isset($node['attributes']['data-figma-node-id']) && is_scalar($node['attributes']['data-figma-node-id']) ) {
            return (string) $node['attributes']['data-figma-node-id'];
        }

        return '';
    }

    /**
     * @param array<string, mixed> $node
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private function boxFromNode(array $node): ?array
    {
        foreach ( array('rect', 'box', 'bounding_client_rect', 'boundingClientRect', 'bounds') as $key ) {
            if ( is_array($node[$key] ?? null) ) {
                $box = $this->boxFromValue($node[$key]);
                if ( null !== $box ) {
                    return $box;
                }
            }
        }

        return $this->boxFromValue($node);
    }

    /**
     * @param array<string, mixed> $sourceNode
     * @param array{x: float, y: float, width: float, height: float} $generatedBox
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private function sourceBoxForGeneratedBox(array $sourceNode, array $generatedBox): ?array
    {
        $sourceBox = $this->boxFromNode($sourceNode);
        if ( ! is_array($sourceNode['visible_rect'] ?? null) ) {
            return $sourceBox;
        }

        $visibleBox = $this->boxFromValue($sourceNode['visible_rect']);
        if ( null === $sourceBox || null === $visibleBox ) {
            return $sourceBox ?? $visibleBox;
        }

        return $this->boxDistance($visibleBox, $generatedBox) < $this->boxDistance($sourceBox, $generatedBox) ? $visibleBox : $sourceBox;
    }

    /**
     * Local component-source geometry has reliable dimensions but no page-space
     * position until transform provenance is available.
     *
     * @param array<string, mixed> $sourceNode
     */
    private function hasTrustworthySourcePosition(array $sourceNode): bool
    {
        return 'unresolved_component_local' !== ($sourceNode['geometry_confidence'] ?? null);
    }

    /**
     * @param array<string, mixed> $sourceNode
     * @return array<string, mixed>
     */
    private function sourceGeometryConfidenceDiagnostic(array $sourceNode): array
    {
        return array_filter(array(
            'severity' => 'warning',
            'code' => 'source_geometry_confidence',
            'node' => array(
                'id' => (string) ($sourceNode['id'] ?? ''),
                'name' => (string) ($sourceNode['name'] ?? ''),
                'type' => (string) ($sourceNode['type'] ?? ''),
                'parent_id' => (string) ($sourceNode['parent_id'] ?? ''),
            ),
            'coordinate_space' => isset($sourceNode['coordinate_space']) && is_scalar($sourceNode['coordinate_space']) ? (string) $sourceNode['coordinate_space'] : null,
            'geometry_confidence' => 'unresolved_component_local',
            'message' => 'Source dimensions are comparable, but component-local coordinates lack page-space transform provenance.',
        ), static fn (mixed $value): bool => null !== $value);
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $left
     * @param array{x: float, y: float, width: float, height: float} $right
     */
    private function boxDistance(array $left, array $right): float
    {
        return max(
            abs($right['x'] - $left['x']),
            abs($right['y'] - $left['y']),
            abs($right['width'] - $left['width']),
            abs($right['height'] - $left['height'])
        );
    }

    /**
     * @param array<string, mixed> $value
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private function boxFromValue(array $value): ?array
    {
        $x = $value['x'] ?? $value['left'] ?? null;
        $y = $value['y'] ?? $value['top'] ?? null;
        $width = $value['width'] ?? null;
        $height = $value['height'] ?? null;
        if ( null === $width && isset($value['right']) && is_numeric($value['right']) && is_numeric($x) ) {
            $width = (float) $value['right'] - (float) $x;
        }
        if ( null === $height && isset($value['bottom']) && is_numeric($value['bottom']) && is_numeric($y) ) {
            $height = (float) $value['bottom'] - (float) $y;
        }

        foreach ( array($x, $y, $width, $height) as $part ) {
            if ( ! is_numeric($part) ) {
                return null;
            }
        }

        return array('x' => (float) $x, 'y' => (float) $y, 'width' => (float) $width, 'height' => (float) $height);
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $sourceBox
     * @param array{x: float, y: float, width: float, height: float} $generatedBox
     * @return array{x: float, y: float, width: float, height: float}
     */
    private function delta(array $sourceBox, array $generatedBox): array
    {
        return array(
            'x' => $generatedBox['x'] - $sourceBox['x'],
            'y' => $generatedBox['y'] - $sourceBox['y'],
            'width' => $generatedBox['width'] - $sourceBox['width'],
            'height' => $generatedBox['height'] - $sourceBox['height'],
        );
    }

    /**
     * @param array<string, mixed> $sourceNode
     * @param array<string, mixed> $generatedNode
     * @param array<string, array<string, mixed>> $sourceNodes
     * @param array<string, array<string, mixed>> $generatedNodes
     * @return array<string, mixed>|null
     */
    private function outsideGeneratedParent(array $sourceNode, array $generatedNode, array $sourceNodes, array $generatedNodes, float $threshold): ?array
    {
        $parentId = isset($sourceNode['parent_id']) && is_scalar($sourceNode['parent_id']) ? (string) $sourceNode['parent_id'] : '';
        if ( '' === $parentId || ! isset($generatedNodes[$parentId]) ) {
            return null;
        }

        $parentBox = $this->boxFromNode($generatedNodes[$parentId]);
        $childBox = $this->boxFromNode($generatedNode);
        if ( null === $parentBox || null === $childBox ) {
            return null;
        }

        $overflow = $this->parentOverflow($parentBox, $childBox);
        $maxOverflow = max($overflow);
        if ( $maxOverflow <= $threshold ) {
            return null;
        }

        $sourceParentBox = isset($sourceNodes[$parentId]) ? $this->boxFromNode($sourceNodes[$parentId]) : null;
        $sourceChildBox = $this->boxFromNode($sourceNode);
        $sourceOverflow = null;
        if ( null !== $sourceParentBox && null !== $sourceChildBox ) {
            $sourceOverflow = $this->parentOverflow($sourceParentBox, $sourceChildBox);
            if ( $maxOverflow <= max($sourceOverflow) + $threshold ) {
                return null;
            }
        }

        return array_filter(array(
            'id' => $parentId,
            'generated_box' => $parentBox,
            'overflow' => $overflow,
            'max_overflow' => $maxOverflow,
            'source_overflow' => $sourceOverflow,
        ), static fn (mixed $value): bool => null !== $value);
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $parentBox
     * @param array{x: float, y: float, width: float, height: float} $childBox
     * @return array{left: float, top: float, right: float, bottom: float}
     */
    private function parentOverflow(array $parentBox, array $childBox): array
    {
        return array(
            'left' => max(0.0, $parentBox['x'] - $childBox['x']),
            'top' => max(0.0, $parentBox['y'] - $childBox['y']),
            'right' => max(0.0, ($childBox['x'] + $childBox['width']) - ($parentBox['x'] + $parentBox['width'])),
            'bottom' => max(0.0, ($childBox['y'] + $childBox['height']) - ($parentBox['y'] + $parentBox['height'])),
        );
    }

    /**
     * @param array<string, mixed> $sourceNode
     * @param array<string, mixed> $generatedNode
     * @param array<string, array<string, mixed>> $sourceNodes
     * @param array<string, array<string, mixed>> $generatedNodes
     * @return array<string, mixed>
     */
    private function diagnosticContext(array $sourceNode, array $generatedNode, array $sourceNodes, array $generatedNodes): array
    {
        $parentId = isset($sourceNode['parent_id']) && is_scalar($sourceNode['parent_id']) ? (string) $sourceNode['parent_id'] : '';
        if ( '' === $parentId || ! isset($sourceNodes[$parentId], $generatedNodes[$parentId]) ) {
            return array();
        }

        $sourceParentBox = $this->boxFromNode($sourceNodes[$parentId]);
        $generatedParentBox = $this->boxFromNode($generatedNodes[$parentId]);
        if ( null === $sourceParentBox || null === $generatedParentBox ) {
            return array();
        }

        return array(
            'parent_delta' => $this->delta($sourceParentBox, $generatedParentBox),
        );
    }

    /**
     * @param array<string, mixed> $sourceNode
     * @param array<string, mixed> $generatedNode
     * @param array{x: float, y: float, width: float, height: float} $sourceBox
     * @param array{x: float, y: float, width: float, height: float} $generatedBox
     * @param array{x: float, y: float, width: float, height: float} $delta
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function diagnostic(string $code, array $sourceNode, array $generatedNode, array $sourceBox, array $generatedBox, array $delta, float $threshold, float $sizeThreshold, array $context = array()): array
    {
        return array_filter(array_merge(array(
            'severity' => 'warning',
            'code' => $code,
            'node' => array(
                'id' => (string) ($sourceNode['id'] ?? ''),
                'name' => (string) ($sourceNode['name'] ?? ''),
                'type' => (string) ($sourceNode['type'] ?? ''),
                'parent_id' => (string) ($sourceNode['parent_id'] ?? ''),
            ),
            'source_box' => $sourceBox,
            'generated_box' => $generatedBox,
            'delta' => $delta,
            'max_delta' => max(abs($delta['x']), abs($delta['y']), abs($delta['width']), abs($delta['height'])),
            'threshold' => $threshold,
            'size_threshold' => $sizeThreshold,
            'page_path' => isset($generatedNode['page_path']) && is_scalar($generatedNode['page_path']) ? (string) $generatedNode['page_path'] : null,
            'frame_id' => isset($generatedNode['frame_id']) && is_scalar($generatedNode['frame_id']) ? (string) $generatedNode['frame_id'] : null,
            'selector' => isset($generatedNode['selector']) && is_scalar($generatedNode['selector']) ? (string) $generatedNode['selector'] : null,
        ), $context), static fn (mixed $value): bool => null !== $value);
    }
}
