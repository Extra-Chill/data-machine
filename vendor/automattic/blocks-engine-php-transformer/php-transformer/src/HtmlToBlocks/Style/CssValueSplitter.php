<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/**
 * Parenthesis-depth-aware splitting for CSS declaration lists and values.
 *
 * Naive `explode(';', ...)`, `explode(',', ...)`, and `preg_split('/\s+/', ...)`
 * over CSS values break apart the inside of functional notation —
 * `rgba(251, 247, 241, .95)`, `clamp(3.5rem, 8vw, 6.5rem)`, `var(--x, 0)`, and
 * `linear-gradient(90deg, red, blue)` all carry commas and spaces that are NOT
 * top-level delimiters. Splitting them mid-function yields truncated, invalid
 * tokens (`rgba(251,`) that no longer round-trip through the block style object,
 * which is what produces "unexpected or invalid content" and mangled spacing.
 *
 * Every method here only treats a delimiter as a separator when it appears at
 * paren depth 0, so functional values stay whole.
 */
final class CssValueSplitter
{
    /**
     * Split on any of the given single-character delimiters, but only when the
     * delimiter occurs outside of `(...)`. Empty/whitespace-only segments are
     * dropped and remaining segments are trimmed.
     *
     * @param array<int, string> $delimiters
     * @return array<int, string>
     */
    public static function splitTopLevel(string $input, array $delimiters): array
    {
        $parts  = array();
        $buffer = '';
        $depth  = 0;
        $length = strlen($input);

        for ( $index = 0; $index < $length; ++$index ) {
            $char = $input[ $index ];
            if ( '(' === $char ) {
                ++$depth;
            } elseif ( ')' === $char && $depth > 0 ) {
                --$depth;
            }

            if ( 0 === $depth && in_array($char, $delimiters, true) ) {
                $parts[] = $buffer;
                $buffer  = '';
                continue;
            }

            $buffer .= $char;
        }

        $parts[] = $buffer;

        $trimmed = array();
        foreach ( $parts as $part ) {
            $part = trim($part);
            if ( '' !== $part ) {
                $trimmed[] = $part;
            }
        }

        return $trimmed;
    }

    /**
     * Split on top-level whitespace runs, keeping functional values whole. This
     * is the paren-aware replacement for `preg_split('/\s+/', ...)` over CSS
     * shorthand values such as `padding: clamp(3.5rem, 8vw, 6.5rem) 0` or
     * `border: 1px solid rgba(0, 0, 0, .1)`.
     *
     * @return array<int, string>
     */
    public static function splitTopLevelWhitespace(string $input): array
    {
        $parts  = array();
        $buffer = '';
        $depth  = 0;
        $length = strlen($input);

        for ( $index = 0; $index < $length; ++$index ) {
            $char = $input[ $index ];
            if ( '(' === $char ) {
                ++$depth;
            } elseif ( ')' === $char && $depth > 0 ) {
                --$depth;
            }

            if ( 0 === $depth && '' === trim($char) ) {
                if ( '' !== $buffer ) {
                    $parts[] = $buffer;
                    $buffer  = '';
                }
                continue;
            }

            $buffer .= $char;
        }

        if ( '' !== $buffer ) {
            $parts[] = $buffer;
        }

        return $parts;
    }

    /**
     * Whether every `(` in the value has a matching `)` and no `)` closes more
     * than has been opened. A value that contains `(` but is unbalanced is a
     * truncated/malformed functional value (e.g. `rgba(251,`) and must not be
     * stored as a resolved CSS value.
     */
    public static function hasBalancedParens(string $value): bool
    {
        $depth  = 0;
        $length = strlen($value);

        for ( $index = 0; $index < $length; ++$index ) {
            $char = $value[ $index ];
            if ( '(' === $char ) {
                ++$depth;
            } elseif ( ')' === $char ) {
                --$depth;
                if ( $depth < 0 ) {
                    return false;
                }
            }
        }

        return 0 === $depth;
    }
}
