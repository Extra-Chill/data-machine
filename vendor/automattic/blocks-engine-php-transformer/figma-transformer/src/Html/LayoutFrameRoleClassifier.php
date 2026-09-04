<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Classifies fixed-canvas frame roles that affect viewport-width emission.
 */
final class LayoutFrameRoleClassifier
{
    public const ROLE_INTRINSIC = 'intrinsic';
    public const ROLE_FULL_BLEED_ROOT = 'full_bleed_root';
    public const ROLE_FULL_BLEED_BAND = 'full_bleed_band';
    public const ROLE_FULL_BLEED_CANVAS_CHILD = 'full_bleed_canvas_child';
    public const ROLE_CENTERED_SHELL = 'centered_shell';

    /**
     * Minimum intrinsic width (px) at which a page frame is treated as a desktop
     * canvas that should fill the viewport instead of preserving fixed width.
     */
    private const FLUID_CANVAS_MIN_WIDTH = 1024.0;

    /**
     * Figma exports frequently include a few pixels of artboard overscan on
     * root-width decorative bands so anti-aliased diagonals cover the edge.
     */
    private const FULL_BLEED_EDGE_TOLERANCE = 4.0;

    /**
     * @param array<string, mixed> $box
     * @param array<string, mixed> $layout
     * @param array<string, mixed>|null $parentNode
     */
    public function frameWidthRole(array $box, array $layout, ?array $parentNode): string
    {
        if ( ! $this->isFluidPageWidth($box, $layout, $parentNode) ) {
            return self::ROLE_INTRINSIC;
        }

        return null === $parentNode ? self::ROLE_FULL_BLEED_ROOT : self::ROLE_FULL_BLEED_BAND;
    }

    /**
     * @param array<string, mixed> $box
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $parentNode
     */
    public function canvasChildRole(array $box, array $layout, array $parentNode, bool $parentUsesFluidCanvasCoordinates, bool $parentIsFreeform): string
    {
        if ( ! $parentUsesFluidCanvasCoordinates ) {
            return self::ROLE_INTRINSIC;
        }

        if ( $this->isAbsoluteFullWidthCanvasChild($box, $layout, $parentNode, $parentIsFreeform) ) {
            return self::ROLE_FULL_BLEED_CANVAS_CHILD;
        }

        if ( $this->isFluidStretchAbsoluteChild($box, $layout, $parentNode, $parentIsFreeform) ) {
            return self::ROLE_CENTERED_SHELL;
        }

        if ( $this->isCenteredCanvasShell($box, $parentNode) ) {
            return self::ROLE_CENTERED_SHELL;
        }

        return self::ROLE_INTRINSIC;
    }

    /**
     * @param array<string, mixed> $layout
     */
    public function roleUsesFlowHeight(string $role, array $layout): bool
    {
        if ( ! in_array($role, array(self::ROLE_FULL_BLEED_ROOT, self::ROLE_FULL_BLEED_BAND, self::ROLE_CENTERED_SHELL), true) ) {
            return false;
        }

        if ( true === ($layout['clips_content'] ?? false) ) {
            return false;
        }

        if ( 'FIXED' === strtoupper((string) ($layout['sizing_vertical'] ?? '')) ) {
            return false;
        }

        return 'flex' === ($layout['display'] ?? null) && 'column' === ($layout['flex_direction'] ?? null);
    }

    /**
     * @param array<string, mixed> $box
     * @param array<string, mixed> $layout
     * @param array<string, mixed>|null $parentNode
     */
    public function isFluidPageWidth(array $box, array $layout, ?array $parentNode): bool
    {
        if ( ! isset($box['width']) || ! is_numeric($box['width']) ) {
            return false;
        }

        $width = (float) $box['width'];
        if ( null === $parentNode ) {
            return $width >= self::FLUID_CANVAS_MIN_WIDTH;
        }

        if ( 'absolute' === ($layout['positioning'] ?? null) ) {
            return false;
        }

        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        if ( ! isset($parentBox['width']) || ! is_numeric($parentBox['width']) || (float) $parentBox['width'] < self::FLUID_CANVAS_MIN_WIDTH ) {
            return false;
        }

        $offset = isset($box['x']) && is_numeric($box['x']) ? abs((float) $box['x']) : 0.0;
        $parentWidth = (float) $parentBox['width'];
        return $offset <= self::FULL_BLEED_EDGE_TOLERANCE && abs($width - $parentWidth) <= self::FULL_BLEED_EDGE_TOLERANCE;
    }

