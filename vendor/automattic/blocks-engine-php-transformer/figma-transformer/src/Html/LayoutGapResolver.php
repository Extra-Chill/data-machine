<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Resolves normalized auto-layout spacing into CSS-safe flex gap values.
 */
final class LayoutGapResolver
{
    /**
     * @param array<string, mixed> $layout
     * @return array{row: float, column: float}|null
     */
    public function resolve(array $layout): ?array
    {
        $itemSpacing = $layout['item_spacing'] ?? ($layout['gap'] ?? null);
        $mainGap = $this->cssGapValue($itemSpacing);
        if ( null === $mainGap ) {
            return null;
        }

        if ( 'wrap' !== ($layout['flex_wrap'] ?? null) ) {
            return array('row' => $mainGap, 'column' => $mainGap);
        }

        $counterGap = isset($layout['counter_axis_spacing']) ? $this->cssGapValue($layout['counter_axis_spacing']) : $mainGap;
        if ( null === $counterGap ) {
            $counterGap = $mainGap;
        }

        $isColumn = 'column' === ($layout['flex_direction'] ?? null);
        return array(
            'row'    => $isColumn ? $mainGap : $counterGap,
            'column' => $isColumn ? $counterGap : $mainGap,
        );
    }

    private function cssGapValue(mixed $value): ?float
    {
        if ( ! is_numeric($value) ) {
            return null;
        }

        $gap = (float) $value;
        if ( ! is_finite($gap) ) {
            return null;
        }

        return max(0.0, $gap);
    }
}
