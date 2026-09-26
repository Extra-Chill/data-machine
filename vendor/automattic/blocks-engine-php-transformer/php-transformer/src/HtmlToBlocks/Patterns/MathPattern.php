<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class MathPattern
{
    /**
     * @param callable(DOMElement, string): string $attr
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $innerHtml
     * @param callable(DOMElement): string $safeFallbackHtml
     * @param callable(string): string $escapeHtml
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function match(DOMElement $element, callable $attr, callable $presentationAttributes, callable $innerHtml, callable $safeFallbackHtml, callable $escapeHtml, callable $createBlock): ?array
    {
        if ( ! $this->isMathElement($element, $attr) ) {
            return null;
        }

        $tagName = strtolower($element->tagName);
        $content = 'math' === $tagName ? $safeFallbackHtml($element) : $this->mathExpressionContent($element, $innerHtml, $escapeHtml);
        if ( '' === trim($content) ) {
            return null;
        }

        return $createBlock('core/math', array_merge($presentationAttributes($element), array( 'content' => $content )), array(), $element);
    }

    /**
     * @param callable(DOMElement, string): string $attr
     */
    private function isMathElement(DOMElement $element, callable $attr): bool
    {
        if ( 'math' === strtolower($element->tagName) ) {
            return true;
        }

        if ( $this->hasMathSignal($element, $attr) ) {
            return true;
        }

        return in_array(strtolower($element->tagName), array( 'div', 'p', 'span' ), true) && $this->isTeXDelimitedText(trim($element->textContent ?? ''));
    }

    /**
     * @param callable(DOMElement, string): string $attr
     */
    private function hasMathSignal(DOMElement $element, callable $attr): bool
    {
        $signals = strtolower(trim(implode(' ', array(
            $attr($element, 'class'),
            $attr($element, 'id'),
            $attr($element, 'data-math'),
            $attr($element, 'data-latex'),
            $attr($element, 'data-tex'),
        ))));

        return (bool) preg_match('/(?:^|[\s_-])(?:math|latex|tex|katex|mathjax)(?:$|[\s_-])/', $signals);
    }

    /**
     * @param callable(DOMElement): string $innerHtml
     * @param callable(string): string $escapeHtml
     */
    private function mathExpressionContent(DOMElement $element, callable $innerHtml, callable $escapeHtml): string
    {
        $html = $innerHtml($element);
        if ( '' !== trim($html) && ! preg_match('/<(?:script|style)\b/i', $html) ) {
            return $html;
        }

        return $escapeHtml(trim($element->textContent ?? ''));
    }

    private function isTeXDelimitedText(string $text): bool
    {
        if ( str_starts_with($text, '$$') && str_ends_with($text, '$$') && 4 < strlen($text) ) {
            return true;
        }
        if ( str_starts_with($text, '$') && str_ends_with($text, '$') && 2 < strlen($text) && ! str_starts_with($text, '$$') ) {
            return true;
        }

        return ( str_starts_with($text, '\\(') && str_ends_with($text, '\\)') && 4 < strlen($text) )
            || ( str_starts_with($text, '\\[') && str_ends_with($text, '\\]') && 4 < strlen($text) );
    }
}
