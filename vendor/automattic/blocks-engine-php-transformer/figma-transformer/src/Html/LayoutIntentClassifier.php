<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Classifies generic layout intent shared by HTML CSS emission and visual maps.
 */
final class LayoutIntentClassifier
{
    public const LAYOUT_INTENT_FLOW_SECTION = 'flow-section';
    public const LAYOUT_INTENT_STACK = 'stack';
    public const LAYOUT_INTENT_NAV_ROW = 'nav-row';
    public const LAYOUT_INTENT_CARD_ROW = 'card-row';
    public const LAYOUT_INTENT_CARD_GRID = 'card-grid';
    public const LAYOUT_INTENT_PRICING_GRID = 'pricing-grid';
    public const LAYOUT_INTENT_SERVICE_GRID = 'service-grid';
    public const LAYOUT_INTENT_ARTICLE_GRID = 'article-grid';
    public const LAYOUT_INTENT_CTA = 'cta';

    public const STACK_REASON_ABSOLUTE_CHILD = StackingContextPolicy::STACK_REASON_ABSOLUTE_CHILD;
    public const STACK_REASON_DECORATIVE_UNDERLAY = StackingContextPolicy::STACK_REASON_DECORATIVE_UNDERLAY;
    public const STACK_REASON_FREEFORM_CONTAINER = StackingContextPolicy::STACK_REASON_FREEFORM_CONTAINER;
    public const STACK_REASON_MIXED_POSITIONING_CHILDREN = StackingContextPolicy::STACK_REASON_MIXED_POSITIONING_CHILDREN;
    public const STACK_REASON_OVERLAPPING_STACKED_CHILD = StackingContextPolicy::STACK_REASON_OVERLAPPING_STACKED_CHILD;
    public const STACK_REASON_SOURCE_Z_INDEX = StackingContextPolicy::STACK_REASON_SOURCE_Z_INDEX;
    public const STACK_REASON_SIBLING_LAYER_RANK = StackingContextPolicy::STACK_REASON_SIBLING_LAYER_RANK;
    public const STACK_REASON_Z_INDEXED_CHILD = StackingContextPolicy::STACK_REASON_Z_INDEXED_CHILD;

    public const LAYER_ROLE_UNDERLAY = StackingContextPolicy::LAYER_ROLE_UNDERLAY;
    public const LAYER_ROLE_CONTENT = StackingContextPolicy::LAYER_ROLE_CONTENT;
    public const LAYER_ROLE_CHROME = StackingContextPolicy::LAYER_ROLE_CHROME;

    public const CHROME_GROUP_ROLE_HEADER = 'header';
    public const CHROME_GROUP_ROLE_FOOTER = 'footer';
    public const CHROME_GROUP_ROLE_NAVIGATION = 'navigation';
    public const CHROME_GROUP_ROLE_SOCIAL = 'social';
    public const CHROME_GROUP_ROLE_CTA = 'cta';

    /** @var array<int, string> */
    private const FREEFORM_CONTAINER_TYPES = array('FRAME', 'GROUP', 'COMPONENT', 'INSTANCE', 'SECTION');

