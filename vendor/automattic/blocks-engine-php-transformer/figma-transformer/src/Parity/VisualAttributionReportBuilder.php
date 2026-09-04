<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Parity;

/**
 * Attributes screenshot pixel differences to reported Figma node rectangles.
 */
final class VisualAttributionReportBuilder
{
    public const SCHEMA = 'blocks-engine/figma-transformer/visual-attribution/v1';

    /**
     * @param array<string, mixed> $transformResult
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function build(array $transformResult, string $sourceImagePath, string $generatedImagePath, array $options = array()): array
    {
        if ( ! function_exists('imagecreatefrompng') ) {
            return $this->errorReport('gd_png_unavailable', 'The GD PNG extension is required for visual attribution.');
        }

        $sourceImage = is_readable($sourceImagePath) ? imagecreatefrompng($sourceImagePath) : false;
        if ( false === $sourceImage ) {
            return $this->errorReport('source_image_unreadable', 'Source screenshot could not be read.', array('path' => $sourceImagePath));
        }

        $generatedImage = is_readable($generatedImagePath) ? imagecreatefrompng($generatedImagePath) : false;
        if ( false === $generatedImage ) {
            return $this->errorReport('generated_image_unreadable', 'Generated screenshot could not be read.', array('path' => $generatedImagePath));
        }

        $threshold = isset($options['threshold']) && is_numeric($options['threshold']) ? (int) $options['threshold'] : 24;
        $materialThreshold = isset($options['material_threshold']) && is_numeric($options['material_threshold']) ? (int) $options['material_threshold'] : 96;
        $severeThreshold = isset($options['severe_threshold']) && is_numeric($options['severe_threshold']) ? (int) $options['severe_threshold'] : 192;
        $rankBy = isset($options['rank_by']) && is_scalar($options['rank_by']) ? (string) $options['rank_by'] : 'material_delta_score';
        $limit = isset($options['limit']) && is_numeric($options['limit']) ? max(1, (int) $options['limit']) : 25;
        $viewportWidth = min(imagesx($sourceImage), imagesx($generatedImage));
        $viewportHeight = min(imagesy($sourceImage), imagesy($generatedImage));
        $diagnostics = $this->nodeStyleDiagnostics($transformResult);
        $visualNodesById = $this->visualNodesById($transformResult);
        $nodes = array();
        $unattributed = 0;

        foreach ( $diagnostics as $diagnostic ) {
            $node = is_array($diagnostic['node'] ?? null) ? $diagnostic['node'] : array();
            $emitted = is_array($diagnostic['emitted'] ?? null) ? $diagnostic['emitted'] : array();
            $expected = is_array($diagnostic['expected'] ?? null) ? $diagnostic['expected'] : array();
            $nodeId = (string) ($node['id'] ?? '');
            $visualNode = $visualNodesById[$nodeId] ?? null;
            $rect = is_array($visualNode) ? $this->rectFromVisualNode($visualNode, $viewportWidth, $viewportHeight) : null;
            if ( null === $rect ) {
                $rect = $this->rectFromStyleData($emitted, $viewportWidth, $viewportHeight);
            }
            if ( null === $rect ) {
                $unattributed++;
                continue;
            }

            $stats = $this->diffStatsForRect($sourceImage, $generatedImage, $rect, $threshold, $materialThreshold, $severeThreshold);
            if ( 0 === $stats['area_pixels'] ) {
                $unattributed++;
                continue;
            }

            $nodes[] = array(
                'node' => array(
                    'id' => $nodeId,
                    'name' => (string) ($node['name'] ?? ''),
                    'type' => (string) ($node['type'] ?? ''),
                    'class' => (string) ($node['class'] ?? ''),
                ),
                'rect' => $rect,
                'features' => $this->features($expected, $emitted, is_array($visualNode) ? $visualNode : array()),
                'mismatches' => is_array($diagnostic['mismatches'] ?? null) ? array_values($diagnostic['mismatches']) : array(),
                'diff' => $stats,
            );
        }

        usort(
            $nodes,
            static fn (array $left, array $right): int => ($right['diff'][$rankBy] ?? 0) <=> ($left['diff'][$rankBy] ?? 0)
        );
        $leafNodes = $this->leafNodes($nodes, $visualNodesById);

        return array(
            'schema' => self::SCHEMA,
            'status' => 'success',
            'inputs' => array(
                'source_image_path' => $sourceImagePath,
                'generated_image_path' => $generatedImagePath,
                'threshold' => $threshold,
                'material_threshold' => $materialThreshold,
                'severe_threshold' => $severeThreshold,
                'rank_by' => $rankBy,
            ),
            'viewport' => array(
                'width' => $viewportWidth,
                'height' => $viewportHeight,
            ),
            'coverage' => array(
                'diagnostic_node_count' => count($diagnostics),
                'attributed_node_count' => count($nodes),
                'leaf_node_count' => count($leafNodes),
                'unattributed_node_count' => $unattributed,
                'coverage_ratio' => 0 === count($diagnostics) ? 0 : count($nodes) / count($diagnostics),
            ),
            'totals' => $this->diffTotals($nodes),
            'top_nodes' => array_slice($nodes, 0, $limit),
            'top_leaf_nodes' => array_slice($leafNodes, 0, $limit),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, array<string, mixed>> $visualNodesById
     * @return array<int, array<string, mixed>>
     */
    private function leafNodes(array $nodes, array $visualNodesById): array
    {
        $parentIds = array();
        foreach ( $visualNodesById as $visualNode ) {
            if ( is_scalar($visualNode['parent_id'] ?? null) && '' !== (string) $visualNode['parent_id'] ) {
                $parentIds[(string) $visualNode['parent_id']] = true;
            }
        }

        return array_values(array_filter(
            $nodes,
            static fn (array $node): bool => ! isset($parentIds[(string) ($node['node']['id'] ?? '')])
        ));
    }

