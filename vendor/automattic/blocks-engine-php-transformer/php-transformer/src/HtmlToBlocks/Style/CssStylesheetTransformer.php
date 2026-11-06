<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/**
 * Safely visits style-rule selector preludes without parsing or reserializing CSS.
 */
final class CssStylesheetTransformer
{
    /**
     * Transform selector preludes in style rules, retaining all other source bytes.
     *
     * The callback receives the complete prelude, including its original whitespace
     * and comments, and must return its replacement. It may optionally return a
     * list of prelude/body pairs when a caller needs to split one source rule.
     *
     * @param callable(string, string): string|list<array{prelude: string, body: string}> $transformSelectorPrelude
     */
    public function transform(string $stylesheet, callable $transformSelectorPrelude): string
    {
        if ( ! $this->isWellFormedStylesheet($stylesheet) ) {
            return $stylesheet;
        }
        return $this->transformRules($stylesheet, $transformSelectorPrelude, null);
    }

    /**
     * Transform complete style rules while retaining at-rule nesting.
     *
     * @param callable(string, string): string $transformStyleRule
     */
    public function transformStyleRules(string $stylesheet, callable $transformStyleRule): string
    {
        if ( ! $this->isWellFormedStylesheet($stylesheet) ) {
            return $stylesheet;
        }
        return $this->transformRules($stylesheet, static fn (string $prelude): string => $prelude, $transformStyleRule);
    }

    /**
     * @return array{preamble: string, stylesheet: string}
     */
    public function splitLeadingAtRulePreamble(string $stylesheet): array
    {
        $offset = 0;
        $length = strlen($stylesheet);
        while ( $offset < $length ) {
            $boundary = $this->nextRuleBoundary($stylesheet, $offset);
            if ( null === $boundary || ';' !== $stylesheet[ $boundary ] ) {
                break;
            }

            $statement = substr($stylesheet, $offset, $boundary - $offset + 1);
            if ( ! in_array(self::atRuleName($statement), array( 'charset', 'import', 'namespace' ), true) ) {
                break;
            }
            $offset = $boundary + 1;
        }

        return array(
            'preamble'   => substr($stylesheet, 0, $offset),
            'stylesheet' => substr($stylesheet, $offset),
        );
    }

    /**
     * Split a selector list only at top-level commas. Null indicates malformed CSS.
     *
     * @return list<string>|null
     */
    public static function splitSelectorList(string $prelude): ?array
    {
        $parts  = array();
        $start  = 0;
        $state  = CssSyntaxScanner::state();
        $length = strlen($prelude);

        for ( $index = 0; $index < $length; ++$index ) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $next = CssSyntaxScanner::consume($prelude, $index, $state);
            if ( null === $next ) {
                return null;
            }
            if ( ',' === $prelude[ $index ] && $topLevel && $next === $index + 1 ) {
                $parts[] = substr($prelude, $start, $index - $start);
                $start   = $index + 1;
            }
            $index = $next - 1;
        }

        if ( ! CssSyntaxScanner::isComplete($state) ) {
            return null;
        }

