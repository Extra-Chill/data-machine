<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Captures why a node leaves normal flow and which declarations came from that boundary.
 */
final class AbsolutePositioningDecision
{
    /**
     * @param array<int, string> $declarations
     */
    public function __construct(
        public readonly string $reasonCode,
        public readonly array $declarations,
        public readonly bool $suppressedFullBleedHorizontalOffsets,
    ) {
    }
}
