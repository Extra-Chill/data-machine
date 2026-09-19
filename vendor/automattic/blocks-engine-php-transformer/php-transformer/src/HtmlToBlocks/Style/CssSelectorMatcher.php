<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use DOMElement;
use DOMNode;

/** Parses a conservative CSS selector subset and matches it without mutating a DOM. */
final class CssSelectorMatcher
{
    /**
     * @return array{supported: bool, reason: string|null, compounds: list<array<string, mixed>>, combinators: list<string>, type_spans: list<array{start: int, end: int, name: string, compound: int}>, rightmost_compound_span: array{start: int, end: int}|null, pseudo_state_suffix_span: array{start: int, end: int}|null, rightmost_rewrite_end: int|null}
     */
    public static function parse(string $selector): array
	{
		static $cache = array();
		if ( array_key_exists($selector, $cache) ) {
			return $cache[$selector];
		}
		$parsed = self::parseUncached($selector);
		if ( count($cache) < 10000 ) {
			$cache[$selector] = $parsed;
		}
		return $parsed;
	}

	/** @return array<string, mixed> */
	private static function parseUncached(string $selector): array
    {
        if ( 1 !== preg_match('//u', $selector) ) {
            return self::unsupported('invalid-utf8');
        }

        $tokens = CssSelectorTokenizer::tokenize($selector);
        if ( ! $tokens['supported'] ) {
            return self::unsupported('tokenization');
        }

        $compounds = array();
        $typeSpans = array();
        $suffix = null;
        $lastCompound = count($tokens['compounds']) - 1;
        foreach ( $tokens['compounds'] as $index => $compound ) {
            $parsed = self::parseCompound($compound, $tokens['compound_spans'][ $index ]['start'], $index === $lastCompound);
            if ( null === $parsed ) {
                return self::unsupported('unsupported-selector');
            }
            $compounds[] = $parsed['compound'];
            if ( null !== $parsed['type_span'] ) {
                $typeSpans[] = array_merge($parsed['type_span'], array( 'compound' => $index ));
            }
            if ( null !== $parsed['suffix'] ) {
                $suffix = $parsed['suffix'];
            }
        }
        if ( in_array('||', $tokens['combinators'], true) ) {
            return self::unsupported('column-combinator');
        }

        $rightmost = $tokens['compound_spans'][ $lastCompound ];
        return array(
            'supported' => true,
            'reason' => null,
            'compounds' => $compounds,
            'combinators' => $tokens['combinators'],
            'type_spans' => $typeSpans,
            'rightmost_compound_span' => $rightmost,
            'pseudo_state_suffix_span' => $suffix,
            'rightmost_rewrite_end' => $suffix['start'] ?? $rightmost['end'],
        );
    }

    /**
     * Match from the rightmost compound. Only hover, focus, active, and visited
     * are detachable dynamic suffixes; callers must explicitly account for them.
     *
     * @param array<string, mixed> $selector Result of parse().
     * @return array{supported: bool, matches: bool}
     */
    public static function matches(DOMElement $element, array $selector, bool $accountForPseudoStateSuffix = false): array
    {
        if ( ! ($selector['supported'] ?? false) ) {
            return array( 'supported' => false, 'matches' => false );
        }
        if ( null !== ($selector['pseudo_state_suffix_span'] ?? null) && ! $accountForPseudoStateSuffix ) {
            return array( 'supported' => false, 'matches' => false );
        }
        if ( self::hasUnmodeledHtmlAttributeValueSemantics($selector['compounds']) ) {
            return array( 'supported' => false, 'matches' => false );
        }

        return array(
            'supported' => true,
            'matches' => self::matchesAt($element, $selector['compounds'], $selector['combinators'], count($selector['compounds']) - 1),
        );
    }

