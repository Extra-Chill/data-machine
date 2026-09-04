<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Builds responsive media-query overrides from planned breakpoint variants.
 */
final class BreakpointMediaDiffBuilder
{
    private const RESPONSIVE_CONTAINER_TYPES = array('FRAME', 'GROUP', 'INSTANCE', 'COMPONENT', 'SYMBOL', 'SECTION');

    private readonly ResponsiveNodeMatcher $responsiveNodeMatcher;
    private readonly BreakpointDimensionPolicy $breakpointDimensionPolicy;
    private readonly LayoutIntentClassifier $layoutIntentClassifier;
    private readonly ResponsiveBreakpointSafetyPolicy $responsiveBreakpointSafetyPolicy;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $decisionTraces = array();

    /**
     * @param callable(array<string, mixed>): array<int, mixed> $nodeList
     * @param callable(array<string, mixed>, string, array<string, mixed>|null, array<string, mixed>|null): array<int, string> $styleDeclarations
     * @param callable(array<string, mixed>, string, array<string, mixed>|null): mixed $supportedVectorSvg
     * @param callable(array<string, mixed>, array<string, mixed>): bool $isFullyClippedDecorativeChild
     * @param callable(array<string, mixed>): bool $isPaginationContainer
     * @param callable(string): string $sanitizeAttribute
     * @param callable(string): string $slug
     * @param callable(float): string $number
     */
    public function __construct(
        private readonly StickyLayoutCoordinator $stickyLayoutCoordinator,
        private readonly mixed $nodeList,
        private readonly mixed $styleDeclarations,
        private readonly mixed $supportedVectorSvg,
        private readonly mixed $isFullyClippedDecorativeChild,
        private readonly mixed $isPaginationContainer,
        private readonly mixed $sanitizeAttribute,
        private readonly mixed $slug,
        private readonly mixed $number,
        ?ResponsiveNodeMatcher $responsiveNodeMatcher = null,
        ?BreakpointDimensionPolicy $breakpointDimensionPolicy = null,
        ?LayoutIntentClassifier $layoutIntentClassifier = null,
        ?ResponsiveBreakpointSafetyPolicy $responsiveBreakpointSafetyPolicy = null,
    ) {
        $this->responsiveNodeMatcher = $responsiveNodeMatcher ?? new ResponsiveNodeMatcher($this->slug);
        $this->breakpointDimensionPolicy = $breakpointDimensionPolicy ?? new BreakpointDimensionPolicy($this->number);
        $this->layoutIntentClassifier = $layoutIntentClassifier ?? new LayoutIntentClassifier();
        $this->responsiveBreakpointSafetyPolicy = $responsiveBreakpointSafetyPolicy ?? new ResponsiveBreakpointSafetyPolicy(
            $this->nodeList,
            $this->number,
            $this->breakpointDimensionPolicy,
            $this->layoutIntentClassifier
        );
    }

