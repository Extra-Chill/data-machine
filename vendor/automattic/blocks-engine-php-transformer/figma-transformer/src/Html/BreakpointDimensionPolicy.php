<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Resolves responsive breakpoint width policy into CSS declarations.
 */
final class BreakpointDimensionPolicy
{
    /**
     * @param callable(float): string|null $number
     */
    public function __construct(
        private readonly mixed $number = null,
    ) {
    }

    /**
     * @return array{reason_code: string, declarations: array<int, string>}
     */
    public function rootFillDecision(): array
    {
        return array('reason_code' => 'root_fluid_canvas_width', 'declarations' => array('width:100%'));
    }

    /**
     * Keep a root breakpoint frame fluid instead of freezing it to the source canvas.
     *
     * @return array<int, string>
     */
    public function rootFillDeclarations(): array
    {
        return $this->rootFillDecision()['declarations'];
    }

    /**
     * @return array{reason_code: string, declarations: array<int, string>}
     */
    public function fluidFillDecision(): array
    {
        return array('reason_code' => 'fluid_fill_width', 'declarations' => array('width:100%', 'max-width:100%'));
    }

    /**
     * Fill the available responsive inline size without preserving a source max width.
     *
     * @return array<int, string>
     */
    public function fluidFillDeclarations(): array
    {
        return $this->fluidFillDecision()['declarations'];
    }

    /**
     * @return array{reason_code: string, declarations: array<int, string>}
     */
    public function sourceMaxWidthDecision(float $sourceMaxWidth, float $gutter, string $placement): array
    {
        $declarations = array(
            'width:calc(100% - ' . $this->formatNumber($gutter * 2.0) . 'px)',
            'max-width:' . $this->formatNumber($sourceMaxWidth) . 'px',
        );

        if ( 'absolute' === $placement ) {
            $declarations[] = 'left:' . $this->formatNumber($gutter) . 'px';
            $declarations[] = 'right:auto';
            return array('reason_code' => 'source_max_width_absolute_gutter', 'declarations' => $declarations);
        }

        if ( 'centered' === $placement ) {
            $declarations[] = 'margin-left:auto';
            $declarations[] = 'margin-right:auto';
            return array('reason_code' => 'source_max_width_centered_gutter', 'declarations' => $declarations);
        }

        return array('reason_code' => 'source_max_width_fixed_gutter', 'declarations' => $declarations);
    }

    /**
     * Fill the breakpoint viewport with symmetric gutters while preserving source max width.
     *
     * @return array<int, string>
     */
    public function sourceMaxWidthDeclarations(float $sourceMaxWidth, float $gutter, string $placement): array
    {
        return $this->sourceMaxWidthDecision($sourceMaxWidth, $gutter, $placement)['declarations'];
    }

    /**
     * Resolve base canvas width declarations for root, full-bleed children, and centered shells.
     *
     * @return array{reason_code: string, declarations: array<int, string>}
     */
    public function canvasWidthDecision(CanvasShellDecision $canvasShell, bool $isFluidPageWidth, ?float $sourceWidth): array
    {
        if ( $canvasShell->fullBleedCanvasChild ) {
            return array('reason_code' => 'full_bleed_canvas_child_viewport_width', 'declarations' => array('width:100vw'));
        }

        if ( $isFluidPageWidth ) {
            return array('reason_code' => 'fluid_page_canvas_width', 'declarations' => $this->rootFillDeclarations());
        }

        if ( $canvasShell->fluidStretchCanvasChild ) {
            return array('reason_code' => 'fluid_stretch_canvas_child_auto_width', 'declarations' => array('width:auto'));
        }

        if ( $canvasShell->responsiveCenteredFlowShell && $canvasShell->responsiveCenteredFlowWidth && null !== $sourceWidth ) {
            return array(
                'reason_code'  => 'responsive_centered_flow_source_max_width',
                'declarations' => array('width:100%', 'max-width:' . $this->formatNumber($sourceWidth) . 'px'),
            );
        }

        return array('reason_code' => '', 'declarations' => array());
    }