    /**
     * @param array<string, mixed> $box
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $parentNode
     */
    public function isAbsoluteFullWidthCanvasChild(array $box, array $layout, array $parentNode, bool $parentIsFreeform): bool
    {
        if ( 'absolute' !== ($layout['positioning'] ?? null) && ! $parentIsFreeform ) {
            return false;
        }

        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        foreach ( array($box, $parentBox) as $candidateBox ) {
            if ( ! isset($candidateBox['width']) || ! is_numeric($candidateBox['width']) ) {
                return false;
            }
        }

        $offset = isset($box['x']) && is_numeric($box['x']) ? abs((float) $box['x']) : 0.0;
        return $offset <= self::FULL_BLEED_EDGE_TOLERANCE && abs((float) $box['width'] - (float) $parentBox['width']) <= self::FULL_BLEED_EDGE_TOLERANCE;
    }

    /**
     * @param array<string, mixed> $box
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $parentNode
     */
    public function isFluidStretchAbsoluteChild(array $box, array $layout, array $parentNode, bool $parentIsFreeform): bool
    {
        if ( 'absolute' !== ($layout['positioning'] ?? null) && ! $parentIsFreeform ) {
            return false;
        }
        if ( 'FILL' !== strtoupper((string) ($layout['sizing_horizontal'] ?? '')) && (! isset($layout['grow']) || ! is_numeric($layout['grow']) || (float) $layout['grow'] <= 0.0) ) {
            return false;
        }

        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        foreach ( array('x', 'width') as $dimension ) {
            if ( ! isset($box[$dimension]) || ! is_numeric($box[$dimension]) ) {
                return false;
            }
        }
        if ( ! isset($parentBox['width']) || ! is_numeric($parentBox['width']) || (float) $parentBox['width'] < self::FLUID_CANVAS_MIN_WIDTH ) {
            return false;
        }

        $trailing = (float) $parentBox['width'] - (float) $box['x'] - (float) $box['width'];
        return $trailing >= -0.5;
    }

    /**
     * @param array<string, mixed> $box
     * @param array<string, mixed> $parentNode
     */
    public function isCenteredCanvasShell(array $box, array $parentNode): bool
    {
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        foreach ( array('x', 'width') as $dimension ) {
            if ( ! isset($box[$dimension]) || ! is_numeric($box[$dimension]) ) {
                return false;
            }
        }
        if ( ! isset($parentBox['width']) || ! is_numeric($parentBox['width']) ) {
            return false;
        }

        $parentWidth = (float) $parentBox['width'];
        $width = (float) $box['width'];
        if ( $parentWidth < self::FLUID_CANVAS_MIN_WIDTH || $width <= 0.0 || $width >= $parentWidth - 1.0 ) {
            return false;
        }

        if ( $this->isSymmetricPaddedContentShell($box, $parentNode, $parentWidth, $width) ) {
            return true;
        }

        $offset = (float) $box['x'];
        if ( $offset < -0.5 || $offset + $width > $parentWidth + 0.5 ) {
            return false;
        }

        $trailing = $parentWidth - $offset - $width;
        return abs($offset - $trailing) <= 1.0;
    }

    /**
     * @param array<string, mixed> $box
     * @param array<string, mixed> $parentNode
     */
    private function isSymmetricPaddedContentShell(array $box, array $parentNode, float $parentWidth, float $width): bool
    {
        $layout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        $padding = is_array($layout['padding'] ?? null) ? $layout['padding'] : array();
        if ( ! isset($padding['left'], $padding['right']) || ! is_numeric($padding['left']) || ! is_numeric($padding['right']) ) {
            return false;
        }

        $left = (float) $padding['left'];
        $right = (float) $padding['right'];
        if ( $left < 0.0 || $right < 0.0 || abs($left - $right) > 1.0 ) {
            return false;
        }

        $offset = (float) $box['x'];
        $contentWidth = $parentWidth - $left - $right;
        return abs($offset) <= 1.0 && $contentWidth > 0.0 && abs($width - $contentWidth) <= 1.0;
    }
}