    public function resetDecisionTraces(): void
    {
        $this->decisionTraces = array();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function decisionTraces(): array
    {
        return array_values($this->decisionTraces);
    }

    /**
     * @param array<string, mixed>                $page
     * @param array<string, mixed>                $baseNode
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<int, string>
     */
    public function buildMediaBlocks(array $page, array $baseNode, array $nodeMap): array
    {
        $variants = is_array($page['variants'] ?? null) ? array_values($page['variants']) : array();
        if ( count($variants) < 2 ) {
            return $this->desktopOnlyResponsiveFallbackMediaBlocks($page, $baseNode);
        }

        $baseStyles = array();
        $this->collectVariantNodeStyles($baseNode, 0, null, null, 'r', $baseStyles);

        $primaryViewportWidth = null;
        foreach ( $variants as $variant ) {
            if ( is_array($variant) && true === ($variant['primary'] ?? false) && is_numeric($variant['viewport_width'] ?? null) ) {
                $primaryViewportWidth = (float) $variant['viewport_width'];
                break;
            }
        }

        $blocks = array();
        foreach ( $this->responsiveVariantsInCascadeOrder($variants, $primaryViewportWidth) as $responsiveVariant ) {
            $variant = $responsiveVariant['variant'];
            $mediaQuery = $responsiveVariant['media_query'];
            $variantId = (string) $variant['frame_id'];
            $viewportWidth = (float) $variant['viewport_width'];
            if ( ! isset($nodeMap[$variantId]) ) {
                continue;
            }

            $variantStyles = array();
            $this->collectVariantNodeStyles($nodeMap[$variantId], 0, null, null, 'r', $variantStyles);

            $diffRules = $this->diffRules($baseStyles, $variantStyles);
            if ( ! empty($diffRules) ) {
                $blocks[] = $this->mediaBlock($mediaQuery['feature'], $mediaQuery['breakpoint'], $diffRules);
            }

            $safetyRules = $this->responsiveSafetyRules($baseStyles, $variantStyles, $viewportWidth, $this->matchedBreakpointGeometryClasses($baseStyles, $variantStyles));
            if ( ! empty($safetyRules) ) {
                $safetyBreakpointPx = 'max-width' === $mediaQuery['feature'] && $viewportWidth <= 480.0
                    ? (int) round($viewportWidth)
                    : $mediaQuery['breakpoint'];
                $blocks[] = $this->mediaBlock($mediaQuery['feature'], $safetyBreakpointPx, $safetyRules);
            }
        }

        return $blocks;
    }

    /**
     * Desktop-only Figma files often export one oversized fixed canvas. Keep the
     * source desktop rules intact and add a narrow-screen safety layer only when
     * the page root itself clearly exceeds common mobile/tablet widths.
     *
     * @param array<string, mixed> $page
     * @param array<string, mixed> $baseNode
     * @return array<int, string>
     */
    private function desktopOnlyResponsiveFallbackMediaBlocks(array $page, array $baseNode): array
    {
        $rootWidth = $this->nodeBoxDimension($baseNode, 'width') ?? $this->primaryVariantViewportWidth($page);
        if ( null === $rootWidth || $rootWidth < 960.0 ) {
            return array();
        }

        $baseStyles = array();
        $this->collectVariantNodeStyles($baseNode, 0, null, null, 'r', $baseStyles);

        $desktopRules = array();
        $mobileRules = array();
        foreach ( $baseStyles as $base ) {
            $class = isset($base['class']) && is_scalar($base['class']) ? (string) $base['class'] : '';
            $node = is_array($base['node'] ?? null) ? $base['node'] : array();
            $baseMap = $this->styleDeclarationMap(is_array($base['styles'] ?? null) ? $base['styles'] : array());
            if ( '' === $class || empty($node) || empty($baseMap) || $this->usesFullBleedViewportBreakout($baseMap) ) {
                continue;
            }

            $depth = isset($base['depth']) && is_numeric($base['depth']) ? (int) $base['depth'] : 0;
            $parentNode = is_array($base['parent_node'] ?? null) ? $base['parent_node'] : null;
            $desktopChanged = $this->changedDeclarations($this->desktopOnlyResponsiveFallbackDeclarations($node, $baseMap, $depth, 0 === $depth ? $rootWidth : null, false, $parentNode, $rootWidth), $baseMap);
            if ( ! empty($desktopChanged) ) {
                $desktopRules[] = '.' . $class . '{' . implode(';', array_values(array_unique($desktopChanged))) . '}';
            }

            $mobileFallback = $this->desktopOnlyResponsiveFallbackDeclarations($node, $baseMap, $depth, 0 === $depth ? $rootWidth : null, true, $parentNode, $rootWidth);
            $mobileChanged = $this->changedDeclarations($mobileFallback, $baseMap);
            if ( in_array('flex-shrink:1', $desktopChanged, true) && in_array('flex-shrink:0', $mobileFallback, true) ) {
                $mobileChanged[] = 'flex-shrink:0';
            }
            if ( in_array('min-height:0', $desktopChanged, true) ) {
                foreach ( $mobileFallback as $declaration ) {
                    if ( str_starts_with($declaration, 'min-height:') && 'min-height:0' !== $declaration ) {
                        $mobileChanged[] = $declaration;
                    }
                }
            }
            if ( ! empty($mobileChanged) ) {
                $mobileRules[] = '.' . $class . '{' . implode(';', array_values(array_unique($mobileChanged))) . '}';
            }
        }

        if ( empty($desktopRules) && empty($mobileRules) ) {
            return array();
        }

        $desktopRules = array_values(array_unique($desktopRules));
        $mobileRules = array_values(array_unique($mobileRules));
        // Nested component clones can be opaque to breakpoint traversal, so
        // enforce the mobile content boundary at the emitted DOM layer too.
        $mobileRules[] = '.figma-root [data-source-node-type="TEXT"]{max-width:calc(100vw - 48px)}';
        $blocks = array();
        if ( $rootWidth > 1200.0 && ! empty($desktopRules) ) {
            $blocks[] = $this->mediaBlock('max-width', (int) floor($rootWidth - 1.0), $desktopRules);
        }
        $blocks[] = $this->mediaBlock('max-width', 767, ! empty($mobileRules) ? $mobileRules : $desktopRules);

        return $blocks;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, string> $baseMap
     * @return array<int, string>
     */
    private function desktopOnlyResponsiveFallbackDeclarations(array $node, array $baseMap, int $depth, ?float $fallbackWidth = null, bool $mobile = false, ?array $parentNode = null, ?float $sourceViewportWidth = null): array
    {
        $type = strtoupper((string) ($node['type'] ?? 'FRAME'));
        $width = $this->responsiveCssWidth($baseMap) ?? $this->nodeBoxDimension($node, 'width') ?? $fallbackWidth;
        $height = $this->cssPixelValue($baseMap['height'] ?? '');
        $minHeight = $this->cssPixelValue($baseMap['min-height'] ?? '');
        $display = (string) ($baseMap['display'] ?? '');
        $position = (string) ($baseMap['position'] ?? '');
        $declarations = array();
        $wrapsRow = in_array($display, array('flex', 'inline-flex'), true) && 'row' === ($baseMap['flex-direction'] ?? null);
        $parentLayout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        $isFlexChild = in_array((string) ($parentLayout['display'] ?? ''), array('flex', 'inline-flex'), true) && 'absolute' !== $position;
        $isHorizontalFlexChild = $isFlexChild && 'row' === ($parentLayout['flex_direction'] ?? null);
        $layoutIntent = $this->layoutIntentClassifier->layoutIntent($node, $parentNode);
        $isInferredGrid = 'grid' === ($layoutIntent['display'] ?? null) && $this->hasPositionedDirectChild($node);
        $parentStacksOnMobile = $mobile
            && $isHorizontalFlexChild
            && ($this->nodeBoxDimension($parentNode ?? array(), 'width') ?? 0.0) > 340.0
            && $this->hasContainerChild($parentNode ?? array());

        if ( $this->isResponsiveContainerType($type) && null !== $width && $width > ($mobile ? 340.0 : 767.0) ) {
            $declarations[] = 'width:100%';
            $declarations[] = 'max-width:100%';
            if ( 'absolute' === $position ) {
                $declarations[] = 'left:0';
                $declarations[] = 'right:auto';
            }
            if ( null !== $height && $height > 240.0 && 'absolute' !== $position ) {
                $declarations[] = 'height:auto';
                // Canvas-positioned containers (no flex/grid layout) size every
                // child absolutely, so `height:auto` collapses them to zero. Keep
                // a source-height floor so relaxing the fixed height never erases
                // the section. Flex/grid containers keep the floor only when no
                // container child can re-establish flow height.
                $isCanvasContainer = ! in_array($display, array('flex', 'inline-flex', 'grid', 'inline-grid'), true);
                if ( $isCanvasContainer || $isInferredGrid || ( ! $wrapsRow && ! $this->hasContainerChild($node) ) ) {
                    $declarations[] = 'min-height:' . ($this->number)(min($height, 720.0)) . 'px';
                }
            }
            if ( null !== $minHeight && $minHeight > 720.0 && in_array($display, array('flex', 'inline-flex', 'grid', 'inline-grid'), true) && $this->hasContainerChild($node) ) {
                $declarations[] = $parentStacksOnMobile || $isInferredGrid
                    ? 'min-height:' . ($this->number)($minHeight) . 'px'
                    : 'min-height:0';
            }
            if ( $mobile && $wrapsRow ) {
                if ( $this->hasContainerChild($node) ) {
                    $declarations[] = 'height:auto';
                    $declarations[] = 'flex-direction:column';
                    $declarations[] = 'align-items:stretch';
                    $declarations[] = 'flex-wrap:nowrap';
                } else {
                    $declarations[] = 'flex-wrap:wrap';
                    $declarations[] = 'align-content:flex-start';
                }
            }

            if ( $mobile && in_array($display, array('grid', 'inline-grid'), true) && $this->hasContainerChild($node) ) {
                $declarations[] = 'grid-template-columns:1fr';
            }

            array_push($declarations, ...$this->responsivePaddingClampDeclarations($baseMap));
            $gap = $this->cssPixelValue($baseMap['gap'] ?? '');
            if ( null !== $gap && $gap > ($mobile ? 24.0 : 48.0) ) {
                $declarations[] = 'gap:' . ($mobile ? '24px' : '48px');
            }
        }

        if ( $isFlexChild && null !== $width && $width > 320.0 ) {
            $declarations[] = 'max-width:100%';
            $declarations[] = 'min-width:0';
            if ( $isHorizontalFlexChild ) {
                $declarations[] = $parentStacksOnMobile ? 'flex-shrink:0' : 'flex-shrink:1';
            }
            if ( $this->isEqualWidthFlexRow($parentNode) ) {
                $declarations[] = 'flex-basis:0';
                $declarations[] = 'flex-grow:1';
            }
            if ( $this->isResponsiveContainerType($type) && null !== $height && $height > 240.0 ) {
                $declarations[] = 'height:auto';
            }
        }

        if ( 'TEXT' === $type && null !== $width && $width > 320.0 ) {
            $declarations[] = 'width:100%';
            $declarations[] = 'max-width:100%';
            if ( 'absolute' === $position ) {
                $declarations[] = 'left:0';
                $declarations[] = 'right:auto';
            }
            if ( null !== $height && $height > 0.0 ) {
                $declarations[] = 'height:auto';
            }
            if ( in_array($baseMap['white-space'] ?? '', array('pre', 'pre-line', 'nowrap'), true) ) {
                $declarations[] = 'white-space:normal';
                $declarations[] = 'overflow-wrap:anywhere';
            }
            if ( $depth <= 2 && 'absolute' === $position ) {
                $declarations[] = 'left:24px';
                $declarations[] = 'right:24px';
            }
            if ( null !== $sourceViewportWidth && $sourceViewportWidth > 1200.0 ) {
                foreach ( array('font-size', 'line-height') as $property ) {
                    $sourceValue = $this->cssPixelValue($baseMap[$property] ?? '');
                    if ( null === $sourceValue || $sourceValue <= 16.0 ) {
                        continue;
                    }
                    $minimum = max(14.0, $sourceValue * 0.55);
                    $declarations[] = $property . ':clamp(' . ($this->number)($minimum) . 'px,' . ($this->number)($sourceValue / $sourceViewportWidth * 100.0) . 'vw,' . ($this->number)($sourceValue) . 'px)';
                }
            }
        }

        $fluidAbsoluteVisual = 'absolute' === $position && null !== $width && $width > 0.0;
        if ( ($mobile || ($isFlexChild && null !== $width && $width > 340.0) || $fluidAbsoluteVisual) && null !== $width && isset($baseMap['background-image']) && str_contains((string) $baseMap['background-image'], 'url(') ) {
            $absoluteFluidGeometry = $fluidAbsoluteVisual ? $this->absoluteFluidVisualGeometry($node, $parentNode, $width) : array();
            $declarations[] = isset($absoluteFluidGeometry['width']) ? 'width:' . $absoluteFluidGeometry['width'] : 'width:100%';
            $declarations[] = 'max-width:100%';
            if ( isset($absoluteFluidGeometry['left']) ) {
                $declarations[] = 'left:' . $absoluteFluidGeometry['left'];
                $declarations[] = 'right:auto';
            }
            if ( null !== $height && $height > 0.0 ) {
                $declarations[] = 'height:auto';
                $declarations[] = 'aspect-ratio:' . ($this->number)($width) . ' / ' . ($this->number)($height);
            }
            if ( isset($baseMap['background-size']) && ! in_array($baseMap['background-size'], array('cover', 'contain'), true) ) {
                $declarations[] = 'background-size:cover';
            }
        }

        $parentIntent = null === $parentNode ? null : $this->layoutIntentClassifier->layoutIntent($parentNode);
        if (
            $mobile
            && 'absolute' === $position
            && 'grid' === ($parentIntent['display'] ?? null)
            && null !== ($parentIntent['collection'] ?? null)
            && in_array($type, array('FRAME', 'GROUP', 'INSTANCE', 'COMPONENT', 'SYMBOL', 'TEXT'), true)
        ) {
            array_push($declarations, 'position:relative', 'left:auto', 'right:auto', 'top:auto', 'bottom:auto', 'width:100%', 'max-width:100%', 'height:auto', 'min-width:0');
        }

        return array_values(array_unique($declarations));
    }

    /**
     * Preserve collage composition when a fixed desktop canvas is fluidized.
     * Absolute visual children retain their source-relative width and x offset
     * as percentages of the containing frame.
     *
     * @param array<string, mixed>      $node
     * @param array<string, mixed>|null $parentNode
     * @return array{width?: string, left?: string}
     */
    private function absoluteFluidVisualGeometry(array $node, ?array $parentNode, float $width): array
    {
        if ( null === $parentNode ) {
            return array();
        }
        $parentWidth = $this->nodeBoxDimension($parentNode, 'width');
        $box = is_array($node['box'] ?? null) ? $node['box'] : $node;
        if ( null === $parentWidth || $parentWidth <= 0.0 || ! is_numeric($box['x'] ?? null) ) {
            return array();
        }

        return array(
            'width' => ($this->number)(min(100.0, $width / $parentWidth * 100.0)) . '%',
            'left'  => ($this->number)(max(0.0, (float) $box['x'] / $parentWidth * 100.0)) . '%',
        );
    }

    /**
     * Equal-width source rows can share constrained viewport space without
     * changing their desktop structure. Unequal rows retain their source
     * proportions and only receive ordinary flex shrinking.
     *
     * @param array<string, mixed>|null $parentNode
     */
    private function isEqualWidthFlexRow(?array $parentNode): bool
    {
        if ( null === $parentNode ) {
            return false;
        }
        $layout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        if ( ! in_array((string) ($layout['display'] ?? ''), array('flex', 'inline-flex'), true) || 'row' !== ($layout['flex_direction'] ?? null) ) {
            return false;
        }

        $widths = array();
        foreach ( ($this->nodeList)($parentNode) as $child ) {
            if ( ! is_array($child) || 'absolute' === ($child['layout']['positioning'] ?? null) ) {
                continue;
            }
            $box = is_array($child['box'] ?? null) ? $child['box'] : $child;
            if ( is_numeric($box['width'] ?? null) && (float) $box['width'] > 0.0 ) {
                $widths[] = (float) $box['width'];
            }
        }
        if ( count($widths) < 2 ) {
            return false;
        }

        return max($widths) - min($widths) <= max($widths) * 0.15;
    }

    /**
     * @param array<int, string> $declarations
     * @param array<string, string> $baseMap
     * @return array<int, string>
     */
    private function changedDeclarations(array $declarations, array $baseMap): array
    {
        $changed = array();
        foreach ( $declarations as $declaration ) {
            $parts = explode(':', $declaration, 2);
            if ( 2 !== count($parts) ) {
                continue;
            }
            $property = trim($parts[0]);
            $value = trim($parts[1]);
            if ( ! array_key_exists($property, $baseMap) || $baseMap[$property] !== $value ) {
                $changed[] = $property . ':' . $value;
            }
        }

        return $changed;
    }

    private function isResponsiveContainerType(string $type): bool
    {
        return in_array($type, self::RESPONSIVE_CONTAINER_TYPES, true);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasContainerChild(array $node): bool
    {
        foreach ( ($this->nodeList)($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            if ( $this->isResponsiveContainerType(strtoupper((string) ($child['type'] ?? 'FRAME'))) ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $node */
    private function hasPositionedDirectChild(array $node): bool
    {
        foreach ( ($this->nodeList)($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }
            $layout = is_array($child['layout'] ?? null) ? $child['layout'] : array();
            if ( 'absolute' === ($layout['positioning'] ?? null) || 'ABSOLUTE' === ($child['layoutPositioning'] ?? null) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $baseMap
     * @return array<int, string>
     */
    private function responsivePaddingClampDeclarations(array $baseMap): array
    {
        $declarations = array();
        foreach ( array('top', 'right', 'bottom', 'left') as $edge ) {
            $property = 'padding-' . $edge;
            $padding = $this->cssPixelValue($baseMap[$property] ?? '');
            if ( null !== $padding && $padding > 24.0 ) {
                $declarations[] = $property . ':24px';
            }
        }

        return $declarations;
    }

    /**
     * @param array<string, string> $baseMap
     */
    private function responsiveCssWidth(array $baseMap): ?float
    {
        $width = $this->cssPixelValue($baseMap['width'] ?? '');
        if ( null === $width && '100%' === ($baseMap['width'] ?? null) ) {
            $width = $this->cssPixelValue($baseMap['max-width'] ?? '');
        }

        return $width;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeBoxDimension(array $node, string $dimension): ?float
    {
        foreach ( array('box', 'figma_box') as $boxKey ) {
            $box = is_array($node[$boxKey] ?? null) ? $node[$boxKey] : array();
            if ( isset($box[$dimension]) && is_numeric($box[$dimension]) ) {
                return (float) $box[$dimension];
            }
        }

        return isset($node[$dimension]) && is_numeric($node[$dimension]) ? (float) $node[$dimension] : null;
    }

    /**
     * @param array<string, mixed> $page
     */
    private function primaryVariantViewportWidth(array $page): ?float
    {
        foreach ( is_array($page['variants'] ?? null) ? $page['variants'] : array() as $variant ) {
            if ( is_array($variant) && true === ($variant['primary'] ?? false) && is_numeric($variant['viewport_width'] ?? null) ) {
                return (float) $variant['viewport_width'];
            }
        }

        return is_numeric($page['viewport_width'] ?? null) ? (float) $page['viewport_width'] : null;
    }

    /**
     * @return array{feature: string, breakpoint: int}|null
     */
    private function variantMediaQuery(float $primaryViewportWidth, float $variantViewportWidth): ?array
    {
        if ( $variantViewportWidth === $primaryViewportWidth ) {
            return null;
        }

        $midpoint = (int) round(($primaryViewportWidth + $variantViewportWidth) / 2);
        if ( $variantViewportWidth < $primaryViewportWidth ) {
            return array(
                'feature'    => 'max-width',
                'breakpoint' => min($midpoint, (int) ceil($primaryViewportWidth) - 1),
            );
        }

        return array(
            'feature'    => 'min-width',
            'breakpoint' => max($midpoint, (int) floor($primaryViewportWidth) + 1),
        );
    }

    /**
     * Sort same-direction ranges by cascade specificity, independently of the
     * planner's input order. Keep the diff and safety blocks adjacent per variant.
     *
     * @param array<int, mixed> $variants
     * @return array<int, array{variant: array<string, mixed>, media_query: array{feature: string, breakpoint: int}, input_index: int}>
     */
    private function responsiveVariantsInCascadeOrder(array $variants, ?float $primaryViewportWidth): array
    {
        if ( null === $primaryViewportWidth ) {
            return array();
        }

        $responsiveVariants = array();
        foreach ( $variants as $inputIndex => $variant ) {
            if ( ! is_array($variant) || true === ($variant['primary'] ?? false) ) {
                continue;
            }

            $variantId = isset($variant['frame_id']) && is_scalar($variant['frame_id']) ? (string) $variant['frame_id'] : '';
            $viewportWidth = $variant['viewport_width'] ?? null;
            if ( '' === $variantId || ! is_numeric($viewportWidth) ) {
                continue;
            }

            $mediaQuery = $this->variantMediaQuery($primaryViewportWidth, (float) $viewportWidth);
            if ( null === $mediaQuery ) {
                continue;
            }

            $responsiveVariants[] = array(
                'variant'     => $variant,
                'media_query' => $mediaQuery,
                'input_index' => (int) $inputIndex,
            );
        }

        usort($responsiveVariants, static function (array $left, array $right): int {
            $leftQuery = $left['media_query'];
            $rightQuery = $right['media_query'];
            if ( $leftQuery['feature'] !== $rightQuery['feature'] ) {
                return 'max-width' === $leftQuery['feature'] ? -1 : 1;
            }

            $comparison = $leftQuery['breakpoint'] <=> $rightQuery['breakpoint'];
            if ( 'max-width' === $leftQuery['feature'] ) {
                $comparison *= -1;
            }

            return 0 !== $comparison ? $comparison : $left['input_index'] <=> $right['input_index'];
        });

        return $responsiveVariants;
    }

    /**
     * @param array<int, string> $rules
     */
    private function mediaBlock(string $feature, int $breakpointPx, array $rules): string
    {
        return '@media (' . $feature . ':' . ($this->number)((float) $breakpointPx) . 'px){'
            . "\n" . implode("\n", $rules) . "\n}";
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $map
     */
    private function collectVariantNodeStyles(array $node, int $depth, ?array $parentNode, ?array $grandParentNode, string $pathKey, array &$map): void
    {
        if ( $this->stickyLayoutCoordinator->isSuppressedStickyGhost($node) ) {
            return;
        }

        $id = ($this->sanitizeAttribute)((string) ($node['id'] ?? ''));
        $name = (string) ($node['name'] ?? '');
        $type = strtoupper((string) ($node['type'] ?? 'FRAME'));
        $className = 'figma-node-' . ($this->slug)($id . '-' . $name);
        $styles = $this->stickyLayoutCoordinator->stickyAwareStyleDeclarations($node, ($this->styleDeclarations)($node, $type, $parentNode, $grandParentNode));

        $map[$pathKey] = array(
            'class'           => $className,
            'styles'          => $styles,
            'contains_sticky' => $this->stickyLayoutCoordinator->containsStickyPrimary($node),
            'node'            => $node,
            'parent_node'     => $parentNode,
            'grand_parent_node' => $grandParentNode,
            'depth'           => $depth,
            'path_key'        => $pathKey,
        );

        $vectorSvg = ($this->supportedVectorSvg)($node, $type, $parentNode);
        if ( 'BOOLEAN_OPERATION' === $type && null !== $vectorSvg ) {
            return;
        }

        $children = array();
        foreach ( ($this->nodeList)($node) as $child ) {
            if ( ! is_array($child) || $this->stickyLayoutCoordinator->isSuppressedStickyGhost($child) || ($this->isFullyClippedDecorativeChild)($child, $node) ) {
                continue;
            }

            $children[] = $child;
        }

        $childOrdinal = 0;
        $siblingSignatureCounts = $this->responsiveNodeMatcher->siblingSignatureCounts($children);
        $siblingSourceIdentityCounts = $this->responsiveNodeMatcher->siblingSourceIdentityCounts($children);
        foreach ( $children as $child ) {
            foreach ( $this->responsiveNodeMatcher->childKeys($child, $childOrdinal, $siblingSignatureCounts, $siblingSourceIdentityCounts) as $childKeyPart ) {
                $childKey = $pathKey . '/' . $childKeyPart;
                $this->collectVariantNodeStyles($child, $depth + 1, $node, $parentNode, $childKey, $map);
            }
            ++$childOrdinal;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $baseStyles
     * @param array<string, array<string, mixed>> $variantStyles
     * @return array<int, string>
     */
    private function diffRules(array $baseStyles, array $variantStyles): array
    {
        $rules = array();
        foreach ( $baseStyles as $pathKey => $base ) {
            if ( ! isset($variantStyles[$pathKey]) ) {
                continue;
            }

            $baseMap = $this->styleDeclarationMap(is_array($base['styles'] ?? null) ? $base['styles'] : array());
            $variantDeclarations = is_array($variantStyles[$pathKey]['styles'] ?? null) ? $variantStyles[$pathKey]['styles'] : array();

            $changed = array();
            $baseContainsSticky = true === ($base['contains_sticky'] ?? false);
            $preserveFullBleedBreakout = $this->usesFullBleedViewportBreakout($baseMap);
            $releasesFullBleedToFlow = $preserveFullBleedBreakout && $this->responsiveDeclarationsConvertAbsoluteToFlow($baseMap, $variantDeclarations);
            $baseNode = is_array($base['node'] ?? null) ? $base['node'] : array();
            $variantNode = is_array($variantStyles[$pathKey]['node'] ?? null) ? $variantStyles[$pathKey]['node'] : array();
            $baseParentNode = is_array($base['parent_node'] ?? null) ? $base['parent_node'] : null;
            $variantParentNode = is_array($variantStyles[$pathKey]['parent_node'] ?? null) ? $variantStyles[$pathKey]['parent_node'] : null;
            $preservePaginationRow = ! empty($baseNode) && ($this->isPaginationContainer)($baseNode);
            $responsiveWidthHandledProperties = array();
            foreach ( $variantDeclarations as $declaration ) {
                $parts = explode(':', (string) $declaration, 2);
                if ( 2 !== count($parts) ) {
                    continue;
                }

                $property = trim($parts[0]);
                $value = trim($parts[1]);
                if ( $baseContainsSticky && 'overflow' === $property ) {
                    continue;
                }
                if ( $preservePaginationRow && in_array($property, array('height', 'flex-wrap', 'align-content'), true) ) {
                    continue;
                }
                if ( $preserveFullBleedBreakout && ! $releasesFullBleedToFlow && in_array($property, array('width', 'left', 'right', 'margin-left', 'margin-right'), true) ) {
                    continue;
                }
                if ( 'height' === $property && $this->shouldUseResponsiveAutoHeight($value, $baseMap, $baseNode, $variantNode, $variantParentNode) ) {
                    if ( ! array_key_exists('height', $baseMap) || 'auto' !== $baseMap['height'] ) {
                        $changed[] = 'height:auto';
                    }
                    continue;
                }
                $responsiveWidthDeclarations = 'width' === $property
                    ? $this->breakpointDimensionPolicy->breakpointWidthDeclarations($value, $baseMap, $baseNode, $variantNode, $baseParentNode, $variantParentNode)
                    : null;
                if ( null !== $responsiveWidthDeclarations ) {
                    foreach ( $responsiveWidthDeclarations as $responsiveWidthDeclaration ) {
                        $responsiveParts = explode(':', $responsiveWidthDeclaration, 2);
                        if ( 2 !== count($responsiveParts) ) {
                            continue;
                        }
                        $responsiveProperty = trim($responsiveParts[0]);
                        $responsiveValue = trim($responsiveParts[1]);
                        $responsiveWidthHandledProperties[$responsiveProperty] = true;
                        if ( ! array_key_exists($responsiveProperty, $baseMap) || $baseMap[$responsiveProperty] !== $responsiveValue ) {
                            $changed[] = $responsiveProperty . ':' . $responsiveValue;
                        }
                    }
                    continue;
                }
                if ( isset($responsiveWidthHandledProperties[$property]) ) {
                    continue;
                }
                if ( ! array_key_exists($property, $baseMap) || $baseMap[$property] !== $value ) {
                    $changed[] = $property . ':' . $value;
                }
            }

            if ( $releasesFullBleedToFlow ) {
                foreach ( array('left:auto', 'right:auto', 'top:auto', 'bottom:auto', 'margin-left:0', 'margin-right:0') as $declaration ) {
                    [$property, $value] = explode(':', $declaration, 2);
                    if ( ! array_key_exists($property, $baseMap) || $baseMap[$property] !== $value ) {
                        $changed[] = $declaration;
                    }
                }
            }

            if ( empty($changed) ) {
                continue;
            }

            $rules[] = '.' . (string) $base['class'] . '{' . implode(';', $changed) . '}';
        }

        return array_values(array_unique($rules));
    }

    /**
     * @param array<string, string> $baseMap
     */
    private function usesFullBleedViewportBreakout(array $baseMap): bool
    {
        return '100vw' === ($baseMap['width'] ?? null)
            && '50%' === ($baseMap['left'] ?? null)
            && '-50vw' === ($baseMap['margin-left'] ?? null);
    }

    /**
     * Figma exports often keep headers, footer rows, newsletter panels, and card
     * grids as page-specific absolute/freeform nodes. When a breakpoint variant
     * changes clone/source identity, structural diffing cannot match those nodes,
     * so desktop widths survive into mobile. These class-scoped fallbacks only
     * target already-emitted base nodes whose normalized names and styles identify
     * those responsive shells.
     *
     * @param array<string, array<string, mixed>> $baseStyles
     * @param array<string, array<string, mixed>> $variantStyles
     * @return array<int, string>
     */
    private function responsiveSafetyRules(array $baseStyles, array $variantStyles, float $viewportWidth, array $matchedBreakpointGeometryClasses): array
    {
        $rules = array();
        foreach ( $baseStyles as $base ) {
            $node = is_array($base['node'] ?? null) ? $base['node'] : array();
            $parentNode = is_array($base['parent_node'] ?? null) ? $base['parent_node'] : null;
            $grandParentNode = is_array($base['grand_parent_node'] ?? null) ? $base['grand_parent_node'] : null;
            $class = isset($base['class']) && is_scalar($base['class']) ? (string) $base['class'] : '';
            $baseMap = $this->styleDeclarationMap(is_array($base['styles'] ?? null) ? $base['styles'] : array());
            if ( '' === $class || empty($node) || empty($baseMap) ) {
                continue;
            }

            $depth = isset($base['depth']) && is_numeric($base['depth']) ? (int) $base['depth'] : 0;
            $pathKey = is_string($base['path_key'] ?? null) ? (string) $base['path_key'] : '';
            $variantNode = '' !== $pathKey && is_array($variantStyles[$pathKey]['node'] ?? null) ? $variantStyles[$pathKey]['node'] : null;
            $decision = $this->responsiveBreakpointSafetyPolicy->responsiveSafetyDecision($node, $parentNode, $baseMap, $viewportWidth, $depth, $grandParentNode, $variantNode);
            $declarations = is_array($decision['declarations'] ?? null) ? $decision['declarations'] : array();
            if ( empty($declarations) ) {
                continue;
            }

            $reasonCode = (string) ($decision['reason_code'] ?? 'responsive_safety_override');
            if ( isset($matchedBreakpointGeometryClasses[$class]) && $this->isGenericResponsiveFlowSafetyReason($reasonCode) ) {
                continue;
            }

            $changed = array();
            foreach ( $declarations as $declaration ) {
                $parts = explode(':', $declaration, 2);
                if ( 2 !== count($parts) ) {
                    continue;
                }
                $property = trim($parts[0]);
                $value = trim($parts[1]);
                if ( ! array_key_exists($property, $baseMap) || $baseMap[$property] !== $value ) {
                    $changed[] = $property . ':' . $value;
                }
            }
            if ( ! empty($changed) ) {
                $rules[] = '.' . $class . '{' . implode(';', $changed) . '}';
                $this->recordResponsiveDecisionTrace($node, $parentNode, $reasonCode, $viewportWidth, $changed, $class, $baseMap, $variantNode, isset($matchedBreakpointGeometryClasses[$class]));
            }
        }

        return array_values(array_unique($rules));
    }

    /**
     * @param array<string, array<string, mixed>> $baseStyles
     * @param array<string, array<string, mixed>> $variantStyles
     * @return array<string, true>
     */
    private function matchedBreakpointGeometryClasses(array $baseStyles, array $variantStyles): array
    {
        $classes = array();
        foreach ( $baseStyles as $pathKey => $base ) {
            if ( ! isset($variantStyles[$pathKey]) ) {
                continue;
            }

            $class = isset($base['class']) && is_scalar($base['class']) ? (string) $base['class'] : '';
            if ( '' === $class ) {
                continue;
            }

            $variantMap = $this->styleDeclarationMap(is_array($variantStyles[$pathKey]['styles'] ?? null) ? $variantStyles[$pathKey]['styles'] : array());
            foreach ( array('position', 'left', 'right', 'top', 'bottom', 'width', 'height') as $property ) {
                if ( array_key_exists($property, $variantMap) ) {
                    $classes[$class] = true;
                    break;
                }
            }
        }

        return $classes;
    }

    private function isGenericResponsiveFlowSafetyReason(string $reasonCode): bool
    {
        return in_array($reasonCode, array(
            'responsive_header_child_chrome_safety',
            'responsive_footer_child_chrome_safety',
            'responsive_generic_mobile_safety',
        ), true);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<int, string> $declarations
     */
    private function recordResponsiveDecisionTrace(array $node, ?array $parentNode, string $reasonCode, float $viewportWidth, array $declarations, string $class, array $baseMap, ?array $variantNode, bool $matchedBreakpointGeometry): void
    {
        DecisionTraceBuilder::recordResponsiveTrace($this->decisionTraces, $node, $parentNode, $reasonCode, $viewportWidth, $declarations, $class, $this->responsiveDecisionEvidence($baseMap, $variantNode, $matchedBreakpointGeometry, $declarations));
    }

    /**
     * @param array<string, string> $baseMap
     * @param array<string, mixed>|null $variantNode
     * @param array<int, string> $declarations
     * @return array<string, mixed>
     */
    private function responsiveDecisionEvidence(array $baseMap, ?array $variantNode, bool $matchedBreakpointGeometry, array $declarations): array
    {
        $variantBox = is_array($variantNode['box'] ?? null) ? $variantNode['box'] : array();
        $variantLayout = is_array($variantNode['layout'] ?? null) ? $variantNode['layout'] : array();

        return array_filter(array(
            'source' => null === $variantNode ? 'class_safety_fallback' : 'matched_breakpoint_variant',
            'matched_breakpoint_geometry' => $matchedBreakpointGeometry,
            'absolute_to_flow_conversion' => $this->responsiveDeclarationsConvertAbsoluteToFlow($baseMap, $declarations),
            'base_position' => $baseMap['position'] ?? null,
            'base_left' => $baseMap['left'] ?? null,
            'base_top' => $baseMap['top'] ?? null,
            'base_width' => $baseMap['width'] ?? null,
            'variant_node_id' => is_array($variantNode) && is_scalar($variantNode['id'] ?? null) ? (string) $variantNode['id'] : null,
            'variant_positioning' => is_scalar($variantLayout['positioning'] ?? null) ? (string) $variantLayout['positioning'] : null,
            'variant_box' => array_intersect_key($variantBox, array('x' => true, 'y' => true, 'width' => true, 'height' => true, 'coordinate_space' => true)),
        ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value);
    }

    /**
     * @param array<string, string> $baseMap
     * @param array<int, string> $declarations
     */
    private function responsiveDeclarationsConvertAbsoluteToFlow(array $baseMap, array $declarations): bool
    {
        if ( 'absolute' !== ($baseMap['position'] ?? null) ) {
            return false;
        }

        $map = $this->styleDeclarationMap($declarations);
        return 'relative' === ($map['position'] ?? null)
            || 'auto' === ($map['left'] ?? null)
            || 'auto' === ($map['right'] ?? null)
            || 'auto' === ($map['top'] ?? null)
            || 'auto' === ($map['bottom'] ?? null);
    }

    private function cssPixelValue(string $value): ?float
    {
        if ( 1 !== preg_match('/^(-?\d+(?:\.\d+)?)px$/', trim($value), $matches) ) {
            return null;
        }

        return (float) $matches[1];
    }

    /**
     * @param array<string, string> $baseMap
     * @param array<string, mixed> $baseNode
     * @param array<string, mixed> $variantNode
     */
    private function shouldUseResponsiveAutoHeight(string $value, array $baseMap, array $baseNode, array $variantNode, ?array $variantParentNode): bool
    {
        if ( null === $this->cssPixelValue($value) || null === $this->cssPixelValue($baseMap['height'] ?? '') ) {
            return false;
        }

        if ( empty($baseNode) || empty($variantNode) ) {
            return false;
        }

        $type = strtoupper((string) ($baseNode['type'] ?? ''));
        if ( in_array($type, array('TEXT', 'VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'STAR', 'POLYGON', 'REGULAR_POLYGON', 'RECTANGLE', 'ROUNDED_RECTANGLE'), true) ) {
            return false;
        }

        $layout = is_array($baseNode['layout'] ?? null) ? $baseNode['layout'] : array();
        if ( 'absolute' === ($layout['positioning'] ?? null) || 'absolute' === ($baseMap['position'] ?? null) ) {
            return false;
        }

        if ( in_array($type, array('INSTANCE', 'COMPONENT', 'SYMBOL'), true) && $this->variantFillsParentWidth($variantNode, $variantParentNode) ) {
            return true;
        }

        $display = (string) ($layout['display'] ?? '');
        if ( ! in_array($display, array('flex', 'inline-flex', 'grid', 'inline-grid'), true) ) {
            return false;
        }

        if ( ($this->hasStableComponentIdentity($baseNode) || $this->hasStableComponentIdentity($variantNode)) && ! $this->variantFlowTopologyChanged($baseNode, $variantNode) ) {
            return false;
        }

        return ! empty(($this->nodeList)($baseNode)) && ! empty(($this->nodeList)($variantNode));
    }

    /**
     * @param array<string, mixed> $baseNode
     * @param array<string, mixed> $variantNode
     */
    private function variantFlowTopologyChanged(array $baseNode, array $variantNode): bool
    {
        $baseLayout = is_array($baseNode['layout'] ?? null) ? $baseNode['layout'] : array();
        $variantLayout = is_array($variantNode['layout'] ?? null) ? $variantNode['layout'] : array();
        foreach ( array('display', 'flex_direction', 'flex_wrap', 'grid_template_columns', 'grid_template_rows') as $layoutKey ) {
            if ( ($baseLayout[$layoutKey] ?? null) !== ($variantLayout[$layoutKey] ?? null) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function variantFillsParentWidth(array $node, ?array $parentNode): bool
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        if ( ! isset($box['width'], $parentBox['width']) || ! is_numeric($box['width']) || ! is_numeric($parentBox['width']) ) {
            return false;
        }

        return abs((float) $box['width'] - (float) $parentBox['width']) <= 1.0;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasStableComponentIdentity(array $node): bool
    {
        foreach ( array('figma_component_source_id', 'source_id', 'componentId', 'component_id') as $identityKey ) {
            if ( isset($node[$identityKey]) && is_scalar($node[$identityKey]) && '' !== (string) $node[$identityKey] ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeBoxWidth(array $node): ?float
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        if ( ! isset($box['width']) || ! is_numeric($box['width']) ) {
            return null;
        }

        return (float) $box['width'];
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeBoxHeight(array $node): ?float
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        if ( ! isset($box['height']) || ! is_numeric($box['height']) ) {
            return null;
        }

        return (float) $box['height'];
    }

    /**
     * @param array<int, string> $styles
     * @return array<string, string>
     */
    private function styleDeclarationMap(array $styles): array
    {
        $map = array();
        foreach ( $styles as $style ) {
            $parts = explode(':', $style, 2);
            if ( 2 !== count($parts) ) {
                continue;
            }
            $map[trim($parts[0])] = trim($parts[1]);
        }

        return $map;
    }
}