    /**
     * @param array<string, mixed> $transformResult
     * @return array<int, array<string, mixed>>
     */
    private function nodeStyleDiagnostics(array $transformResult): array
    {
        $diagnostics = $transformResult['source_reports']['figma']['html']['node_style_diagnostics'] ?? array();
        return is_array($diagnostics) ? array_values(array_filter($diagnostics, 'is_array')) : array();
    }

    /**
     * @param array<string, mixed> $transformResult
     * @return array<string, array<string, mixed>>
     */
    private function visualNodesById(array $transformResult): array
    {
        $visualNodes = $transformResult['source_reports']['figma']['html']['visual_node_map'] ?? array();
        $byId = array();
        if ( ! is_array($visualNodes) ) {
            return $byId;
        }

        foreach ( $visualNodes as $visualNode ) {
            if ( ! is_array($visualNode) || ! is_scalar($visualNode['id'] ?? null) ) {
                continue;
            }
            $byId[(string) $visualNode['id']] = $visualNode;
        }

        return $byId;
    }

    /**
     * @param array<string, mixed> $visualNode
     * @return array{x:int,y:int,width:int,height:int}|null
     */
    private function rectFromVisualNode(array $visualNode, int $viewportWidth, int $viewportHeight): ?array
    {
        $rect = is_array($visualNode['rect'] ?? null) ? $visualNode['rect'] : array();
        foreach ( array('x', 'y', 'width', 'height') as $key ) {
            if ( ! isset($rect[$key]) || ! is_numeric($rect[$key]) ) {
                return null;
            }
        }

        $left = max(0, (int) floor((float) $rect['x']));
        $top = max(0, (int) floor((float) $rect['y']));
        $right = min($viewportWidth, (int) ceil((float) $rect['x'] + (float) $rect['width']));
        $bottom = min($viewportHeight, (int) ceil((float) $rect['y'] + (float) $rect['height']));
        if ( $right <= $left || $bottom <= $top ) {
            return null;
        }

        return array(
            'x' => $left,
            'y' => $top,
            'width' => $right - $left,
            'height' => $bottom - $top,
        );
    }

