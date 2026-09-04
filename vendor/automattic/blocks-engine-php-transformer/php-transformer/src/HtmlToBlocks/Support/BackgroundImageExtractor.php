<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter;

final class BackgroundImageExtractor
{
    public function urlFromStyle(string $style): string
    {
        foreach ( CssValueSplitter::splitTopLevel($style, array( ';' )) as $declaration ) {
            if ( ! str_contains($declaration, ':') ) {
                continue;
            }

            [$name, $value] = array_map('trim', explode(':', $declaration, 2));
            if ( ! in_array(strtolower($name), array( 'background', 'background-image' ), true) ) {
                continue;
            }

            if ( preg_match('/\burl\(\s*(?:(["\'])(.*?)\1|([^)]*))\s*\)/is', $value, $matches) ) {
                return $this->safeUrl((string) ('' !== ($matches[2] ?? '') ? $matches[2] : ($matches[3] ?? '')));
            }
        }

        return '';
    }

    private function safeUrl(string $value): string
    {
        $url = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ) {
            return '';
        }

        return $url;
    }

    /**
     * @param array<string, string> $attributes
     */
    public function altFromAttributes(array $attributes): string
    {
        foreach ( array( 'aria-label', 'title' ) as $attribute ) {
            $value = trim($attributes[$attribute] ?? '');
            if ( '' !== $value ) {
                return $value;
            }
        }

        return '';
    }
}
