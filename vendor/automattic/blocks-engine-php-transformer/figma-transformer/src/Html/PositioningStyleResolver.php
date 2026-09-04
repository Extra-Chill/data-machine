<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Resolves node positioning, shell-centering, and local stacking declarations.
 */
final class PositioningStyleResolver
{
    /**
     * @param callable(array<string, mixed>): bool $isFreeformContainer
     * @param callable(array<string, mixed>): bool $freeformContainerShouldUseFlow
     * @param callable(array<string, mixed>, array<string, mixed>): bool $isDecorativeFlexUnderlay
     * @param callable(array<string, mixed>): bool $hasDecorativeFlexUnderlayChild
     */
    public function __construct(
        private readonly LayoutIntentClassifier $layoutIntentClassifier,
        private readonly CssPositioningResolver $cssPositioningResolver,
        private readonly CanvasShellResolver $canvasShellResolver,
        private readonly mixed $isFreeformContainer,
        private readonly mixed $freeformContainerShouldUseFlow,
        private readonly mixed $isDecorativeFlexUnderlay,
        private readonly mixed $hasDecorativeFlexUnderlayChild,
    ) {
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $box
     * @param array<string, mixed> $layout
     * @param array<string, mixed>|null $parentNode
     * @param array<int, string> $declaredStyles
     */
    public function resolve(array $node, string $type, ?array $parentNode, array $box, array $layout, CanvasShellDecision $canvasShell, array $declaredStyles): PositioningStyleDecision
    {
        $styles = array();
        $isDecorativeFlexUnderlay = null !== $parentNode && $this->isDecorativeFlexUnderlay($node, $parentNode);
        $parentFreeformUsesFlow = null !== $parentNode && $this->freeformContainerShouldUseFlow($parentNode);
        $willPositionAbsolute = (null !== $parentNode && $this->isFreeformContainer($parentNode) && ! $parentFreeformUsesFlow) || ('absolute' === ($layout['positioning'] ?? null) && ! $parentFreeformUsesFlow) || $isDecorativeFlexUnderlay;
        $stackingContextPlan = $this->layoutIntentClassifier->stackingContextPlan($node, $parentNode);
        $effectiveZIndex = isset($stackingContextPlan['z_index']) && is_int($stackingContextPlan['z_index']) ? $stackingContextPlan['z_index'] : null;
        $zIndexReason = isset($stackingContextPlan['z_index_reason']) && is_string($stackingContextPlan['z_index_reason']) ? $stackingContextPlan['z_index_reason'] : null;

        if ( $canvasShell->responsiveCenteredFlowShell && ! $willPositionAbsolute ) {
            $styles[] = 'margin-left:auto';
            $styles[] = 'margin-right:auto';
        }
        if ( ! $willPositionAbsolute && (true === ($stackingContextPlan['manages_local_stacking'] ?? false) || ($parentFreeformUsesFlow && 'FRAME' === $type)) ) {
            $styles[] = 'position:relative';
        }

        if ( true === ($stackingContextPlan['needs_isolation'] ?? false) ) {
            $styles[] = 'isolation:isolate';
        }

        $absolutePositioningDecision = $this->absolutePositioningDecision($node, $parentNode, $box, $layout, $canvasShell, $isDecorativeFlexUnderlay, $parentFreeformUsesFlow);
        if ( null !== $absolutePositioningDecision ) {
            foreach ( $absolutePositioningDecision->declarations as $style ) {
                $styles[] = $style;
            }
        }

        if ( $isDecorativeFlexUnderlay ) {
            if ( null !== $effectiveZIndex && ! $this->stylesDeclareProperty(array_merge($declaredStyles, $styles), 'z-index') ) {
                $styles[] = 'z-index:' . (string) $effectiveZIndex;
            }
            $styles[] = 'pointer-events:none';
        }

        if ( null !== $parentNode && ! $willPositionAbsolute && null === $effectiveZIndex && $this->hasDecorativeFlexUnderlayChild($parentNode) ) {
            $styles[] = 'position:relative';
            $styles[] = 'z-index:1';
        }

        if ( null !== $effectiveZIndex && ! $willPositionAbsolute && ! $this->stylesDeclareProperty(array_merge($declaredStyles, $styles), 'position') ) {
            $styles[] = 'position:relative';
        }

        if ( null !== $effectiveZIndex && ! $this->stylesDeclareProperty(array_merge($declaredStyles, $styles), 'z-index') ) {
            $styles[] = 'z-index:' . (string) $effectiveZIndex;
        }

        return new PositioningStyleDecision($styles, $willPositionAbsolute, $isDecorativeFlexUnderlay, $zIndexReason, $absolutePositioningDecision);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, mixed> $box
     * @param array<string, mixed> $layout
     */
    private function absolutePositioningDecision(array $node, ?array $parentNode, array $box, array $layout, CanvasShellDecision $canvasShell, bool $isDecorativeFlexUnderlay, bool $parentFreeformUsesFlow): ?AbsolutePositioningDecision
    {
        $reasonCode = '';
        if ( $isDecorativeFlexUnderlay ) {
            $reasonCode = 'decorative_flex_underlay_absolute';
        } elseif ( null !== $parentNode && $this->isFreeformContainer($parentNode) && ! $parentFreeformUsesFlow ) {
            $reasonCode = 'freeform_parent_absolute_child';
        } elseif ( 'absolute' === ($layout['positioning'] ?? null) && ! $parentFreeformUsesFlow ) {
            $reasonCode = 'explicit_absolute_positioning';
        }

        if ( '' === $reasonCode ) {
            return null;
        }

        $declarations = array('position:absolute');
        $suppressedFullBleedHorizontalOffsets = false;
        foreach ( $this->cssPositioningResolver->styles($box, $layout, $parentNode, $node, $canvasShell->centeredWithinParentFluidCanvas) as $style ) {
            if ( $canvasShell->fullBleedCanvasChild && $this->isHorizontalOffsetStyle($style) ) {
                $suppressedFullBleedHorizontalOffsets = true;
                continue;
            }
            $declarations[] = $style;
        }
        foreach ( $this->canvasShellResolver->fullBleedViewportBreakoutDecision($canvasShell)['declarations'] as $style ) {
            $declarations[] = $style;
        }

        return new AbsolutePositioningDecision($reasonCode, $declarations, $suppressedFullBleedHorizontalOffsets);
    }

    /**
     * @param array<int, string> $styles
     */
    private function stylesDeclareProperty(array $styles, string $property): bool
    {
        $prefix = $property . ':';
        foreach ( $styles as $style ) {
            if ( str_starts_with($style, $prefix) ) {
                return true;
            }
        }

        return false;
    }

    private function isHorizontalOffsetStyle(string $style): bool
    {
        return str_starts_with($style, 'left:') || str_starts_with($style, 'right:') || str_starts_with($style, 'margin-left:') || str_starts_with($style, 'margin-right:');
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isFreeformContainer(array $node): bool
    {
        return ($this->isFreeformContainer)($node);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function freeformContainerShouldUseFlow(array $node): bool
    {
        return ($this->freeformContainerShouldUseFlow)($node);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isDecorativeFlexUnderlay(array $node, array $parentNode): bool
    {
        return ($this->isDecorativeFlexUnderlay)($node, $parentNode);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasDecorativeFlexUnderlayChild(array $node): bool
    {
        return ($this->hasDecorativeFlexUnderlayChild)($node);
    }
}
