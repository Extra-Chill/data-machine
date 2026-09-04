<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * CSS rule and declaration helpers for StaticHtmlEmitter.
 */
final class StaticHtmlCssRuleSet
{
    /**
     * Maps each per-node CSS class to a readable base name used for shared classes.
     *
     * @var array<string, string>
     */
    private array $nodeReadableNames = array();

    public function resetReadableNames(): void
    {
        $this->nodeReadableNames = array();
    }

    public function rememberNodeReadableName(string $className, string $name, string $type): void
    {
        $this->nodeReadableNames[$className] = $this->sharedClassBaseName($name, $type);
    }

    /**
     * @param array<int, string> $styles
     * @return array<string, string>
     */
    public function styleDeclarationMap(array $styles): array
    {
        $map = array();
        foreach ( $styles as $style ) {
            $parts = explode(':', $style, 2);
            if ( 2 === count($parts) ) {
                $map[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $map;
    }

    /**
     * @param array<int, string> $styles
     */
    public function stylesDeclareProperty(array $styles, string $property): bool
    {
        foreach ( $styles as $style ) {
            if ( is_string($style) && str_starts_with($style, $property . ':') ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    public function negativeAutoLayoutSpacingRules(string $className, array $node): array
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        if ( 'flex' !== ($layout['display'] ?? null) || ! $this->isFiniteNumeric($layout['item_spacing'] ?? null) || (float) $layout['item_spacing'] >= 0.0 ) {
            return array();
        }

        $property = 'column' === ($layout['flex_direction'] ?? null) ? 'margin-top' : 'margin-left';
        return array('.' . $className . '>*+*{' . $property . ':' . $this->number((float) $layout['item_spacing']) . 'px}');
    }

    /**
     * Collapse repeated per-node style rules into shared, readably-named CSS
     * classes while leaving the original figma-node hooks in the HTML.
     *
     * @param array<int, string> $cssRules
     * @return array{rules: array<int, string>, class_map: array<string, string>}
     */
    public function applySharedStyleClasses(array $cssRules, bool $hashReadableNames = false): array
    {
        $pattern = '/^\.(figma-node-[A-Za-z0-9_-]+)\{(.*)\}$/s';

        $bodyToSelectors = array();
        $bodyFirstIndex  = array();
        foreach ( $cssRules as $index => $rule ) {
            if ( 1 === preg_match($pattern, $rule, $matches) ) {
                $body = $matches[2];
                $bodyToSelectors[$body][] = $matches[1];
                if ( ! isset($bodyFirstIndex[$body]) ) {
                    $bodyFirstIndex[$body] = $index;
                }
            }
        }

        $sharedOrder = array();
        foreach ( $bodyToSelectors as $body => $selectors ) {
            if ( count($selectors) >= 2 ) {
                $sharedOrder[$body] = $bodyFirstIndex[$body];
            }
        }
        asort($sharedOrder);

        $reserved = array(
            'figma-root'         => true,
            'figma-link'         => true,
            'figma-text-glyphs'  => true,
            'figma-vector-asset' => true,
            'figma-image-asset'  => true,
        );
        $usedNames         = array();
        $bodyToSharedClass = array();
        foreach ( array_keys($sharedOrder) as $body ) {
            $firstSelector = $bodyToSelectors[$body][0];
            $base = $this->nodeReadableNames[$firstSelector] ?? 'style';
            if ( $hashReadableNames ) {
                $base .= '-' . substr(sha1($body), 0, 8);
            }
            $name = $base;
            $suffix = 2;
            while ( isset($usedNames[$name]) || isset($reserved[$name]) ) {
                $name = $base . '-' . $suffix;
                ++$suffix;
            }
            $usedNames[$name]         = true;
            $bodyToSharedClass[$body] = $name;
        }

        $rules         = array();
        $emittedShared = array();
        $classMap      = array();
        foreach ( $cssRules as $rule ) {
            if ( 1 === preg_match($pattern, $rule, $matches) ) {
                $selector = $matches[1];
                $body     = $matches[2];
                if ( isset($bodyToSharedClass[$body]) ) {
                    $shared              = $bodyToSharedClass[$body];
                    $classMap[$selector] = $shared;
                    if ( ! isset($emittedShared[$shared]) ) {
                        $rules[]                = '.' . $shared . '{' . $body . '}';
                        $emittedShared[$shared] = true;
                    }
                    continue;
                }
            }
            $rules[] = $rule;
        }

        return array('rules' => $rules, 'class_map' => $classMap);
    }

    /**
     * @param array<string, string> $classMap
     */
    public function applySharedClassMapToHtml(string $html, array $classMap): string
    {
        foreach ( $classMap as $selector => $shared ) {
            $html = str_replace(
                'class="' . $selector . '"',
                'class="' . $selector . ' ' . $shared . '"',
                $html
            );
        }

        return $html;
    }

    private function sharedClassBaseName(string $name, string $type): string
    {
        $base = $this->slug($name);
        if ( 'node' === $base || '' === $base ) {
            $base = $this->slug($type);
            if ( 'node' === $base || '' === $base ) {
                $base = 'style';
            }
        }

        if ( 1 !== preg_match('/^[a-z_]/', $base) ) {
            $base = 'style-' . $base;
        }

        return $base;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $value) ?? '');
        $slug = trim($slug, '-');

        return '' === $slug ? 'node' : $slug;
    }

    private function isFiniteNumeric(mixed $value): bool
    {
        return is_numeric($value) && is_finite((float) $value);
    }

    private function number(float $value): string
    {
        if ( ! is_finite($value) ) {
            return '0';
        }

        return rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
    }
}
