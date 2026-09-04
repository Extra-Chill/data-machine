<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Resolves class-scoped responsive fallback decisions when breakpoint nodes cannot be matched directly.
 */
final class ResponsiveBreakpointSafetyPolicy
{
    /**
     * @param callable(array<string, mixed>): array<int, mixed> $nodeList
     * @param callable(float): string $number
     */
    public function __construct(
        private readonly mixed $nodeList,
        private readonly mixed $number,
        private readonly BreakpointDimensionPolicy $breakpointDimensionPolicy,
        private readonly LayoutIntentClassifier $layoutIntentClassifier,
    ) {
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, string> $baseMap
     * @param array<string, mixed>|null $grandParentNode
     * @param array<string, mixed>|null $variantNode
     * @return array{reason_code: string, declarations: array<int, string>}
     */
    public function responsiveSafetyDecision(array $node, ?array $parentNode, array $baseMap, float $viewportWidth, int $depth = 0, ?array $grandParentNode = null, ?array $variantNode = null): array
    {
        $name = strtolower(trim((string) ($node['name'] ?? '')));
        $type = strtoupper((string) ($node['type'] ?? 'FRAME'));
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        $positioning = (string) ($layout['positioning'] ?? ($baseMap['position'] ?? ''));
        $display = (string) ($baseMap['display'] ?? '');
        $width = $this->responsiveSourceWidth($baseMap);
        $parentName = null === $parentNode ? '' : strtolower(trim((string) ($parentNode['name'] ?? '')));
        $isContainer = in_array($type, array('FRAME', 'GROUP', 'INSTANCE', 'COMPONENT', 'SYMBOL'), true);
        $chromeRole = $this->layoutIntentClassifier->chromeGroupRole($node, $parentNode, $depth);
        $parentChromeRole = null === $parentNode ? null : $this->layoutIntentClassifier->chromeGroupRole($parentNode, $grandParentNode, max(1, $depth - 1));

        $chromeDecision = $this->responsiveChromeFlowDecision($node, $parentNode, $baseMap, $variantNode, $name, $parentName, $isContainer, $chromeRole, $parentChromeRole);
        if ( '' !== $chromeDecision['reason_code'] ) {
            return $chromeDecision;
        }

        $namedShellDecision = $this->namedResponsiveShellDecision($node, $parentNode, $baseMap, $name, $parentName, $isContainer, $width, $positioning, $display, $chromeRole, $viewportWidth);
        if ( '' !== $namedShellDecision['reason_code'] ) {
            return $namedShellDecision;
        }

        if ( $viewportWidth <= 900.0 ) {
            $oversizedDeclarations = $this->oversizedDesktopGeometrySafetyDeclarations($node, $parentNode, $baseMap, $viewportWidth, $type, $isContainer, $width, $positioning, $display);
            if ( ! empty($oversizedDeclarations) ) {
                return array('reason_code' => 'responsive_oversized_desktop_geometry_safety', 'declarations' => $oversizedDeclarations);
            }
        }

        if ( $viewportWidth <= 480.0 ) {
            $inferredGridChildDeclarations = $this->inferredGridChildFlowDeclarations($node, $parentNode, $type, $positioning);
            if ( ! empty($inferredGridChildDeclarations) ) {
                return array('reason_code' => 'responsive_inferred_grid_child_flow', 'declarations' => $inferredGridChildDeclarations);
            }

            $mobileTextDeclarations = $this->mobileCenteredTextFallbackDecision($node, $parentNode, $baseMap, $viewportWidth, $type, $width, $positioning, $variantNode);
            if ( ! empty($mobileTextDeclarations) ) {
                return array('reason_code' => 'responsive_centered_text_mobile_safety', 'declarations' => $mobileTextDeclarations);
            }

            $mobileDeclarations = $this->genericMobileSafetyDeclarations($node, $parentNode, $baseMap, $viewportWidth, $isContainer, $width, $positioning, $display);
            if ( ! empty($mobileDeclarations) ) {
                return array('reason_code' => 'responsive_generic_mobile_safety', 'declarations' => $mobileDeclarations);
            }
        }

        return array('reason_code' => '', 'declarations' => array());
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, string> $baseMap
     * @return array<int, string>
     */
    private function oversizedDesktopGeometrySafetyDeclarations(array $node, ?array $parentNode, array $baseMap, float $viewportWidth, string $type, bool $isContainer, ?float $width, string $positioning, string $display): array
    {
        if ( null === $parentNode || null === $width || 'absolute' === $positioning ) {
            return array();
        }

        $contentWidth = max(1.0, $viewportWidth - 48.0);
        $mobileContentWidth = min($contentWidth, 340.0);
        $height = $this->cssPixelValue($baseMap['height'] ?? '') ?? $this->nodeBoxHeight($node);
        $isOverwide = $width > $contentWidth || $width > 767.0 || ($viewportWidth <= 767.0 && $width > $mobileContentWidth);
        $hasBackgroundImage = isset($baseMap['background-image']) && str_contains((string) $baseMap['background-image'], 'url(');

        if ( 'TEXT' === $type ) {
            $declarations = array();
            if ( $isOverwide ) {
                $declarations[] = 'width:100%';
                $declarations[] = 'max-width:100%';
                if ( null !== $height && $height > 0.0 ) {
                    $declarations[] = 'height:auto';
                }
            }
            if ( $isOverwide || in_array($baseMap['white-space'] ?? '', array('pre', 'pre-line', 'nowrap'), true) ) {
                $declarations[] = 'white-space:normal';
                $declarations[] = 'overflow-wrap:anywhere';
            }

            return array_values(array_unique($declarations));
        }

        if ( ! $isOverwide ) {
            return array();
        }

        if ( $hasBackgroundImage && null !== $height && $height > 0.0 ) {
            $declarations = array('width:100%', 'max-width:100%', 'height:auto', 'aspect-ratio:' . ($this->number)($width) . ' / ' . ($this->number)($height));
            if ( isset($baseMap['background-size']) && ! in_array($baseMap['background-size'], array('cover', 'contain'), true) ) {
                $declarations[] = 'background-size:cover';
            }

            return $declarations;
        }

        if ( ! $isContainer ) {
            return array('max-width:100%');
        }

        $declarations = array('width:100%', 'max-width:100%', 'box-sizing:border-box');
        $wrapsRow = in_array($display, array('flex', 'inline-flex'), true) && 'row' === ($baseMap['flex-direction'] ?? null);
        if ( null !== $height && $height > 240.0 ) {
            $declarations[] = 'height:auto';
            if ( ! $wrapsRow ) {
                $declarations[] = 'min-height:' . ($this->number)(min($height, 720.0)) . 'px';
            }
        }

        if ( $wrapsRow ) {
            if ( $viewportWidth <= 480.0 && $this->hasContainerChild($node) ) {
                $declarations[] = 'flex-direction:column';
                $declarations[] = 'align-items:stretch';
                $declarations[] = 'flex-wrap:nowrap';
            } else {
                $declarations[] = 'flex-wrap:wrap';
                $declarations[] = 'align-content:flex-start';
            }
        }

        if ( in_array($display, array('grid', 'inline-grid'), true) && $viewportWidth <= 480.0 ) {
            $declarations[] = 'grid-template-columns:1fr';
        }

        array_push($declarations, ...$this->mobilePaddingClampDeclarations($baseMap));
        array_push($declarations, ...$this->mobileMarginResetDeclarations($baseMap));

        return array_values(array_unique($declarations));
    }

    /**
     * Release inferred semantic-grid children from desktop canvas coordinates so
     * the one-column mobile grid can size itself from real flow content.
     *
     * @param array<string, mixed>      $node
     * @param array<string, mixed>|null $parentNode
     * @return array<int, string>
     */
    private function inferredGridChildFlowDeclarations(array $node, ?array $parentNode, string $type, string $positioning): array
    {
        if ( null === $parentNode || 'absolute' !== $positioning || ! in_array($type, array('FRAME', 'GROUP', 'INSTANCE', 'COMPONENT', 'SYMBOL', 'TEXT'), true) ) {
            return array();
        }

        $parentIntent = $this->layoutIntentClassifier->layoutIntent($parentNode);
        if ( 'grid' !== ($parentIntent['display'] ?? null) || null === ($parentIntent['collection'] ?? null) ) {
            return array();
        }

        return array('position:relative', 'left:auto', 'right:auto', 'top:auto', 'bottom:auto', 'width:100%', 'max-width:100%', 'height:auto', 'min-width:0');
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, string> $baseMap
     * @param array<string, mixed>|null $variantNode
     * @return array{reason_code: string, declarations: array<int, string>}
     */
    public function responsiveChromeFlowDecision(array $node, ?array $parentNode, array $baseMap, ?array $variantNode, string $name, string $parentName, bool $isContainer, ?string $chromeRole, ?string $parentChromeRole): array
    {
        if ( (LayoutIntentClassifier::CHROME_GROUP_ROLE_HEADER === $chromeRole || $this->isHeaderChromeShellName($name)) && $isContainer ) {
            return array('reason_code' => 'responsive_header_chrome_safety', 'declarations' => $this->breakpointDimensionPolicy->headerChromeDeclarations($this->responsiveHeaderMinHeight($node, $baseMap, $variantNode)));
        }

        if ( LayoutIntentClassifier::CHROME_GROUP_ROLE_FOOTER === $chromeRole || 'footer' === $name ) {
            if ( $isContainer && ! $this->hasFooterResponsiveShell($node) ) {
                return array('reason_code' => 'responsive_footer_chrome_safety', 'declarations' => $this->footerChromeDeclarations($node, $baseMap, $variantNode));
            }
        }

        if ( null === $variantNode && (LayoutIntentClassifier::CHROME_GROUP_ROLE_HEADER === $parentChromeRole || $this->isHeaderChromeShellName($parentName)) ) {
            $headerChildDeclarations = array('position:relative', 'left:auto', 'right:auto', 'top:auto', 'max-width:100%');
            if ( $isContainer ) {
                array_unshift($headerChildDeclarations, 'width:100%', 'max-width:100%', 'height:auto');
                array_push($headerChildDeclarations, 'justify-content:flex-start', 'align-items:center', 'flex-wrap:wrap', 'gap:16px', 'padding-top:24px', 'padding-right:24px', 'padding-bottom:24px', 'padding-left:24px');
            }

            return array('reason_code' => 'responsive_header_child_chrome_safety', 'declarations' => array_values(array_unique($headerChildDeclarations)));
        }

        if ( LayoutIntentClassifier::CHROME_GROUP_ROLE_FOOTER === $parentChromeRole || 'footer' === $parentName ) {
            if ( str_contains($name, 'newsletter signup') || 'frame 19' === $name || $this->isDecorativeFooterUnderlay($node, $baseMap) ) {
                return array('reason_code' => '', 'declarations' => array());
            }

            $footerChildDeclarations = array('position:relative', 'left:auto', 'right:auto', 'top:auto', 'bottom:auto', 'max-width:100%', 'margin-left:0', 'margin-right:0');
            if ( $isContainer ) {
                array_unshift($footerChildDeclarations, 'width:100%', 'max-width:100%', 'height:auto');
                array_push($footerChildDeclarations, 'justify-content:flex-start', 'align-items:center', 'flex-wrap:wrap', 'gap:16px');
            }

            return array('reason_code' => 'responsive_footer_child_chrome_safety', 'declarations' => array_values(array_unique($footerChildDeclarations)));
        }

        if ( $this->isNavigationShellName($name) && $isContainer ) {
            return array('reason_code' => 'responsive_navigation_shell_safety', 'declarations' => array('width:100%', 'max-width:100%', 'height:auto', 'justify-content:flex-start', 'flex-wrap:wrap', 'gap:16px'));
        }

        return array('reason_code' => '', 'declarations' => array());
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, string> $baseMap
     * @return array{reason_code: string, declarations: array<int, string>}
     */
    private function namedResponsiveShellDecision(array $node, ?array $parentNode, array $baseMap, string $name, string $parentName, bool $isContainer, ?float $width, string $positioning, string $display, ?string $chromeRole, float $viewportWidth): array
    {
        if ( 'footer' === $name && $isContainer && $this->hasFooterResponsiveShell($node) ) {
            return array('reason_code' => 'responsive_footer_shell_safety', 'declarations' => array('height:auto', 'min-height:' . ($this->number)($this->footerResponsiveMinHeight($node)) . 'px'));
        }

        if ( (LayoutIntentClassifier::CHROME_GROUP_ROLE_NAVIGATION === $chromeRole || 'navigation' === $name) && $isContainer ) {
            return array('reason_code' => 'responsive_navigation_chrome_safety', 'declarations' => array('width:100%', 'max-width:100%', 'height:auto', 'justify-content:flex-start', 'flex-wrap:wrap', 'gap:16px'));
        }

        if ( str_contains($name, 'newsletter signup') && $isContainer && 'absolute' === $positioning ) {
            return array('reason_code' => 'responsive_absolute_newsletter_shell_safety', 'declarations' => array_merge($this->mobileSafeSourceMaxWidthDeclarations(1216.0, $viewportWidth, 'fixed'), array('height:auto', 'left:24px')));
        }

        if ( 'frame 20' === $name && $isContainer && null !== $parentNode && str_contains($parentName, 'newsletter signup') ) {
            return array('reason_code' => 'responsive_newsletter_inner_shell_safety', 'declarations' => array('height:auto', 'padding-top:56px', 'padding-right:24px', 'padding-bottom:48px', 'padding-left:24px', 'gap:24px'));
        }

        if ( 'frame 19' === $name && $isContainer && 'absolute' === $positioning ) {
            return array('reason_code' => 'responsive_absolute_inner_shell_safety', 'declarations' => array('height:auto', 'position:relative', 'left:auto', 'top:auto', 'justify-content:center', 'flex-wrap:wrap', 'align-content:flex-start', 'padding-top:32px', 'padding-right:24px', 'padding-bottom:32px', 'padding-left:24px'));
        }

        if ( ('featured preview' === $name || 'preview' === $name) && $isContainer && null !== $width && $width > 340.0 ) {
            return array(
                'reason_code' => 'responsive_preview_card_width_safety',
                'declarations' => array_merge(
                    array('width:100%', 'height:auto'),
                    $this->stackedMobileFlowDeclarations($baseMap, $display, $this->hasContainerChild($node))
                ),
            );
        }

        if ( 'pagination' === $name && $isContainer ) {
            return array(
                'reason_code' => 'responsive_pagination_overflow_safety',
                'declarations' => array_merge(
                    $this->mobileSafeSourceMaxWidthDeclarations(1216.0, $viewportWidth, 'fixed'),
                    array('height:auto', 'display:grid', 'grid-template-columns:auto minmax(0,1fr) auto', 'gap:8px', 'overflow:visible')
                ),
            );
        }

        if ( 'pagination numbers' === $name && $isContainer ) {
            return array(
                'reason_code' => 'responsive_pagination_numbers_overflow_safety',
                'declarations' => array('width:100%', 'max-width:100%', 'min-width:0', 'overflow-x:auto'),
            );
        }

        if ( 'image' === $name && in_array($display, array('flex', 'inline-flex'), true) && null !== $width && $width > 340.0 ) {
            return array('reason_code' => 'responsive_image_fill_safety', 'declarations' => $this->breakpointDimensionPolicy->fluidFillDeclarations());
        }

        return array('reason_code' => '', 'declarations' => array());
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, string> $baseMap
     * @param array<string, mixed>|null $variantNode
     * @return array<int, string>
     */
    public function mobileCenteredTextFallbackDecision(array $node, ?array $parentNode, array $baseMap, float $viewportWidth, string $type, ?float $width, string $positioning, ?array $variantNode): array
    {
        if ( 'TEXT' !== $type || null === $parentNode || null === $width || 'absolute' !== $positioning ) {
            return array();
        }

        $computedLeft = $this->mobileComputedCenteredLeft($baseMap['left'] ?? '', $viewportWidth);
        if ( null === $computedLeft || $computedLeft >= 0.0 ) {
            return array();
        }

        if ( null !== $variantNode && $this->variantTextFitsViewport($variantNode, $viewportWidth) ) {
            return array();
        }

        $mobileContentWidth = max(1.0, $viewportWidth - 48.0);
        return array(
            'width:calc(100% - 48px)',
            'max-width:' . ($this->number)(min($width, $mobileContentWidth)) . 'px',
            'left:24px',
            'right:auto',
        );
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, string> $baseMap
     * @return array<int, string>
     */
    private function genericMobileSafetyDeclarations(array $node, ?array $parentNode, array $baseMap, float $viewportWidth, bool $isContainer, ?float $width, string $positioning, string $display): array
    {
        $mobileContentWidth = max(1.0, $viewportWidth - 48.0);
        $hasUnsafeFluidRow = null === $width
            && '100%' === ($baseMap['width'] ?? null)
            && in_array($display, array('flex', 'inline-flex'), true)
            && 'row' === ($baseMap['flex-direction'] ?? null)
            && $this->hasOverwideContainerChild($node, $mobileContentWidth);
        if ( ! $isContainer || null === $parentNode || empty(($this->nodeList)($node)) ) {
            return array();
        }
        if ( ! $hasUnsafeFluidRow && (null === $width || $width <= min(340.0, $mobileContentWidth)) ) {
            return array();
        }

        $declarations = array();
        $hasContainerChild = $this->hasContainerChild($node);

        if ( $hasUnsafeFluidRow ) {
            return array('width:100%', 'max-width:100%', 'height:auto', 'flex-direction:column', 'align-items:stretch', 'flex-wrap:nowrap');
        }

        if ( 'absolute' === $positioning ) {
            if ( $width > $mobileContentWidth ) {
                array_push($declarations, ...$this->mobileSafeSourceMaxWidthDeclarations($width, $viewportWidth, 'absolute'));
                $declarations[] = 'height:auto';
                array_push($declarations, ...$this->stackedMobileFlowDeclarations($baseMap, $display, $hasContainerChild));
                array_push($declarations, ...$this->mobilePaddingClampDeclarations($baseMap));
            }

            return $declarations;
        }

        if ( 'auto' === ($baseMap['margin-left'] ?? null) && 'auto' === ($baseMap['margin-right'] ?? null) && $width > $mobileContentWidth ) {
            array_push($declarations, ...$this->mobileSafeSourceMaxWidthDeclarations($width, $viewportWidth, 'centered'));

            if ( $hasContainerChild ) {
                $declarations[] = 'height:auto';
            }

            array_push($declarations, ...$this->stackedMobileFlowDeclarations($baseMap, $display, $hasContainerChild));
            array_push($declarations, ...$this->mobilePaddingClampDeclarations($baseMap));

            return $declarations;
        }

        array_push($declarations, ...$this->breakpointDimensionPolicy->fluidFillDeclarations());

        if ( $hasContainerChild ) {
            $declarations[] = 'height:auto';
        }

        array_push($declarations, ...$this->stackedMobileFlowDeclarations($baseMap, $display, $hasContainerChild));
        array_push($declarations, ...$this->mobilePaddingClampDeclarations($baseMap));

        return $declarations;
    }

    /**
     * @return array<int, string>
     */
    private function mobileSafeSourceMaxWidthDeclarations(float $sourceMaxWidth, float $viewportWidth, string $placement): array
    {
        if ( $viewportWidth > 480.0 ) {
            return $this->breakpointDimensionPolicy->sourceMaxWidthDeclarations($sourceMaxWidth, 24.0, $placement);
        }

        return $this->breakpointDimensionPolicy->sourceMaxWidthDeclarations(min($sourceMaxWidth, max(1.0, $viewportWidth - 48.0)), 24.0, $placement);
    }

    /**
     * @param array<string, string> $baseMap
     * @return array<int, string>
     */
    private function stackedMobileFlowDeclarations(array $baseMap, string $display, bool $hasContainerChild): array
    {
        if ( ! $hasContainerChild ) {
            return array();
        }

        if ( in_array($display, array('flex', 'inline-flex'), true) && 'row' === ($baseMap['flex-direction'] ?? null) ) {
            return array('flex-direction:column', 'align-items:stretch', 'flex-wrap:nowrap');
        }

        if ( in_array($display, array('grid', 'inline-grid'), true) ) {
            return array('grid-template-columns:1fr');
        }

        return array();
    }

    /**
     * @param array<string, string> $baseMap
     * @return array<int, string>
     */
    private function mobilePaddingClampDeclarations(array $baseMap): array
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
     * @return array<int, string>
     */
    private function mobileMarginResetDeclarations(array $baseMap): array
    {
        $declarations = array();
        foreach ( array('left', 'right') as $edge ) {
            $property = 'margin-' . $edge;
            $margin = $this->cssPixelValue($baseMap[$property] ?? '');
            if ( null !== $margin && 0.0 !== $margin ) {
                $declarations[] = $property . ':0';
            }
        }

        return $declarations;
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

            $childType = strtoupper((string) ($child['type'] ?? 'FRAME'));
            if ( in_array($childType, array('FRAME', 'GROUP', 'INSTANCE', 'COMPONENT', 'SYMBOL'), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasOverwideContainerChild(array $node, float $contentWidth): bool
    {
        foreach ( ($this->nodeList)($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $childType = strtoupper((string) ($child['type'] ?? 'FRAME'));
            $box = is_array($child['box'] ?? null) ? $child['box'] : array();
            if ( in_array($childType, array('FRAME', 'GROUP', 'INSTANCE', 'COMPONENT', 'SYMBOL'), true)
                && isset($box['width'])
                && is_numeric($box['width'])
                && (float) $box['width'] > $contentWidth
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $baseMap
     */
    private function responsiveSourceWidth(array $baseMap): ?float
    {
        $width = $this->cssPixelValue($baseMap['width'] ?? '');
        if ( null === $width && '100%' === ($baseMap['width'] ?? null) ) {
            $width = $this->cssPixelValue($baseMap['max-width'] ?? '');
        }

        return $width;
    }

    private function mobileComputedCenteredLeft(string $left, float $viewportWidth): ?float
    {
        $left = trim($left);
        if ( 1 === preg_match('/^calc\(50%\s*([+-])\s*(\d+(?:\.\d+)?)px\)$/', $left, $matches) ) {
            $delta = (float) $matches[2];
            return ($viewportWidth / 2.0) + ('-' === $matches[1] ? -$delta : $delta);
        }

        return $this->cssPixelValue($left);
    }

    /**
     * @param array<string, mixed> $variantNode
     */
    private function variantTextFitsViewport(array $variantNode, float $viewportWidth): bool
    {
        $box = is_array($variantNode['box'] ?? null) ? $variantNode['box'] : array();
        if ( ! isset($box['x'], $box['width']) || ! is_numeric($box['x']) || ! is_numeric($box['width']) ) {
            return false;
        }

        $x = (float) $box['x'];
        $width = (float) $box['width'];
        return $x >= 0.0 && $width > 0.0 && ($x + min($width, $viewportWidth)) <= $viewportWidth + 1.0;
    }

    private function isHeaderChromeShellName(string $name): bool
    {
        return (bool) preg_match('/^(?:header|site\s+header|page\s+header|main\s+header|masthead|top\s*bar|site\s*chrome)$/', $name);
    }

    private function isNavigationShellName(string $name): bool
    {
        return (bool) preg_match('/(?:^|[^a-z0-9])(?:navigation|nav|menu)(?:[^a-z0-9]|$)/', $name);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasFooterResponsiveShell(array $node): bool
    {
        $hasNewsletter = false;
        $hasBottomRow = false;
        $freeformParent = $this->isFreeformContainer($node);
        foreach ( ($this->nodeList)($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }
            $name = strtolower(trim((string) ($child['name'] ?? '')));
            $layout = is_array($child['layout'] ?? null) ? $child['layout'] : array();
            if ( str_contains($name, 'newsletter signup') && ('absolute' === ($layout['positioning'] ?? null) || $freeformParent) ) {
                $hasNewsletter = true;
            }
            if ( 'frame 19' === $name ) {
                $hasBottomRow = true;
            }
        }

        return $hasNewsletter && $hasBottomRow;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isFreeformContainer(array $node): bool
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        return empty($layout['display']) && ! empty(($this->nodeList)($node));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function footerResponsiveMinHeight(array $node): float
    {
        $baseHeight = $this->nodeBoxHeight($node) ?? 0.0;
        $newsletterHeight = 0.0;
        $bottomRowHeight = 0.0;
        foreach ( ($this->nodeList)($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }
            $name = strtolower(trim((string) ($child['name'] ?? '')));
            if ( str_contains($name, 'newsletter signup') ) {
                $newsletterHeight = max($newsletterHeight, $this->nodeBoxHeight($child) ?? 0.0);
            }
            if ( 'frame 19' === $name ) {
                $bottomRowHeight = max($bottomRowHeight, $this->nodeBoxHeight($child) ?? 0.0);
            }
        }

        return max($baseHeight, $newsletterHeight + $bottomRowHeight);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, string> $baseMap
     * @param array<string, mixed>|null $variantNode
     */
    private function responsiveHeaderMinHeight(array $node, array $baseMap, ?array $variantNode): ?float
    {
        $baseHeight = $this->cssPixelValue($baseMap['height'] ?? '') ?? $this->nodeBoxHeight($node);
        $variantHeight = null === $variantNode ? null : $this->nodeBoxHeight($variantNode);

        if ( null === $baseHeight ) {
            return $variantHeight;
        }

        if ( null === $variantHeight ) {
            return $baseHeight;
        }

        return max($baseHeight, $variantHeight);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, string> $baseMap
     * @param array<string, mixed>|null $variantNode
     * @return array<int, string>
     */
    private function footerChromeDeclarations(array $node, array $baseMap, ?array $variantNode): array
    {
        $declarations = array('width:100%', 'max-width:100%', 'height:auto', 'display:flex', 'flex-direction:column', 'align-items:stretch', 'justify-content:flex-start');
        $minHeight = $this->responsiveHeaderMinHeight($node, $baseMap, $variantNode);
        if ( null !== $minHeight && $minHeight > 0.0 ) {
            $declarations[] = 'min-height:' . ($this->number)($minHeight) . 'px';
        }

        return $declarations;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, string> $baseMap
     */
    private function isDecorativeFooterUnderlay(array $node, array $baseMap): bool
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( ! in_array($type, array('RECTANGLE', 'VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'STAR', 'POLYGON', 'REGULAR_POLYGON', 'ROUNDED_RECTANGLE'), true) ) {
            return false;
        }

        return 'none' === ($baseMap['pointer-events'] ?? null) || isset($baseMap['background']) || isset($baseMap['background-color']) || isset($baseMap['transform']);
    }

    private function cssPixelValue(string $value): ?float
    {
        if ( 1 !== preg_match('/^(-?\d+(?:\.\d+)?)px$/', trim($value), $matches) ) {
            return null;
        }

        return (float) $matches[1];
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
}
