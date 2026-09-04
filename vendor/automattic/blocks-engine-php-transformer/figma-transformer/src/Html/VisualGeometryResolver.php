<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Resolves visual-box geometry that must stay consistent between emission and diagnostics.
 */
final class VisualGeometryResolver
{
    private const FULL_BLEED_EDGE_TOLERANCE = 4.0;

    public function __construct(
        private readonly LayoutIntentClassifier $layoutIntentClassifier,
    ) {
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    public function isFullyClippedDecorativeChild(array $node, array $parentNode): bool
    {
        $parentLayout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        if ( true !== ($parentLayout['clips_content'] ?? false) || ! $this->layoutIntentClassifier->isClippableDecorativeVisualNode($node) ) {
            return false;
        }

        return null === $this->childVisibleRectInParent($node, $parentNode);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    public function isFullyOffCanvasChild(array $node, array $parentNode): bool
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        if ( 'absolute' === ($layout['positioning'] ?? null) ) {
            return false;
        }

        return null === $this->childVisibleRectInParent($node, $parentNode, true);
    }

    /**
     * @param array<int, mixed> $children
     * @param array<string, mixed> $parentNode
     */
    public function hasOffCanvasChildCluster(array $children, array $parentNode, int $threshold = 2): bool
    {
        $count = 0;
        foreach ( $children as $child ) {
            if ( is_array($child) && $this->isFullyOffCanvasChild($child, $parentNode) ) {
                ++$count;
                if ( $count >= $threshold ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    public function isVisualFullWidthCanvasChild(array $node, array $parentNode, bool $parentIsFreeform): bool
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        if ( 'absolute' !== ($layout['positioning'] ?? null) && ! $parentIsFreeform ) {
            return false;
        }

        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        if ( ! isset($parentBox['width']) || ! is_numeric($parentBox['width']) || (float) $parentBox['width'] <= 0.0 ) {
            return false;
        }

        $rect = $this->childVisualRectInParent($node, $parentNode);
        if ( null === $rect || array() === $rect ) {
            return false;
        }

        $parentWidth = (float) $parentBox['width'];
        return abs((float) $rect['x']) <= self::FULL_BLEED_EDGE_TOLERANCE && abs((float) $rect['width'] - $parentWidth) <= self::FULL_BLEED_EDGE_TOLERANCE;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     * @return array{x: float, y: float, width: float, height: float}|array{}|null
     */
    public function childVisualBoundsInParent(array $node, array $parentNode): ?array
    {
        return $this->childVisualRectInParent($node, $parentNode);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     * @return array<string, mixed>
     */
    public function childVisualBoundsEvidenceInParent(array $node, array $parentNode): array
    {
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $sourceBox = $this->diagnosticBox($box);
        $visualBounds = $this->childVisualBoundsInParent($node, $parentNode);

        return array_filter(array(
            'node_id' => isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : null,
            'parent_id' => isset($parentNode['id']) && is_scalar($parentNode['id']) ? (string) $parentNode['id'] : null,
            'source_box' => $sourceBox,
            'parent_source_box' => $this->diagnosticBox($parentBox),
            'transformed_visual_box' => is_array($visualBounds) && ! empty($visualBounds) ? $this->diagnosticBox($visualBounds) : null,
            'used_transformed_visual_box' => is_array($visualBounds) && isset($visualBounds['y'], $visualBounds['height']) && is_numeric($visualBounds['y']) && is_numeric($visualBounds['height']),
        ), static fn (mixed $value): bool => null !== $value && array() !== $value);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, float>
     */
    public function nodeSourceBoxEvidence(array $node): array
    {
        return $this->diagnosticBox(is_array($node['box'] ?? null) ? $node['box'] : array());
    }

    /**
     * @param array<string, mixed> $node
     */
    public function isHorizontallyReflected(array $node): bool
    {
        $box = is_array($node['figma_box'] ?? null) ? $node['figma_box'] : array();
        $matrix = $this->cssTransformMatrixValues(is_array($box['transform'] ?? null) ? $box['transform'] : null);

        return null !== $matrix && $matrix[0] < -0.00001;
    }

    /**
     * @param array{x: float|int, y: float|int, width: float|int, height: float|int} $rect
     * @param array{x: float|int, y: float|int, width: float|int, height: float|int} $clipRect
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    public function rectIntersection(array $rect, array $clipRect): ?array
    {
        $left = max($rect['x'], $clipRect['x']);
        $top = max($rect['y'], $clipRect['y']);
        $right = min($rect['x'] + $rect['width'], $clipRect['x'] + $clipRect['width']);
        $bottom = min($rect['y'] + $rect['height'], $clipRect['y'] + $clipRect['height']);
        if ( $right <= $left || $bottom <= $top ) {
            return null;
        }

        return array('x' => $left, 'y' => $top, 'width' => $right - $left, 'height' => $bottom - $top);
    }

    /**
     * @return array{x: float, y: float, width: float, height: float}
     */
    public function transformedRect(float $width, float $height, array $matrix): array
    {
        [$a, $b, $c, $d, $e, $f] = $matrix;
        $points = array(array(0.0, 0.0), array($width, 0.0), array(0.0, $height), array($width, $height));
        $xs = array();
        $ys = array();
        foreach ( $points as $point ) {
            [$localX, $localY] = $point;
            $xs[] = ($a * $localX) + ($c * $localY) + $e;
            $ys[] = ($b * $localX) + ($d * $localY) + $f;
        }

        $left = min($xs);
        $top = min($ys);
        $right = max($xs);
        $bottom = max($ys);

        return array('x' => $left, 'y' => $top, 'width' => $right - $left, 'height' => $bottom - $top);
    }

    /**
     * @param array<int|string, mixed>|null $transform
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}|null
     */
    public function cssTransformMatrixValues(?array $transform): ?array
    {
        if ( null === $transform ) {
            return null;
        }

        if ( isset($transform['m00'], $transform['m01'], $transform['m02'], $transform['m10'], $transform['m11'], $transform['m12']) ) {
            if ( 0.00001 > abs((float) $transform['m00'] - 1.0) && 0.00001 > abs((float) $transform['m01']) && 0.00001 > abs((float) $transform['m10']) && 0.00001 > abs((float) $transform['m11'] - 1.0) ) {
                return null;
            }
            $values = array($transform['m00'], $transform['m10'], $transform['m01'], $transform['m11'], 0, 0);
        } elseif ( 2 === count($transform) && is_array($transform[0] ?? null) && is_array($transform[1] ?? null) ) {
            $values = array($transform[0][0] ?? null, $transform[1][0] ?? null, $transform[0][1] ?? null, $transform[1][1] ?? null, $transform[0][2] ?? null, $transform[1][2] ?? null);
        } else {
            return null;
        }

        foreach ( $values as $value ) {
            if ( ! is_numeric($value) ) {
                return null;
            }
        }

        return array_map(static fn (mixed $value): float => (float) $value, $values);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function childVisibleRectInParent(array $node, array $parentNode, bool $requirePositiveParentAndChild = false): ?array
    {
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        if ( ! isset($parentBox['width'], $parentBox['height'], $box['width'], $box['height']) || ! is_numeric($parentBox['width']) || ! is_numeric($parentBox['height']) || ! is_numeric($box['width']) || ! is_numeric($box['height']) ) {
            return array();
        }

        if ( $requirePositiveParentAndChild && ((float) $box['width'] <= 0.0 || (float) $box['height'] <= 0.0 || (float) $parentBox['width'] <= 0.0 || (float) $parentBox['height'] <= 0.0) ) {
            return array();
        }

        $left = $this->layoutIntentClassifier->positionOffset($box, $parentBox, 'x', $parentNode);
        $top = $this->layoutIntentClassifier->positionOffset($box, $parentBox, 'y', $parentNode);
        if ( null === $left || null === $top ) {
            return array();
        }

        $parentRect = array('x' => 0.0, 'y' => 0.0, 'width' => (float) $parentBox['width'], 'height' => (float) $parentBox['height']);
        $childRect = array('x' => $left, 'y' => $top, 'width' => (float) $box['width'], 'height' => (float) $box['height']);

        return $this->rectIntersection($parentRect, $childRect);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     * @return array{x: float, y: float, width: float, height: float}|array{}|null
     */
    private function childVisualRectInParent(array $node, array $parentNode): ?array
    {
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        if ( ! isset($parentBox['width'], $parentBox['height'], $box['width'], $box['height']) || ! is_numeric($parentBox['width']) || ! is_numeric($parentBox['height']) || ! is_numeric($box['width']) || ! is_numeric($box['height']) ) {
            return array();
        }

        $left = $this->layoutIntentClassifier->positionOffset($box, $parentBox, 'x', $parentNode);
        $top = $this->layoutIntentClassifier->positionOffset($box, $parentBox, 'y', $parentNode);
        if ( null === $left || null === $top ) {
            return array();
        }

        $nodeBox = is_array($node['figma_box'] ?? null) ? $node['figma_box'] : array();
        $matrix = $this->cssTransformMatrixValues(is_array($nodeBox['transform'] ?? null) ? $nodeBox['transform'] : null) ?? array(1.0, 0.0, 0.0, 1.0, 0.0, 0.0);
        $translated = array($matrix[0], $matrix[1], $matrix[2], $matrix[3], $matrix[4] + (float) $left, $matrix[5] + (float) $top);

        return $this->transformedRect((float) $box['width'], (float) $box['height'], $translated);
    }

    /**
     * @param array<string, mixed> $box
     * @return array<string, float>
     */
    private function diagnosticBox(array $box): array
    {
        $diagnostic = array();
        foreach ( array('x', 'y', 'width', 'height') as $key ) {
            if ( isset($box[$key]) && is_numeric($box[$key]) ) {
                $diagnostic[$key] = (float) $box[$key];
            }
        }

        return $diagnostic;
    }
}
