<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class DetailsPattern
{
    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @param callable(DOMElement, array<int, array<string, mixed>>&, array<int, string>): array<int, array<string, mixed>> $convertChildrenWithoutTags
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $innerHtml
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function match(DOMElement $element, array &$fallbacks, callable $convertChildrenWithoutTags, callable $presentationAttributes, callable $innerHtml, callable $createBlock): ?array
    {
        $summary = $this->firstChildElement($element, 'summary');
        $children = $convertChildrenWithoutTags($element, $fallbacks, array( 'summary' ));
        if ( null === $summary && array() === $children ) {
            return null;
        }

        return $createBlock('core/details', array_filter(array_merge($presentationAttributes($element), array(
            'summary'     => $summary instanceof DOMElement ? $innerHtml($summary) : '',
            'showContent' => $element->hasAttribute('open') ? true : '',
        )), static fn ($value): bool => '' !== $value), $children, $element);
    }

    /**
     * Recognize a generic accordion/disclosure widget that is NOT a native
     * `<details>` in the source and convert it to `core/details` so the
     * show/hide behavior is carried by the native disclosure block instead of
     * being lost as dead JavaScript.
     *
     * Recognition is purely STRUCTURAL/semantic — it keys off the WAI-ARIA
     * disclosure shape (a single toggle control carrying `aria-expanded` and/or
     * `aria-controls`, plus an associated collapsible region) and never off any
     * class string such as `faq-*`. A plain heading followed by text — with no
     * toggle control, aria-expanded, or aria-controls — is not a disclosure and
     * is intentionally left untouched. Multi-item accordions are handled earlier
     * by the dedicated accordion recognizer (`core/accordion`); this only fires
     * for a single leftover disclosure widget.
     *
     * @param callable(DOMElement): array<int, array<string, mixed>> $convertChildren
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $innerHtml
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function matchDisclosure(DOMElement $element, callable $convertChildren, callable $presentationAttributes, callable $innerHtml, callable $createBlock): ?array
    {
        if ( $this->hasRuntimeHeavyDescendant($element) ) {
            return null;
        }

        $toggles = $this->disclosureToggles($element);
        if ( 1 !== count($toggles) ) {
            return null;
        }

        $toggle = $toggles[0];

        $summaryHtml = $this->toggleLabelHtml($toggle, $innerHtml);
        if ( '' === trim(strip_tags($summaryHtml)) ) {
            return null;
        }

        $header = $this->headerForToggle($element, $toggle);
        if ( ! $header instanceof DOMElement ) {
            return null;
        }

        $panel = $this->disclosurePanel($element, $toggle, $header);
        if ( ! $panel instanceof DOMElement || $this->isNavigationLandmark($panel) ) {
            return null;
        }

        $panelBlocks = $convertChildren($panel);
        if ( array() === $panelBlocks ) {
            return null;
        }

        return $createBlock('core/details', array_filter(array_merge($presentationAttributes($element), array(
            'summary' => $summaryHtml,
        )), static fn ($value): bool => '' !== $value), $panelBlocks, $element);
    }

    /**
     * @return array<int, DOMElement>
     */
    private function disclosureToggles(DOMElement $element): array
    {
        $toggles = array();
        foreach ( $element->getElementsByTagName('*') as $candidate ) {
            if ( $candidate instanceof DOMElement && $this->isDisclosureToggle($candidate) ) {
                $toggles[] = $candidate;
            }
        }

        return $toggles;
    }

    /**
     * A toggle control carries explicit disclosure semantics: an
     * `aria-expanded` state, or a button-like control that points at the region
     * it reveals via `aria-controls`.
     */
    private function isDisclosureToggle(DOMElement $element): bool
    {
        if ( $element->hasAttribute('aria-expanded') ) {
            return true;
        }

        if ( ! $element->hasAttribute('aria-controls') ) {
            return false;
        }

        $tagName = strtolower($element->tagName);
        $role = strtolower($this->attr($element, 'role'));

        return 'button' === $tagName || 'summary' === $tagName || 'button' === $role
            || ( 'a' === $tagName && 'button' === $role );
    }

    /**
     * The collapsible region the toggle reveals: the element referenced by
     * `aria-controls`, otherwise the toggle header's next sibling region.
     */
    private function disclosurePanel(DOMElement $element, DOMElement $toggle, DOMElement $header): ?DOMElement
    {
        $controlledId = trim($this->attr($toggle, 'aria-controls'));
        if ( '' !== $controlledId ) {
            foreach ( $element->getElementsByTagName('*') as $candidate ) {
                if ( ! $candidate instanceof DOMElement || $candidate->getAttribute('id') !== $controlledId ) {
                    continue;
                }

                if ( $candidate->isSameNode($header) || $this->containsNode($candidate, $toggle) || $this->containsNode($header, $candidate) ) {
                    continue;
                }

                return $candidate;
            }
        }

        for ( $node = $header->nextSibling; null !== $node; $node = $node->nextSibling ) {
            if ( $node instanceof DOMElement ) {
                return $node;
            }
        }

        return null;
    }

    /**
     * The ancestor-or-self of the toggle that is a direct child of the widget
     * container — the clickable header whose text becomes the summary.
     */
    private function headerForToggle(DOMElement $element, DOMElement $toggle): ?DOMElement
    {
        for ( $node = $toggle; $node instanceof DOMElement; $node = $node->parentNode ) {
            $parent = $node->parentNode;
            if ( $parent instanceof DOMElement && $parent->isSameNode($element) ) {
                return $node;
            }
        }

        return null;
    }

    private function toggleLabelHtml(DOMElement $toggle, callable $innerHtml): string
    {
        $html = $innerHtml($toggle);
        $html = preg_replace('/<svg\b[^>]*>.*?<\/svg>/is', '', $html) ?? $html;
        $html = preg_replace('/<([a-z][a-z0-9]*)\b[^>]*\baria-hidden\s*=\s*(["\'])?true\2[^>]*>.*?<\/\1>/is', '', $html) ?? $html;

        return trim($html);
    }

    private function isNavigationLandmark(DOMElement $element): bool
    {
        return 'nav' === strtolower($element->tagName) || 'navigation' === strtolower($this->attr($element, 'role'));
    }

    private function hasRuntimeHeavyDescendant(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('*') as $candidate ) {
            if ( $candidate instanceof DOMElement && in_array(strtolower($candidate->tagName), array( 'script', 'canvas', 'template', 'iframe', 'form' ), true) ) {
                return true;
            }
        }

        return false;
    }

    private function containsNode(DOMElement $ancestor, DOMElement $node): bool
    {
        for ( $current = $node; $current instanceof DOMElement; $current = $current->parentNode ) {
            if ( $current->isSameNode($ancestor) ) {
                return true;
            }
        }

        return false;
    }

    private function attr(DOMElement $element, string $name): string
    {
        return $element->hasAttribute($name) ? trim($element->getAttribute($name)) : '';
    }

    private function firstChildElement(DOMElement $element, string $tagName): ?DOMElement
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && strtolower($child->tagName) === $tagName ) {
                return $child;
            }
        }

        return null;
    }
}