    /** @var array<int, string> */
    private const PRIMITIVE_VECTOR_SHAPE_TYPES = array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'STAR', 'POLYGON', 'REGULAR_POLYGON', 'RECTANGLE', 'ROUNDED_RECTANGLE');

    /** @var array<int, string> */
    private const VECTOR_SHAPE_CONTAINER_TYPES = array('FRAME', 'GROUP', 'COMPONENT', 'INSTANCE', 'BOOLEAN_OPERATION');

    /** @var array<int, string> */
    private const ASSET_REFERENCE_KEYS = array('asset_id', 'assetId', 'image_ref', 'imageRef', 'imageHash', 'ref');

    /** @var array<int, string> */
    private const PAINT_ASSET_REFERENCE_KEYS = array('imageRef', 'imageHash', 'ref', 'asset_id', 'assetId', 'image_ref');

    /** @var array<int, string> */
    private const PAINT_COLLECTION_KEYS = array('fills', 'strokes', 'background');

    /** @var array<int, string> */
    private const CHROME_NAME_HINTS = array('header', 'footer', 'nav', 'navigation', 'menu', 'social', 'cta', 'call to action');

    /** @var array<int, string> */
    private const CONTROL_LIST_NAME_HINTS = array('pagination', 'page number');

    private ?StackingContextPolicy $stackingContextPolicy = null;

    /**
     * @param array<string, array<string, mixed>> $assetsById
     */
    public function __construct(
        private readonly array $assetsById = array()
    ) {
    }

    /**
     * @param array<string, mixed> $node
     */
    public function isFreeformContainer(array $node): bool
    {
        if ( true === ($node['layout']['freeform'] ?? false) ) {
            return true;
        }

        $children = $this->nodeList($node);
        if ( true === ($node['figma_component']['resolved'] ?? false) && ! empty($children) && $this->hasNoDeclaredDisplay($node) ) {
            return true;
        }

        if ( $this->hasNoDeclaredDisplay($node) && $this->hasPositionedSourceChild($node, $children) ) {
            return true;
        }

        if ( $this->hasNoDeclaredDisplay($node) && $this->hasInsetSingleVisualChild($node, $children) ) {
            return true;
        }

        return $this->hasSingleChildOverflowingLayoutBox($node, $children);
    }

    /**
     * Returns the ids of a container's children when they form a semantic content
     * list: repeated, structurally-similar, text-bearing siblings rather than a
     * compact navigation/chrome cluster.
     *
     * @param array<string, mixed> $container
     * @return array<int, string>
     */
    public function semanticListItemIds(array $container): array
    {
        $name = strtolower((string) ($container['name'] ?? ''));
        foreach ( array_merge(self::CHROME_NAME_HINTS, array('article')) as $hint ) {
            if ( str_contains($name, $hint) ) {
                return array();
            }
        }
        if ( str_contains($name, 'table of contents') || preg_match('/\btoc\b/', $name) ) {
            return array();
        }

        $children = array_values(array_filter($this->nodeList($container), 'is_array'));
        if ( 3 > count($children) ) {
            return array();
        }

        $linkChildCount = $this->linkChildCount($children);
        if ( $linkChildCount >= count($children) && ! $this->hasRichRepeatedContent($children) ) {
            return array();
        }

        $type = strtoupper((string) ($children[0]['type'] ?? ''));
        $heights = array();
        foreach ( $children as $child ) {
            if ( strtoupper((string) ($child['type'] ?? '')) !== $type ) {
                return array();
            }
            if ( ! $this->subtreeHasText($child) ) {
                return array();
            }
            $height = $this->boxValue($child, 'height');
            if ( null !== $height ) {
                $heights[] = $height;
            }
        }

        // Direct text-only lists are usually compact nav/legal rows. A larger
        // text region with several text nodes is content, not a list.
        if ( 'TEXT' === $type ) {
            $containerHeight = $this->boxValue($container, 'height');
            if ( null === $containerHeight || empty($heights) ) {
                return array();
            }
            $maxChildHeight = max($heights);
            if ( $maxChildHeight > 0.0 && $containerHeight > ( $maxChildHeight * 2.0 ) ) {
                return array();
            }
        }

        if ( count($heights) >= 2 ) {
            $min = min($heights);
            $max = max($heights);
            if ( $min > 0.0 && ( $max / $min ) > 1.5 ) {
                return array();
            }
        }

        $ids = array();
        foreach ( $children as $child ) {
            $ids[] = (string) ($child['id'] ?? '');
        }

        return $ids;
    }

    /**
     * @param array<string, mixed>      $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, mixed>|null $grandParentNode
     */
    public function isChromeListContext(array $node, ?array $parentNode, ?array $grandParentNode): bool
    {
        foreach ( array($node, $parentNode, $grandParentNode) as $candidate ) {
            if ( ! is_array($candidate) ) {
                continue;
            }

            if ( null !== $this->chromeGroupRole($candidate, null, 1) ) {
                return true;
            }

            $name = strtolower((string) ($candidate['name'] ?? ''));
            foreach ( array_merge(self::CHROME_NAME_HINTS, self::CONTROL_LIST_NAME_HINTS) as $hint ) {
                if ( str_contains($name, $hint) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Classifies generic website chrome groups independently from the eventual
     * HTML element chosen by the emitter.
     *
     * @param array<string, mixed>      $node
     * @param array<string, mixed>|null $parentNode
     */
    public function chromeGroupRole(array $node, ?array $parentNode, int $depth): ?string
    {
        if ( $depth <= 0 ) {
            return null;
        }

        $name = strtolower((string) ($node['name'] ?? ''));
        $children = array_values(array_filter($this->nodeList($node), 'is_array'));

        if ( str_contains($name, 'header') && (str_contains($name, 'menu') || str_contains($name, 'nav')) && $this->isNavigationContainer($children) ) {
            return self::CHROME_GROUP_ROLE_NAVIGATION;
        }

        if ( str_contains($name, 'social') && $this->isSocialIconCluster($node, $children) ) {
            return self::CHROME_GROUP_ROLE_SOCIAL;
        }

        if ( str_contains($name, 'header') ) {
            return $this->isHeaderChromeCandidate($node, $children, $depth, $parentNode) ? self::CHROME_GROUP_ROLE_HEADER : null;
        }

        if ( str_contains($name, 'footer') ) {
            return $this->isFooterChromeCandidate($node, $depth, $parentNode) ? self::CHROME_GROUP_ROLE_FOOTER : null;
        }

        if ( (str_contains($name, 'nav') || str_contains($name, 'menu')) && ! $this->isMenuItemName($name) ) {
            return $this->isNavigationContainer($children) ? self::CHROME_GROUP_ROLE_NAVIGATION : null;
        }

        if ( $this->isCtaGroup($node, $children) ) {
            return self::CHROME_GROUP_ROLE_CTA;
        }

        if ( empty($children) ) {
            return null;
        }

        $linkCount = $this->linkChildCount($children);
        if ( $depth <= 1 && null !== $parentNode ) {
            $region = $this->verticalRegion($node, $parentNode);
            if ( 'top' === $region && $this->hasLogoChild($children) && ( $linkCount >= 1 || count($children) >= 2 ) ) {
                return self::CHROME_GROUP_ROLE_HEADER;
            }
            if ( 'bottom' === $region && $this->hasLegalText($node) && $this->textDescendantCount($node) <= 12 ) {
                return self::CHROME_GROUP_ROLE_FOOTER;
            }
        }

        if ( $this->isSocialIconCluster($node, $children) ) {
            return self::CHROME_GROUP_ROLE_SOCIAL;
        }

        if ( $linkCount >= 2 && $linkCount === count($children) ) {
            return self::CHROME_GROUP_ROLE_NAVIGATION;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $container
     */
    public function semanticListLooksOrdered(array $container): bool
    {
        $itemIds = $this->semanticListItemIds($container);
        if ( empty($itemIds) ) {
            return false;
        }

        $expected = 1;
        foreach ( $this->nodeList($container) as $child ) {
            if ( ! is_array($child) || ! in_array((string) ($child['id'] ?? ''), $itemIds, true) ) {
                continue;
            }

            $children = array_values(array_filter($this->nodeList($child), 'is_array'));
            $hasExpectedMarker = false;
            foreach ( $children as $itemChild ) {
                if ( $this->isListMarkerTextChild($itemChild, $expected) ) {
                    $hasExpectedMarker = true;
                    break;
                }
            }
            if ( ! $hasExpectedMarker ) {
                return false;
            }
            ++$expected;
        }

        return $expected > 2;
    }

    /**
     * Classifies broad layout intent for static HTML artifacts. This stays at
     * the HTML/CSS seam so downstream importers can consume intent without this
     * transformer knowing about any block system.
     *
     * @param array<string, mixed>      $node
     * @param array<string, mixed>|null $parentNode
     * @return array{intent: string, display: string, direction: string, collection: string|null, item_count: int, column_count: int|null, gap: float|null, confidence: string}|null
     */
    public function layoutIntent(array $node, ?array $parentNode = null): ?array
    {
        $children = $this->layoutContentChildren($node);
        if ( count($children) < 2 ) {
            return null;
        }

        $role = $this->chromeGroupRole($node, $parentNode, null === $parentNode ? 0 : 1);
        if ( self::CHROME_GROUP_ROLE_NAVIGATION === $role || $this->isNavigationContainer($children) ) {
            return $this->layoutIntentResult(self::LAYOUT_INTENT_NAV_ROW, 'flex', 'row', null, $children, 1, $this->averageMainAxisGap($children, 'row'), 'high');
        }
        if ( in_array($role, array(self::CHROME_GROUP_ROLE_HEADER, self::CHROME_GROUP_ROLE_FOOTER, self::CHROME_GROUP_ROLE_SOCIAL), true) ) {
            return null;
        }
        if ( self::CHROME_GROUP_ROLE_CTA === $role ) {
            return $this->layoutIntentResult(self::LAYOUT_INTENT_CTA, 'flex', 'column', null, $children, 1, $this->averageMainAxisGap($children, 'column'), 'medium');
        }

        $shape = $this->childFlowShape($children);
        $name = strtolower((string) ($node['name'] ?? ''));
        $text = strtolower($this->subtreePlainText($node));
        $haystack = $name . ' ' . $text;
        $hasRepeatedContent = $this->hasRichRepeatedContent($children) || $this->repeatedChildShape($children);
        $collection = null;
        $intent = null;

        if ( $hasRepeatedContent && $this->matchesAny($haystack, array('pricing', 'prices', 'plans', 'plan ', '$', '/mo', '/month', 'per month')) ) {
            $intent = self::LAYOUT_INTENT_PRICING_GRID;
            $collection = 'pricing';
        } elseif ( $hasRepeatedContent && $this->matchesAny($name, array('services', 'service', 'treatments', 'treatment', 'features', 'feature')) ) {
            $intent = self::LAYOUT_INTENT_SERVICE_GRID;
            $collection = 'services';
        } elseif ( $hasRepeatedContent && $this->matchesAny($name, array('articles', 'article', 'blog', 'posts', 'post ', 'news', 'journal', 'stories')) ) {
            $intent = self::LAYOUT_INTENT_ARTICLE_GRID;
            $collection = 'articles';
        } elseif ( $hasRepeatedContent && $this->matchesAny($name, array('cards', 'card', 'grid', 'columns')) ) {
            $intent = 'grid' === $shape['display'] ? self::LAYOUT_INTENT_CARD_GRID : self::LAYOUT_INTENT_CARD_ROW;
            $collection = 'cards';
        } elseif ( 'column' === $shape['direction'] && $this->looksLikeSectionName($name) ) {
            $intent = self::LAYOUT_INTENT_FLOW_SECTION;
        } elseif ( 'column' === $shape['direction'] ) {
            $intent = self::LAYOUT_INTENT_STACK;
        }

        if ( null === $intent ) {
            return null;
        }

        $display = in_array($intent, array(self::LAYOUT_INTENT_CARD_GRID, self::LAYOUT_INTENT_PRICING_GRID, self::LAYOUT_INTENT_SERVICE_GRID, self::LAYOUT_INTENT_ARTICLE_GRID), true) ? 'grid' : 'flex';
        $direction = 'grid' === $display ? 'grid' : $shape['direction'];
        return $this->layoutIntentResult($intent, $display, $direction, $collection, $children, 'grid' === $display ? $shape['column_count'] : 1, $shape['gap'], null !== $collection ? 'high' : 'medium');
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasNoDeclaredDisplay(array $node): bool
    {
        return empty($node['layout']['display'] ?? null);
    }

    /**
     * @param array<int, array<string, mixed>> $children
     * @return array{display: string, direction: string, column_count: int, gap: float|null}
     */
    private function childFlowShape(array $children): array
    {
        $rows = array();
        $columns = array();
        foreach ( $children as $child ) {
            $x = $this->boxValue($child, 'x');
            $y = $this->boxValue($child, 'y');
            if ( null === $x || null === $y ) {
                continue;
            }
            $this->appendClusterValue($rows, $y);
            $this->appendClusterValue($columns, $x);
        }

        $rowCount = count($rows);
        $columnCount = max(1, count($columns));
        if ( $rowCount >= 2 && $columnCount >= 2 ) {
            return array('display' => 'grid', 'direction' => 'grid', 'column_count' => $columnCount, 'gap' => $this->averageGridGap($children));
        }

        $direction = $columnCount >= 2 && $rowCount <= 1 ? 'row' : 'column';
        return array('display' => 'flex', 'direction' => $direction, 'column_count' => $columnCount, 'gap' => $this->averageMainAxisGap($children, $direction));
    }

    /** @param array<int, float> $clusters */
    private function appendClusterValue(array &$clusters, float $value): void
    {
        foreach ( $clusters as $cluster ) {
            if ( abs($cluster - $value) <= 8.0 ) {
                return;
            }
        }
        $clusters[] = $value;
        sort($clusters, SORT_NUMERIC);
    }

    /**
     * @param array<int, array<string, mixed>> $children
     */
    private function averageGridGap(array $children): ?float
    {
        return $this->averageMainAxisGap($children, 'row') ?? $this->averageMainAxisGap($children, 'column');
    }

    /**
     * @param array<int, array<string, mixed>> $children
     */
    private function averageMainAxisGap(array $children, string $direction): ?float
    {
        $axis = 'row' === $direction ? 'x' : 'y';
        $size = 'row' === $direction ? 'width' : 'height';
        $items = array();
        foreach ( $children as $child ) {
            $start = $this->boxValue($child, $axis);
            $length = $this->boxValue($child, $size);
            if ( null !== $start && null !== $length ) {
                $items[] = array('start' => $start, 'end' => $start + $length);
            }
        }
        if ( count($items) < 2 ) {
            return null;
        }
        usort($items, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $gaps = array();
        for ( $i = 1; $i < count($items); $i++ ) {
            $gap = $items[$i]['start'] - $items[$i - 1]['end'];
            if ( $gap >= 0.0 && $gap <= 160.0 ) {
                $gaps[] = $gap;
            }
        }
        if ( empty($gaps) ) {
            return null;
        }

        return array_sum($gaps) / count($gaps);
    }

    /**
     * @param array<int, array<string, mixed>> $children
     */
    private function repeatedChildShape(array $children): bool
    {
        if ( count($children) < 2 ) {
            return false;
        }
        $widths = array();
        $heights = array();
        foreach ( $children as $child ) {
            $width = $this->boxValue($child, 'width');
            $height = $this->boxValue($child, 'height');
            if ( null === $width || null === $height || ! $this->subtreeHasText($child) ) {
                return false;
            }
            $widths[] = $width;
            $heights[] = $height;
        }

        return $this->valuesAreNearUniform($widths, 0.35) && $this->valuesAreNearUniform($heights, 0.45);
    }

    /** @param array<int, float> $values */
    private function valuesAreNearUniform(array $values, float $tolerance): bool
    {
        $min = min($values);
        $max = max($values);
        if ( $min <= 0.0 ) {
            return false;
        }

        return (($max - $min) / $min) <= $tolerance;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    private function layoutContentChildren(array $node): array
    {
        $children = array();
        foreach ( $this->nodeList($node) as $child ) {
            if ( ! is_array($child) || false === ($child['visible'] ?? null) ) {
                continue;
            }
            if ( $this->isPrimitiveVisualOnly($child) && ! $this->subtreeHasLink($child) && ! $this->nodeHasImageReference($child) ) {
                continue;
            }
            if ( ! $this->subtreeHasText($child) && ! $this->subtreeHasLink($child) && ! $this->nodeHasImageReference($child) ) {
                continue;
            }
            $children[] = $child;
        }

        return $children;
    }

    /** @param array<string, mixed> $node */
    private function isPrimitiveVisualOnly(array $node): bool
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        return in_array($type, self::PRIMITIVE_VECTOR_SHAPE_TYPES, true) && ! $this->subtreeHasText($node);
    }

    /** @param array<int, string> $needles */
    private function matchesAny(string $haystack, array $needles): bool
    {
        foreach ( $needles as $needle ) {
            if ( str_contains($haystack, $needle) ) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeSectionName(string $name): bool
    {
        return $this->matchesAny($name, array('section', 'hero', 'content', 'intro', 'main', 'cta', 'call to action'));
    }

    /**
     * @param array<int, array<string, mixed>> $children
     * @return array{intent: string, display: string, direction: string, collection: string|null, item_count: int, column_count: int|null, gap: float|null, confidence: string}
     */
    private function layoutIntentResult(string $intent, string $display, string $direction, ?string $collection, array $children, ?int $columnCount, ?float $gap, string $confidence): array
    {
        return array(
            'intent'       => $intent,
            'display'      => $display,
            'direction'    => $direction,
            'collection'   => $collection,
            'item_count'   => count($children),
            'column_count' => $columnCount,
            'gap'          => null === $gap ? null : round($gap, 3),
            'confidence'   => $confidence,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $children
     */
    private function hasRichRepeatedContent(array $children): bool
    {
        foreach ( $children as $child ) {
            if ( $this->textDescendantCount($child) >= 2 ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $children
     */
    private function linkChildCount(array $children): int
    {
        $count = 0;
        foreach ( $children as $child ) {
            if ( $this->subtreeHasLink($child) ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function subtreeHasLink(array $node): bool
    {
        if ( ! empty($node['figma_link']) ) {
            return true;
        }
        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) && $this->subtreeHasLink($child) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function subtreeHasText(array $node): bool
    {
        if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
            return true;
        }
        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) && $this->subtreeHasText($child) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function textDescendantCount(array $node): int
    {
        $count = 0;
        foreach ( $this->nodeList($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }
            if ( 'TEXT' === strtoupper((string) ($child['type'] ?? '')) ) {
                $count++;
            }
            $count += $this->textDescendantCount($child);
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isListMarkerTextChild(array $node, ?int $expectedNumber = null): bool
    {
        if ( 'TEXT' !== strtoupper((string) ($node['type'] ?? '')) ) {
            return false;
        }

        $text = trim($this->subtreePlainText($node));
        if ( '' === $text ) {
            return false;
        }

        if ( null !== $expectedNumber ) {
            return 1 === preg_match('/^' . preg_quote((string) $expectedNumber, '/') . '[.)]?$/', $text);
        }

        return 1 === preg_match('/^\d+[.)]?$/', $text);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function subtreePlainText(array $node): string
    {
        $parts = array();
        $text = $this->nodePlainText($node);
        if ( '' !== $text ) {
            $parts[] = $text;
        }
        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) ) {
                $childText = $this->subtreePlainText($child);
                if ( '' !== $childText ) {
                    $parts[] = $childText;
                }
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodePlainText(array $node): string
    {
        foreach ( array('characters', 'text', 'content') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                return trim((string) $node[$key]);
            }
        }
        if ( isset($node['textData']['characters']) && is_scalar($node['textData']['characters']) ) {
            return trim((string) $node['textData']['characters']);
        }
        if ( isset($node['figma_text']['characters']) && is_scalar($node['figma_text']['characters']) ) {
            return trim((string) $node['figma_text']['characters']);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function boxValue(array $node, string $key): ?float
    {
        if ( isset($node[$key]) && is_numeric($node[$key]) ) {
            return (float) $node[$key];
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        if ( isset($box[$key]) && is_numeric($box[$key]) ) {
            return (float) $box[$key];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, mixed>    $children
     */
    private function hasSingleChildOverflowingLayoutBox(array $node, array $children): bool
    {
        if ( 1 !== count($children) || ! is_array($children[0]) ) {
            return false;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $childBox = is_array($children[0]['box'] ?? null) ? $children[0]['box'] : array();
        if ( ! $this->boxesHaveDimensions($box, $childBox, array('width', 'height')) ) {
            return false;
        }

        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        if ( ! empty($layout['display'] ?? null) ) {
            return $this->flexChildOverflowsMainAxis($layout, $box, $childBox);
        }

        return (float) $childBox['width'] > (float) $box['width'] || (float) $childBox['height'] > (float) $box['height'];
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $box
     * @param array<string, mixed> $childBox
     */
    private function flexChildOverflowsMainAxis(array $layout, array $box, array $childBox): bool
    {
        if ( 'flex' !== ($layout['display'] ?? null) ) {
            return false;
        }

        $mainAxis = 'row' === ($layout['flex_direction'] ?? null) ? 'width' : 'height';
        return (float) $childBox[$mainAxis] > (float) $box[$mainAxis];
    }

    /**
     * @param array<string, mixed> $box
     * @param array<string, mixed> $childBox
     * @param array<int, string>   $dimensions
     */
    private function boxesHaveDimensions(array $box, array $childBox, array $dimensions): bool
    {
        foreach ( $dimensions as $dimension ) {
            if ( ! isset($box[$dimension], $childBox[$dimension]) || ! is_numeric($box[$dimension]) || ! is_numeric($childBox[$dimension]) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, mixed>    $children
     */
    private function hasInsetSingleVisualChild(array $node, array $children): bool
    {
        if ( 1 !== count($children) || ! is_array($children[0]) || ! $this->treeIsVectorShapeOnly($children[0]) ) {
            return false;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $childBox = is_array($children[0]['box'] ?? null) ? $children[0]['box'] : array();
        if ( ! $this->boxesHaveDimensions($box, $childBox, array('width', 'height')) ) {
            return false;
        }

        $widthDelta = (float) $box['width'] - (float) $childBox['width'];
        $heightDelta = (float) $box['height'] - (float) $childBox['height'];
        return ($widthDelta > 0.5 && $widthDelta <= 32.0) || ($heightDelta > 0.5 && $heightDelta <= 32.0);
    }

    /**
     * @param array<string, mixed> $node
     */
    public function hasAbsoluteChild(array $node): bool
    {
        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) && $this->isAbsoluteChild($child) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    public function overlappingSiblingZIndex(array $node, array $parentNode): ?int
    {
        $stackPlan = $this->siblingLayerStackPlan($node, $parentNode);
        return isset($stackPlan['z_index']) && is_int($stackPlan['z_index']) ? $stackPlan['z_index'] : null;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     * @return array{role: string, overlaps_sibling: bool, z_index: int|null}
     */
    public function siblingLayerStackPlan(array $node, array $parentNode): array
    {
        $nodeId = (string) ($node['id'] ?? '');
        if ( '' === $nodeId ) {
            return array('role' => $this->siblingLayerRole($node, $parentNode), 'overlaps_sibling' => false, 'z_index' => null);
        }

        $siblings = array_values(array_filter($this->nodeList($parentNode), 'is_array'));
        $stackedSiblings = array();
        $nodeOverlapsSibling = false;
        foreach ( $siblings as $index => $sibling ) {
            $siblingId = (string) ($sibling['id'] ?? '');
            if ( '' === $siblingId ) {
                continue;
            }

            if ( $siblingId !== $nodeId && $this->nodesOverlapInParent($node, $sibling, $parentNode) ) {
                $nodeOverlapsSibling = true;
            }

            $stackedSiblings[] = array(
                'id'    => $siblingId,
                'index' => $index,
                'key'   => $this->nodeSiblingStackKey($sibling, $parentNode, $index),
            );
        }

        if ( ! $nodeOverlapsSibling ) {
            return array('role' => $this->siblingLayerRole($node, $parentNode), 'overlaps_sibling' => false, 'z_index' => null);
        }

        usort(
            $stackedSiblings,
            static fn (array $left, array $right): int => $left['key'] <=> $right['key']
        );

        foreach ( $stackedSiblings as $rank => $sibling ) {
            if ( $sibling['id'] === $nodeId ) {
                return array('role' => $this->siblingLayerRole($node, $parentNode), 'overlaps_sibling' => true, 'z_index' => $rank + 1);
            }
        }

        return array('role' => $this->siblingLayerRole($node, $parentNode), 'overlaps_sibling' => true, 'z_index' => null);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @return array{manages_local_stacking: bool, needs_isolation: bool, local_reasons: array<int, string>, sibling_role: string|null, overlaps_sibling: bool, z_index: int|null, z_index_reason: string|null}
     */
    public function stackingContextPlan(array $node, ?array $parentNode = null): array
    {
        $localReasons = $this->localStackingReasons($node);
        $isolationReasons = $this->localStackIsolationReasons($node);
        $siblingStackPlan = null !== $parentNode ? $this->siblingLayerStackPlan($node, $parentNode) : array('role' => null, 'overlaps_sibling' => false, 'z_index' => null);
        $isDecorativeUnderlay = null !== $parentNode && ($this->isDecorativeFlexUnderlay($node, $parentNode) || $this->isDecorativeTrackUnderlay($node, $parentNode));

        $sourceZIndex = $this->nodeZIndex($node);
        if ( 'reverse_child_order' === ($node['layout']['z_index_source'] ?? null) && true !== ($siblingStackPlan['overlaps_sibling'] ?? false) && (null === $parentNode || ! $this->hasNegativeAutoLayoutSpacing($parentNode)) ) {
            $sourceZIndex = null;
        }

        return $this->stackingContextPolicy()->plan($localReasons, $isolationReasons, $siblingStackPlan, $isDecorativeUnderlay, $sourceZIndex);
    }

    private function stackingContextPolicy(): StackingContextPolicy
    {
        return $this->stackingContextPolicy ??= new StackingContextPolicy();
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    public function siblingLayerRole(array $node, array $parentNode): string
    {
        if ( $this->hasProtrudingDecorativeUnderlay($node, $parentNode) || $this->isDecorativeFlexUnderlay($node, $parentNode) || $this->isDecorativeTrackUnderlay($node, $parentNode) ) {
            return self::LAYER_ROLE_UNDERLAY;
        }

        return $this->isTopChromeLayer($node, $parentNode) ? self::LAYER_ROLE_CHROME : self::LAYER_ROLE_CONTENT;
    }

    /**
     * @param array<string, mixed> $node
     */
    public function hasOverlappingStackedChild(array $node): bool
    {
        $children = array_values(array_filter($this->nodeList($node), 'is_array'));
        $count = count($children);
        if ( $count < 2 ) {
            return false;
        }

        for ( $left = 0; $left < $count; $left++ ) {
            for ( $right = $left + 1; $right < $count; $right++ ) {
                if ( $this->nodesOverlapInParent($children[$left], $children[$right], $node) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    public function managesLocalStacking(array $node): bool
    {
        return ! empty($this->localStackingReasons($node));
    }

    /**
     * @param array<string, mixed> $node
     */
    public function needsLocalStackIsolation(array $node): bool
    {
        return ! empty($this->localStackIsolationReasons($node));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function localStackingReasons(array $node): array
    {
        $reasons = array();
        if ( $this->hasAbsoluteChild($node) ) {
            $reasons[] = self::STACK_REASON_ABSOLUTE_CHILD;
        }
        if ( $this->hasDecorativeFlexUnderlayChild($node) ) {
            $reasons[] = self::STACK_REASON_DECORATIVE_UNDERLAY;
        }
        if ( $this->isFreeformContainer($node) ) {
            $reasons[] = self::STACK_REASON_FREEFORM_CONTAINER;
        }
        if ( $this->hasOverlappingStackedChild($node) ) {
            $reasons[] = self::STACK_REASON_OVERLAPPING_STACKED_CHILD;
        }

        return $reasons;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function localStackIsolationReasons(array $node): array
    {
        $reasons = array();
        if ( $this->hasDecorativeFlexUnderlayChild($node) ) {
            $reasons[] = self::STACK_REASON_DECORATIVE_UNDERLAY;
        }
        if ( $this->hasMixedPositioningChildren($node) ) {
            $reasons[] = self::STACK_REASON_MIXED_POSITIONING_CHILDREN;
        }
        if ( $this->hasZIndexedChild($node) ) {
            $reasons[] = self::STACK_REASON_Z_INDEXED_CHILD;
        }
        if ( $this->hasOverlappingStackedChild($node) ) {
            $reasons[] = self::STACK_REASON_OVERLAPPING_STACKED_CHILD;
        }

        return $reasons;
    }

    /**
     * @param array<string, mixed> $node
     */
    public function isAbsoluteChild(array $node): bool
    {
        return 'absolute' === ($node['layout']['positioning'] ?? null);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasMixedPositioningChildren(array $node): bool
    {
        $hasAbsolute = false;
        $hasFlow = false;
        foreach ( $this->nodeList($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            if ( $this->isAbsoluteChild($child) ) {
                $hasAbsolute = true;
            } else {
                $hasFlow = true;
            }

            if ( $hasAbsolute && $hasFlow ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasZIndexedChild(array $node): bool
    {
        foreach ( $this->nodeList($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            if ( null !== $this->nodeZIndex($child) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    public function hasDecorativeFlexUnderlayChild(array $node): bool
    {
        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) && $this->isDecorativeFlexUnderlay($child, $node) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    public function isDecorativeFlexUnderlay(array $node, array $parentNode): bool
    {
        $parentLayout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        $isCompactControlUnderlay = $this->isCompactAbsoluteShapeUnderlay($node, $parentNode);
        $isLargeVectorUnderlay = $this->isLargeDecorativeVectorUnderlay($node, $parentNode);
        if ( ! $isCompactControlUnderlay && ! $isLargeVectorUnderlay && ! $this->parentSupportsDecorativeUnderlay($parentNode, $parentLayout) ) {
            return false;
        }

        if ( ! $isCompactControlUnderlay && ! $isLargeVectorUnderlay && ! $this->hasDecorativeUnderlayForegroundEvidence($node, $parentNode) ) {
            return false;
        }

        return $isCompactControlUnderlay
            || $isLargeVectorUnderlay
            || $this->isOversizedAgainstParent($node, $parentNode)
            || $this->isAbsoluteBackgroundBleed($node, $parentNode, $parentLayout)
            || $this->isCompactAbsoluteShapeUnderlay($node, $parentNode);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isDecorativeTrackUnderlay(array $node, array $parentNode): bool
    {
        if ( ! $this->isDecorativeUnderlayVisualCandidate($node) || ! $this->isThinTrackShape($node) ) {
            return false;
        }

        $nodeId = (string) ($node['id'] ?? '');
        foreach ( $this->nodeList($parentNode) as $sibling ) {
            if ( ! is_array($sibling) || (string) ($sibling['id'] ?? '') === $nodeId ) {
                continue;
            }
            if ( $this->isCompactVisualMarker($sibling) && $this->nodesOverlapInParent($node, $sibling, $parentNode) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isThinTrackShape(array $node): bool
    {
        $width = $this->boxValue($node, 'width');
        $height = $this->boxValue($node, 'height');
        if ( null === $width || null === $height || $width <= 0.0 || $height <= 0.0 ) {
            return false;
        }

        return min($width, $height) <= 24.0 && max($width, $height) >= min($width, $height) * 4.0;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isCompactVisualMarker(array $node): bool
    {
        $width = $this->boxValue($node, 'width');
        $height = $this->boxValue($node, 'height');
        if ( null === $width || null === $height || $width <= 0.0 || $height <= 0.0 || $width > 64.0 || $height > 64.0 ) {
            return false;
        }

        $ratio = max($width, $height) / min($width, $height);
        return $ratio <= 2.0 && $this->isDecorativeUnderlayVisualCandidate($node);
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<string, mixed>|null $parentNode
     */
    public function fillsParentFlexMainAxis(array $layout, ?array $parentNode): bool
    {
        $parentLayout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        $parentMainAxisSizingKey = 'column' === ($parentLayout['flex_direction'] ?? null) ? 'sizing_vertical' : 'sizing_horizontal';
        return 'FILL' === ($layout[$parentMainAxisSizingKey] ?? null);
    }

    /**
     * @param array<string, mixed> $box
     * @param array<string, mixed> $parentBox
     */
    public function positionOffset(array $box, array $parentBox, string $dimension, ?array $parentNode = null): ?float
    {
        if ( ! isset($box[$dimension]) || ! is_numeric($box[$dimension]) ) {
            return null;
        }

        if ( 'local' === ($box['coordinate_space'] ?? null) ) {
            return (float) $box[$dimension];
        }

        if ( null !== $parentNode && (! isset($parentBox[$dimension]) ? $this->shouldInferMissingParentOrigin($parentBox, $parentNode, $dimension) : $this->shouldInferRootCanvasOrigin($parentBox, $parentNode, $dimension)) ) {
            $origin = $this->inferredContainingBlockOrigin($parentNode, $dimension);
            if ( null !== $origin ) {
                return (float) $box[$dimension] - $origin;
            }
        }

        return $this->relativeOffset($box, $parentBox, $dimension);
    }

    /**
     * @param array<string, mixed> $box
     * @param array<string, mixed> $parentBox
     */
    public function relativeOffset(array $box, array $parentBox, string $dimension): ?float
    {
        if ( ! isset($box[$dimension]) || ! is_numeric($box[$dimension]) ) {
            return null;
        }

        $offset = (float) $box[$dimension];
        if ( isset($parentBox[$dimension]) && is_numeric($parentBox[$dimension]) ) {
            $offset -= (float) $parentBox[$dimension];
        }

        return $offset;
    }

    /**
     * @param array<string, mixed> $node
     */
    public function isClippableDecorativeVisualNode(array $node): bool
    {
        return $this->isDecorativeUnderlayVisualCandidate($node);
    }

    /**
     * @param array<string, mixed> $layout
     */
    private function parentSupportsDecorativeUnderlay(array $parentNode, array $layout): bool
    {
        return in_array((string) ($layout['display'] ?? ''), array('flex', 'inline-flex'), true)
            || $this->isFreeformContainer($parentNode);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function hasDecorativeUnderlayForegroundEvidence(array $node, array $parentNode): bool
    {
        $parentLayout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        $requiresTextOverlap = ! in_array((string) ($parentLayout['display'] ?? ''), array('flex', 'inline-flex'), true);

        return $this->isDecorativeUnderlayVisualCandidate($node)
            && $this->parentHasTextOutsideNode($parentNode, $node)
            && (! $requiresTextOverlap || $this->nodeOverlapsTextOutsideNode($parentNode, $node))
            && $this->nodeIsBehindTextOutsideNode($parentNode, $node);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isDecorativeUnderlayVisualCandidate(array $node): bool
    {
        return ! $this->treeHasText($node) && ! $this->treeHasImageReference($node) && $this->treeIsShapeOnlyPrimitiveVisual($node);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isLargeDecorativeVectorUnderlay(array $node, array $parentNode): bool
    {
        if ( $this->isDecorativeUnderlayVisualCandidate($parentNode) || ! $this->isDecorativeUnderlayVisualCandidate($node) || ! $this->isOversizedAgainstParent($node, $parentNode) ) {
            return false;
        }

        $name = strtolower((string) ($node['name'] ?? ''));
        $parentName = strtolower((string) ($parentNode['name'] ?? ''));
        if ( str_contains($name, 'logo') || str_contains($parentName, 'logo') || str_contains($parentName, 'social') ) {
            return false;
        }

        foreach ( array('background', 'bg', 'underlay', 'decorative', 'artwork', 'illustration') as $hint ) {
            if ( str_contains($name, $hint) || str_contains($parentName, $hint) ) {
                return true;
            }
        }

        return $this->parentHasTextOutsideNode($parentNode, $node)
            && $this->nodeOverlapsTextOutsideNode($parentNode, $node)
            && $this->nodeIsBehindTextOutsideNode($parentNode, $node);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, mixed> $children
     */
    private function hasPositionedSourceChild(array $node, array $children): bool
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( ! in_array($type, self::FREEFORM_CONTAINER_TYPES, true) ) {
            return false;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        foreach ( $children as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $childBox = is_array($child['box'] ?? null) ? $child['box'] : array();
            $left = $this->positionOffset($childBox, $box, 'x', $node);
            $top = $this->positionOffset($childBox, $box, 'y', $node);
            if ( (null !== $left && abs($left) > 0.5) || (null !== $top && abs($top) > 0.5) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $parentBox
     * @param array<string, mixed> $parentNode
     */
    private function shouldInferRootCanvasOrigin(array $parentBox, array $parentNode, string $dimension): bool
    {
        if ( ! isset($parentBox[$dimension]) || ! is_numeric($parentBox[$dimension]) ) {
            return false;
        }

        if ( ! empty($parentNode['_parent_id']) ) {
            return false;
        }

        $origin = $this->inferredContainingBlockOrigin($parentNode, $dimension);
        if ( null === $origin ) {
            return false;
        }

        $parentOrigin = (float) $parentBox[$dimension];
        if ( 0.0 === $parentOrigin ) {
            return $origin < 0.0 || $this->hasRootCanvasOriginMismatch($parentBox, $parentNode);
        }

        return ($origin < 0.0 && ($parentOrigin - $origin) >= 100.0)
            || $this->hasRootCanvasOriginMismatch($parentBox, $parentNode);
    }

    /**
     * @param array<string, mixed> $parentBox
     * @param array<string, mixed> $parentNode
     */
    private function shouldInferMissingParentOrigin(array $parentBox, array $parentNode, string $dimension): bool
    {
        $origin = $this->inferredContainingBlockOrigin($parentNode, $dimension);
        if ( null === $origin ) {
            return false;
        }

        foreach ( array('x' => 'width', 'y' => 'height') as $originDimension => $sizeKey ) {
            $origin = $this->inferredContainingBlockOrigin($parentNode, $originDimension);
            if ( null === $origin ) {
                continue;
            }

            $parentSize = isset($parentBox[$sizeKey]) && is_numeric($parentBox[$sizeKey]) ? (float) $parentBox[$sizeKey] : null;
            if ( abs($origin) >= 1000.0 || (null !== $parentSize && $origin > $parentSize + 100.0) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $parentBox
     * @param array<string, mixed> $parentNode
     */
    private function hasRootCanvasOriginMismatch(array $parentBox, array $parentNode): bool
    {
        foreach ( array('x', 'y') as $dimension ) {
            $origin = $this->inferredContainingBlockOrigin($parentNode, $dimension);
            if ( null === $origin || ! isset($parentBox[$dimension]) || ! is_numeric($parentBox[$dimension]) ) {
                continue;
            }

            $parentOrigin = (float) $parentBox[$dimension];
            $sizeKey = 'x' === $dimension ? 'width' : 'height';
            $parentSize = isset($parentBox[$sizeKey]) && is_numeric($parentBox[$sizeKey]) ? (float) $parentBox[$sizeKey] : null;
            if ( abs($origin - $parentOrigin) >= 1000.0 || (null !== $parentSize && $origin > $parentOrigin + $parentSize + 100.0) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $parentNode
     */
    private function inferredContainingBlockOrigin(array $parentNode, string $dimension): ?float
    {
        $preferredOrigin = null;
        $fallbackOrigin = null;
        foreach ( $this->nodeList($parentNode) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $childBox = is_array($child['box'] ?? null) ? $child['box'] : array();
            if ( 'local' === ($childBox['coordinate_space'] ?? null) || ! isset($childBox[$dimension]) || ! is_numeric($childBox[$dimension]) ) {
                continue;
            }

            $value = (float) $childBox[$dimension];
            $fallbackOrigin = null === $fallbackOrigin ? $value : min($fallbackOrigin, $value);
            if ( $this->isContainingBlockOriginCandidate($child) ) {
                $preferredOrigin = null === $preferredOrigin ? $value : min($preferredOrigin, $value);
            }
        }

        return $preferredOrigin ?? $fallbackOrigin;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isContainingBlockOriginCandidate(array $node): bool
    {
        return $this->treeHasText($node) || $this->isImageBackedLandmark($node) || ! $this->treeIsShapeOnlyPrimitiveVisual($node);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isImageBackedLandmark(array $node): bool
    {
        return $this->treeHasImageReference($node);
    }

    /**
     * @param array<string, mixed> $parentNode
     * @param array<string, mixed> $node
     */
    private function parentHasTextOutsideNode(array $parentNode, array $node): bool
    {
        $nodeId = (string) ($node['id'] ?? '');
        foreach ( $this->nodeList($parentNode) as $sibling ) {
            if ( ! is_array($sibling) || (string) ($sibling['id'] ?? '') === $nodeId ) {
                continue;
            }
            if ( $this->treeHasText($sibling) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $parentNode
     * @param array<string, mixed> $node
     */
    private function nodeIsBehindTextOutsideNode(array $parentNode, array $node): bool
    {
        $nodeId = (string) ($node['id'] ?? '');
        $nodeZIndex = $this->nodeZIndex($node);
        $siblings = $this->nodeList($parentNode);
        $nodeSiblingIndex = $this->nodeSiblingIndex($siblings, $nodeId);

        foreach ( $siblings as $index => $sibling ) {
            if ( ! is_array($sibling) || (string) ($sibling['id'] ?? '') === $nodeId || ! $this->treeHasText($sibling) ) {
                continue;
            }

            if ( $this->nodeHasPaintOrderEvidenceBehindSibling($node, $sibling, $nodeZIndex, $nodeSiblingIndex, (int) $index) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $parentNode
     * @param array<string, mixed> $node
     */
    private function nodeOverlapsTextOutsideNode(array $parentNode, array $node): bool
    {
        $nodeId = (string) ($node['id'] ?? '');
        foreach ( $this->nodeList($parentNode) as $sibling ) {
            if ( ! is_array($sibling) || (string) ($sibling['id'] ?? '') === $nodeId || ! $this->treeHasText($sibling) ) {
                continue;
            }

            if ( $this->nodesOverlapInParent($node, $sibling, $parentNode) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $siblings
     */
    private function nodeSiblingIndex(array $siblings, string $nodeId): ?int
    {
        foreach ( $siblings as $index => $sibling ) {
            if ( is_array($sibling) && (string) ($sibling['id'] ?? '') === $nodeId ) {
                return (int) $index;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $sibling
     */
    private function nodeHasPaintOrderEvidenceBehindSibling(array $node, array $sibling, ?int $nodeZIndex, ?int $nodeSiblingIndex, int $siblingIndex): bool
    {
        $siblingZIndex = $this->nodeZIndex($sibling);
        if ( null !== $nodeZIndex && null !== $siblingZIndex ) {
            return $nodeZIndex < $siblingZIndex;
        }

        $nodeOrder = $this->nodeSourceOrder($node) ?? $nodeSiblingIndex;
        $siblingOrder = $this->nodeSourceOrder($sibling) ?? $siblingIndex;
        return null !== $nodeOrder && $nodeOrder < $siblingOrder;
    }

    /**
     * @param array<string, mixed> $node
     * @return array{0: int, 1: int|float|string, 2: int, 3: string}
     */
    private function nodePaintOrderKey(array $node, int $fallbackIndex): array
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        if ( isset($layout['layer_order']) && is_scalar($layout['layer_order']) ) {
            $layerOrder = (string) $layout['layer_order'];
            return array(0, is_numeric($layerOrder) ? (float) $layerOrder : $layerOrder, $fallbackIndex, (string) ($node['id'] ?? ''));
        }

        $sourceOrder = $this->nodeSourceOrder($node);
        return array(1, null === $sourceOrder ? $fallbackIndex : $sourceOrder, $fallbackIndex, (string) ($node['id'] ?? ''));
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     * @return array<int, int|float|string>
     */
    private function nodeSiblingStackKey(array $node, array $parentNode, int $fallbackIndex): array
    {
        return array_merge(
            array($this->siblingLayerRoleRank($node, $parentNode)),
            $this->nodePaintOrderKey($node, $fallbackIndex)
        );
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function siblingLayerRoleRank(array $node, array $parentNode): int
    {
        $role = $this->siblingLayerRole($node, $parentNode);
        if ( self::LAYER_ROLE_CONTENT === $role && $this->isHeroMediaLayerOverTopChromeUnderlay($node, $parentNode) ) {
            return 3;
        }
        if ( self::LAYER_ROLE_CONTENT === $role && $this->isTopChromePrimitiveVisual($node, $parentNode) ) {
            return 2;
        }

        return match ( $role ) {
            self::LAYER_ROLE_UNDERLAY => 0,
            self::LAYER_ROLE_CHROME => 2,
            default => 1,
        };
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isHeroMediaLayerOverTopChromeUnderlay(array $node, array $parentNode): bool
    {
        if ( $this->treeHasText($node) || ! $this->treeHasImageReference($node) ) {
            return false;
        }

        $rect = $this->nodeVisualRectInParent($node, $parentNode);
        if ( null === $rect || $rect['height'] < 160.0 ) {
            return false;
        }

        $nodeId = (string) ($node['id'] ?? '');
        foreach ( $this->nodeList($parentNode) as $sibling ) {
            if ( ! is_array($sibling) || (string) ($sibling['id'] ?? '') === $nodeId ) {
                continue;
            }
            if ( ! $this->nodesOverlapInParent($node, $sibling, $parentNode) ) {
                continue;
            }
            if ( $this->isTopChromePrimitiveVisual($sibling, $parentNode) || self::LAYER_ROLE_UNDERLAY === $this->siblingLayerRole($sibling, $parentNode) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isTopChromePrimitiveVisual(array $node, array $parentNode): bool
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( ! in_array($type, self::PRIMITIVE_VECTOR_SHAPE_TYPES, true) ) {
            return false;
        }

        $rect = $this->nodeRectInParent($node, $parentNode);
        if ( null === $rect || $rect['y'] < -0.5 ) {
            return false;
        }

        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        $parentHeight = isset($parentBox['height']) && is_numeric($parentBox['height']) ? (float) $parentBox['height'] : null;
        $topChromeLimit = null === $parentHeight ? 48.0 : max(48.0, min(160.0, $parentHeight * 0.05));
        if ( $rect['y'] > $topChromeLimit ) {
            return false;
        }

        return $rect['height'] <= max(160.0, null === $parentHeight ? 0.0 : $parentHeight * 0.25);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isTopChromeLayer(array $node, array $parentNode): bool
    {
        $role = $this->chromeGroupRole($node, $parentNode, 1);
        $name = strtolower(trim((string) ($node['name'] ?? '')));
        if ( ! in_array($role, array(self::CHROME_GROUP_ROLE_HEADER, self::CHROME_GROUP_ROLE_NAVIGATION), true) && ! str_contains($name, 'header') ) {
            return false;
        }

        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( ! in_array($type, self::FREEFORM_CONTAINER_TYPES, true) ) {
            return false;
        }

        $rect = $this->nodeRectInParent($node, $parentNode);
        if ( null === $rect || $rect['y'] < -0.5 ) {
            return false;
        }

        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        $parentHeight = isset($parentBox['height']) && is_numeric($parentBox['height']) ? (float) $parentBox['height'] : null;
        $topChromeLimit = null === $parentHeight ? 48.0 : max(48.0, min(160.0, $parentHeight * 0.05));
        if ( $rect['y'] > $topChromeLimit ) {
            return false;
        }

        return $rect['height'] <= max(160.0, null === $parentHeight ? 0.0 : $parentHeight * 0.25);
    }

    /**
     * @param array<string, mixed>             $node
     * @param array<int, array<string, mixed>> $children
     * @param array<string, mixed>|null        $parentNode
     */
    private function isHeaderChromeCandidate(array $node, array $children, int $depth, ?array $parentNode): bool
    {
        if ( null === $parentNode ) {
            return false;
        }

        $name = strtolower((string) ($node['name'] ?? ''));
        if ( str_contains($name, 'header') && ($this->hasLogoChild($children) || $this->linkChildCount($children) >= 1 || $this->hasNavigationTextRun(strtolower($this->subtreePlainText($node))) || $this->hasCtaChild($children)) ) {
            $rect = $this->nodeRectInParent($node, $parentNode);
            if ( null !== $rect ) {
                $parentHeight = $this->boxValue($parentNode, 'height');
                $topChromeLimit = null === $parentHeight ? 160.0 : max(160.0, $parentHeight * 0.05);
                if ( $rect['y'] >= -0.5 && $rect['y'] <= $topChromeLimit && $rect['height'] <= max(240.0, null === $parentHeight ? 0.0 : $parentHeight * 0.25) ) {
                    return true;
                }
            }
        }

        return 'top' === $this->verticalRegion($node, $parentNode)
            && ($this->hasLogoChild($children) || $this->linkChildCount($children) >= 1 || $depth <= 1);
    }

    /**
     * @param array<int, array<string, mixed>> $children
     */
    private function hasCtaChild(array $children): bool
    {
        foreach ( $children as $child ) {
            if ( $this->isCtaGroup($child, array_values(array_filter($this->nodeList($child), 'is_array'))) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>      $node
     * @param array<string, mixed>|null $parentNode
     */
    private function isFooterChromeCandidate(array $node, int $depth, ?array $parentNode): bool
    {
        if ( null !== $parentNode && ( 'bottom' === $this->verticalRegion($node, $parentNode) || $this->hasLegalText($node) || $depth <= 1 ) ) {
            return true;
        }

        $text = strtolower($this->subtreePlainText($node));
        return str_contains($text, 'copyright')
            || str_contains($text, 'all rights reserved')
            || (str_contains($text, 'contact') && str_contains($text, 'location'))
            || ($this->hasNavigationTextRun($text) && $this->textDescendantCount($node) <= 8);
    }

    /**
     * @param array<int, array<string, mixed>> $children
     */
    private function isNavigationContainer(array $children): bool
    {
        if ( empty($children) ) {
            return false;
        }

        $linkCount = $this->linkChildCount($children);
        if ( $linkCount >= 2 && $linkCount === count($children) ) {
            return true;
        }

        $textCount = 0;
        foreach ( $children as $child ) {
            if ( 'TEXT' === strtoupper((string) ($child['type'] ?? '')) || $this->isMenuItemName(strtolower((string) ($child['name'] ?? ''))) ) {
                $textCount++;
            }
        }

        return $textCount >= 2 && $textCount === count($children);
    }

    private function hasNavigationTextRun(string $text): bool
    {
        $matches = preg_match_all('/\b(home|about|services|reviews|faq|contact|news|blog|appointments?|handouts?)\b/', $text);
        return false !== $matches && $matches >= 3;
    }

    private function isMenuItemName(string $lowerName): bool
    {
        return 1 === preg_match('/\b(menu|nav(?:igation)?)\s*item\b|\bitem\s*(menu|nav(?:igation)?)\b/', $lowerName);
    }

    /**
     * @param array<string, mixed>             $node
     * @param array<int, array<string, mixed>> $children
     */
    private function isSocialIconCluster(array $node, array $children): bool
    {
        if ( count($children) < 2 ) {
            return false;
        }

        $nameAndText = strtolower((string) ($node['name'] ?? '') . ' ' . $this->subtreePlainText($node));
        $hasSocialSignal = 1 === preg_match('/\b(social|facebook|instagram|linkedin|twitter|x social|x\.com|youtube|tiktok|pinterest)\b/', $nameAndText);
        if ( ! $hasSocialSignal ) {
            return false;
        }

        $compactVisualCount = 0;
        foreach ( $children as $child ) {
            if ( $this->subtreeHasLink($child) || $this->isCompactVisualOnlyNode($child) ) {
                $compactVisualCount++;
            }
        }

        return $compactVisualCount === count($children);
    }

    /**
     * @param array<string, mixed>             $node
     * @param array<int, array<string, mixed>> $children
     */
    private function isCtaGroup(array $node, array $children): bool
    {
        $nameAndText = strtolower((string) ($node['name'] ?? '') . ' ' . $this->subtreePlainText($node));
        if ( 1 !== preg_match('/\b(cta|call to action|book now|get started|sign up|subscribe|contact us|learn more)\b/', $nameAndText) ) {
            return false;
        }

        if ( count($children) > 0 && count($children) <= 3 ) {
            return true;
        }

        $width = $this->boxValue($node, 'width');
        $height = $this->boxValue($node, 'height');
        return null !== $width && null !== $height && $width <= 640.0 && $height <= 220.0 && $this->textDescendantCount($node) <= 3;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isCompactVisualOnlyNode(array $node): bool
    {
        if ( $this->treeHasText($node) ) {
            return false;
        }

        $width = $this->boxValue($node, 'width');
        $height = $this->boxValue($node, 'height');
        if ( null !== $width && $width > 96.0 ) {
            return false;
        }
        if ( null !== $height && $height > 96.0 ) {
            return false;
        }

        return $this->treeIsShapeOnlyPrimitiveVisual($node) || $this->treeHasImageReference($node);
    }

    /**
     * @param array<int, array<string, mixed>> $children
     */
    private function hasLogoChild(array $children): bool
    {
        foreach ( $children as $child ) {
            $name = strtolower((string) ($child['name'] ?? ''));
            if ( str_contains($name, 'logo') || str_contains($name, 'brand') ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasLegalText(array $node): bool
    {
        $text = strtolower($this->subtreePlainText($node));
        return str_contains($text, '©') || str_contains($text, 'copyright') || str_contains($text, 'rights reserved');
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function verticalRegion(array $node, array $parentNode): ?string
    {
        $siblings = array_values(array_filter($this->nodeList($parentNode), 'is_array'));
        if ( 2 > count($siblings) ) {
            return 'middle';
        }

        $thisId = (string) ($node['id'] ?? '');
        $positions = array();
        $haveAll = true;
        foreach ( $siblings as $sibling ) {
            $y = $this->boxValue($sibling, 'y');
            if ( null === $y ) {
                $haveAll = false;
                break;
            }
            $positions[(string) ($sibling['id'] ?? '')] = $y;
        }

        if ( $haveAll && isset($positions[$thisId]) ) {
            $y = $positions[$thisId];
            if ( $y <= min($positions) ) {
                return 'top';
            }
            if ( $y >= max($positions) ) {
                return 'bottom';
            }

            return 'middle';
        }

        $firstId = (string) ($siblings[0]['id'] ?? '');
        $lastId = (string) ($siblings[count($siblings) - 1]['id'] ?? '');
        if ( $thisId === $firstId ) {
            return 'top';
        }
        if ( $thisId === $lastId ) {
            return 'bottom';
        }

        return 'middle';
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function hasProtrudingDecorativeUnderlay(array $node, array $parentNode): bool
    {
        $nodeRect = $this->nodeRectInParent($node, $parentNode);
        if ( null === $nodeRect ) {
            return false;
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( ! is_array($child) || ! $this->isDecorativeFlexUnderlay($child, $node) ) {
                continue;
            }
            $childRect = $this->nodeRectInParent($child, $node);
            if ( null === $childRect ) {
                continue;
            }
            if ( $childRect['x'] < -0.5 || $childRect['y'] < -0.5 || $childRect['x'] + $childRect['width'] > $nodeRect['width'] + 0.5 || $childRect['y'] + $childRect['height'] > $nodeRect['height'] + 0.5 ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $sibling
     * @param array<string, mixed> $parentNode
     */
    private function nodesOverlapInParent(array $node, array $sibling, array $parentNode): bool
    {
        $nodeRect = $this->nodeVisualRectInParent($node, $parentNode);
        $siblingRect = $this->nodeVisualRectInParent($sibling, $parentNode);
        if ( null === $nodeRect || null === $siblingRect ) {
            return false;
        }

        return $nodeRect['x'] < $siblingRect['x'] + $siblingRect['width']
            && $nodeRect['x'] + $nodeRect['width'] > $siblingRect['x']
            && $nodeRect['y'] < $siblingRect['y'] + $siblingRect['height']
            && $nodeRect['y'] + $nodeRect['height'] > $siblingRect['y'];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private function nodeRectInParent(array $node, array $parentNode): ?array
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        foreach ( array('width', 'height') as $dimension ) {
            if ( ! isset($box[$dimension]) || ! is_numeric($box[$dimension]) ) {
                return null;
            }
        }

        $x = $this->positionOffset($box, $parentBox, 'x', $parentNode);
        $y = $this->positionOffset($box, $parentBox, 'y', $parentNode);
        if ( null === $x || null === $y ) {
            return null;
        }

        return array('x' => $x, 'y' => $y, 'width' => (float) $box['width'], 'height' => (float) $box['height']);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private function nodeVisualRectInParent(array $node, array $parentNode): ?array
    {
        $rect = $this->nodeRectInParent($node, $parentNode);
        if ( null === $rect ) {
            return null;
        }
        $baseRect = $rect;

        foreach ( $this->nodeList($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }
            $childRect = $this->nodeVisualRectInParent($child, $node);
            if ( null === $childRect ) {
                continue;
            }
            $rect = $this->unionRects($rect, array(
                'x'      => $baseRect['x'] + $childRect['x'],
                'y'      => $baseRect['y'] + $childRect['y'],
                'width'  => $childRect['width'],
                'height' => $childRect['height'],
            ));
        }

        return $rect;
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $left
     * @param array{x: float, y: float, width: float, height: float} $right
     * @return array{x: float, y: float, width: float, height: float}
     */
    private function unionRects(array $left, array $right): array
    {
        $x1 = min($left['x'], $right['x']);
        $y1 = min($left['y'], $right['y']);
        $x2 = max($left['x'] + $left['width'], $right['x'] + $right['width']);
        $y2 = max($left['y'] + $left['height'], $right['y'] + $right['height']);

        return array('x' => $x1, 'y' => $y1, 'width' => $x2 - $x1, 'height' => $y2 - $y1);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeZIndex(array $node): ?int
    {
        return isset($node['layout']['z_index']) && is_numeric($node['layout']['z_index']) ? (int) $node['layout']['z_index'] : null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasNegativeAutoLayoutSpacing(array $node): bool
    {
        return isset($node['layout']['item_spacing']) && is_numeric($node['layout']['item_spacing']) && (float) $node['layout']['item_spacing'] < 0.0;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeSourceOrder(array $node): ?int
    {
        if ( isset($node['layout']['source_order']) && is_numeric($node['layout']['source_order']) ) {
            return (int) $node['layout']['source_order'];
        }

        return isset($node['_source_order']) && is_numeric($node['_source_order']) ? (int) $node['_source_order'] : null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isOversizedAgainstParent(array $node, array $parentNode): bool
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        foreach ( array('width', 'height') as $dimension ) {
            if ( ! isset($box[$dimension], $parentBox[$dimension]) || ! is_numeric($box[$dimension]) || ! is_numeric($parentBox[$dimension]) || 0.0 >= (float) $parentBox[$dimension] ) {
                return false;
            }
        }

        if ( (float) $box['width'] < 300.0 && (float) $box['height'] < 300.0 ) {
            return false;
        }

        $widthRatio = (float) $box['width'] / (float) $parentBox['width'];
        $heightRatio = (float) $box['height'] / (float) $parentBox['height'];
        $areaRatio = ((float) $box['width'] * (float) $box['height']) / ((float) $parentBox['width'] * (float) $parentBox['height']);

        return 0.75 <= $widthRatio || 0.75 <= $heightRatio || 0.45 <= $areaRatio;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     * @param array<string, mixed> $parentLayout
     */
    private function isAbsoluteBackgroundBleed(array $node, array $parentNode, array $parentLayout): bool
    {
        if ( 'absolute' !== ($node['layout']['positioning'] ?? null) ) {
            return false;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        foreach ( array('width', 'height') as $dimension ) {
            if ( ! isset($box[$dimension], $parentBox[$dimension]) || ! is_numeric($box[$dimension]) || ! is_numeric($parentBox[$dimension]) || 0.0 >= (float) $parentBox[$dimension] ) {
                return false;
            }
        }

        $isRow = 'row' === ($parentLayout['flex_direction'] ?? null);
        $mainAxis = $isRow ? 'width' : 'height';
        $crossAxis = $isRow ? 'height' : 'width';
        $crossOrigin = $isRow ? 'y' : 'x';
        $mainRatio = (float) $box[$mainAxis] / (float) $parentBox[$mainAxis];
        if ( 0.95 > $mainRatio ) {
            return false;
        }

        $crossOffset = $this->positionOffset($box, $parentBox, $crossOrigin, $parentNode);
        if ( null === $crossOffset ) {
            return false;
        }

        $crossSize = (float) $box[$crossAxis];
        $parentCrossSize = (float) $parentBox[$crossAxis];
        return 1.0 <= ($crossSize / $parentCrossSize) || ($crossOffset <= 0.0 && $crossOffset + $crossSize >= $parentCrossSize);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isCompactAbsoluteShapeUnderlay(array $node, array $parentNode): bool
    {
        if ( 'absolute' !== ($node['layout']['positioning'] ?? null) ) {
            return false;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        foreach ( array('width', 'height') as $dimension ) {
            if ( ! isset($box[$dimension], $parentBox[$dimension]) || ! is_numeric($box[$dimension]) || ! is_numeric($parentBox[$dimension]) || 0.0 >= (float) $parentBox[$dimension] ) {
                return false;
            }
        }

        $parentArea = (float) $parentBox['width'] * (float) $parentBox['height'];
        $nodeArea = (float) $box['width'] * (float) $box['height'];
        if ( $parentArea > 9216.0 || ($nodeArea / $parentArea) < 0.45 ) {
            return false;
        }

        foreach ( $this->nodeList($parentNode) as $sibling ) {
            if ( is_array($sibling) && (string) ($sibling['id'] ?? '') !== (string) ($node['id'] ?? '') && $this->treeHasText($sibling) && $this->nodesOverlapInParent($node, $sibling, $parentNode) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function treeHasText(array $node): bool
    {
        if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
            return '' !== trim(strip_tags($this->textContent($node)));
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) && $this->treeHasText($child) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function treeHasImageReference(array $node): bool
    {
        if ( $this->nodeHasImageReference($node) ) {
            return true;
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) && $this->treeHasImageReference($child) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function treeIsVectorShapeOnly(array $node): bool
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        $children = $this->nodeList($node);
        if ( empty($children) ) {
            return $this->isPrimitiveVectorShapeType($type);
        }

        if ( ! $this->isVectorShapeContainerType($type) ) {
            return false;
        }

        foreach ( $children as $child ) {
            if ( ! is_array($child) || ! $this->treeIsVectorShapeOnly($child) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function treeIsShapeOnlyPrimitiveVisual(array $node): bool
    {
        return $this->treeIsVectorShapeOnly($node);
    }

    private function isPrimitiveVectorShapeType(string $type): bool
    {
        return in_array($type, self::PRIMITIVE_VECTOR_SHAPE_TYPES, true);
    }

    private function isVectorShapeContainerType(string $type): bool
    {
        return in_array($type, self::VECTOR_SHAPE_CONTAINER_TYPES, true);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function textContent(array $node): string
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $segments = is_array($text['segments'] ?? null) ? $text['segments'] : array();
        if ( ! empty($segments) ) {
            $content = '';
            foreach ( $segments as $segment ) {
                if ( is_array($segment) && isset($segment['characters']) && is_scalar($segment['characters']) ) {
                    $content .= (string) $segment['characters'];
                }
            }
            if ( '' !== $content ) {
                return htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        $characters = isset($text['characters']) && is_scalar($text['characters']) ? (string) $text['characters'] : (string) ($node['characters'] ?? $node['text'] ?? '');
        if ( $this->isUnresolvedComponentPlaceholderText($node, $characters) ) {
            return '';
        }

        return htmlspecialchars($characters, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isUnresolvedComponentPlaceholderText(array $node, string $characters): bool
    {
        $placeholder = strtolower(trim($characters));
        if ( ! in_array($placeholder, array('button label', 'label'), true) ) {
            return false;
        }

        $id = (string) ($node['id'] ?? '');
        return str_contains($id, '/') || isset($node['figma_component_source_id']);
    }

    private function nodeAssetPath(array $node): ?string
    {
        foreach ( $this->nodeAssetReferences($node) as $assetId ) {
            if ( isset($this->assetsById[$assetId]) ) {
                return (string) $this->assetsById[$assetId]['path'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeHasImageReference(array $node): bool
    {
        return null !== $this->nodeAssetPath($node) || ! empty($this->explicitNodeAssetReferences($node)) || ! empty($this->nodeImagePaints($node));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function nodeAssetReferences(array $node): array
    {
        $references = array();
        foreach ( self::ASSET_REFERENCE_KEYS as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                $references[] = (string) $node[$key];
            }
        }
        foreach ( self::PAINT_COLLECTION_KEYS as $paintKey ) {
            foreach ( is_array($node[$paintKey] ?? null) ? $node[$paintKey] : array() as $paint ) {
                if ( ! is_array($paint) ) {
                    continue;
                }
                foreach ( self::PAINT_ASSET_REFERENCE_KEYS as $key ) {
                    if ( isset($paint[$key]) && is_scalar($paint[$key]) ) {
                        $references[] = (string) $paint[$key];
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($references, static fn (string $reference): bool => '' !== $reference)));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function explicitNodeAssetReferences(array $node): array
    {
        $references = array();
        foreach ( self::ASSET_REFERENCE_KEYS as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
                $references[] = (string) $node[$key];
            }
        }
        if ( is_array($node['image'] ?? null) ) {
            foreach ( self::ASSET_REFERENCE_KEYS as $key ) {
                if ( isset($node['image'][$key]) && is_scalar($node['image'][$key]) && '' !== (string) $node['image'][$key] ) {
                    $references[] = (string) $node['image'][$key];
                }
            }
        }

        return array_values(array_unique($references));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    private function nodeImagePaints(array $node): array
    {
        return VisualLayerEvidence::imagePaints($node);
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
