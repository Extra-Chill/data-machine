<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Builds focused CSS declaration groups for emitted Figma nodes.
 */
final class StyleDeclarationBuilder
{
    /**
     * @param callable(float): string $numberFormatter
     * @param callable(array<int, mixed>): array{css: string, gradient: bool}|null $firstCssPaint
     * @param callable(mixed, mixed=): ?string $color
     */
    public function __construct(
        private readonly mixed $numberFormatter,
        private readonly mixed $firstCssPaint,
        private readonly mixed $color,
    ) {
    }

    /**
     * @param array<string, mixed> $box
     * @return array<int, string>
     */
    public function radiusStyles(array $box): array
    {
        if ( isset($box['corner_radius']) && is_numeric($box['corner_radius']) ) {
            return array('border-radius:' . $this->number((float) $box['corner_radius']) . 'px');
        }

        $styles = array();
        foreach ( array(
            'top_left_radius' => 'border-top-left-radius',
            'top_right_radius' => 'border-top-right-radius',
            'bottom_right_radius' => 'border-bottom-right-radius',
            'bottom_left_radius' => 'border-bottom-left-radius',
        ) as $sourceKey => $property ) {
            if ( isset($box[$sourceKey]) && is_numeric($box[$sourceKey]) ) {
                $styles[] = $property . ':' . $this->number((float) $box[$sourceKey]) . 'px';
            }
        }

        return $styles;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    public function strokeStyles(array $node): array
    {
        $paints = is_array($node['figma_paints']['strokes'] ?? null) ? $node['figma_paints']['strokes'] : array();
        $stroke = $this->firstCssPaint($paints);
        if ( null === $stroke ) {
            return array();
        }

        $width = 1.0;
        if ( isset($node['strokeWeight']) && is_numeric($node['strokeWeight']) ) {
            $width = (float) $node['strokeWeight'];
        }

        $align     = strtoupper((string) ($node['strokeAlign'] ?? ''));
        $sideWidths = $this->strokeSideWidths($node, $width);
        $dashed    = $this->hasDashPattern($node);
        // CSS borders can't reproduce an exact Figma dash array (border-style only
        // exposes the `dashed` keyword), so a non-empty dashPattern degrades to a
        // dashed border. Precise dash lengths would need an SVG/background stroke,
        // which is out of scope here.
        $lineStyle = $dashed ? 'dashed' : 'solid';

        if ( true === $stroke['gradient'] ) {
            return array(
                'border:' . $this->number($width) . 'px ' . $lineStyle . ' transparent',
                'border-image:' . $stroke['css'] . ' 1',
            );
        }

        // Outside strokes don't grow the layout box; the emitter renders them as an
        // outset box-shadow ring at the real weight. box-shadow can't express dashes
        // or per-side weights, so those fall through to the border path below.
        if ( 'OUTSIDE' === $align && null === $sideWidths && ! $dashed ) {
            return array('box-shadow:0 0 0 ' . $this->number($width) . 'px ' . $stroke['css']);
        }

        if ( null !== $sideWidths || $dashed ) {
            $styles = array();
            if ( null !== $sideWidths ) {
                foreach ( $sideWidths as $side => $sideWidth ) {
                    $styles[] = 'border-' . $side . '-width:' . $this->number($sideWidth) . 'px';
                }
            } else {
                $styles[] = 'border-width:' . $this->number($width) . 'px';
            }
            $styles[] = 'border-style:' . $lineStyle;
            $styles[] = 'border-color:' . $stroke['css'];
            // Inside strokes are drawn within the node bounds; keep the border from
            // expanding the box so the design's dimensions stay intact.
            if ( 'INSIDE' === $align ) {
                $styles[] = 'box-sizing:border-box';
            }

            return $styles;
        }

        $styles = array('border:' . $this->number($width) . 'px solid ' . $stroke['css']);
        if ( 'INSIDE' === $align ) {
            $styles[] = 'box-sizing:border-box';
        }

        return $styles;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    public function effectStyles(array $node, string $type): array
    {
        $effects = is_array($node['figma_effects'] ?? null) ? $node['figma_effects'] : array();
        $boxShadows = array();
        $textShadows = array();
        $filters = array();
        $backdropFilters = array();

        foreach ( $effects as $effect ) {
            if ( ! is_array($effect) ) {
                continue;
            }

            $effectType = (string) ($effect['type'] ?? '');
            if ( in_array($effectType, array('drop_shadow', 'inner_shadow'), true) ) {
                if ( 'drop_shadow' === $effectType && $this->shouldEmitSilhouetteDropShadowFilter($effect, $node, $type) ) {
                    $filters[] = $this->dropShadowFilterValue($effect);
                    continue;
                }

                $shadow = 'TEXT' === $type && 'drop_shadow' === $effectType
                    ? $this->textShadowValue($effect)
                    : $this->shadowValue($effect, 'inner_shadow' === $effectType);
                if ( null === $shadow ) {
                    continue;
                }
                if ( 'TEXT' === $type && 'drop_shadow' === $effectType ) {
                    $textShadows[] = $shadow;
                } else {
                    $boxShadows[] = $shadow;
                }
                continue;
            }

            if ( 'layer_blur' === $effectType && isset($effect['radius']) && is_numeric($effect['radius']) ) {
                $filters[] = 'blur(' . $this->number((float) $effect['radius']) . 'px)';
            } elseif ( 'background_blur' === $effectType && isset($effect['radius']) && is_numeric($effect['radius']) ) {
                $backdropFilters[] = 'blur(' . $this->number((float) $effect['radius']) . 'px)';
            }
        }

        $styles = array();
        if ( ! empty($boxShadows) ) {
            $styles[] = 'box-shadow:' . implode(',', $boxShadows);
        }
        if ( ! empty($textShadows) ) {
            $styles[] = 'text-shadow:' . implode(',', $textShadows);
        }
        if ( ! empty($filters) ) {
            $styles[] = 'filter:' . implode(' ', $filters);
        }
        if ( ! empty($backdropFilters) ) {
            $styles[] = 'backdrop-filter:' . implode(' ', $backdropFilters);
        }

        return $styles;
    }

    /**
     * Resolve per-side stroke widths when a node carries independent border
     * weights. Returns null when the stroke is uniform so callers use the
     * single-weight border path.
     *
     * @param array<string, mixed> $node
     * @return array<string, float>|null
     */
    private function strokeSideWidths(array $node, float $fallback): ?array
    {
        $sides = array(
            'top'    => array('borderTopWeight', 'strokeTopWeight'),
            'right'  => array('borderRightWeight', 'strokeRightWeight'),
            'bottom' => array('borderBottomWeight', 'strokeBottomWeight'),
            'left'   => array('borderLeftWeight', 'strokeLeftWeight'),
        );

        $widths = array();
        $found  = false;
        foreach ( $sides as $side => $keys ) {
            $value = null;
            foreach ( $keys as $key ) {
                if ( isset($node[$key]) && is_numeric($node[$key]) ) {
                    $value = (float) $node[$key];
                    break;
                }
            }
            if ( null !== $value ) {
                $found = true;
            }
            $widths[$side] = null !== $value ? $value : $fallback;
        }

        return $found ? $widths : null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasDashPattern(array $node): bool
    {
        $pattern = $node['dashPattern'] ?? null;
        if ( ! is_array($pattern) ) {
            return false;
        }

        foreach ( $pattern as $value ) {
            if ( is_numeric($value) && (float) $value > 0 ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $effect
     */
    private function shadowValue(array $effect, bool $inset): ?string
    {
        $color = $this->effectColor($effect);
        if ( null === $color ) {
            $color = 'rgba(0,0,0,0.25)';
        }

        return ( $inset ? 'inset ' : '' )
            . $this->number((float) ($effect['offset_x'] ?? 0)) . 'px '
            . $this->number((float) ($effect['offset_y'] ?? 0)) . 'px '
            . $this->number((float) ($effect['radius'] ?? 0)) . 'px '
            . $this->number((float) ($effect['spread'] ?? 0)) . 'px '
            . $color;
    }

    /**
     * @param array<string, mixed> $effect
     */
    private function textShadowValue(array $effect): ?string
    {
        $color = $this->effectColor($effect);
        if ( null === $color ) {
            $color = 'rgba(0,0,0,0.25)';
        }

        return $this->number((float) ($effect['offset_x'] ?? 0)) . 'px '
            . $this->number((float) ($effect['offset_y'] ?? 0)) . 'px '
            . $this->number((float) ($effect['radius'] ?? 0)) . 'px '
            . $color;
    }

    /**
     * @param array<string, mixed> $effect
     */
    private function shouldEmitSilhouetteDropShadowFilter(array $effect, array $node, string $type): bool
    {
        $imagePaints = array_filter(
            is_array($node['figma_paints']['fills'] ?? null) ? $node['figma_paints']['fills'] : array(),
            static fn (mixed $paint): bool => is_array($paint) && 'IMAGE' === strtoupper((string) ($paint['type'] ?? ''))
        );
        if ( empty($imagePaints) && ! in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'STAR', 'POLYGON', 'REGULAR_POLYGON'), true) ) {
            return false;
        }

        return abs((float) ($effect['spread'] ?? 0)) < 0.0001;
    }

    /**
     * @param array<string, mixed> $effect
     */
    private function dropShadowFilterValue(array $effect): string
    {
        $color = $this->effectColor($effect);
        if ( null === $color ) {
            $color = 'rgba(0,0,0,0.25)';
        }

        return 'drop-shadow('
            . $this->number((float) ($effect['offset_x'] ?? 0)) . 'px '
            . $this->number((float) ($effect['offset_y'] ?? 0)) . 'px '
            . $this->number((float) ($effect['radius'] ?? 0)) . 'px '
            . $color
            . ')';
    }

    /**
     * @param array<string, mixed> $effect
     */
    private function effectColor(array $effect): ?string
    {
        $color = $effect['color'] ?? null;
        $opacity = $effect['opacity'] ?? null;
        if ( is_numeric($opacity) && is_array($color) && isset($color['a']) && is_numeric($color['a']) ) {
            $opacity = (float) $opacity * (float) $color['a'];
        }

        return $this->color($color, $opacity);
    }

    /**
     * @param array<int, mixed> $paints
     * @return array{css: string, gradient: bool}|null
     */
    private function firstCssPaint(array $paints): ?array
    {
        return ($this->firstCssPaint)($paints);
    }

    private function color(mixed $value, mixed $opacity = null): ?string
    {
        return ($this->color)($value, $opacity);
    }

    private function number(float $value): string
    {
        return ($this->numberFormatter)($value);
    }
}
