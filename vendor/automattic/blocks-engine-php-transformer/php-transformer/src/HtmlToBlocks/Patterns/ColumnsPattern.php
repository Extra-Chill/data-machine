<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

/**
 * Recognizes column / split-layout / documentation-sidebar containers and
 * converts them to a core/columns block of core/column children.
 *
 * Extracted verbatim from HtmlTransformer::columnsBlockFromElement() and its
 * private helpers. The recognizer needs three coupling points from the host
 * transformer, supplied as callables so behavior is byte-identical:
 *   - $convertChildren: convertChildren($child, $fallbacks, true) for wrapper
 *     columns (recursion that may emit fallbacks).
 *   - $convertElement:  convertElement($child, $fallbacks, true) for non-wrapper
 *     children (single-element recursion that may return null / emit fallbacks).
 *   - $fallbacks (by reference): the mutable accumulator. Per-child fallbacks are
 *     collected into a local list and array_push'd onto the host accumulator,
 *     preserving the original ordering and counts.
 *
 * This recognizer is invoked at its specific div/section-like call site only and
 * is intentionally NOT registered in PatternRecognizerRegistry: looksLikeColumnsContainer
 * is a broad flex/grid/class heuristic that would misfire ahead of more specific
 * recognizers in the shared dispatch.
 */
final class ColumnsPattern
{
    use PatternDomHelpersTrait;
    use PatternGateHelpersTrait;

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @param callable(DOMElement, array<int, array<string, mixed>>&, bool): array<int, array<string, mixed>> $convertChildren
     * @param callable(DOMElement, array<int, array<string, mixed>>&, bool): (array<string, mixed>|null) $convertElement
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $resolvedStyle
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function match(
        DOMElement $element,
        array &$fallbacks,
        callable $convertChildren,
        callable $convertElement,
        callable $presentationAttributes,
        callable $resolvedStyle,
        callable $createBlock
    ): ?array {
        if ( ! $this->looksLikeColumnsContainer($element, $resolvedStyle($element)) ) {
            return null;
        }

        // core/columns is a flex layout; WordPress rejects it with is-layout-grid.
        // The layout resolver stamps layout:{type:grid} from signals this
        // recognizer's style-based grid bail cannot see (grid-ish class names,
        // class-resolved display:grid), so a would-be columns container whose
        // layout resolves to grid must decline here and demote to core/group,
        // where grid layout is native.
        $layout = ( $presentationAttributes($element) )['layout'] ?? null;
        if ( is_array($layout) && 'grid' === (string) ($layout['type'] ?? '') ) {
            return null;
        }

        $elementChildren = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            if ( XML_COMMENT_NODE === $child->nodeType ) {
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                return null;
            }
            $elementChildren[] = $child;
        }

        if ( count($elementChildren) < 2 ) {
            return null;
        }

        // Explicit grid placement depends on the source parent's track model.
        // core/columns replaces that model with flex and cannot preserve it.
        foreach ( $elementChildren as $child ) {
            $childStyle = strtolower(trim($resolvedStyle($child) . ';' . $this->attr($child, 'style')));
            if ( preg_match('/(?:^|;)\s*grid-(?:column|row|area)\s*:/', $childStyle) ) {
                return null;
            }
        }

        $columns = array();
        $columnFallbacks = array();
        foreach ( $elementChildren as $child ) {
            $children = $this->isColumnWrapperElement($child)
                ? $convertChildren($child, $columnFallbacks, true)
                : array_filter(array( $convertElement($child, $columnFallbacks, true) ));
            $columns[] = $createBlock('core/column', $presentationAttributes($child), $children, $child);
        }
        array_push($fallbacks, ...$columnFallbacks);

