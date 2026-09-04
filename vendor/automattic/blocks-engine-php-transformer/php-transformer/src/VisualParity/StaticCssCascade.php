<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\VisualParity;

use DOMDocument;
use DOMElement;

/**
 * Render-free static CSS cascade resolver.
 *
 * Resolves the *effective* declared value of CSS properties for an element by
 * statically matching the document's author stylesheets (every <style> block,
 * plus any explicitly supplied CSS such as a linked stylesheet inlined by the
 * caller) and the element's inline style attribute, then applying CSS
 * inheritance for inheritable properties from the nearest declaring ancestor.
 *
 * This is a deterministic, browser-free approximation of getComputedStyle for
 * the subset of author-declared properties that visual parity cares about: same
 * input HTML+CSS always yields byte-identical output. It does NOT compute used
 * layout geometry (box sizes, resolved lengths) — only the cascaded *declared*
 * values — which is exactly the contract a static parity signal needs: "does the
 * same effective styling apply to the same content".
 *
 * Mirrors the proven selector engine in {@see TypographyVisualProbe} and adds
 * deterministic specificity-then-source-order cascade ordering so the highest
 * specificity declaration wins, with inline styles overriding all author rules.
 */
final class StaticCssCascade
{
    /**
     * @var array<int, array{selector: string, declarations: array<string, string>, specificity: int, order: int}>
     */
    private array $rules;

    public function __construct(DOMDocument $document, string $extraCss = '')
    {
        $this->rules = $this->buildRules($document, $extraCss);
    }

    /**
     * Resolve the effective declared style for the requested properties.
     *
     * @param array<int, string> $properties  Properties to resolve (lowercase).
     * @param array<int, string> $inheritable Subset of $properties that inherit.
     * @return array<string, string> property => declared value (ksorted)
     */
    public function resolve(DOMElement $element, array $properties, array $inheritable): array
    {
        $style = $this->cascadedStyle($element);

        foreach ( $inheritable as $field ) {
            if ( isset($style[$field]) && '' !== trim($style[$field]) ) {
                continue;
            }
            for ( $node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
                $ancestor = $this->cascadedStyle($node);
                if ( isset($ancestor[$field]) && '' !== trim($ancestor[$field]) ) {
                    $style[$field] = $ancestor[$field];
                    break;
                }
            }
        }

        $customProperties = $this->customProperties($element);
        foreach ($style as $property => $value) {
            if (! str_starts_with($property, '--')) {
                $style[$property] = $this->resolveVariables($value, $customProperties);
            }
        }
        $style = array_intersect_key($style, array_flip($properties));
        ksort($style);

        return $style;
    }

