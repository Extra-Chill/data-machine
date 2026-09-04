<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Resolves CSS declarations for clipping and mask-image composition.
 */
final class ClipMaskStyleResolver
{
    /**
     * @param callable(array<string, mixed>): bool $containsStickyPrimary
     */
    public function __construct(
        private readonly EffectOverflowPolicy $effectOverflowPolicy,
        private readonly mixed $containsStickyPrimary,
    ) {
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    public function resolve(array $node): array
    {
        $styles = array();

        if ( $this->effectOverflowPolicy->shouldHideOverflow($node, ($this->containsStickyPrimary)($node)) ) {
            $styles[] = 'overflow:hidden';
        }

        if ( isset($node['_figma_css_clip_path']) && is_scalar($node['_figma_css_clip_path']) && '' !== (string) $node['_figma_css_clip_path'] ) {
            $styles[] = 'clip-path:' . (string) $node['_figma_css_clip_path'];
        }

        if ( isset($node['_figma_css_mask_image_path']) && is_scalar($node['_figma_css_mask_image_path']) && '' !== (string) $node['_figma_css_mask_image_path'] ) {
            $maskPath = (string) $node['_figma_css_mask_image_path'];
            $styles[] = '-webkit-mask-image:url("' . $maskPath . '")';
            $styles[] = 'mask-image:url("' . $maskPath . '")';
            $styles[] = '-webkit-mask-size:100% 100%';
            $styles[] = 'mask-size:100% 100%';
            $styles[] = '-webkit-mask-repeat:no-repeat';
            $styles[] = 'mask-repeat:no-repeat';
        }

        return $styles;
    }
}
