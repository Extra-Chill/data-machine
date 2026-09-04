<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Diagnostics;

/**
 * Compares source Figma/emitted styles with runner-supplied browser computed styles.
 */
final class RenderStyleMismatchReportBuilder
{
    public const SCHEMA = 'blocks-engine/figma-transformer/render-style-mismatch-report/v1';
    public const RENDER_EVIDENCE_SCHEMA = 'homeboy/static-artifact-render-evidence/v1';

    /**
     * @param array<string, mixed> $htmlSourceReport
     * @param array<string, mixed> $evidence
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function build(array $htmlSourceReport, array $evidence = array(), array $options = array()): array
    {
        $limit = max(1, (int) $this->numericOption($options, 'limit', 100.0));
        $sourceNodes = $this->sourceNodesById($htmlSourceReport);
        $pagePath = isset($options['page_path']) && is_scalar($options['page_path']) ? (string) $options['page_path'] : null;
        $renderNodes = $this->renderNodesById($evidence, $pagePath);
        $diagnostics = array();
        $matched = 0;
        $unmatchedSource = 0;

        foreach ( $sourceNodes as $nodeId => $sourceNode ) {
            $renderNode = $renderNodes[$nodeId] ?? null;
            if ( null === $renderNode ) {
                $unmatchedSource++;
                continue;
            }

            $computed = $this->computedStyle($renderNode);
            if ( empty($computed) ) {
                $unmatchedSource++;
                continue;
            }

            $matched++;
            foreach ( $this->styleComparisons($sourceNode, $computed) as $comparison ) {
                if ( $comparison['matches'] ) {
                    continue;
                }

                $diagnostics[] = array(
                    'severity' => 'warning',
                    'code' => 'render_style_mismatch',
                    'category' => $comparison['category'],
                    'property' => $comparison['property'],
                    'node' => $this->diagnosticNode($sourceNode, $renderNode),
                    'expected' => $comparison['expected'],
                    'computed' => $comparison['computed'],
                );
            }
        }

        usort(
            $diagnostics,
            static fn (array $left, array $right): int => strcmp((string) ($left['category'] ?? ''), (string) ($right['category'] ?? '')) ?: strcmp((string) ($left['node']['id'] ?? ''), (string) ($right['node']['id'] ?? ''))
        );

        $totalDiagnostics = $diagnostics;
        $diagnostics = array_slice($totalDiagnostics, 0, $limit);

        return array(
            'schema' => self::SCHEMA,
            'input_schema' => isset($evidence['schema']) && is_scalar($evidence['schema']) ? (string) $evidence['schema'] : self::RENDER_EVIDENCE_SCHEMA,
            'status' => empty($renderNodes) ? 'not_run' : (empty($totalDiagnostics) ? 'pass' : 'fail'),
            'summary' => $this->summary($sourceNodes, $renderNodes, $matched, $unmatchedSource, $totalDiagnostics, $diagnostics, $pagePath),
            'diagnostics' => $diagnostics,
        );
    }

    /**
     * @param array<string, array<string, mixed>> $sourceNodes
     * @param array<string, array<string, mixed>> $renderNodes
     * @param array<int, array<string, mixed>> $totalDiagnostics
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, mixed>
     */
    private function summary(array $sourceNodes, array $renderNodes, int $matched, int $unmatchedSource, array $totalDiagnostics, array $diagnostics, ?string $pagePath): array
    {
        $categoryCounts = $this->categoryCounts($totalDiagnostics);
        $sourceCount = count($sourceNodes);

        return array(
            'source_node_count' => $sourceCount,
            'render_node_count' => count($renderNodes),
            'matched_node_count' => $matched,
            'unmatched_source_node_count' => $unmatchedSource,
            'match_ratio' => $sourceCount > 0 ? round($matched / $sourceCount, 4) : 0.0,
            'page_path' => $pagePath,
            'diagnostic_count' => count($totalDiagnostics),
            'reported_diagnostic_count' => count($diagnostics),
            'truncated' => count($diagnostics) < count($totalDiagnostics),
            'font_mismatch_count' => (int) ($categoryCounts['font'] ?? 0),
            'color_mismatch_count' => (int) ($categoryCounts['color'] ?? 0),
            'background_mismatch_count' => (int) ($categoryCounts['background'] ?? 0),
            'border_mismatch_count' => (int) ($categoryCounts['border'] ?? 0),
            'opacity_mismatch_count' => (int) ($categoryCounts['opacity'] ?? 0),
            'asset_mismatch_count' => (int) ($categoryCounts['asset'] ?? 0),
            'text_metric_mismatch_count' => (int) ($categoryCounts['text_metric'] ?? 0),
            'category_counts' => $categoryCounts,
        );
    }

