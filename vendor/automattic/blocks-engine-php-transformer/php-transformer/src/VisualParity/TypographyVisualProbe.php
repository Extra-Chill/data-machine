<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\VisualParity;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Extracts normalized typography probes (heading + body roles) from rendered
 * HTML so source and target renders can be compared for font-family,
 * font-weight, and type-scale fidelity.
 *
 * Mirrors {@see ButtonMenuVisualProbe}: a generic, self-contained extractor
 * that emits the shared visual-parity probe envelope. No fixture-specific
 * strings or assumptions.
 */
final class TypographyVisualProbe
{
    public const SCHEMA = 'blocks-engine/php-transformer/visual-parity-probes/v1';

    /**
     * Inheritable typography properties resolved from the nearest ancestor when
     * the element itself does not declare them (mirrors CSS inheritance for the
     * font shorthand family).
     */
    private const INHERITABLE_FIELDS = array(
        'font-family',
        'font-style',
        'font-weight',
        'letter-spacing',
        'line-height',
        'text-transform',
    );

    private const TYPOGRAPHY_FIELDS = array(
        'font-family',
        'font-size',
        'font-style',
        'font-weight',
        'letter-spacing',
        'line-height',
        'text-decoration',
        'text-transform',
    );

    /**
     * @return array<string, mixed>
     */
    public function extract(string $html): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $sourceHtml = preg_match('/<(?:!doctype|html|head|body)\b/i', $html) ? $html : '<body>' . $this->bodyHtml($html) . '</body>';
        $loaded   = $document->loadHTML('<?xml encoding="utf-8" ?>' . $sourceHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ( ! $loaded ) {
            return array(
                'schema' => self::SCHEMA,
                'status' => 'failed',
                'probes' => array(),
                'summary' => array(
                    'total' => 0,
                    'by_role' => array(),
                    'type_scale' => array(),
                    'font_families' => array(),
                ),
                'diagnostics' => array(
                    array(
                        'code' => 'html_parse_failed',
                        'message' => 'Unable to parse HTML for typography probes.',
                    ),
                ),
            );
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if ( ! $body instanceof DOMElement ) {
            return $this->result(array());
        }

        $rules = $this->styleRules($document);
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6|//p');
        $probes = array();
        $seen = array();

        if ( false !== $nodes ) {
            foreach ( $nodes as $node ) {
                if ( ! $node instanceof DOMElement || $this->isInsideStyleOrScript($node) ) {
                    continue;
                }

                $text = $this->normalizedText($node);
                if ( '' === $text ) {
                    continue;
                }

                $selector = $this->selector($node);
                if ( isset($seen[$selector]) ) {
                    continue;
                }
                $seen[$selector] = true;

                $tag = strtolower($node->tagName);
                $isHeading = preg_match('/^h([1-6])$/', $tag, $match) === 1;

                $probe = array(
                    'id' => 'typography-probe-' . ( count($probes) + 1 ),
                    'role' => $isHeading ? 'heading' : 'body',
                    'level' => $isHeading ? (int) $match[1] : 0,
                    'tag' => $tag,
                    'selector' => $selector,
                    'text' => mb_substr($text, 0, 120),
                );

                $typography = $this->computedTypography($node, $rules);
                if ( array() !== $typography ) {
                    $probe['typography'] = $typography;
                }

                $probes[] = $probe;
            }
        }

        return $this->result($probes);
    }

    /**
     * @param array<int, array<string, mixed>> $probes
     * @return array<string, mixed>
     */
    private function result(array $probes): array
    {
        $byRole = array();
        $typeScale = array();
        $fontFamilies = array();
        foreach ( $probes as $probe ) {
            $role = (string) ($probe['role'] ?? 'unknown');
            $byRole[$role] = ($byRole[$role] ?? 0) + 1;

            $typography = is_array($probe['typography'] ?? null) ? $probe['typography'] : array();
            $tag = (string) ($probe['tag'] ?? '');
            if ( isset($typography['font-size']) && '' !== (string) $typography['font-size'] && ! isset($typeScale[$tag]) ) {
                $typeScale[$tag] = (string) $typography['font-size'];
            }
            if ( isset($typography['font-family']) && '' !== (string) $typography['font-family'] ) {
                $fontFamilies[(string) $typography['font-family']] = true;
            }
        }
        ksort($byRole);
        ksort($typeScale);
        $families = array_keys($fontFamilies);
        sort($families);

        return array(
            'schema' => self::SCHEMA,
            'status' => 'success',
            'probes' => $probes,
            'summary' => array(
                'total' => count($probes),
                'by_role' => $byRole,
                'type_scale' => $typeScale,
                'font_families' => $families,
            ),
            'diagnostics' => array(),
        );
    }

    private function bodyHtml(string $html): string
    {
        if ( preg_match('/<body\b[^>]*>(.*?)<\/body>/is', $html, $match) ) {
            return (string) $match[1];
        }

        return $html;
    }

    /**
     * Resolves the typography style for an element, applying CSS inheritance for
     * inheritable properties from the nearest ancestor that declares them.
     *
     * @param array<int, array{selector: string, declarations: array<string, string>}> $rules
     * @return array<string, string>
     */
    private function computedTypography(DOMElement $element, array $rules): array
    {
        $style = $this->computedStyle($element, $rules);

        foreach ( self::INHERITABLE_FIELDS as $field ) {
            if ( isset($style[$field]) && '' !== trim($style[$field]) ) {
                continue;
            }
            for ( $node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
                $ancestor = $this->computedStyle($node, $rules);
                if ( isset($ancestor[$field]) && '' !== trim($ancestor[$field]) ) {
                    $style[$field] = $ancestor[$field];
                    break;
                }
            }
        }

        ksort($style);

        return $style;
    }

    /**
     * @param array<int, array{selector: string, declarations: array<string, string>}> $rules
     * @return array<string, string>
     */
    private function computedStyle(DOMElement $element, array $rules): array
    {
        $style = array();
        foreach ( $rules as $rule ) {
            if ( $this->matchesSimpleSelector($element, $rule['selector']) ) {
                $style = array_merge($style, $rule['declarations']);
            }
        }

        if ( $element->hasAttribute('style') ) {
            $style = array_merge($style, $this->declarations($element->getAttribute('style')));
        }

        $style = array_intersect_key($style, array_flip(self::TYPOGRAPHY_FIELDS));
        ksort($style);

        return $style;
    }

    /**
     * @return array<int, array{selector: string, declarations: array<string, string>}>
     */
    private function styleRules(DOMDocument $document): array
    {
        $rules = array();
        foreach ( $document->getElementsByTagName('style') as $style ) {
            $css = (string) $style->textContent;
            if ( ! preg_match_all('/([^{}]+)\{([^{}]+)\}/', $css, $matches, PREG_SET_ORDER) ) {
                continue;
            }
            foreach ( $matches as $match ) {
                foreach ( explode(',', (string) $match[1]) as $selector ) {
                    $selector = trim($selector);
                    if ( '' === $selector ) {
                        continue;
                    }
                    $rules[] = array(
                        'selector' => $selector,
                        'declarations' => $this->declarations((string) $match[2]),
                    );
                }
            }
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    private function declarations(string $style): array
    {
        $declarations = array();
        foreach ( explode(';', $style) as $declaration ) {
            if ( ! str_contains($declaration, ':') ) {
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $declaration, 2));
            $name = strtolower($name);
            if ( '' !== $name && '' !== $value ) {
                $declarations[$name] = preg_replace('/\s+/', ' ', $value) ?? $value;
            }
        }

        return $declarations;
    }

    private function matchesSimpleSelector(DOMElement $element, string $selector): bool
    {
        $selector = trim(preg_replace('/:(hover|focus|active|visited|before|after)\b.*/', '', $selector) ?? $selector);
        if ( '' === $selector || str_contains($selector, '>') || str_contains($selector, '+') || str_contains($selector, '~') ) {
            return false;
        }

        if ( str_contains($selector, ' ') ) {
            return $this->matchesDescendantSelector($element, $selector);
        }

        if ( preg_match('/^#([A-Za-z0-9_-]+)$/', $selector, $match) ) {
            return $element->hasAttribute('id') && $element->getAttribute('id') === $match[1];
        }

        if ( preg_match('/^\.([A-Za-z0-9_-]+)$/', $selector, $match) ) {
            return in_array($match[1], $this->tokens($element->hasAttribute('class') ? $element->getAttribute('class') : ''), true);
        }

        if ( preg_match('/^([A-Za-z0-9_-]+)(\.[A-Za-z0-9_-]+)+$/', $selector) ) {
            $parts = explode('.', $selector);
            $tag = array_shift($parts);
            if ( strtolower((string) $tag) !== strtolower($element->tagName) ) {
                return false;
            }
            $classes = $this->tokens($element->hasAttribute('class') ? $element->getAttribute('class') : '');
            foreach ( $parts as $class ) {
                if ( ! in_array($class, $classes, true) ) {
                    return false;
                }
            }

            return true;
        }

        return strtolower($selector) === strtolower($element->tagName);
    }

    private function matchesDescendantSelector(DOMElement $element, string $selector): bool
    {
        $parts = preg_split('/\s+/', trim($selector)) ?: array();
        if ( array() === $parts || ! $this->matchesSimpleSelector($element, array_pop($parts)) ) {
            return false;
        }

        $current = $element->parentNode instanceof DOMElement ? $element->parentNode : null;
        for ( $index = count($parts) - 1; $index >= 0; --$index ) {
            $matched = false;
            for ( $node = $current; $node instanceof DOMElement; $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null ) {
                if ( $this->matchesSimpleSelector($node, $parts[$index]) ) {
                    $matched = true;
                    $current = $node->parentNode instanceof DOMElement ? $node->parentNode : null;
                    break;
                }
            }
            if ( ! $matched ) {
                return false;
            }
        }

        return true;
    }

    private function selector(DOMElement $element): string
    {
        $segments = array();
        for ( $node = $element; $node instanceof DOMElement && 'body' !== strtolower($node->tagName); $node = $node->parentNode ) {
            $segment = strtolower($node->tagName);
            if ( $node->hasAttribute('id') && '' !== trim($node->getAttribute('id')) ) {
                $segment .= '#' . trim($node->getAttribute('id'));
                array_unshift($segments, $segment);
                break;
            }
            $classes = $this->tokens($node->hasAttribute('class') ? $node->getAttribute('class') : '');
            if ( array() !== $classes ) {
                $segment .= '.' . implode('.', array_slice($classes, 0, 2));
            }
            $index = $this->elementIndex($node);
            if ( $index > 1 ) {
                $segment .= ':nth-of-type(' . $index . ')';
            }
            array_unshift($segments, $segment);
        }

        return implode(' > ', $segments);
    }

    private function elementIndex(DOMElement $element): int
    {
        $index = 1;
        for ( $node = $element->previousSibling; $node instanceof DOMNode; $node = $node->previousSibling ) {
            if ( $node instanceof DOMElement && strtolower($node->tagName) === strtolower($element->tagName) ) {
                ++$index;
            }
        }

        return $index;
    }

    private function normalizedText(DOMElement $element): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode((string) $element->textContent, ENT_QUOTES | ENT_HTML5)) ?? '');
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $value): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($value)) ?: array(), static fn (string $token): bool => '' !== $token));
    }

    private function isInsideStyleOrScript(DOMElement $element): bool
    {
        for ( $node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( in_array(strtolower($node->tagName), array( 'script', 'style' ), true) ) {
                return true;
            }
        }

        return false;
    }
}
