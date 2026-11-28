<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter;
use DOMElement;

final class NavigationUnderlineColorResolver
{
    /**
     * @param callable(DOMElement): array<string, string> $presentationDeclarations
     * @param array<int, array{selector: string, pseudo: string, declarations: array<string, string>}> $staticPseudoElementStyleRules
     * @param callable(DOMElement, string): bool $matchesCssSelector
     */
    public function resolve(DOMElement $item, DOMElement $anchor, callable $presentationDeclarations, array $staticPseudoElementStyleRules, callable $matchesCssSelector): string
    {
        foreach ( array( $anchor, $item ) as $element ) {
            $declarations = $presentationDeclarations($element);
            foreach ( array( 'text-decoration-color' ) as $property ) {
                $color = $this->usableCssColor((string) ($declarations[ $property ] ?? ''));
                if ( '' !== $color ) {
                    return $color;
                }
            }
            if ( isset($declarations['border-bottom-width']) && ! preg_match('/^0(?:[a-z%]+)?$/i', trim($declarations['border-bottom-width'])) ) {
                foreach ( array( 'border-bottom-color', 'border-color' ) as $property ) {
                    $color = $this->usableCssColor((string) ($declarations[ $property ] ?? ''));
                    if ( '' !== $color ) {
                        return $color;
                    }
                }
            }
        }

        $matchedPseudoElement = false;
        foreach ( $staticPseudoElementStyleRules as $rule ) {
            if ( ! $matchesCssSelector($anchor, $rule['selector']) && ! $matchesCssSelector($item, $rule['selector']) ) {
                continue;
            }

            $matchedPseudoElement = true;
            $declarations = $rule['declarations'];
            foreach ( array( 'background-color', 'background', 'border-bottom-color', 'border-color', 'color' ) as $property ) {
                $color = $this->usableCssColor((string) ($declarations[ $property ] ?? ''));
                if ( '' !== $color ) {
                    return $color;
                }
            }
        }

        if ( $matchedPseudoElement ) {
            foreach ( array( $anchor, $item ) as $element ) {
                $color = $this->usableCssColor((string) ($presentationDeclarations($element)['color'] ?? ''));
                if ( '' !== $color ) {
                    return $color;
                }
            }
        }

        return '';
    }

    private function usableCssColor(string $value): string
    {
        $value = trim($value);
        if ( '' === $value ) {
            return '';
        }

        $lower = strtolower($value);
        if ( in_array($lower, array( 'transparent', 'none', 'inherit', 'initial', 'unset', 'revert', 'auto' ), true) ) {
            return '';
        }

        if ( str_contains($value, '(') && ( ! str_ends_with($value, ')') || ! CssValueSplitter::hasBalancedParens($value) ) ) {
            return '';
        }

        if ( preg_match('/^#[0-9a-f]{3,8}$/i', $value) || preg_match('/^(?:rgb|rgba|hsl|hsla|var)\s*\(/i', $value) || 'currentcolor' === $lower || preg_match('/^[a-z]+$/', $lower) ) {
            return 'currentcolor' === $lower ? 'currentColor' : $value;
        }

        return '';
    }
}
