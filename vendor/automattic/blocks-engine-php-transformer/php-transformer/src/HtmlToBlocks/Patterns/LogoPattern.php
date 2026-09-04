<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\LinkUrlSanitizer;
use DOMDocument;
use DOMElement;

final class LogoPattern
{
    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $innerHtml
     * @param callable(DOMElement): string $outerHtml
     * @param callable(DOMElement, string): ?string $materializeSvgImages
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function match(DOMElement $element, callable $presentationAttributes, callable $innerHtml, callable $outerHtml, callable $materializeSvgImages, callable $createBlock): ?array
    {
        if ( ! $this->hasLogoSignal($element) || '' === trim($element->textContent ?? '') ) {
            return null;
        }

        $tagName = strtolower($element->tagName);
        if ( 'a' !== $tagName && $this->containsBlockContent($element) ) {
            return null;
        }

        if ( 'a' === $tagName && $this->hasStructuredAnchorChrome($element) ) {
            $content = $materializeSvgImages($element, $innerHtml($element)) ?? (preg_replace('/<svg\b[^>]*>.*?<\/svg>/is', '', $innerHtml($element)) ?? $innerHtml($element));
            $attrs = array_filter(array(
                'text'  => trim($content),
                'url'   => $this->safeNavigationUrl($element->hasAttribute('href') ? $element->getAttribute('href') : ''),
                'title' => trim($element->hasAttribute('aria-label') ? $element->getAttribute('aria-label') : ''),
				'style' => array(
                    'color' => array( 'background' => 'transparent' ),
                    'border' => array( 'radius' => '0' ),
                    'spacing' => array(
                        'padding' => array( 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0' ),
                    ),
                ),
            ), static fn (mixed $value): bool => is_array($value) ? array() !== $value : '' !== $value);

            $margin = $presentationAttributes($element)['style']['spacing']['margin'] ?? null;
            $wrapperAttrs = is_array($margin) && array() !== $margin
                ? array( 'style' => array( 'spacing' => array( 'margin' => $margin ) ) )
                : array();

            return $createBlock('core/buttons', $wrapperAttrs, array(
                $createBlock('core/button', $attrs, array(), $element, $element),
            ), $element);
        }

        $content = 'a' === $tagName ? $this->anchorLogoContent($element, $innerHtml($element), $materializeSvgImages) : $this->logoLabelHtml($element, $innerHtml($element), $materializeSvgImages);
        if ( '' === trim($content) ) {
            return null;
        }

        $attrs = $presentationAttributes($element);
        if ( 'div' === $tagName ) {
            $attrs = array_replace_recursive(array(
                'style' => array(
                    'spacing' => array(
                        'margin' => array( 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0' ),
                    ),
                ),
            ), $attrs);
        }

        return $createBlock('core/paragraph', array_merge($attrs, array( 'content' => $content )), array(), $element);
    }

    /** @param callable(DOMElement, string): ?string $materializeSvgImages */
    private function anchorLogoContent(DOMElement $anchor, string $html, callable $materializeSvgImages): string
    {
        $label = $this->logoLabelHtml($anchor, $html, $materializeSvgImages);
        if ( '' === trim($this->plainText($label)) ) {
            $label = $this->accessibleFallbackLabel($anchor);
        }

        if ( '' === trim($label) ) {
            return '';
        }

        $href = $this->safeNavigationUrl($anchor->hasAttribute('href') ? $anchor->getAttribute('href') : '');
        if ( '' === $href ) {
            return $label;
        }

        $attrs = array( 'href' => $href );
        foreach ( array( 'target', 'rel', 'title' ) as $name ) {
            if ( $anchor->hasAttribute($name) && '' !== trim($anchor->getAttribute($name)) ) {
                $attrs[$name] = $anchor->getAttribute($name);
            }
        }

        return '<a' . $this->htmlAttributeString($attrs) . '>' . $label . '</a>';
    }

    /** @param callable(DOMElement, string): ?string $materializeSvgImages */
    private function logoLabelHtml(DOMElement $element, string $html, callable $materializeSvgImages): string
    {
        $html = preg_replace('/<img\b[^>]*\balt\s*=\s*(["\'])(.*?)\1[^>]*>/is', '$2', $html) ?? $html;
        $html = preg_replace('/<img\b[^>]*>/is', '', $html) ?? $html;
        $html = $materializeSvgImages($element, $html) ?? (preg_replace('/<svg\b[^>]*>.*?<\/svg>/is', '', $html) ?? $html);
        $html = preg_replace('/<([a-z][a-z0-9]*)\b[^>]*\baria-hidden\s*=\s*(["\'])?true\2[^>]*>\s*<\/\1>/i', '', $html) ?? $html;
        $html = $this->semanticMarkerSpansAsMarks($html);
        $html = trim($html);
        $text = $this->plainText($html);
        if ( '' === $text ) {
            return '';
        }

        $unsupported = '/<\/?(?!a\b|em\b|i\b|strong\b|b\b|mark\b|small\b|sub\b|sup\b|br\b)[a-z][a-z0-9]*\b[^>]*>/i';
        if ( preg_match($unsupported, $html) ) {
            $unwrapped = trim(preg_replace_callback($unsupported, static fn (array $match): string => str_starts_with($match[0], '</') ? '' : ' ', $html) ?? $html);
            if ( '' !== $unwrapped && ! preg_match($unsupported, $unwrapped) ) {
                return $unwrapped;
            }

            $flattened = $this->plainText(preg_replace('/<\/?[a-z][a-z0-9]*\b[^>]*>/i', ' ', $html) ?? $html);
            return htmlspecialchars('' !== $flattened ? $flattened : $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $html;
    }

    private function semanticMarkerSpansAsMarks(string $html): string
    {
        if ( ! str_contains($html, '--blocks-engine-richtext-marker:') ) {
            return $html;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $body = $loaded ? $document->getElementsByTagName('body')->item(0) : null;
        if ( ! $body instanceof DOMElement ) {
            return $html;
        }

        $spans = array();
        foreach ( $body->getElementsByTagName('span') as $span ) {
            if ( $span instanceof DOMElement && str_contains($span->getAttribute('style'), '--blocks-engine-richtext-marker:') ) {
                $spans[] = $span;
            }
        }
        foreach ( $spans as $span ) {
            $mark = $document->createElement('mark');
            $mark->setAttribute('style', $span->getAttribute('style'));
            while ( null !== $span->firstChild ) {
                $mark->appendChild($span->firstChild);
            }
            $span->parentNode?->replaceChild($mark, $span);
        }

        $content = '';
        foreach ( $body->childNodes as $child ) {
            $content .= $document->saveHTML($child);
        }
        return $content;
    }

    private function unwrapPresentationalSpan(string $html): string
    {
        while ( preg_match('/^<span\b[^>]*>(.*)<\/span>$/is', $html, $matches) === 1 && $this->spanWrapsEntireContent($matches[1]) ) {
            $html = trim($matches[1]);
        }

        return $html;
    }

    private function spanWrapsEntireContent(string $inner): bool
    {
        $depth = 0;
        if ( preg_match_all('/<(\/?)span\b[^>]*>/i', $inner, $matches) ) {
            foreach ( $matches[1] as $slash ) {
                $depth += '' === $slash ? 1 : -1;
                if ( $depth < 0 ) {
                    return false;
                }
            }
        }

        return 0 === $depth;
    }

    private function plainText(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private function accessibleFallbackLabel(DOMElement $element): string
    {
        foreach ( array( 'aria-label', 'title' ) as $attribute ) {
            $label = trim($element->hasAttribute($attribute) ? $element->getAttribute($attribute) : '');
            if ( '' !== $label ) {
                return htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        $image = $element->getElementsByTagName('img')->item(0);
        if ( $image instanceof DOMElement ) {
            $alt = trim($image->hasAttribute('alt') ? $image->getAttribute('alt') : '');
            if ( '' !== $alt ) {
                return htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        $title = $element->getElementsByTagName('title')->item(0);
        if ( $title instanceof DOMElement && '' !== trim($title->textContent ?? '') ) {
            return htmlspecialchars(trim($title->textContent ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return '';
    }

    private function safeNavigationUrl(string $url): string
    {
        return LinkUrlSanitizer::sanitize($url);
    }

    /**
     * @param array<string, string> $attrs
     */
    private function htmlAttributeString(array $attrs): string
    {
        $html = '';
        foreach ( $attrs as $name => $value ) {
            $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $html;
    }

    private function hasLogoSignal(DOMElement $element): bool
    {
        foreach ( array( 'class', 'id' ) as $attribute ) {
            $value = $element->hasAttribute($attribute) ? $element->getAttribute($attribute) : '';
            foreach ( preg_split('/[^a-z0-9]+/', strtolower($value)) ?: array() as $token ) {
                if ( in_array($token, array( 'logo', 'brand', 'branding' ), true) ) {
                    return true;
                }
            }

            if ( preg_match('/(?:^|[^a-z0-9])site-(?:logo|title)(?:[^a-z0-9]|$)/i', $value) ) {
                return true;
            }
        }

        return false;
    }

    private function containsBlockContent(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            if ( $this->isBlockContentTag(strtolower($child->tagName)) ) {
                return true;
            }

            if ( $this->containsBlockContent($child) ) {
                return true;
            }
        }

        return false;
    }

    private function hasStructuredAnchorChrome(DOMElement $anchor): bool
    {
        foreach ( $anchor->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && in_array(strtolower($descendant->tagName), array( 'img', 'picture', 'svg' ), true) ) {
                return true;
            }
        }

        foreach ( $anchor->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || 'true' !== strtolower(trim($child->getAttribute('aria-hidden'))) ) {
                continue;
            }

            if ( '' !== trim($child->getAttribute('class'))
                || '' !== trim($child->getAttribute('id'))
                || '' !== trim($child->getAttribute('style')) ) {
                return true;
            }
        }

        return false;
    }

    private function isBlockContentTag(string $tagName): bool
    {
        return in_array($tagName, array(
            'address',
            'article',
            'aside',
            'blockquote',
            'details',
            'div',
            'dl',
            'fieldset',
            'figcaption',
            'figure',
            'footer',
            'form',
            'h1',
            'h2',
            'h3',
            'h4',
            'h5',
            'h6',
            'header',
            'hr',
            'li',
            'main',
            'nav',
            'ol',
            'p',
            'pre',
            'section',
            'table',
            'ul',
        ), true);
    }
}