        $parts[] = substr($prelude, $start);
        return $parts;
    }

    /**
     * @param callable(string): string $transformSelectorPrelude
     */
    private function transformRules(string $css, callable $transformSelectorPrelude, ?callable $transformStyleRule): string
    {
        $output = '';
        $offset = 0;
        $length = strlen($css);

        while ( $offset < $length ) {
            $boundary = $this->nextRuleBoundary($css, $offset);
            if ( null === $boundary ) {
                return $output . substr($css, $offset);
            }

            $token = $css[ $boundary ];
            if ( ';' === $token ) {
                $output .= substr($css, $offset, $boundary - $offset + 1);
                $offset = $boundary + 1;
                continue;
            }

            $blockEnd = $this->matchingBrace($css, $boundary);
            if ( null === $blockEnd ) {
                return $output . substr($css, $offset);
            }

            $prelude = substr($css, $offset, $boundary - $offset);
            if ( $this->isAtRule($prelude) ) {
                $output .= $prelude . '{';
                $body = substr($css, $boundary + 1, $blockEnd - $boundary - 1);
                $output .= $this->walksNestedRules($prelude) ? $this->transformRules($body, $transformSelectorPrelude, $transformStyleRule) : $body;
                $output .= '}';
            } elseif ( $this->isStylePrelude($prelude) ) {
                $body = substr($css, $boundary + 1, $blockEnd - $boundary - 1);
                if ( null !== $transformStyleRule ) {
                    $output .= $transformStyleRule($prelude, $body);
                } else {
                    $transformed = $transformSelectorPrelude($prelude, $body);
                    if ( is_array($transformed) ) {
                        foreach ( $transformed as $rule ) {
                            $output .= $rule['prelude'] . '{' . $rule['body'] . '}';
                        }
                    } else {
                        $output .= $transformed . '{' . $body . '}';
                    }
                }
            } else {
                $output .= substr($css, $offset, $blockEnd - $offset + 1);
            }

            $offset = $blockEnd + 1;
        }

        return $output;
    }

    private function nextRuleBoundary(string $css, int $offset): ?int
    {
        $state  = CssSyntaxScanner::state();
        $length = strlen($css);
        for ( $index = $offset; $index < $length; ++$index ) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $next = CssSyntaxScanner::consume($css, $index, $state);
            if ( null === $next ) {
                return null;
            }
            if ( $topLevel && $next === $index + 1 && ('{' === $css[ $index ] || ';' === $css[ $index ]) ) {
                return $index;
            }
            $index = $next - 1;
        }

        return null;
    }

    private function matchingBrace(string $css, int $openingBrace): ?int
    {
        $state  = CssSyntaxScanner::state();
        $depth  = 0;
        $length = strlen($css);
        for ( $index = $openingBrace; $index < $length; ++$index ) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $next = CssSyntaxScanner::consume($css, $index, $state);
            if ( null === $next ) {
                return null;
            }
            if ( $topLevel && $next === $index + 1 && '{' === $css[ $index ] ) {
                ++$depth;
            } elseif ( $topLevel && $next === $index + 1 && '}' === $css[ $index ] && 0 === --$depth ) {
                return $index;
            }
            $index = $next - 1;
        }

        return null;
    }

    private function isAtRule(string $prelude): bool
    {
        return '@' === self::firstSignificantCharacter($prelude);
    }

    private function isStylePrelude(string $prelude): bool
    {
        return '' !== self::firstSignificantCharacter($prelude) && null !== self::splitSelectorList($prelude);
    }

    private function walksNestedRules(string $prelude): bool
    {
        return in_array(self::atRuleName($prelude), array( 'container', 'layer', 'media', 'scope', 'starting-style', 'supports' ), true);
    }

    private function isWellFormedStylesheet(string $css): bool
    {
        $state = CssSyntaxScanner::state();
        $braces = 0;
        $length = strlen($css);
        for ( $offset = 0; $offset < $length; ) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $next = CssSyntaxScanner::consume($css, $offset, $state);
            if ( null === $next ) {
                return false;
            }
            if ( $topLevel && $next === $offset + 1 ) {
                if ( '{' === $css[ $offset ] ) {
                    ++$braces;
                } elseif ( '}' === $css[ $offset ] ) {
                    if ( --$braces < 0 ) {
                        return false;
                    }
                }
            }
            $offset = $next;
        }
        return 0 === $braces && CssSyntaxScanner::isComplete($state);
    }

    private static function firstSignificantCharacter(string $value): string
    {
        $state = CssSyntaxScanner::state();
        $length = strlen($value);
        for ( $offset = 0; $offset < $length; ) {
            $next = CssSyntaxScanner::consume($value, $offset, $state);
            if ( null === $next ) {
                return '';
            }
            if ( $next === $offset + 1 && CssSyntaxScanner::isTopLevel($state) && ! CssSyntaxScanner::isCssWhitespace($value[ $offset ]) ) {
                return $value[ $offset ];
            }
            $offset = $next;
        }
        return '';
    }

    private static function atRuleName(string $prelude): string
    {
        $state = CssSyntaxScanner::state();
        $length = strlen($prelude);
        $seenAt = false;
        $name = '';
        for ( $offset = 0; $offset < $length; ) {
            $next = CssSyntaxScanner::consume($prelude, $offset, $state);
            if ( null === $next ) {
                return '';
            }
            if ( $next !== $offset + 1 || ! CssSyntaxScanner::isTopLevel($state) ) {
                $offset = $next;
                continue;
            }
            $character = $prelude[ $offset ];
            if ( ! $seenAt ) {
                if ( CssSyntaxScanner::isCssWhitespace($character) ) { $offset = $next; continue; }
                if ( '@' !== $character ) { return ''; }
                $seenAt = true;
            } elseif ( ctype_alpha($character) || '-' === $character ) {
                $name .= strtolower($character);
            } else {
                return $name;
            }
            $offset = $next;
        }
        return $name;
    }
}
