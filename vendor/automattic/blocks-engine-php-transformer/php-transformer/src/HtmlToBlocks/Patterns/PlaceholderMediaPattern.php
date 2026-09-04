<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class PlaceholderMediaPattern
{
    use PatternDomHelpersTrait;

    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(string): string $escapeHtml
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function match(DOMElement $element, callable $presentationAttributes, callable $escapeHtml, callable $createBlock): ?array
    {
        if ( ! $this->isPlaceholderMediaElement($element) ) {
            return null;
        }

        $attrs = $presentationAttributes($element);
        // The aspect ratio rides on the preserved placeholder/ratio classNames and
        // companion-plugin CSS; a raw inline `style` string would invalidate the
        // core/group block, so it is intentionally not emitted here (#261).
        $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), 'blocks-engine-placeholder-media');
        unset($attrs['style']);

        $label = $this->placeholderLabel($element);
        $children = '' !== $label ? array( $createBlock('core/paragraph', array( 'content' => $escapeHtml($label) ), array(), null) ) : array();

        return $createBlock('core/group', array_filter($attrs, static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value)), $children, $element);
    }

    private function isPlaceholderMediaElement(DOMElement $element): bool
    {
        $className = strtolower($this->attr($element, 'class'));
        if ( ! preg_match('/(?:^|\s)(?:ph|placeholder|media-placeholder|image-placeholder|video-placeholder)(?:\s|$)/', $className) && ! preg_match('/(?:^|\s)ratio-[0-9]+(?:x|:|-)[0-9]+(?:\s|$)/', $className) ) {
            return false;
        }

        return '' !== $this->placeholderAspectRatio($element)
            || preg_match('/(?:^|;)\s*aspect-ratio\s*:/i', $this->attr($element, 'style'))
            || preg_match('/(?:^|\s)(?:media|image|video|thumb|thumbnail|poster|avatar)(?:\s|$)/', $className);
    }

    private function placeholderAspectRatio(DOMElement $element): string
    {
        if ( preg_match('/(?:^|;)\s*aspect-ratio\s*:\s*([0-9.]+\s*\/\s*[0-9.]+|[0-9.]+)\s*(?:;|$)/i', $this->attr($element, 'style'), $styleMatch) ) {
            return preg_replace('/\s+/', '', $styleMatch[1]) ?? '';
        }

        $className = strtolower($this->attr($element, 'class'));
        if ( preg_match('/(?:^|\s)ratio-([0-9]+)(?:x|:|-)([0-9]+)(?:\s|$)/', $className, $classMatch) ) {
            return $classMatch[1] . '/' . $classMatch[2];
        }

        return '';
    }

    private function placeholderLabel(DOMElement $element): string
    {
        foreach ( $element->getElementsByTagName('span') as $span ) {
            if ( ! $span instanceof DOMElement ) {
                continue;
            }

            $className = strtolower($this->attr($span, 'class'));
            if ( preg_match('/(?:^|\s)(?:label|caption|placeholder-label)(?:\s|$)/', $className) ) {
                return trim(preg_replace('/\s+/', ' ', $span->textContent ?? '') ?? '');
            }
        }

        $directText = trim(preg_replace('/\s+/', ' ', $element->textContent ?? '') ?? '');
        return strlen($directText) <= 80 ? $directText : '';
    }

    private function mergeClassNames(string ...$classNames): string
    {
        $classes = array();
        foreach ( $classNames as $className ) {
            foreach ( preg_split('/\s+/', trim($className)) ?: array() as $class ) {
                if ( '' !== $class && ! in_array($class, $classes, true) ) {
                    $classes[] = $class;
                }
            }
        }

        return implode(' ', $classes);
    }
}
