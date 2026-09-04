<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Captures positioning styles and downstream style-emission facts for one node.
 */
final class PositioningStyleDecision
{
    /**
     * @param array<int, string> $styles
     */
    public function __construct(
        public readonly array $styles,
        public readonly bool $willPositionAbsolute,
        public readonly bool $isDecorativeFlexUnderlay,
        public readonly ?string $zIndexReasonCode = null,
        public readonly ?AbsolutePositioningDecision $absolutePositioningDecision = null,
    ) {
    }
}