        return $createBlock('core/columns', $presentationAttributes($element), $columns, $element);
    }

    private function isColumnWrapperElement(DOMElement $element): bool
    {
        return in_array(strtolower($element->tagName), array( 'article', 'aside', 'div', 'footer', 'header', 'main', 'nav', 'section' ), true);
    }

    private function looksLikeColumnsContainer(DOMElement $element, string $resolvedStyle): bool
    {
        if ( $this->hasClass($element, 'wp-block-columns') ) {
            return true;
        }

        $className = strtolower($this->attr($element, 'class'));
        $inlineStyle = strtolower($this->attr($element, 'style'));
        $style = strtolower('' !== trim($resolvedStyle) ? $resolvedStyle : $inlineStyle);

        if ( preg_match('/(?:^|;)\s*display\s*:\s*(?:inline-)?flex\b/', $style) && $this->hasDirectChildElement($element, 'svg') ) {
            return false;
        }

        // A genuinely vertical flex container (display:flex with
        // flex-direction: column / column-reverse) lays its children out in a
        // vertical stack. Emitting core/columns would render them horizontally —
        // the wrong direction. Decline here so the host transformer routes the
        // element to a vertical core/group instead, preserving its classes and
        // styles. Horizontal flex (row / row-reverse / default) remains eligible.
        if ( $this->isVerticalFlexContainer($style) ) {
            return false;
        }

        // core/columns is a flex layout. Preserve resolved grid containers as
        // groups so their source classes continue to control track geometry.
        if ( preg_match('/(?:^|;)\s*display\s*:\s*(?:inline-)?grid\b/', $style) ) {
            return false;
        }

        // Split-layout names describe two-pane structures. Multi-child content
        // stacks such as hero copy must stay groups so source CSS controls flow.
        return (bool) preg_match('/(?:^|[\s_-])columns?(?:$|[\s_-])/', $className)
            || ( $this->looksLikeSplitLayout($element) && 2 === $this->directElementChildCount($element) )
            || ( $this->looksLikeDocumentationLayout($element) && $this->hasSidebarAndContentChildren($element) )
            || $this->hasSidebarAndContentChildren($element)
            || preg_match('/(?:^|;)\s*display\s*:\s*(?:inline-)?flex/', $inlineStyle);
    }

    private function looksLikeSplitLayout(DOMElement $element): bool
    {
        $name = strtolower(trim($this->attr($element, 'class') . ' ' . $this->attr($element, 'id')));

        return (bool) preg_match('/(?:^|[\s_-])(?:split|two[\s_-]?col|media[\s_-]?text|text[\s_-]?media|feature[\s_-]?row|hero[\s_-]?(?:inner|grid|content|layout)|content[\s_-]?grid)(?:$|[\s_-])/', $name);
    }

    private function directElementChildCount(DOMElement $element): int
    {
        $count = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                ++$count;
            }
        }

        return $count;
    }

    private function looksLikeDocumentationLayout(DOMElement $element): bool
    {
        $name = strtolower(trim($this->attr($element, 'class') . ' ' . $this->attr($element, 'id')));
        return (bool) preg_match('/(?:^|[\s_-])(?:docs?|documentation|article|content)(?:[\s_-]+(?:layout|shell|page|with[\s_-]+sidebar)|$)|(?:^|[\s_-])sidebar[\s_-]+layout(?:$|[\s_-])/', $name);
    }

    private function hasSidebarAndContentChildren(DOMElement $element): bool
    {
        $hasSidebar = false;
        $hasContent = false;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $name = strtolower(trim($child->tagName . ' ' . $this->attr($child, 'class') . ' ' . $this->attr($child, 'id') . ' ' . $this->attr($child, 'role')));
            $hasSidebar = $hasSidebar || (bool) preg_match('/(?:^|[\s_-])(?:aside|sidebar|toc|table[\s_-]+of[\s_-]+contents)(?:$|[\s_-])/', $name);
            $hasContent = $hasContent || in_array(strtolower($child->tagName), array( 'article', 'form', 'main' ), true)
                || (bool) preg_match('/(?:^|[\s_-])(?:main|content|article|form|docs?[\s_-]+content|documentation[\s_-]+content)(?:$|[\s_-])/', $name);
        }

        return $hasSidebar && $hasContent;
    }

}