    /** @return array{compound: array<string, mixed>, suffix: array{start: int, end: int}|null, type_span: array{start: int, end: int, name: string}|null}|null */
    private static function parseCompound(string $source, int $sourceStart, bool $isRightmost): ?array
    {
        $compound = array( 'type' => null, 'universal' => false, 'classes' => array(), 'ids' => array(), 'attributes' => array(), 'not' => array(), 'nth_child' => null, 'first_child' => false, 'last_child' => false );
        $offset = 0;
        $suffix = null;
        $typeSpan = null;
        $hasSimple = false;
        $length = strlen($source);

        while ( $offset < $length ) {
            self::skipIgnorable($source, $offset);
            if ( $offset >= $length ) {
                break;
            }

            $character = $source[ $offset ];
            if ( ':' === $character ) {
                $start = $offset;
                ++$offset;
                if ( ':' === ($source[ $offset ] ?? '') ) {
                    return null;
                }
                $name = self::identifier($source, $offset);
                if ( null === $name ) {
                    return null;
                }
                $lowerName = strtolower($name);
                if ( 'not' === $lowerName && '(' === ($source[ $offset ] ?? '') ) {
                    $closing = strpos($source, ')', $offset + 1);
                    if ( false === $closing ) {
                        return null;
                    }
                    $negated = self::parseCompound(trim(substr($source, $offset + 1, $closing - $offset - 1)), 0, false);
                    if ( null === $negated || null !== $negated['suffix'] || array() !== $negated['compound']['not'] ) {
                        return null;
                    }
                    $compound['not'][] = $negated['compound'];
                    $offset = $closing + 1;
                    $hasSimple = true;
                    continue;
                }
                if ( in_array($lowerName, array( 'first-child', 'last-child' ), true) && '(' !== ($source[ $offset ] ?? '') ) {
                    $compound['first-child' === $lowerName ? 'first_child' : 'last_child'] = true;
                    $hasSimple = true;
                    continue;
                }
                if ( 'nth-child' === $lowerName && '(' === ($source[ $offset ] ?? '') ) {
                    $closing = strpos($source, ')', $offset + 1);
                    if ( false === $closing ) {
                        return null;
                    }
                    $argument = trim(substr($source, $offset + 1, $closing - $offset - 1));
                    if ( ! preg_match('/^[1-9][0-9]*$/', $argument) ) {
                        return null;
                    }
                    $compound['nth_child'] = (int) $argument;
                    $offset = $closing + 1;
                    $hasSimple = true;
                    continue;
                }
                if ( '(' === ($source[ $offset ] ?? '') || ! $isRightmost || ! in_array($lowerName, array( 'hover', 'focus', 'active', 'visited' ), true) ) {
                    return null;
                }
                if ( null === $suffix ) {
                    $suffix = array( 'start' => $sourceStart + $start, 'end' => $sourceStart + $offset );
                } else {
                    $suffix['end'] = $sourceStart + $offset;
                }
                continue;
            }
            if ( null !== $suffix ) {
                return null;
            }

            if ( '.' === $character || '#' === $character ) {
                ++$offset;
                $name = self::identifier($source, $offset);
                if ( null === $name ) {
                    return null;
                }
                $key = '.' === $character ? 'classes' : 'ids';
                $compound[ $key ][] = $name;
                $hasSimple = true;
                continue;
            }
            if ( '[' === $character ) {
                $attribute = self::attribute($source, $offset);
                if ( null === $attribute ) {
                    return null;
                }
                $compound['attributes'][] = $attribute;
                $hasSimple = true;
                continue;
            }
            if ( '*' === $character ) {
                if ( $hasSimple || $compound['universal'] ) {
                    return null;
                }
                ++$offset;
                $compound['universal'] = true;
                $hasSimple = true;
                continue;
            }
            if ( $hasSimple || '|' === $character ) {
                return null;
            }
            $start = $offset;
            $name = self::identifier($source, $offset);
            if ( null === $name ) {
                return null;
            }
            $compound['type'] = $name;
            $typeSpan = array( 'start' => $sourceStart + $start, 'end' => $sourceStart + $offset, 'name' => $name );
            $hasSimple = true;
        }

        return $hasSimple ? array( 'compound' => $compound, 'suffix' => $suffix, 'type_span' => $typeSpan ) : null;
    }

