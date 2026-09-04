<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Resolves CSS declarations for nodes that leave normal flow.
 */
final class CssPositioningResolver
{
    /**
     * @param callable(float): string $numberFormatter
     */
    public function __construct(
        private readonly LayoutIntentClassifier $layoutIntentClassifier,
        private readonly mixed $numberFormatter,
    ) {
    }

    /**
     * @param array<string, mixed> $box
     * @param array<string, mixed> $layout
     * @param array<string, mixed>|null $parentNode
     * @param array<string, mixed>|null $node
     * @return array<int, string>
     */
    public function styles(array $box, array $layout, ?array $parentNode, ?array $node = null, bool $centerWithinFluidCanvas = false): array
    {
        $styles = array();
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        $parentLayout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        $parentIsFreeform = true === ($parentLayout['freeform'] ?? false);
        $offsets = $this->effectiveOffsets($box, $parentNode, $node);
        $left = $offsets['x'];
        $top = $offsets['y'];
        $centerInsetVisualChild = null !== $node && null !== $parentNode && $this->isInsetSingleVisualChild($node, $parentNode);
        $constraints = is_array($layout['constraints'] ?? null) ? $layout['constraints'] : array();
        if ( $centerInsetVisualChild ) {
            $left = $this->centeredInsetOffset($box, $parentBox, 'width');
            $top = $this->centeredInsetOffset($box, $parentBox, 'height');
            $constraints['horizontal'] = 'CENTER';
            $constraints['vertical'] = 'CENTER';
        }
        if ( null !== $node && $this->hasComponentCloneGeometry($node) && 'local' === ($box['coordinate_space'] ?? null) && 'absolute' !== ($layout['positioning'] ?? null) ) {
            unset($constraints['horizontal'], $constraints['vertical']);
        }

        foreach ( $this->axisConstraintStyles('horizontal', is_scalar($constraints['horizontal'] ?? null) ? (string) $constraints['horizontal'] : null, $left, $parentBox, $box, $layout, $parentNode, $node, $centerWithinFluidCanvas, $parentIsFreeform) as $style ) {
            $styles[] = $style;
        }
        foreach ( $this->axisConstraintStyles('vertical', is_scalar($constraints['vertical'] ?? null) ? (string) $constraints['vertical'] : null, $top, $parentBox, $box, $layout, $parentNode, $node, false, $parentIsFreeform) as $style ) {
            $styles[] = $style;
        }

        return $styles;
    }

