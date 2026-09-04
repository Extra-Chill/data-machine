<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Decides when Figma content clipping can be represented as CSS overflow hidden.
 */
final class EffectOverflowPolicy
{
    /**
     * @param array<string, mixed> $node
     */
    public function shouldHideOverflow(array $node, bool $containsStickyPrimary): bool
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();

        return true === ($layout['clips_content'] ?? false) && ! $containsStickyPrimary && ! $this->clipsVisibleEffectOverflow($node);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function clipsVisibleEffectOverflow(array $node): bool
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        foreach ( $this->nodeList($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }
            if ( $this->nodeEffectOverflowAmount($child) <= 0.0 ) {
                if ( $this->clipsVisibleEffectOverflow($child) ) {
                    return true;
                }
                continue;
            }

            $childBox = is_array($child['box'] ?? null) ? $child['box'] : array();
            $left = isset($childBox['x']) && is_numeric($childBox['x']) ? (float) $childBox['x'] : 0.0;
            $top = isset($childBox['y']) && is_numeric($childBox['y']) ? (float) $childBox['y'] : 0.0;
            $width = isset($childBox['width']) && is_numeric($childBox['width']) ? (float) $childBox['width'] : 0.0;
            $height = isset($childBox['height']) && is_numeric($childBox['height']) ? (float) $childBox['height'] : 0.0;
            $parentWidth = isset($box['width']) && is_numeric($box['width']) ? (float) $box['width'] : null;
            $parentHeight = isset($box['height']) && is_numeric($box['height']) ? (float) $box['height'] : null;

            if ( $left <= 0.0 || $top <= 0.0 || (null !== $parentWidth && $left + $width >= $parentWidth) || (null !== $parentHeight && $top + $height >= $parentHeight) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeEffectOverflowAmount(array $node): float
    {
        $amount = 0.0;
        foreach ( is_array($node['figma_effects'] ?? null) ? $node['figma_effects'] : array() as $effect ) {
            if ( ! is_array($effect) || false === ($effect['visible'] ?? true) ) {
                continue;
            }
            $type = (string) ($effect['type'] ?? '');
            if ( ! in_array($type, array('drop_shadow', 'layer_blur'), true) ) {
                continue;
            }
            $radius = isset($effect['radius']) && is_numeric($effect['radius']) ? (float) $effect['radius'] : 0.0;
            $spread = isset($effect['spread']) && is_numeric($effect['spread']) ? (float) $effect['spread'] : 0.0;
            $offsetX = isset($effect['offset_x']) && is_numeric($effect['offset_x']) ? abs((float) $effect['offset_x']) : 0.0;
            $offsetY = isset($effect['offset_y']) && is_numeric($effect['offset_y']) ? abs((float) $effect['offset_y']) : 0.0;
            $amount = max($amount, $radius + $spread + $offsetX, $radius + $spread + $offsetY);
        }

        return $amount;
    }

    /**
     * @param array<string, mixed> $container
     * @return array<int, mixed>
     */
    private function nodeList(array $container): array
    {
        if ( is_array($container['nodes'] ?? null) ) {
            return array_values($container['nodes']);
        }

        if ( is_array($container['children'] ?? null) ) {
            return array_values($container['children']);
        }

        return array();
    }
}