    /** @return array{name: string, operator: string|null, value: string|null, flag: string|null}|null */
    private static function attribute(string $source, int &$offset): ?array
    {
        ++$offset;
        self::skipIgnorable($source, $offset);
        $name = self::identifier($source, $offset);
        if ( null === $name ) {
            return null;
        }
        $name = strtolower($name); // HTML attribute names are ASCII-case-insensitive.
        self::skipIgnorable($source, $offset);
        if ( ']' === ($source[ $offset ] ?? '') ) {
            ++$offset;
            return array( 'name' => $name, 'operator' => null, 'value' => null, 'flag' => null );
        }

        $operator = self::attributeOperator($source, $offset);
        if ( null === $operator ) {
            return null;
        }
        self::skipIgnorable($source, $offset);
        $value = self::attributeValue($source, $offset);
        if ( null === $value ) {
            return null;
        }
        self::skipIgnorable($source, $offset);

        $flag = null;
        if ( ']' !== ($source[ $offset ] ?? '') ) {
            $flag = self::identifier($source, $offset);
            if ( null !== $flag ) {
                $flag = strtolower($flag);
            }
            if ( ! in_array($flag, array( 'i', 's' ), true) ) {
                return null;
            }
            self::skipIgnorable($source, $offset);
        }
        if ( ']' !== ($source[ $offset ] ?? '') ) {
            return null;
        }
        ++$offset;

        return array( 'name' => $name, 'operator' => $operator, 'value' => $value, 'flag' => $flag );
    }

    private static function attributeOperator(string $source, int &$offset): ?string
    {
        foreach ( array( '~=', '|=', '^=', '$=', '*=', '=' ) as $candidate ) {
            if ( substr($source, $offset, strlen($candidate)) === $candidate ) {
                $offset += strlen($candidate);
                return $candidate;
            }
        }
        return null;
    }

    private static function attributeValue(string $source, int &$offset): ?string
    {
        $quote = $source[ $offset ] ?? '';
        if ( '"' !== $quote && "'" !== $quote ) {
            return self::identifier($source, $offset);
        }

        ++$offset;
        $value = '';
        while ( $offset < strlen($source) && $source[ $offset ] !== $quote ) {
            if ( "\n" === $source[ $offset ] || "\r" === $source[ $offset ] || "\f" === $source[ $offset ] ) {
                return null;
            }
            $escape = self::escape($source, $offset);
            if ( null === $escape ) {
                $value .= $source[ $offset ];
                ++$offset;
                continue;
            }
            $value .= $escape;
        }
        if ( $quote !== ($source[ $offset ] ?? '') ) {
            return null;
        }
        ++$offset;
        return $value;
    }

    private static function identifier(string $source, int &$offset): ?string
    {
        $start = $offset;
        $first = self::identifierFirstCharacter($source, $offset);
        if ( null === $first ) {
            $offset = $start;
            return null;
        }
        $value = $first;
        while ( $offset < strlen($source) ) {
            $escape = self::escape($source, $offset);
            if ( null !== $escape ) {
                $value .= $escape;
                continue;
            }
            if ( ! self::isIdentifierCharacter($source[ $offset ]) ) {
                break;
            }
            $value .= $source[ $offset ];
            ++$offset;
        }
        return $value;
    }

    private static function identifierFirstCharacter(string $source, int &$offset): ?string
    {
        $escape = self::escape($source, $offset);
        if ( null !== $escape ) {
            return $escape;
        }
        $character = $source[ $offset ] ?? '';
        if ( '-' === $character ) {
            ++$offset;
            $next = self::escape($source, $offset);
            if ( null !== $next ) {
                return '-' . $next;
            }
            if ( '-' !== ($source[ $offset ] ?? '') && ! self::isIdentifierStartCharacter($source[ $offset ] ?? '') ) {
                return null;
            }
            return '-';
        }
        if ( ! self::isIdentifierStartCharacter($character) ) {
            return null;
        }
        ++$offset;
        return $character;
    }

