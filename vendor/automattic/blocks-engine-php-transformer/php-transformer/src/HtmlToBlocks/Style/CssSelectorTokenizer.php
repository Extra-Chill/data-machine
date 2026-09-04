<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/** Extract selector compounds and top-level combinators without normalization. */
final class CssSelectorTokenizer
{
    /**
     * Spans use zero-based, end-exclusive byte offsets in the original selector.
     *
     * @return array{supported: bool, compounds: list<string>, combinators: list<string>, rightmost_compound: string, compound_spans: list<array{start: int, end: int}>, combinator_spans: list<array{start: int, end: int}>}
     */
    public static function tokenize(string $selector): array
    {
        $unsupported = static fn (): array => array( 'supported' => false, 'compounds' => array(), 'combinators' => array(), 'rightmost_compound' => '', 'compound_spans' => array(), 'combinator_spans' => array() );
        $compounds = array();
        $combinators = array();
        $compoundSpans = array();
        $combinatorSpans = array();
        $state = CssSyntaxScanner::state();
        $compoundStart = null;
        $whitespaceStart = null;
        $length = strlen($selector);

        for ( $offset = 0; $offset < $length; ) {
            $character = $selector[ $offset ];
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $next = CssSyntaxScanner::consume($selector, $offset, $state);
            if ( null === $next ) {
                return $unsupported();
            }
            if ( ! $topLevel || $next !== $offset + 1 ) {
                // An escape is one lexical unit, but it can also be the first
                // byte of a compound (including after descendant whitespace).
                if ( $topLevel && '\\' === $character ) {
                    if ( null === $compoundStart ) {
                        $compoundStart = $offset;
                    } elseif ( null !== $whitespaceStart ) {
                        self::appendCompound($selector, $compoundStart, $whitespaceStart, $compounds, $compoundSpans);
                        $combinators[] = ' ';
                        $combinatorSpans[] = array( 'start' => $whitespaceStart, 'end' => $offset );
                        $compoundStart = $offset;
                    }
                    $whitespaceStart = null;
                }
                $offset = $next;
                continue;
            }
            if ( CssSyntaxScanner::isCssWhitespace($character) ) {
                if ( null !== $compoundStart && null === $whitespaceStart ) {
                    $whitespaceStart = $offset;
                }
                $offset = $next;
                continue;
            }
            if ( ',' === $character || '{' === $character || '}' === $character || ';' === $character ) {
                return $unsupported();
            }
            $combinatorLength = '|' === $character && '|' === ($selector[ $offset + 1 ] ?? '') ? 2 : (in_array($character, array( '>', '+', '~' ), true) ? 1 : 0);
            if ( $combinatorLength > 0 ) {
                if ( null === $compoundStart ) {
                    return $unsupported();
                }
                $end = $whitespaceStart ?? $offset;
                self::appendCompound($selector, $compoundStart, $end, $compounds, $compoundSpans);
                $combinators[] = substr($selector, $offset, $combinatorLength);
                $combinatorSpans[] = array( 'start' => $offset, 'end' => $offset + $combinatorLength );
                $compoundStart = null;
                $whitespaceStart = null;
                $offset += $combinatorLength;
                continue;
            }
            if ( null === $compoundStart ) {
                $compoundStart = $offset;
            } elseif ( null !== $whitespaceStart ) {
                self::appendCompound($selector, $compoundStart, $whitespaceStart, $compounds, $compoundSpans);
                $combinators[] = ' ';
                $combinatorSpans[] = array( 'start' => $whitespaceStart, 'end' => $offset );
                $compoundStart = $offset;
            }
            $whitespaceStart = null;
            $offset = $next;
        }

        if ( ! CssSyntaxScanner::isComplete($state) || null === $compoundStart ) {
            return $unsupported();
        }
        self::appendCompound($selector, $compoundStart, $whitespaceStart ?? $length, $compounds, $compoundSpans);
        if ( count($combinators) !== count($compounds) - 1 ) {
            return $unsupported();
        }
        return array( 'supported' => true, 'compounds' => $compounds, 'combinators' => $combinators, 'rightmost_compound' => $compounds[ count($compounds) - 1 ], 'compound_spans' => $compoundSpans, 'combinator_spans' => $combinatorSpans );
    }

    /** @param list<string> $compounds @param list<array{start: int, end: int}> $spans */
    private static function appendCompound(string $selector, int $start, int $end, array &$compounds, array &$spans): void
    {
        if ( $end <= $start ) {
            return;
        }
        $compounds[] = substr($selector, $start, $end - $start);
        $spans[] = array( 'start' => $start, 'end' => $end );
    }
}