    /** @return array<string, string> */
    private function customProperties(DOMElement $element): array
    {
        $nodes = array();
        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            array_unshift($nodes, $node);
        }
        $properties = array();
        foreach ($nodes as $node) {
            foreach ($this->cascadedStyle($node) as $name => $value) {
                if (str_starts_with($name, '--')) {
                    $properties[$name] = $value;
                }
            }
        }
        return $properties;
    }

    /** Resolve local/inherited variables and one-level fallback chains deterministically. */
    private function resolveVariables(string $value, array $properties): string
    {
        for ($depth = 0; $depth < 12 && str_contains($value, 'var('); ++$depth) {
            $resolved = preg_replace_callback('/var\(\s*(--[A-Za-z0-9_-]+)\s*(?:,\s*([^()]+))?\)/', static function (array $matches) use ($properties): string {
                $name = $matches[1];
                return isset($properties[$name]) ? $properties[$name] : trim((string) ($matches[2] ?? $matches[0]));
            }, $value);
            if ($resolved === $value || null === $resolved) {
                break;
            }
            $value = $resolved;
        }
        return $value;
    }

    /**
     * Resolve matching declarations using importance, specificity, source order,
     * and inline-origin precedence.
     *
     * @return array<string, string>
     */
    private function cascadedStyle(DOMElement $element): array
    {
        $matched = array();
        foreach ( $this->rules as $rule ) {
            if ( $this->matchesSimpleSelector($element, $rule['selector']) ) {
                $matched[] = $rule;
            }
        }

        $resolved = array();
        foreach ( $matched as $rule ) {
            $this->applyDeclarations($resolved, $rule['declarations'], $rule['specificity'], $rule['order'], false);
        }

        if ( $element->hasAttribute('style') ) {
            $this->applyDeclarations($resolved, $this->declarations($element->getAttribute('style')), 10000, PHP_INT_MAX, true);
        }

        return array_map(static fn (array $entry): string => $entry['value'], $resolved);
    }

    /**
     * @param array<string, array{value: string, important: bool, specificity: int, order: int, inline: bool}> $resolved
     * @param array<string, string> $declarations
     */
    private function applyDeclarations(array &$resolved, array $declarations, int $specificity, int $order, bool $inline): void
    {
        foreach ($declarations as $name => $rawValue) {
            $important = 1 === preg_match('/\s*!important\s*$/i', $rawValue);
            $value = preg_replace('/\s*!important\s*$/i', '', $rawValue) ?? $rawValue;
            $current = $resolved[$name] ?? null;
            if (is_array($current)
                && (int) $current['important'] > (int) $important) {
                continue;
            }
            if (is_array($current)
                && (bool) $current['important'] === $important
                && ($current['specificity'] > $specificity
                    || ($current['specificity'] === $specificity && $current['order'] > $order)
                    || ($current['specificity'] === $specificity && $current['order'] === $order && $current['inline'] && ! $inline))) {
                continue;
            }
            $resolved[$name] = array(
                'value' => $value,
                'important' => $important,
                'specificity' => $specificity,
                'order' => $order,
                'inline' => $inline,
            );
        }
    }

    /**
     * @return array<int, array{selector: string, declarations: array<string, string>, specificity: int, order: int}>
     */
    private function buildRules(DOMDocument $document, string $extraCss): array
    {
        $rules = array();
        $order = 0;

        $cssBlocks = array();
        if ( '' !== trim($extraCss) ) {
            $cssBlocks[] = $extraCss;
        }
        foreach ( $document->getElementsByTagName('style') as $style ) {
            $cssBlocks[] = (string) $style->textContent;
        }

        foreach ( $cssBlocks as $css ) {
            $css = $this->stripAtRuleBlocks($css);
            if ( ! preg_match_all('/([^{}]+)\{([^{}]+)\}/', $css, $matches, PREG_SET_ORDER) ) {
                continue;
            }
            foreach ( $matches as $match ) {
                $declarations = $this->declarations((string) $match[2]);
                if ( array() === $declarations ) {
                    continue;
                }
                foreach ( explode(',', (string) $match[1]) as $selector ) {
                    $selector = trim($selector);
                    if ( '' === $selector ) {
                        continue;
                    }
                    $rules[] = array(
                        'selector' => $selector,
                        'declarations' => $declarations,
                        'specificity' => $this->specificity($selector),
                        'order' => $order++,
                    );
                }
            }
        }

        return $rules;
    }

    /**
     * Remove at-rule prelude tokens (@media/@supports/@font-face headers and
     * @keyframes blocks) that would otherwise corrupt the flat rule grammar.
     * The nested rules inside @media/@supports are intentionally kept (flattened)
     * because they still declare effective style; @keyframes interiors are dropped
     * because their "selectors" (0%, to, from) are not element selectors.
     */
    private function stripAtRuleBlocks(string $css): string
    {
        // Drop @keyframes blocks (including nested braces) entirely.
        $css = preg_replace('/@(?:-webkit-|-moz-|-o-)?keyframes\b[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/i', '', $css) ?? $css;
        // Drop @font-face / @import / @charset prelude+block which carry no element rules.
        $css = preg_replace('/@font-face\b[^{]*\{[^{}]*\}/i', '', $css) ?? $css;
        $css = preg_replace('/@(?:import|charset)\b[^;]*;/i', '', $css) ?? $css;
        // Unwrap @media/@supports/@layer wrappers, keeping their inner rules.
        $css = preg_replace('/@(?:media|supports|layer)\b[^{]*\{/i', '', $css) ?? $css;

        return $css;
    }

    /**
     * Deterministic specificity heuristic: 100 per #id, 10 per .class/[attr]/
     * pseudo-class, 1 per element/pseudo-element. Inline styles are applied
     * separately and always win.
     */
    private function specificity(string $selector): int
    {
        $selector = trim(preg_replace('/::?(hover|focus|active|visited|before|after)\b[^ ]*/', '', $selector) ?? $selector);
        $ids = preg_match_all('/#[A-Za-z0-9_-]+/', $selector);
        $classes = preg_match_all('/\.[A-Za-z0-9_-]+|\[[^\]]+\]/', $selector);
        $bare = preg_replace('/[#.][A-Za-z0-9_-]+|\[[^\]]+\]|[>+~]/', ' ', $selector) ?? $selector;
        $elements = preg_match_all('/[A-Za-z][A-Za-z0-9_-]*/', $bare);

        return ( (int) $ids * 100 ) + ( (int) $classes * 10 ) + (int) $elements;
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
        if ( '' === $selector || str_contains($selector, '>') || str_contains($selector, '+') || str_contains($selector, '~') || str_contains($selector, '[') ) {
            return false;
        }

        if ( str_contains($selector, ' ') ) {
            return $this->matchesDescendantSelector($element, $selector);
        }

        if ( '*' === $selector ) {
            return true;
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

        if ( ! preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $selector) ) {
            return false;
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

    /**
     * @return array<int, string>
     */
    private function tokens(string $value): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($value)) ?: array(), static fn (string $token): bool => '' !== $token));
    }
}
