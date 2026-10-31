<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class AccordionPattern implements PatternRecognizerInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function match(DOMElement $element, PatternContext $context): ?array
    {
        $createBlock = $context->createBlockCallback();
        $convertChildren = $context->convertChildrenCallback();
        $convertChildrenWithoutTags = $context->convertChildrenWithoutTagsCallback();
        $presentationAttributes = $context->presentationAttributesCallback();
        $innerHtml = $context->innerHtmlCallback();

        if ( null === $convertChildren || null === $convertChildrenWithoutTags || ! $this->hasAccordionSignal($element) || $this->hasRuntimeHeavyDescendant($element) ) {
            return null;
        }

        $items = array();
        foreach ( $this->directChildElements($element) as $child ) {
            $item = $this->accordionItem($child, $innerHtml, $convertChildren, $convertChildrenWithoutTags, $createBlock, $presentationAttributes);
            if ( null === $item ) {
                return null;
            }
            $items[] = $item;
        }

        if ( count($items) < 2 ) {
            return null;
        }

        return $createBlock('core/accordion', $presentationAttributes($element), $items, $element);
    }

    private function accordionItem(DOMElement $item, callable $innerHtml, callable $convertChildren, callable $convertChildrenWithoutTags, callable $createBlock, callable $presentationAttributes): ?array
    {
        if ( ! $this->isAccordionItemElement($item) || $this->hasRuntimeHeavyDescendant($item) ) {
            return null;
        }

        $title = $this->titleElement($item);
        if ( ! $title instanceof DOMElement ) {
            return null;
        }

        $panel = $this->panelElement($item, $title);
        if ( null !== $panel && $panel->isSameNode($title) ) {
            return null;
        }

        $titleHtml = $this->titleHtml($title, $innerHtml);
        if ( '' === trim(strip_tags($titleHtml)) ) {
            return null;
        }

        $panelBlocks = $panel instanceof DOMElement ? $convertChildren($panel) : $convertChildrenWithoutTags($item, array( 'summary' ));
        if ( array() === $panelBlocks ) {
            return null;
        }

        $headingAttrs = array(
            'title' => $titleHtml,
            'level' => $this->headingLevel($title),
        );

        return $createBlock('core/accordion-item', array_filter(array_merge($presentationAttributes($item), array(
            'openByDefault' => $this->isOpen($item, $title, $panel) ? true : '',
        )), static fn ($value): bool => '' !== $value), array(
            $createBlock('core/accordion-heading', $headingAttrs, array(), $title),
            $createBlock('core/accordion-panel', $panel instanceof DOMElement ? $presentationAttributes($panel) : array(), $panelBlocks, $panel),
        ), $item);
    }

    private function hasAccordionSignal(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        $class = strtolower($this->attr($element, 'class'));
        $role = strtolower($this->attr($element, 'role'));

        return str_contains($class, 'accordion')
            || str_contains($class, 'faq')
            || 'accordion' === $role
            || in_array($tagName, array( 'section', 'div', 'ul', 'ol' ), true) && str_contains(strtolower($this->attr($element, 'aria-label')), 'faq');
    }

    private function isAccordionItemElement(DOMElement $element): bool
    {
        if ( 'details' === strtolower($element->tagName) ) {
            return true;
        }

        $class = strtolower($this->attr($element, 'class'));
        return str_contains($class, 'item')
            || str_contains($class, 'accordion')
            || str_contains($class, 'faq')
            || str_contains($class, 'question');
    }

    private function titleElement(DOMElement $item): ?DOMElement
    {
        foreach ( $this->directChildElements($item) as $child ) {
            $tagName = strtolower($child->tagName);
            if ( 'summary' === $tagName || 'button' === $tagName || preg_match('/^h[1-6]$/', $tagName) ) {
                return $child;
            }

            $class = strtolower($this->attr($child, 'class'));
            if ( str_contains($class, 'title') || str_contains($class, 'heading') || str_contains($class, 'question') || str_contains($class, 'trigger') ) {
                return $child;
            }
        }

        return null;
    }

    private function panelElement(DOMElement $item, DOMElement $title): ?DOMElement
    {
        $controlledId = trim($this->attr($title, 'aria-controls'));
        if ( '' !== $controlledId ) {
            foreach ( $item->getElementsByTagName('*') as $candidate ) {
                if ( $candidate instanceof DOMElement && $candidate->getAttribute('id') === $controlledId ) {
                    return $candidate;
                }
            }
        }

        foreach ( $this->directChildElements($item) as $child ) {
            if ( $child->isSameNode($title) ) {
                continue;
            }

            $class = strtolower($this->attr($child, 'class'));
            $role = strtolower($this->attr($child, 'role'));
            if ( 'region' === $role || str_contains($class, 'panel') || str_contains($class, 'content') || str_contains($class, 'body') || str_contains($class, 'answer') ) {
                return $child;
            }
        }

        return null;
    }

    private function titleHtml(DOMElement $title, callable $innerHtml): string
    {
        $html = $innerHtml($title);
        $html = preg_replace('/<svg\b[^>]*>.*?<\/svg>/is', '', $html) ?? $html;
        $html = preg_replace('/<([a-z][a-z0-9]*)\b[^>]*\baria-hidden\s*=\s*(["\'])?true\2[^>]*>.*?<\/\1>/is', '', $html) ?? $html;

        return trim($html);
    }

    private function headingLevel(DOMElement $title): int
    {
        return preg_match('/^h([1-6])$/', strtolower($title->tagName), $matches) ? (int) $matches[1] : 3;
    }

    private function isOpen(DOMElement $item, DOMElement $title, ?DOMElement $panel): bool
    {
        if ( $item->hasAttribute('open') || 'true' === strtolower($this->attr($title, 'aria-expanded')) ) {
            return true;
        }

        foreach ( array_filter(array( $item, $title, $panel )) as $element ) {
            if ( $element instanceof DOMElement && preg_match('/(?:^|\s)(?:active|open|is-active|is-open|expanded)(?:\s|$)/i', $this->attr($element, 'class')) ) {
                return true;
            }
        }

        return false;
    }

    private function hasRuntimeHeavyDescendant(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('*') as $candidate ) {
            if ( ! $candidate instanceof DOMElement ) {
                continue;
            }

            if ( in_array(strtolower($candidate->tagName), array( 'script', 'canvas', 'template', 'iframe', 'form' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function directChildElements(DOMElement $element): array
    {
        $children = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' !== trim($child->textContent ?? '') ) {
                return array();
            }

            if ( $child instanceof DOMElement ) {
                $children[] = $child;
            }
        }

        return $children;
    }

    private function attr(DOMElement $element, string $name): string
    {
        return $element->hasAttribute($name) ? trim($element->getAttribute($name)) : '';
    }
}