    private static function escape(string $source, int &$offset): ?string
    {
        if ( '\\' !== ($source[ $offset ] ?? '') ) {
            return null;
        }
        $escaped = $source[ $offset + 1 ] ?? '';
        if ( '' === $escaped || "\n" === $escaped || "\r" === $escaped || "\f" === $escaped ) {
            return null;
        }
        $end = CssSyntaxScanner::escapeEnd($source, $offset);
        if ( null === $end ) {
            return null;
        }
        $raw = substr($source, $offset + 1, $end - $offset - 1);
        $offset = $end;
        $hex = preg_replace('/[\x09\x0A\x0C\x0D\x20].*$/', '', $raw);
        if ( '' === $hex || ! ctype_xdigit($hex) ) {
            return $raw;
        }
        $codepoint = hexdec($hex);
        if ( 0 === $codepoint || $codepoint > 0x10ffff || ($codepoint >= 0xd800 && $codepoint <= 0xdfff) ) {
            return "\xef\xbf\xbd";
        }
        return self::utf8($codepoint);
    }

    private static function skipIgnorable(string $source, int &$offset): void
    {
        $length = strlen($source);
        while ( $offset < $length ) {
            if ( CssSyntaxScanner::isCssWhitespace($source[ $offset ]) ) {
                ++$offset;
                continue;
            }
            if ( '/*' !== substr($source, $offset, 2) ) {
                return;
            }
            $end = strpos($source, '*/', $offset + 2);
            if ( false === $end ) {
                $offset = $length;
                return;
            }
            $offset = $end + 2;
        }
    }