    /**
     * Resolve the near-edge offsets CSS will use before constraint-specific
     * anchoring. Diagnostics consume this so component clone/source geometry is
     * judged against emitted placement, not stale source coordinates.
     *
     * @param array<string, mixed> $box
     * @param array<string, mixed>|null $parentNode
     * @param array<string, mixed>|null $node
     * @return array{x: float|null, y: float|null}
     */
    public function effectiveOffsets(array $box, ?array $parentNode, ?array $node = null): array
    {
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        $left = $this->layoutIntentClassifier->positionOffset($box, $parentBox, 'x', $parentNode);
        $top = $this->layoutIntentClassifier->positionOffset($box, $parentBox, 'y', $parentNode);

        if ( null !== $node ) {
            $left = $this->componentSourceCloneScalarOffset($node, $box, $parentBox, 'x', $left);
            $top = $this->componentSourceCloneScalarOffset($node, $box, $parentBox, 'y', $top);
        }
        if ( null !== $node && $this->hasComponentCloneGeometry($node) ) {
            $left = $this->componentCloneSourceOffset($node, $box, $parentBox, 'x', $left);
            $top = $this->componentCloneSourceOffset($node, $box, $parentBox, 'y', $top);
        }

        return array('x' => $left, 'y' => $top);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isInsetSingleVisualChild(array $node, array $parentNode): bool
    {
        $children = $this->nodeList($parentNode);
        if ( 1 !== count($children) || ! is_array($children[0]) || (string) ($children[0]['id'] ?? '') !== (string) ($node['id'] ?? '') ) {
            return false;
        }

        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( ! in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE'), true) ) {
            return false;
        }

        $parentLayout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        if ( ! empty($parentLayout['display'] ?? null) ) {
            return false;
        }

        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        foreach ( array('width', 'height') as $dimension ) {
            if ( ! isset($parentBox[$dimension], $box[$dimension]) || ! is_numeric($parentBox[$dimension]) || ! is_numeric($box[$dimension]) ) {
                return false;
            }
        }

        $left = $this->layoutIntentClassifier->positionOffset($box, $parentBox, 'x', $parentNode);
        $top = $this->layoutIntentClassifier->positionOffset($box, $parentBox, 'y', $parentNode);
        if ( (null !== $left && abs($left) > 0.5) || (null !== $top && abs($top) > 0.5) ) {
            return false;
        }

        $widthDelta = (float) $parentBox['width'] - (float) $box['width'];
        $heightDelta = (float) $parentBox['height'] - (float) $box['height'];
        return ($widthDelta > 0.5 && $widthDelta <= 32.0) || ($heightDelta > 0.5 && $heightDelta <= 32.0);
    }

    /**
     * @param array<string, mixed> $box
     * @param array<string, mixed> $parentBox
     */
    private function centeredInsetOffset(array $box, array $parentBox, string $dimension): ?float
    {
        if ( ! isset($box[$dimension], $parentBox[$dimension]) || ! is_numeric($box[$dimension]) || ! is_numeric($parentBox[$dimension]) ) {
            return null;
        }

        return ((float) $parentBox[$dimension] - (float) $box[$dimension]) / 2.0;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, mixed>
     */
    private function nodeList(array $node): array
    {
        $children = $node['children'] ?? array();
        return is_array($children) && array_is_list($children) ? $children : array();
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasComponentCloneGeometry(array $node): bool
    {
        if ( true === ($node['_component_source_clone_geometry'] ?? false) ) {
            return true;
        }

        foreach ( array('box', 'figma_box') as $boxKey ) {
            $box = is_array($node[$boxKey] ?? null) ? $node[$boxKey] : array();
            if ( 'component_source_clone' === ($box['geometry_semantics'] ?? null) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $box
     * @param array<string, mixed> $parentBox
     */
    private function componentSourceCloneScalarOffset(array $node, array $box, array $parentBox, string $dimension, ?float $offset): ?float
    {
        if ( null === $offset || ! isset($node['figma_component_source_id']) || ! is_scalar($node['figma_component_source_id']) || '' === (string) $node['figma_component_source_id'] ) {
            return $offset;
        }

        if ( 'page' !== ($box['local_origin'] ?? null) || ! isset($node[$dimension]) || ! is_numeric($node[$dimension]) ) {
            return $offset;
        }

        $scalar = (float) $node[$dimension];
        $sizeKey = 'x' === $dimension ? 'width' : 'height';
        if ( isset($parentBox[$sizeKey], $box[$sizeKey]) && is_numeric($parentBox[$sizeKey]) && is_numeric($box[$sizeKey]) ) {
            $parentSize = (float) $parentBox[$sizeKey];
            $boxSize = (float) $box[$sizeKey];
            if ( $parentSize > 0.0 && $boxSize > 0.0 ) {
                $offsetFitsParent = $offset >= -0.5 && $offset + $boxSize <= $parentSize + 0.5;
                $scalarFitsParent = $scalar >= -0.5 && $scalar + $boxSize <= $parentSize + 0.5;
                if ( $offsetFitsParent || ! $scalarFitsParent ) {
                    return $offset;
                }
            }
        }

        return abs($offset - $scalar) > 0.5 ? $scalar : $offset;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $box
     * @param array<string, mixed> $parentBox
     */
    private function componentCloneSourceOffset(array $node, array $box, array $parentBox, string $dimension, ?float $offset): ?float
    {
        if ( null === $offset ) {
            return null;
        }

        if ( 'local' === ($box['coordinate_space'] ?? null) ) {
            return $offset;
        }

        $sizeKey = 'x' === $dimension ? 'width' : 'height';
        if ( ! isset($parentBox[$sizeKey], $box[$sizeKey]) || ! is_numeric($parentBox[$sizeKey]) || ! is_numeric($box[$sizeKey]) ) {
            return $offset;
        }

        $parentSize = (float) $parentBox[$sizeKey];
        $boxSize = (float) $box[$sizeKey];
        if ( $parentSize <= 0.0 || $boxSize <= 0.0 || ($offset >= -0.5 && $offset + $boxSize <= $parentSize + 0.5) ) {
            return $offset;
        }

        $sourceBox = is_array($node['_component_source_clone_source_box'] ?? null) ? $node['_component_source_clone_source_box'] : array();
        if ( isset($sourceBox[$dimension]) && is_numeric($sourceBox[$dimension]) ) {
            return (float) $sourceBox[$dimension];
        }

        return 0.0;
    }

    /**
     * Resolve the absolute-position CSS for a single axis from its Figma pin
     * constraint. The near edge (left/top) is the default; LEFT_RIGHT/TOP_BOTTOM
     * pin both edges, RIGHT/BOTTOM pin only the far edge, and CENTER holds a fixed
     * offset from the parent center without relying on `transform` (which the
     * emitter reserves for the node's own matrix). SCALE is percentage-based and
     * has no clean pixel translation, so it falls back to the deterministic near
     * pin instead of emitting a wrong guess.
     *
     * @param array<string, mixed> $parentBox
     * @param array<string, mixed> $box
     * @param array<string, mixed> $layout
     * @return array<int, string>
     */
    private function axisConstraintStyles(string $axis, ?string $constraint, ?float $offset, array $parentBox, array $box, array $layout, ?array $parentNode, ?array $node, bool $centerWithinFluidCanvas = false, bool $parentIsFreeform = false): array
    {
        $isHorizontal = 'horizontal' === $axis;
        $startProp = $isHorizontal ? 'left' : 'top';
        $endProp = $isHorizontal ? 'right' : 'bottom';
        $sizeKey = $isHorizontal ? 'width' : 'height';
        $bothPin = $isHorizontal ? 'LEFT_RIGHT' : 'TOP_BOTTOM';
        $farPin = $isHorizontal ? 'RIGHT' : 'BOTTOM';
        $parentSize = isset($parentBox[$sizeKey]) && is_numeric($parentBox[$sizeKey]) ? (float) $parentBox[$sizeKey] : null;
        $boxSize = isset($box[$sizeKey]) && is_numeric($box[$sizeKey]) ? (float) $box[$sizeKey] : null;
        $constraint = null === $constraint ? null : strtoupper($constraint);

        $styles = array();

        if ( null !== $offset && null !== $parentSize && null !== $boxSize && $this->shouldUseProportionalOverlayOffset($axis, $constraint, $parentBox, $box, $parentNode, $node, $parentIsFreeform) ) {
            $percent = $this->number(( $offset / $parentSize ) * 100.0) . '%';
            $trailing = $parentSize - $offset - $boxSize;
            $trailingPercent = $this->number(( max(0.0, $trailing) / $parentSize ) * 100.0) . '%';

            if ( $farPin === $constraint ) {
                $styles[] = $endProp . ':' . $trailingPercent;
                return $styles;
            }

            $styles[] = $startProp . ':' . $percent;
            if ( $bothPin === $constraint && $trailing >= -0.5 ) {
                $styles[] = $endProp . ':' . $trailingPercent;
            }
            return $styles;
        }

        // Far-edge-only pin (REST RIGHT/BOTTOM, Kiwi MAX): anchor to the trailing
        // edge and drop the leading offset so the node stays glued on resize.
        if ( $farPin === $constraint && null !== $offset && null !== $parentSize && null !== $boxSize ) {
            $styles[] = $endProp . ':' . $this->number($parentSize - $offset - $boxSize) . 'px';
            return $styles;
        }

        if ( $isHorizontal && $parentIsFreeform && $centerWithinFluidCanvas && null !== $offset && null !== $parentSize && null !== $boxSize && $this->hasFluidStretchIntent($layout) ) {
            $trailing = $parentSize - $offset - $boxSize;
            if ( $trailing >= -0.5 ) {
                $styles[] = $startProp . ':' . $this->number(max(0.0, $offset)) . 'px';
                $styles[] = $endProp . ':' . $this->number(max(0.0, $trailing)) . 'px';
                return $styles;
            }
        }

        if ( $isHorizontal && null !== $offset && null !== $parentSize && null !== $boxSize && $this->parentIsHeaderChrome($parentNode ?? null) ) {
            $trailing = $parentSize - $offset - $boxSize;
            if ( $trailing >= -0.5 && $trailing <= 64.0 && $offset > 64.0 ) {
                if ( $boxSize >= $parentSize * 0.5 ) {
                    $styles[] = $startProp . ':' . $this->number($offset) . 'px';
                    $styles[] = $endProp . ':' . $this->number(max(0.0, $trailing)) . 'px';
                    $styles[] = $sizeKey . ':auto';
                    return $styles;
                }

                $styles[] = $endProp . ':' . $this->number(max(0.0, $trailing)) . 'px';
                return $styles;
            }
        }

        // Center pin: keep the child center at a constant offset from the parent
        // center. Emit the leading edge directly so node transforms remain free.
        if ( 'CENTER' === $constraint && null !== $offset && null !== $parentSize ) {
            $halfBoxSize = null !== $boxSize ? $boxSize / 2.0 : 0.0;
            $centerDelta = $offset + $halfBoxSize - ( $parentSize / 2.0 );
            $leadingDelta = $centerDelta - $halfBoxSize;
            $sign = $leadingDelta < 0 ? '-' : '+';
            $styles[] = $startProp . ':calc(50% ' . $sign . ' ' . $this->number(abs($leadingDelta)) . 'px)';
            return $styles;
        }

        if ( $isHorizontal && $centerWithinFluidCanvas && null !== $offset && null !== $parentSize && null !== $boxSize && $this->hasSymmetricFluidCanvasGutters($offset, $parentSize, $boxSize) && ! in_array($constraint, array($farPin, 'CENTER'), true) ) {
            $leadingDelta = $offset - ( $parentSize / 2.0 );
            $sign = $leadingDelta < 0 ? '-' : '+';
            $styles[] = $startProp . ':calc(50% ' . $sign . ' ' . $this->number(abs($leadingDelta)) . 'px)';
            return $styles;
        }

        // Near-edge pin (LEFT/TOP/default, also SCALE fallback) plus an optional
        // far-edge pin for the both-side stretch constraint.
        if ( null !== $offset ) {
            $styles[] = $startProp . ':' . $this->number($offset) . 'px';
        }
        if ( $bothPin === $constraint && null !== $offset && null !== $parentSize && null !== $boxSize ) {
            $styles[] = $endProp . ':' . $this->number($parentSize - $offset - $boxSize) . 'px';
        }

        return $styles;
    }

    private function hasSymmetricFluidCanvasGutters(float $offset, float $parentSize, float $boxSize): bool
    {
        if ( $offset < -0.5 || $offset + $boxSize > $parentSize + 0.5 ) {
            return false;
        }

        $trailing = $parentSize - $offset - $boxSize;
        return abs($offset - $trailing) <= 1.0;
    }

    /**
     * @param array<string, mixed> $parentBox
     * @param array<string, mixed> $box
     * @param array<string, mixed>|null $parentNode
     * @param array<string, mixed>|null $node
     */
    private function shouldUseProportionalOverlayOffset(string $axis, ?string $constraint, array $parentBox, array $box, ?array $parentNode, ?array $node, bool $parentIsFreeform): bool
    {
        if ( null === $parentNode || null === $node || ! $parentIsFreeform || ! $this->isFluidMediaContainer($parentNode) || ! $this->isOverlayLikeChild($node, $box, $parentBox) ) {
            return false;
        }

        $constraint = null === $constraint ? null : strtoupper($constraint);
        $allowed = 'horizontal' === $axis ? array(null, 'LEFT', 'RIGHT', 'LEFT_RIGHT', 'SCALE') : array(null, 'TOP', 'BOTTOM', 'TOP_BOTTOM', 'SCALE');
        return in_array($constraint, $allowed, true);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isFluidMediaContainer(array $node): bool
    {
        $name = strtolower((string) ($node['name'] ?? ''));
        if ( 1 === preg_match('/\b(map|image|photo|picture|media)\b/', $name) ) {
            return true;
        }

        $parentBox = is_array($node['box'] ?? null) ? $node['box'] : array();
        $parentArea = $this->area($parentBox);
        if ( $parentArea <= 0.0 ) {
            return false;
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( ! is_array($child) || ! $this->isMediaSurfaceNode($child) ) {
                continue;
            }

            $childBox = is_array($child['box'] ?? null) ? $child['box'] : $child;
            if ( $this->area($childBox) >= $parentArea * 0.5 ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $box
     * @param array<string, mixed> $parentBox
     */
    private function isOverlayLikeChild(array $node, array $box, array $parentBox): bool
    {
        if ( $this->isImageBackedNode($node) ) {
            return false;
        }

        $parentArea = $this->area($parentBox);
        $childArea = $this->area($box);
        if ( $parentArea <= 0.0 || $childArea <= 0.0 ) {
            return false;
        }

        return $childArea <= $parentArea * 0.35;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isMediaSurfaceNode(array $node): bool
    {
        if ( $this->isImageBackedNode($node) ) {
            return true;
        }

        $name = strtolower((string) ($node['name'] ?? ''));
        return 1 === preg_match('/\b(map|image|photo|picture|media)\b/', $name);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isImageBackedNode(array $node): bool
    {
        if ( isset($node['asset_id']) || isset($node['image_hash']) || isset($node['imageHash']) ) {
            return true;
        }

        foreach ( array('fillPaints', 'fills') as $paintKey ) {
            $paints = is_array($node[$paintKey] ?? null) ? $node[$paintKey] : array();
            foreach ( $paints as $paint ) {
                if ( is_array($paint) && 'IMAGE' === strtoupper((string) ($paint['type'] ?? '')) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $box
     */
    private function area(array $box): float
    {
        if ( ! isset($box['width'], $box['height']) || ! is_numeric($box['width']) || ! is_numeric($box['height']) ) {
            return 0.0;
        }

        return max(0.0, (float) $box['width']) * max(0.0, (float) $box['height']);
    }

    /**
     * @param array<string, mixed> $layout
     */
    private function hasFluidStretchIntent(array $layout): bool
    {
        return isset($layout['grow']) && is_numeric($layout['grow']) && (float) $layout['grow'] > 0.0;
    }

    private function number(float $value): string
    {
        return ($this->numberFormatter)($value);
    }

    /**
     * @param array<string, mixed>|null $parentNode
     */
    private function parentIsHeaderChrome(?array $parentNode): bool
    {
        if ( null === $parentNode ) {
            return false;
        }

        $name = strtolower(trim((string) ($parentNode['name'] ?? '')));
        return LayoutIntentClassifier::CHROME_GROUP_ROLE_HEADER === $this->layoutIntentClassifier->chromeGroupRole($parentNode, null, 1)
            || 'header' === $name
            || str_contains($name, 'top bar');
    }
}