    /**
     * @param array<string, mixed> $styleData
     * @return array{x:int,y:int,width:int,height:int}|null
     */
    private function rectFromStyleData(array $styleData, int $viewportWidth, int $viewportHeight): ?array
    {
        $width = $this->cssPixels($styleData['width'] ?? null);
        $height = $this->cssPixels($styleData['height'] ?? null);
        if ( null === $width || null === $height || $width <= 0 || $height <= 0 ) {
            return null;
        }

        $x = $this->cssPixels($styleData['x'] ?? null) ?? 0.0;
        $y = $this->cssPixels($styleData['y'] ?? null) ?? 0.0;
        $left = max(0, (int) floor($x));
        $top = max(0, (int) floor($y));
        $right = min($viewportWidth, (int) ceil($x + $width));
        $bottom = min($viewportHeight, (int) ceil($y + $height));
        if ( $right <= $left || $bottom <= $top ) {
            return null;
        }

        return array(
            'x' => $left,
            'y' => $top,
            'width' => $right - $left,
            'height' => $bottom - $top,
        );
    }

    private function cssPixels(mixed $value): ?float
    {
        if ( is_numeric($value) ) {
            return (float) $value;
        }
        if ( ! is_scalar($value) ) {
            return null;
        }
        $value = trim((string) $value);
        if ( preg_match('/^-?\d+(?:\.\d+)?px$/', $value) ) {
            return (float) substr($value, 0, -2);
        }

        return null;
    }