    /** @param list<array<string, mixed>> $compounds @param list<string> $combinators */
    private static function matchesAt(DOMElement $element, array $compounds, array $combinators, int $index): bool
    {
        if ( ! self::matchesCompound($element, $compounds[ $index ]) ) {
            return false;
        }
        if ( 0 === $index ) {
            return true;
        }

        $combinator = $combinators[ $index - 1 ];
        if ( '>' === $combinator ) {
            return $element->parentNode instanceof DOMElement && self::matchesAt($element->parentNode, $compounds, $combinators, $index - 1);
        }
        if ( '+' === $combinator ) {
            $previous = self::previousElementSibling($element);
            return null !== $previous && self::matchesAt($previous, $compounds, $combinators, $index - 1);
        }
        if ( '~' === $combinator ) {
            for ( $previous = self::previousElementSibling($element); null !== $previous; $previous = self::previousElementSibling($previous) ) {
                if ( self::matchesAt($previous, $compounds, $combinators, $index - 1) ) {
                    return true;
                }
            }
            return false;
        }

        for ( $parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( self::matchesAt($parent, $compounds, $combinators, $index - 1) ) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $compound */
    private static function matchesCompound(DOMElement $element, array $compound): bool
    {
        if ( null !== $compound['type'] && 0 !== strcasecmp($element->tagName, $compound['type']) ) {
            return false;
        }
        foreach ( $compound['ids'] as $id ) {
            if ( $element->getAttribute('id') !== $id ) {
                return false;
            }
        }
        $classes = preg_split('/[\x09\x0A\x0C\x0D\x20]+/', trim($element->getAttribute('class'))) ?: array();
        foreach ( $compound['classes'] as $class ) {
            if ( ! in_array($class, $classes, true) ) {
                return false;
            }
        }
        foreach ( $compound['attributes'] as $attribute ) {
            if ( ! self::matchesAttribute($element, $attribute) ) {
                return false;
            }
        }
        foreach ( $compound['not'] as $negated ) {
            if ( self::matchesCompound($element, $negated) ) {
                return false;
            }
        }
        $childIndex = null;
        if ( null !== $compound['nth_child'] || $compound['first_child'] || $compound['last_child'] ) {
            $childIndex = 1;
            for ( $previous = self::previousElementSibling($element); null !== $previous; $previous = self::previousElementSibling($previous) ) {
                ++$childIndex;
            }
        }
        if ( null !== $compound['nth_child'] && $childIndex !== $compound['nth_child'] ) {
            return false;
        }
        if ( $compound['first_child'] && 1 !== $childIndex ) {
            return false;
        }
        if ( $compound['last_child'] && self::hasNextElementSibling($element) ) {
            return false;
        }
        return true;
    }

    private static function hasNextElementSibling(DOMElement $element): bool
    {
        for ( $next = $element->nextSibling; $next instanceof DOMNode; $next = $next->nextSibling ) {
            if ( $next instanceof DOMElement ) {
                return true;
            }
        }
        return false;
    }

    /** @param array{name: string, operator: string|null, value: string|null, flag: string|null} $attribute */
    private static function matchesAttribute(DOMElement $element, array $attribute): bool
    {
        if ( ! $element->hasAttribute($attribute['name']) ) {
            return false;
        }
        if ( null === $attribute['operator'] ) {
            return true;
        }

        $actual = $element->getAttribute($attribute['name']);
        $expected = (string) $attribute['value'];
        if ( 'i' === $attribute['flag'] ) {
            $actual = strtolower($actual);
            $expected = strtolower($expected);
        }
        return match ( $attribute['operator'] ) {
            '=' => $actual === $expected,
            '~=' => in_array($expected, preg_split('/[\x09\x0A\x0C\x0D\x20]+/', trim($actual)) ?: array(), true),
            '|=' => $actual === $expected || str_starts_with($actual, $expected . '-'),
            '^=' => '' !== $expected && str_starts_with($actual, $expected),
            '$=' => '' !== $expected && str_ends_with($actual, $expected),
            '*=' => '' !== $expected && str_contains($actual, $expected),
        };
    }

    /** @param list<array<string, mixed>> $compounds */
    private static function hasUnmodeledHtmlAttributeValueSemantics(array $compounds): bool
    {
        // HTML defines these enumerated values as ASCII-case-insensitive by default.
        $enumerated = array( 'autocomplete', 'contenteditable', 'dir', 'draggable', 'enterkeyhint', 'hidden', 'inputmode', 'kind', 'method', 'rel', 'spellcheck', 'translate', 'type' );
        foreach ( $compounds as $compound ) {
            foreach ( $compound['attributes'] as $attribute ) {
                if ( null !== $attribute['operator'] && null === $attribute['flag'] && in_array($attribute['name'], $enumerated, true) ) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function previousElementSibling(DOMElement $element): ?DOMElement
    {
        for ( $node = $element->previousSibling; $node instanceof DOMNode; $node = $node->previousSibling ) {
            if ( $node instanceof DOMElement ) {
                return $node;
            }
        }
        return null;
    }

    private static function isIdentifierStartCharacter(string $character): bool
    {
        return ctype_alpha($character) || '_' === $character || ('' !== $character && ord($character) >= 0x80);
    }

    private static function isIdentifierCharacter(string $character): bool
    {
        return self::isIdentifierStartCharacter($character) || ctype_digit($character) || '-' === $character;
    }

    private static function utf8(int $codepoint): string
    {
        if ( $codepoint <= 0x7f ) {
            return chr($codepoint);
        }
        if ( $codepoint <= 0x7ff ) {
            return chr(0xc0 | ($codepoint >> 6)) . chr(0x80 | ($codepoint & 0x3f));
        }
        if ( $codepoint <= 0xffff ) {
            return chr(0xe0 | ($codepoint >> 12)) . chr(0x80 | (($codepoint >> 6) & 0x3f)) . chr(0x80 | ($codepoint & 0x3f));
        }
        return chr(0xf0 | ($codepoint >> 18)) . chr(0x80 | (($codepoint >> 12) & 0x3f)) . chr(0x80 | (($codepoint >> 6) & 0x3f)) . chr(0x80 | ($codepoint & 0x3f));
    }

    /** @return array{supported: false, reason: string, compounds: list<array<string, mixed>>, combinators: list<string>, type_spans: list<array{start: int, end: int, name: string, compound: int}>, rightmost_compound_span: null, pseudo_state_suffix_span: null, rightmost_rewrite_end: null} */
    private static function unsupported(string $reason): array
    {
        return array( 'supported' => false, 'reason' => $reason, 'compounds' => array(), 'combinators' => array(), 'type_spans' => array(), 'rightmost_compound_span' => null, 'pseudo_state_suffix_span' => null, 'rightmost_rewrite_end' => null );
    }
}