    /**
     * @return array{reason_code: string, declarations: array<int, string>, evidence?: array<string, mixed>}
     */
    public function fullBleedViewportBreakoutDecision(CanvasShellDecision $canvasShell): array
    {
        if ( ! $canvasShell->fullBleedCanvasChild ) {
            return array('reason_code' => '', 'declarations' => array());
        }

        $evidence = $this->fullBleedViewportBreakoutEvidence($canvasShell);

        return array(
            'reason_code'  => 'full_bleed_canvas_child_viewport_breakout',
            'declarations' => array('left:50%', 'margin-left:-50vw'),
            'evidence'     => $evidence,
        );
    }

    /**
     * Explain why viewport breakout uses the mirrored-safe start anchor.
     *
     * @return array<string, mixed>
     */
    private function fullBleedViewportBreakoutEvidence(CanvasShellDecision $canvasShell): array
    {
        return array(
            'frame_width_role'                       => $canvasShell->frameWidthRole,
            'canvas_child_role'                      => $canvasShell->canvasChildRole,
            'parent_renders_fluid_canvas'            => $canvasShell->parentRendersFluidCanvas,
            'parent_uses_fluid_canvas_coordinates'   => $canvasShell->parentUsesFluidCanvasCoordinates,
            'full_bleed_canvas_child'                => $canvasShell->fullBleedCanvasChild,
            'full_bleed_canvas_child_reflected'      => $canvasShell->fullBleedCanvasChildReflected,
            'viewport_anchor_strategy'               => 'mirrored_safe_start_edge',
            'viewport_anchor_declarations'           => array('left:50%', 'margin-left:-50vw'),
        );
    }

    /**
     * Pair fluid responsive chrome with a source-height floor so headers can wrap
     * without collapsing below their desktop visual rhythm.
     *
     * @return array<int, string>
     */
    public function headerChromeDeclarations(?float $sourceHeight): array
    {
        $declarations = array('width:100%', 'max-width:100%', 'height:auto', 'display:flex', 'flex-direction:column', 'align-items:stretch', 'justify-content:flex-start');
        if ( null !== $sourceHeight && $sourceHeight > 0.0 ) {
            $declarations[] = 'min-height:' . ($this->number)($sourceHeight) . 'px';
        }

        return $declarations;
    }

    /**
     * Resolve a variant width override relative to its breakpoint parent.
     *
     * @param array<string, string> $baseMap
     * @param array<string, mixed> $baseNode
     * @param array<string, mixed> $variantNode
     * @param array<string, mixed>|null $baseParentNode
     * @param array<string, mixed>|null $variantParentNode
     * @return array<int, string>|null
     */
    public function breakpointWidthDeclarations(string $value, array $baseMap, array $baseNode, array $variantNode, ?array $baseParentNode, ?array $variantParentNode): ?array
    {
        $decision = $this->breakpointWidthDecision($value, $baseMap, $baseNode, $variantNode, $baseParentNode, $variantParentNode);
        $declarations = is_array($decision['declarations'] ?? null) ? $decision['declarations'] : array();

        return array() === $declarations ? null : $declarations;
    }

