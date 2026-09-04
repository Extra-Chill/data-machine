<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class SpacerPattern
{
    /**
     * @param callable(DOMElement): int $childElementCount
     * @param callable(DOMElement, string): string $attr
     * @param callable(DOMElement, string): bool $hasClass
     * @param callable(DOMElement, array<int, string>): array<string, mixed> $presentationAttributes
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function match(DOMElement $element, callable $childElementCount, callable $attr, callable $hasClass, callable $presentationAttributes, callable $createBlock): ?array
    {
        if ( '' !== trim($element->textContent ?? '') || 0 !== $childElementCount($element) ) {
            return null;
        }

        $height = $this->heightFromStyle($attr($element, 'style'));
        if ( '' === $height ) {
            return null;
        }

        if ( ! $hasClass($element, 'wp-block-spacer') && ! $hasClass($element, 'spacer') ) {
            return null;
        }

        // core/spacer serializes height itself. Preserve all remaining geometry
        // through the generated stylesheet rather than removing the whole carrier.
        $attrs = $presentationAttributes($element, array( 'height' ));
        $attrs['height'] = $height;
        unset($attrs['style']);

        return $createBlock('core/spacer', $attrs, array(), $element);
    }

    private function heightFromStyle(string $style): string
    {
        if ( ! preg_match('/(?:^|;)\s*height\s*:\s*([^;]+)/i', $style, $matches) ) {
            return '';
        }

        $height = trim($matches[1]);
        if ( '' === $height || preg_match('/[{}]/', $height) || strlen($height) > 80 ) {
            return '';
        }

        return $height;
    }
}
