<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Resolves source-space gaps not represented by a parent's declared flex gap.
 */
final class SourceGeometryFlexGapResolver
{
    /** @var callable(array<string, mixed>, array<string, mixed>): bool */
    private mixed $isNormalFlowChild;

    /**
     * @param callable(array<string, mixed>, array<string, mixed>): bool $isNormalFlowChild
     */
    public function __construct(
        private readonly LayoutIntentClassifier $layoutIntentClassifier,
        callable $isNormalFlowChild
    ) {
        $this->isNormalFlowChild = $isNormalFlowChild;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @return array{axis: string, property: string, value: float}|null
     */
    public function resolve(array $node, ?array $parentNode): ?array
    {
        if ( null === $parentNode || ! ($this->isNormalFlowChild)($node, $parentNode) ) {
            return null;
        }

        $parentLayout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        $direction = (string) ($parentLayout['flex_direction'] ?? '');
        if ( ! in_array($parentLayout['display'] ?? null, array('flex', 'inline-flex'), true)
            || ! $this->participatesInFlow($node, $parentNode)
            || ! in_array($direction, array('row', 'column'), true)
            || ! in_array((string) ($parentLayout['justify_content'] ?? ''), array('', 'flex-start'), true)
            || ! in_array((string) ($parentLayout['flex_wrap'] ?? ''), array('', 'nowrap'), true) ) {
            return null;
        }

        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $axis = 'column' === $direction ? 'y' : 'x';
        $size = 'column' === $direction ? 'height' : 'width';
        if ( ! isset($box[$size]) || ! is_numeric($box[$size]) ) {
            return null;
        }

        $offset = $this->layoutIntentClassifier->positionOffset($box, $parentBox, $axis, $parentNode);
        if ( null === $offset ) {
            return null;
        }

        $previous = null;
        foreach ( $this->nodeList($parentNode) as $sibling ) {
            if ( ! is_array($sibling) || ! $this->participatesInFlow($sibling, $parentNode) ) {
                continue;
            }
            if ( (string) ($sibling['id'] ?? '') === (string) ($node['id'] ?? '') ) {
                break;
            }
            $previous = $sibling;
        }
        if ( null === $previous ) {
            return null;
        }

        $previousBox = is_array($previous['box'] ?? null) ? $previous['box'] : array();
        if ( ! isset($previousBox[$size]) || ! is_numeric($previousBox[$size]) ) {
            return null;
        }
        $previousOffset = $this->layoutIntentClassifier->positionOffset($previousBox, $parentBox, $axis, $parentNode);
        if ( null === $previousOffset ) {
            return null;
        }

        $sourceGap = $offset - ($previousOffset + (float) $previousBox[$size]);
        $cssGap = isset($parentLayout['item_spacing']) && is_numeric($parentLayout['item_spacing']) ? (float) $parentLayout['item_spacing'] : 0.0;
        $residualGap = $sourceGap - $cssGap;
        if ( $residualGap <= 0.5 ) {
            return null;
        }

        return array(
            'axis' => $axis,
            'property' => 'column' === $direction ? 'margin-top' : 'margin-left',
            'value' => $residualGap,
        );
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function participatesInFlow(array $node, array $parentNode): bool
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        return 'absolute' !== ($layout['positioning'] ?? null)
            && ($this->isNormalFlowChild)($node, $parentNode);
    }

    /** @return array<int, mixed> */
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