    /**
     * Resolve a variant width override and expose the policy branch that made the decision.
     *
     * @param array<string, string> $baseMap
     * @param array<string, mixed> $baseNode
     * @param array<string, mixed> $variantNode
     * @param array<string, mixed>|null $baseParentNode
     * @param array<string, mixed>|null $variantParentNode
     * @return array{reason_code: string, declarations: array<int, string>}
     */
    public function breakpointWidthDecision(string $value, array $baseMap, array $baseNode, array $variantNode, ?array $baseParentNode, ?array $variantParentNode): array
    {
        $variantWidth = $this->cssPixelValue($value);
        if ( null === $variantWidth || empty($variantNode) ) {
            return array('reason_code' => 'not_pixel_width', 'declarations' => array());
        }

        if ( $this->isViewportBreakoutBase($baseMap) ) {
            return array('reason_code' => 'preserve_full_bleed_viewport_breakout', 'declarations' => array('width:100vw', 'left:50%', 'margin-left:-50vw'));
        }

        $variantType = strtoupper((string) ($variantNode['type'] ?? 'FRAME'));
        $variantSourceId = isset($variantNode['figma_component_source_id']) && is_scalar($variantNode['figma_component_source_id']) ? (string) $variantNode['figma_component_source_id'] : '';
        if ( '' === $variantSourceId && isset($variantNode['source_id']) && is_scalar($variantNode['source_id']) ) {
            $variantSourceId = (string) $variantNode['source_id'];
        }
        if ( '' !== $variantSourceId && ! in_array($variantType, array('FRAME', 'GROUP', 'INSTANCE', 'COMPONENT', 'SYMBOL'), true) ) {
            return array('reason_code' => 'component_leaf_width_preserved', 'declarations' => array());
        }

        if ( null === $variantParentNode ) {
            return array('reason_code' => 'root_fill', 'declarations' => $this->rootFillDeclarations());
        }

        $variantParentBox = is_array($variantParentNode['box'] ?? null) ? $variantParentNode['box'] : array();
        if ( ! isset($variantParentBox['width']) || ! is_numeric($variantParentBox['width']) ) {
            return array('reason_code' => 'missing_variant_parent_width', 'declarations' => array());
        }

        $variantParentWidth = (float) $variantParentBox['width'];
        if ( $variantParentWidth <= 0.0 || $variantWidth > $variantParentWidth + 1.0 ) {
            return array('reason_code' => 'invalid_variant_parent_width', 'declarations' => array());
        }

        $baseWidth = $this->nodeBoxWidth($baseNode);
        if ( null !== $baseWidth && abs($variantWidth - $baseWidth) <= 1.0 ) {
            return array('reason_code' => 'unchanged_width', 'declarations' => array());
        }

        $variantParentLayout = is_array($variantParentNode['layout'] ?? null) ? $variantParentNode['layout'] : array();
        $padding = is_array($variantParentLayout['padding'] ?? null) ? $variantParentLayout['padding'] : array();
        $paddingLeft = isset($padding['left']) && is_numeric($padding['left']) ? (float) $padding['left'] : 0.0;
        $paddingRight = isset($padding['right']) && is_numeric($padding['right']) ? (float) $padding['right'] : 0.0;
        $contentWidth = max(0.0, $variantParentWidth - $paddingLeft - $paddingRight);
        if ( abs($variantWidth - $variantParentWidth) <= 1.0 || abs($variantWidth - $contentWidth) <= 1.0 ) {
            return array('reason_code' => 'parent_fill', 'declarations' => array('width:100%'));
        }

        $gutter = ($variantParentWidth - $variantWidth) / 2.0;
        if ( $gutter <= 0.0 ) {
            return array('reason_code' => 'invalid_gutter', 'declarations' => array());
        }

        $baseParentWidth = null === $baseParentNode ? null : $this->nodeBoxWidth($baseParentNode);
        if ( null === $baseWidth || null === $baseParentWidth || $baseWidth > $baseParentWidth + 1.0 ) {
            return array('reason_code' => 'missing_source_max_width', 'declarations' => array());
        }

        $placement = 'absolute' === ($baseMap['position'] ?? null) ? 'absolute' : 'fixed';
        if ( 'absolute' !== $placement && in_array((string) ($baseMap['display'] ?? ''), array('flex', 'inline-flex', 'grid', 'inline-grid'), true) ) {
            $placement = 'centered';
        }

        return array('reason_code' => 'source_max_width_' . $placement, 'declarations' => $this->sourceMaxWidthDeclarations($baseWidth, $gutter, $placement));
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
     */
    private function isViewportBreakoutBase(array $baseMap): bool
    {
        return '100vw' === ($baseMap['width'] ?? null)
            && '50%' === ($baseMap['left'] ?? null)
            && '-50vw' === ($baseMap['margin-left'] ?? null);
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

    private function formatNumber(float $value): string
    {
        if ( is_callable($this->number) ) {
            return ($this->number)($value);
        }

        return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
    }
}