    /**
     * @param array<string, mixed> $htmlSourceReport
     * @return array<string, array<string, mixed>>
     */
    private function sourceNodesById(array $htmlSourceReport): array
    {
        $nodes = array();
        foreach ( is_array($htmlSourceReport['node_style_diagnostics'] ?? null) ? $htmlSourceReport['node_style_diagnostics'] : array() as $diagnostic ) {
            if ( ! is_array($diagnostic) || ! is_array($diagnostic['node'] ?? null) ) {
                continue;
            }

            $id = $this->nodeId($diagnostic['node']);
            if ( '' === $id ) {
                continue;
            }

            $diagnostic['node']['id'] = $id;
            $nodes[$id] = $diagnostic;
        }

        foreach ( is_array($htmlSourceReport['visual_node_map'] ?? null) ? $htmlSourceReport['visual_node_map'] : array() as $visualNode ) {
            if ( ! is_array($visualNode) ) {
                continue;
            }

            $id = $this->nodeId($visualNode);
            if ( '' === $id || isset($nodes[$id]) ) {
                continue;
            }

            $nodes[$id] = array('node' => $visualNode, 'expected' => array(), 'emitted' => array());
        }

        ksort($nodes);
        return $nodes;
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, array<string, mixed>>
     */
    private function renderNodesById(array $evidence, ?string $pagePath): array
    {
        $nodes = array();
        foreach ( array('elements', 'nodes', 'render_nodes') as $key ) {
            if ( is_array($evidence[$key] ?? null) ) {
                $nodes = array_merge($nodes, $this->nodesForPage($evidence[$key], $pagePath));
            }
        }

        foreach ( is_array($evidence['entrypoints'] ?? null) ? $evidence['entrypoints'] : array() as $entrypoint ) {
            if ( is_array($entrypoint) && is_array($entrypoint['elements'] ?? null) ) {
                $entrypointPagePath = isset($entrypoint['page_path']) && is_scalar($entrypoint['page_path']) ? (string) $entrypoint['page_path'] : null;
                if ( null !== $pagePath && null !== $entrypointPagePath && $this->normalizePagePath($pagePath) !== $this->normalizePagePath($entrypointPagePath) ) {
                    continue;
                }
                $nodes = array_merge($nodes, $this->nodesForPage($entrypoint['elements'], $pagePath, $entrypointPagePath));
            }
        }

        if ( empty($nodes) && is_array($evidence['generated']['elements'] ?? null) ) {
            $nodes = $this->nodesForPage($evidence['generated']['elements'], $pagePath);
        }

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
     * @param array<int, mixed> $nodes
     * @return array<int, mixed>
     */
    private function nodesForPage(array $nodes, ?string $pagePath, ?string $fallbackPagePath = null): array
    {
        if ( null === $pagePath ) {
            return $nodes;
        }

        $filtered = array();
        foreach ( $nodes as $node ) {
            if ( ! is_array($node) ) {
                continue;
            }
            $nodePagePath = isset($node['page_path']) && is_scalar($node['page_path']) ? (string) $node['page_path'] : $fallbackPagePath;
            if ( null !== $nodePagePath && $this->normalizePagePath($nodePagePath) !== $this->normalizePagePath($pagePath) ) {
                continue;
            }
            $node['page_path'] = $nodePagePath ?? $pagePath;
            $filtered[] = $node;
        }

        return $filtered;
    }

    private function normalizePagePath(string $path): string
    {
        return ltrim(parse_url($path, PHP_URL_PATH) ?: $path, '/');
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
     * @return array<string, mixed>
     */
    private function computedStyle(array $node): array
    {
        foreach ( array('computed_style', 'computedStyle', 'style', 'styles') as $key ) {
            if ( is_array($node[$key] ?? null) ) {
                return $node[$key];
            }
        }

        return array();
    }

    /**
     * @param array<string, mixed> $sourceNode
     * @param array<string, mixed> $computed
     * @return array<int, array{category:string,property:string,expected:string,computed:string,matches:bool}>
     */
    private function styleComparisons(array $sourceNode, array $computed): array
    {
        $expected = is_array($sourceNode['expected'] ?? null) ? $sourceNode['expected'] : array();
        $emitted = is_array($sourceNode['emitted'] ?? null) ? $sourceNode['emitted'] : array();
        $source = array_merge($emitted, array_filter($expected, static fn (mixed $value): bool => null !== $value && '' !== $value));
        $comparisons = array();

        foreach ( array(
            'font_family' => array('font', 'font-family'),
            'font_weight' => array('font', 'font-weight'),
            'text_color' => array('color', 'color'),
            'background' => array('background', 'background-color'),
            'border_color' => array('border', 'border-color'),
            'border_width' => array('border', 'border-width'),
            'opacity' => array('opacity', 'opacity'),
            'background_image' => array('asset', 'background-image'),
            'font_size' => array('text_metric', 'font-size'),
            'line_height' => array('text_metric', 'line-height'),
            'letter_spacing' => array('text_metric', 'letter-spacing'),
        ) as $sourceKey => $mapping ) {
            $expectedValue = $this->sourceStyleValue($source, $sourceKey);
            $computedValue = $this->computedStyleValue($computed, $mapping[1]);
            if ( null === $expectedValue || null === $computedValue ) {
                continue;
            }

            $comparisons[] = array(
                'category' => $mapping[0],
                'property' => $mapping[1],
                'expected' => $expectedValue,
                'computed' => $computedValue,
                'matches' => $this->styleValuesMatch($mapping[0], $mapping[1], $expectedValue, $computedValue, $source, $computed),
            );
        }

        return $comparisons;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function sourceStyleValue(array $source, string $key): ?string
    {
        if ( ! isset($source[$key]) || ! is_scalar($source[$key]) ) {
            return null;
        }

        $value = trim((string) $source[$key]);
        return '' === $value ? null : $value;
    }

    /**
     * @param array<string, mixed> $computed
     */
    private function computedStyleValue(array $computed, string $property): ?string
    {
        foreach ( array($property, str_replace('-', '_', $property)) as $key ) {
            if ( isset($computed[$key]) && is_scalar($computed[$key]) ) {
                $value = trim((string) $computed[$key]);
                return '' === $value ? null : $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $computedStyle
     */
    private function styleValuesMatch(string $category, string $property, string $expected, string $computed, array $source = array(), array $computedStyle = array()): bool
    {
        if ( in_array($category, array('color', 'background', 'border'), true) ) {
            return $this->normalizeColor($expected) === $this->normalizeColor($computed);
        }

        if ( 'font-family' === $property ) {
            return $this->normalizeFontFamily($expected) === $this->normalizeFontFamily($computed);
        }

        if ( 'line-height' === $property && $this->isUnitlessCssNumber($expected) && str_ends_with(strtolower(trim($computed)), 'px') ) {
            $expectedNumber = $this->numberFromCssValue($expected);
            $computedNumber = $this->numberFromCssValue($computed);
            $fontSize = $this->lineHeightFontSize($source, $computedStyle);
            if ( null !== $expectedNumber && null !== $computedNumber && null !== $fontSize ) {
                return abs(($expectedNumber * $fontSize) - $computedNumber) <= 0.5;
            }
        }

        if ( in_array($property, array('font-size', 'line-height', 'letter-spacing', 'border-width', 'opacity'), true) ) {
            $expectedNumber = $this->numberFromCssValue($expected);
            $computedNumber = $this->numberFromCssValue($computed);
            if ( null !== $expectedNumber && null !== $computedNumber ) {
                return abs($expectedNumber - $computedNumber) <= 0.5;
            }
        }

        return $this->normalizeCssToken($expected) === $this->normalizeCssToken($computed);
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $computedStyle
     */
    private function lineHeightFontSize(array $source, array $computedStyle): ?float
    {
        $computedFontSize = $this->computedStyleValue($computedStyle, 'font-size');
        $computedNumber = null === $computedFontSize ? null : $this->numberFromCssValue($computedFontSize);
        if ( null !== $computedNumber ) {
            return $computedNumber;
        }

        $sourceFontSize = $this->sourceStyleValue($source, 'font_size');
        return null === $sourceFontSize ? null : $this->numberFromCssValue($sourceFontSize);
    }

    private function isUnitlessCssNumber(string $value): bool
    {
        return 1 === preg_match('/^-?\d+(?:\.\d+)?$/', trim($value));
    }

    private function normalizeFontFamily(string $value): string
    {
        $families = explode(',', $value);
        $primary = trim($families[0] ?? '', " \t\n\r\0\x0B\"'");
        return strtolower($primary);
    }

    private function normalizeColor(string $value): string
    {
        $value = strtolower(trim($value));
        if ( preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $value) ) {
            if ( 4 === strlen($value) ) {
                return '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
            }

            return $value;
        }

        if ( preg_match('/^rgba?\(([^)]+)\)$/', $value, $matches) ) {
            $parts = array_map('trim', explode(',', $matches[1]));
            if ( count($parts) >= 3 ) {
                return sprintf('#%02x%02x%02x', max(0, min(255, (int) round((float) $parts[0]))), max(0, min(255, (int) round((float) $parts[1]))), max(0, min(255, (int) round((float) $parts[2]))));
            }
        }

        return $this->normalizeCssToken($value);
    }

    private function numberFromCssValue(string $value): ?float
    {
        return preg_match('/-?\d+(?:\.\d+)?/', $value, $matches) ? (float) $matches[0] : null;
    }

    private function normalizeCssToken(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value, " \t\n\r\0\x0B\"'"));
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, int>
     */
    private function categoryCounts(array $diagnostics): array
    {
        $counts = array();
        foreach ( $diagnostics as $diagnostic ) {
            $category = isset($diagnostic['category']) && is_scalar($diagnostic['category']) ? (string) $diagnostic['category'] : '';
            if ( '' !== $category ) {
                $counts[$category] = ($counts[$category] ?? 0) + 1;
            }
        }

        ksort($counts);
        return $counts;
    }

    /**
     * @param array<string, mixed> $sourceNode
     * @param array<string, mixed> $renderNode
     * @return array<string, mixed>
     */
    private function diagnosticNode(array $sourceNode, array $renderNode): array
    {
        $node = is_array($sourceNode['node'] ?? null) ? $sourceNode['node'] : $sourceNode;
        return array_filter(array(
            'id' => $this->nodeId($node),
            'name' => isset($node['name']) && is_scalar($node['name']) ? (string) $node['name'] : null,
            'type' => isset($node['type']) && is_scalar($node['type']) ? (string) $node['type'] : null,
            'selector' => isset($renderNode['selector']) && is_scalar($renderNode['selector']) ? (string) $renderNode['selector'] : null,
        ), static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function numericOption(array $values, string $key, float $default): float
    {
        return isset($values[$key]) && is_numeric($values[$key]) ? max(0.0, (float) $values[$key]) : $default;
    }
}
