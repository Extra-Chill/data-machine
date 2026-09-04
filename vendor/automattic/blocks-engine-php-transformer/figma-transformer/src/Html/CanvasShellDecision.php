<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Captures the viewport-canvas role decisions for a single emitted node.
 */
final class CanvasShellDecision
{
    public function __construct(
        public readonly string $frameWidthRole,
        public readonly string $canvasChildRole,
        public readonly bool $parentRendersFluidCanvas,
        public readonly bool $parentUsesFluidCanvasCoordinates,
        public readonly bool $fullBleedCanvasChild,
        public readonly bool $centeredWithinParentFluidCanvas,
        public readonly bool $responsiveCenteredFlowShell,
        public readonly bool $fluidStretchCanvasChild,
        public readonly bool $responsiveCenteredFlowWidth,
        public readonly bool $fullBleedCanvasChildReflected = false,
    ) {
    }
}
