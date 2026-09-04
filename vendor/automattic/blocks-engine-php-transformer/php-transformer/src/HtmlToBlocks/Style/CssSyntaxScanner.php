<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/** Internal byte scanner shared by CSS source-preserving primitives. */
final class CssSyntaxScanner
{
    /** @return array{quote: string, comment: bool, parens: int, brackets: int} */
    public static function state(): array
    {
        return array( 'quote' => '', 'comment' => false, 'parens' => 0, 'brackets' => 0 );
    }

    /**
     * Consume one CSS lexical unit and return the next byte offset.
     *
     * @param array{quote: string, comment: bool, parens: int, brackets: int} $state
     */
    public static function consume(string $value, int $offset, array &$state): ?int
    {
        $character = $value[ $offset ];
        $next      = $value[ $offset + 1 ] ?? '';
        if ( $state['comment'] ) {
            if ( '*' === $character && '/' === $next ) {
                $state['comment'] = false;
                return $offset + 2;
            }
            return $offset + 1;
        }
        if ( '' !== $state['quote'] ) {
            if ( '\\' === $character ) {
                return self::escapeEnd($value, $offset);
            }
            if ( $character === $state['quote'] ) {
                $state['quote'] = '';
            }
            return $offset + 1;
        }
        if ( '/' === $character && '*' === $next ) {
            $state['comment'] = true;
            return $offset + 2;
        }
        if ( '"' === $character || "'" === $character ) {
            $state['quote'] = $character;
            return $offset + 1;
        }
        if ( '\\' === $character ) {
            return self::escapeEnd($value, $offset);
        }
        if ( '(' === $character ) {
            ++$state['parens'];
        } elseif ( ')' === $character ) {
            if ( 0 === $state['parens'] ) {
                return null;
            }
            --$state['parens'];
        } elseif ( '[' === $character ) {
            ++$state['brackets'];
        } elseif ( ']' === $character ) {
            if ( 0 === $state['brackets'] ) {
                return null;
            }
            --$state['brackets'];
        }
        return $offset + 1;
    }

    public static function isCssWhitespace(string $character): bool
    {
        return " " === $character || "\t" === $character || "\n" === $character || "\r" === $character || "\f" === $character;
    }

    /** @param array{quote: string, comment: bool, parens: int, brackets: int} $state */
    public static function isTopLevel(array $state): bool
    {
        return '' === $state['quote'] && ! $state['comment'] && 0 === $state['parens'] && 0 === $state['brackets'];
    }

    /** @param array{quote: string, comment: bool, parens: int, brackets: int} $state */
    public static function isComplete(array $state): bool
    {
        return self::isTopLevel($state);
    }

    /** Consume a CSS escape beginning at the supplied backslash. */
    public static function escapeEnd(string $value, int $offset): ?int
    {
        if ( '\\' !== ($value[ $offset ] ?? '') ) {
            return null;
        }
        return self::consumeEscape($value, $offset);
    }

    private static function consumeEscape(string $value, int $offset): ?int
    {
        $length = strlen($value);
        $offset++;
        if ( $offset >= $length ) {
            return null;
        }
        if ( ! ctype_xdigit($value[ $offset ]) ) {
            if ( "\r" === $value[ $offset ] && "\n" === ($value[ $offset + 1 ] ?? '') ) {
                return $offset + 2;
            }
            return $offset + 1;
        }
        $end = $offset;
        while ( $end < $length && $end < $offset + 6 && ctype_xdigit($value[ $end ]) ) {
            ++$end;
        }
        if ( $end < $length && self::isCssWhitespace($value[ $end ]) ) {
            return "\r" === $value[ $end ] && "\n" === ($value[ $end + 1 ] ?? '') ? $end + 2 : $end + 1;
        }
        return $end;
    }
}