    /**
     * @param resource|\GdImage $sourceImage
     * @param resource|\GdImage $generatedImage
     * @param array{x:int,y:int,width:int,height:int} $rect
     * @return array<string, mixed>
     */
    private function diffStatsForRect(mixed $sourceImage, mixed $generatedImage, array $rect, int $threshold, int $materialThreshold, int $severeThreshold): array
    {
        $mismatch = 0;
        $materialMismatch = 0;
        $severeMismatch = 0;
        $materialDeltaScore = 0;
        $bucketGt24 = 0;
        $bucketGt48 = 0;
        $bucketGt96 = 0;
        $bucketGt192 = 0;
        $sum = 0;
        $max = 0;
        $area = $rect['width'] * $rect['height'];

        for ( $y = $rect['y']; $y < $rect['y'] + $rect['height']; $y++ ) {
            for ( $x = $rect['x']; $x < $rect['x'] + $rect['width']; $x++ ) {
                $sourceColor = imagecolorat($sourceImage, $x, $y);
                $generatedColor = imagecolorat($generatedImage, $x, $y);
                $delta = abs((($sourceColor >> 16) & 255) - (($generatedColor >> 16) & 255))
                    + abs((($sourceColor >> 8) & 255) - (($generatedColor >> 8) & 255))
                    + abs(($sourceColor & 255) - ($generatedColor & 255));
                $sum += $delta;
                $max = max($max, $delta);
                if ( $delta > $threshold ) {
                    $mismatch++;
                }
                if ( $delta > $materialThreshold ) {
                    $materialMismatch++;
                    $materialDeltaScore += $delta - $threshold;
                }
                if ( $delta > $severeThreshold ) {
                    $severeMismatch++;
                }
                if ( $delta > 24 ) {
                    $bucketGt24++;
                }
                if ( $delta > 48 ) {
                    $bucketGt48++;
                }
                if ( $delta > 96 ) {
                    $bucketGt96++;
                }
                if ( $delta > 192 ) {
                    $bucketGt192++;
                }
            }
        }

        return array(
            'area_pixels' => $area,
            'mismatch_pixels' => $mismatch,
            'mismatch_ratio' => 0 === $area ? 0 : $mismatch / $area,
            'material_mismatch_pixels' => $materialMismatch,
            'material_mismatch_ratio' => 0 === $area ? 0 : $materialMismatch / $area,
            'severe_mismatch_pixels' => $severeMismatch,
            'severe_mismatch_ratio' => 0 === $area ? 0 : $severeMismatch / $area,
            'mean_rgb_sum_delta' => 0 === $area ? 0 : $sum / $area,
            'material_delta_score' => $materialDeltaScore,
            'mean_material_delta' => 0 === $area ? 0 : $materialDeltaScore / $area,
            'max_rgb_sum_delta' => $max,
            'severity_buckets' => array(
                'gt24' => $bucketGt24,
                'gt48' => $bucketGt48,
                'gt96' => $bucketGt96,
                'gt192' => $bucketGt192,
            ),
            'thresholds' => array(
                'noise' => $threshold,
                'material' => $materialThreshold,
                'severe' => $severeThreshold,
            ),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array<string, mixed>
     */
    private function diffTotals(array $nodes): array
    {
        $totals = array(
            'mismatch_pixels' => 0,
            'material_mismatch_pixels' => 0,
            'severe_mismatch_pixels' => 0,
            'material_delta_score' => 0,
            'severity_buckets' => array(
                'gt24' => 0,
                'gt48' => 0,
                'gt96' => 0,
                'gt192' => 0,
            ),
        );

        foreach ( $nodes as $node ) {
            $diff = is_array($node['diff'] ?? null) ? $node['diff'] : array();
            foreach ( array('mismatch_pixels', 'material_mismatch_pixels', 'severe_mismatch_pixels', 'material_delta_score') as $key ) {
                if ( isset($diff[$key]) && is_numeric($diff[$key]) ) {
                    $totals[$key] += (int) $diff[$key];
                }
            }
            $buckets = is_array($diff['severity_buckets'] ?? null) ? $diff['severity_buckets'] : array();
            foreach ( array('gt24', 'gt48', 'gt96', 'gt192') as $key ) {
                if ( isset($buckets[$key]) && is_numeric($buckets[$key]) ) {
                    $totals['severity_buckets'][$key] += (int) $buckets[$key];
                }
            }
        }

        return $totals;
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $emitted
     * @return array<int, string>
     */
    private function features(array $expected, array $emitted, array $visualNode = array()): array
    {
        $features = array();
        $image = is_array($visualNode['image'] ?? null) ? $visualNode['image'] : array();
        if ( ! empty($image) ) {
            $features[] = 'image';
            if ( isset($image['scale_mode']) && is_scalar($image['scale_mode']) ) {
                $features[] = 'image-' . strtolower((string) $image['scale_mode']);
            }
            if ( true === ($image['has_transform'] ?? false) ) {
                $features[] = 'image-transform';
            }
            if ( true === ($image['color_managed'] ?? false) ) {
                $features[] = 'image-color-managed';
            }
        }
        $text = is_array($visualNode['text'] ?? null) ? $visualNode['text'] : array();
        if ( isset($expected['font_family']) || isset($emitted['font_family']) ) {
            $features[] = 'text';
            if ( true === ($text['has_derived_layout'] ?? false) ) {
                $features[] = 'text-derived-layout';
            }
            if ( isset($text['baseline_count']) && is_numeric($text['baseline_count']) && 1 < (int) $text['baseline_count'] ) {
                $features[] = 'text-multiline-derived';
            }
            if ( isset($text['glyph_count']) && is_numeric($text['glyph_count']) && 0 < (int) $text['glyph_count'] ) {
                $features[] = 'text-glyph-metadata';
            }
        }
        if ( isset($expected['background']) || isset($emitted['background']) ) {
            $features[] = 'background';
        }
        if ( isset($expected['x']) || isset($expected['y']) || isset($emitted['x']) || isset($emitted['y']) ) {
            $features[] = 'positioned';
        }
        if ( empty($features) ) {
            $features[] = 'layout';
        }

        return $features;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function errorReport(string $code, string $message, array $context = array()): array
    {
        return array(
            'schema' => self::SCHEMA,
            'status' => 'error',
            'diagnostics' => array(
                array(
                    'severity' => 'error',
                    'code' => $code,
                    'message' => $message,
                    'context' => $context,
                ),
            ),
        );
    }
}
