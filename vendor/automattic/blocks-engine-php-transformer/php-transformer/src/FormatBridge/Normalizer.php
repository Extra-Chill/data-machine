<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\FormatBridge;

use InvalidArgumentException;

/**
 * @internal Declared-format normalization is owned by FormatBridge.
 */
final class Normalizer
{
    /**
     * @param array<string, mixed> $options
     */
    public function normalize(string $content, string $format, AdapterRegistry $registry, array $options = array()): string
    {
        $mode = isset($options['mode']) ? (string) $options['mode'] : 'strict';
        if ( ! in_array($mode, array( 'strict', 'lenient' ), true) ) {
            throw new InvalidArgumentException(sprintf('Unsupported normalization mode "%s".', $mode));
        }

        if ( ! $registry->supports($format) ) {
            throw new InvalidArgumentException(sprintf('No format adapter is registered for format "%s".', $format));
        }

        return match ($format) {
            'blocks' => $this->normalizeBlocks($content),
            'markdown' => $this->normalizeMarkdown($content),
            'html' => $this->normalizeHtml($content),
            default => $content,
        };
    }

    public function normalizeBlocks(string $content): string
    {
        if ( '' === trim($content) ) {
            return '';
        }

        $tokens = $this->extractBlockTokens($content);
        if ( array() === $tokens ) {
            throw new InvalidArgumentException('Declared blocks content does not contain serialized block comments.');
        }

        $stack = array();
        $cursor = 0;
        foreach ( $tokens as $token ) {
            $between = substr($content, $cursor, $token['offset'] - $cursor);
            if ( array() === $stack && '' !== trim($between) ) {
                throw new InvalidArgumentException('Declared blocks content contains raw content outside serialized block comments.');
            }

            if ( 'open' === $token['type'] ) {
                $stack[] = $token['name'];
            } elseif ( 'close' === $token['type'] ) {
                $expected = array_pop($stack);
                if ( $expected !== $token['name'] ) {
                    throw new InvalidArgumentException('Mismatched serialized block closing comment.');
                }
            }

            $cursor = $token['offset'] + strlen($token['raw']);
        }

        $trailing = substr($content, $cursor);
        if ( array() === $stack && '' !== trim($trailing) ) {
            throw new InvalidArgumentException('Declared blocks content contains raw content outside serialized block comments.');
        }

        if ( array() !== $stack ) {
            throw new InvalidArgumentException('Serialized block markup contains an unclosed block comment.');
        }

        return $content;
    }

    public function normalizeMarkdown(string $content): string
    {
        if ( $this->containsBlockComment($content) ) {
            throw new InvalidArgumentException('Declared markdown content contains serialized block comments.');
        }

        return str_replace(array( "\r\n", "\r" ), "\n", $content);
    }

    public function normalizeHtml(string $content): string
    {
        if ( $this->containsBlockComment($content) ) {
            throw new InvalidArgumentException('Declared HTML content contains serialized block comments.');
        }

        if ( $this->htmlContainsMarkdownMarkers($content) ) {
            throw new InvalidArgumentException('Declared HTML content contains markdown markers.');
        }

        return $content;
    }

    /**
     * @return array<int, array{raw: string, offset: int, type: string, name: string}>
     */
    private function extractBlockTokens(string $content): array
    {
        if ( ! preg_match_all('/<!--\s*(\/?wp:[^>]*)-->/', $content, $matches, PREG_OFFSET_CAPTURE) ) {
            return array();
        }

        $tokens = array();
        foreach ( $matches[0] as $index => $match ) {
            $raw = $match[0];
            $inner = trim($matches[1][$index][0]);

            if ( preg_match('/^wp:([a-z][a-z0-9-]*(?:\/[a-z][a-z0-9-]*)?)(?:\s+\{.*\})?\s*(\/)?$/s', $inner, $open) ) {
                $tokens[] = array(
                    'raw' => $raw,
                    'offset' => $match[1],
                    'type' => isset($open[2]) ? 'self' : 'open',
                    'name' => $open[1],
                );
                continue;
            }

            if ( preg_match('/^\/wp:([a-z][a-z0-9-]*(?:\/[a-z][a-z0-9-]*)?)$/', $inner, $close) ) {
                $tokens[] = array(
                    'raw' => $raw,
                    'offset' => $match[1],
                    'type' => 'close',
                    'name' => $close[1],
                );
                continue;
            }

            throw new InvalidArgumentException('Malformed serialized block comment.');
        }

        return $tokens;
    }

    private function containsBlockComment(string $content): bool
    {
        return (bool) preg_match('/<!--\s*\/?wp:/', $content);
    }

    private function htmlContainsMarkdownMarkers(string $content): bool
    {
        return (bool) preg_match('/(^|\n)\s*(```|#{1,6}\s+|[-*+]\s+\S|>\s+\S)/', $content);
    }
}
