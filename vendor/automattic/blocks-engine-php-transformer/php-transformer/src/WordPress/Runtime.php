<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPress;

final class Runtime
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $diagnostics = array();

    public function hasWordPress(): bool
    {
        return $this->canParseBlocks()
            || $this->canSerializeBlocks()
            || $this->canRenderBlock()
            || $this->canStripAllTags()
            || $this->canParseShortcodeAttributes()
            || $this->canEncodeJson()
            || $this->canEscapeHtml()
            || $this->canEscapeAttribute();
    }

    public function canParseBlocks(): bool
    {
        return function_exists('parse_blocks');
    }

    public function canSerializeBlocks(): bool
    {
        return function_exists('serialize_blocks');
    }

    public function canRenderBlock(): bool
    {
        return function_exists('render_block');
    }

    public function canStripAllTags(): bool
    {
        return function_exists('wp_strip_all_tags');
    }

    public function canParseShortcodeAttributes(): bool
    {
        return function_exists('shortcode_parse_atts');
    }

    public function canEncodeJson(): bool
    {
        return function_exists('wp_json_encode');
    }

    public function canEscapeHtml(): bool
    {
        return function_exists('esc_html');
    }

    public function canEscapeAttribute(): bool
    {
        return function_exists('esc_attr');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseBlocks(string $content): array
    {
        $this->diagnostics = array();

        if ( $this->canParseBlocks() ) {
            return parse_blocks($content);
        }

        $this->addDiagnostic('wordpress_parse_blocks_unavailable', 'parse_blocks() is unavailable; using the PHP transformer serialized-block fallback.');

        $blocks = $this->parseSerializedBlocks($content);
        if ( array() !== $blocks ) {
            return $blocks;
        }

        return '' === trim($content) ? array() : array(
            array(
                'blockName'    => null,
                'attrs'        => array(),
                'innerBlocks'  => array(),
                'innerHTML'    => $content,
                'innerContent' => array( $content ),
            ),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    public function serializeBlocks(array $blocks): string
    {
        $this->diagnostics = array();

        if ( $this->canSerializeBlocks() ) {
            return serialize_blocks($blocks);
        }

        $this->addDiagnostic('wordpress_serialize_blocks_unavailable', 'serialize_blocks() is unavailable; using the PHP transformer serialized-block fallback.');

        $serialized = '';
        foreach ( $blocks as $block ) {
            $serialized .= $this->serializeBlock($block);
        }

        return $serialized;
    }

    /**
     * @param array<string, mixed> $block
     */
    public function renderBlock(array $block): string
    {
        $this->diagnostics = array();

        if ( $this->canRenderBlock() ) {
            return render_block($block);
        }

        $this->addDiagnostic('wordpress_render_block_unavailable', 'render_block() is unavailable; rendering static block HTML only.');

        return $this->renderStaticBlock($block);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    public function renderBlocks(array $blocks): string
    {
        $this->diagnostics = array();

        $html = '';
        foreach ( $blocks as $block ) {
            if ( $this->canRenderBlock() ) {
                $html .= render_block($block);
                continue;
            }

            $html .= $this->renderStaticBlock($block);
        }

        if ( ! $this->canRenderBlock() && array() !== $blocks ) {
            $this->addDiagnostic('wordpress_render_block_unavailable', 'render_block() is unavailable; rendering static block HTML only.');
        }

        return $html;
    }

    public function stripAllTags(string $text, bool $removeBreaks = false): string
    {
        $this->diagnostics = array();

        if ( $this->canStripAllTags() ) {
            return wp_strip_all_tags($text, $removeBreaks);
        }

        $this->addDiagnostic('wordpress_strip_all_tags_unavailable', 'wp_strip_all_tags() is unavailable; using the PHP strip_tags() fallback.');

        $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text) ?? $text;
        $text = strip_tags($text);

        return $removeBreaks ? preg_replace('/[\r\n\t ]+/', ' ', $text) ?? $text : $text;
    }

    public function containsShortcode(string $text): bool
    {
        return array() !== $this->parseShortcodes($text);
    }

    public function isShortcodeOnly(string $text): bool
    {
        $text = trim($text);
        if ( '' === $text ) {
            return false;
        }

        $shortcodes = $this->parseShortcodes($text);
        if ( 1 !== count($shortcodes) ) {
            return false;
        }

        return $shortcodes[0]['raw'] === $text;
    }

    public function preserveShortcodeText(string $text): string
    {
        return trim($text);
    }

    /**
     * @return array<int, array{name: string, attrs: array<string, mixed>, content: string|null, raw: string}>
     */
    public function parseShortcodes(string $text): array
    {
        if ( ! preg_match_all('/\[([A-Za-z][A-Za-z0-9_-]*)([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(\/)?\](?:(.*?)\[\/\1\])?/s', $text, $matches, PREG_SET_ORDER) ) {
            return array();
        }

        $shortcodes = array();
        foreach ( $matches as $match ) {
            $raw = $match[0];
            if ( str_starts_with($raw, '[[') ) {
                continue;
            }

            $shortcodes[] = array(
                'name'    => $match[1],
                'attrs'   => $this->parseShortcodeAttributes(trim($match[2] ?? '')),
                'content' => array_key_exists(4, $match) && '' !== $match[4] ? $match[4] : null,
                'raw'     => $raw,
            );
        }

        return $shortcodes;
    }

    /**
     * @return array<string, mixed>
     */
    public function parseShortcodeAttributes(string $text): array
    {
        if ( '' === $text ) {
            return array();
        }

        if ( $this->canParseShortcodeAttributes() ) {
            $attrs = shortcode_parse_atts($text);
            return is_array($attrs) ? $attrs : array();
        }

        $attrs = array();
        if ( preg_match_all('/([A-Za-z0-9_-]+)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s]+))|"([^"]*)"|\'([^\']*)\'|(\S+)/', $text, $matches, PREG_SET_ORDER) ) {
            foreach ( $matches as $match ) {
                if ( '' !== ($match[1] ?? '') ) {
                    $attrs[$match[1]] = $match[3] ?? $match[4] ?? $match[5] ?? '';
                    continue;
                }

                $attrs[] = $match[6] ?? $match[7] ?? $match[8] ?? '';
            }
        }

        return $attrs;
    }

    /**
     * @param mixed $data
     */
    public function encodeJson(mixed $data, int $flags = 0): string
    {
        $flags |= JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        if ( $this->canEncodeJson() ) {
            $json = wp_json_encode($data, $flags);
        } else {
            $json = json_encode($data, $flags);
        }

        return false === $json ? '' : $json;
    }

    public function escapeHtml(string $text): string
    {
        return $this->canEscapeHtml() ? esc_html($text) : htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function escapeAttribute(string $text): string
    {
        return $this->canEscapeAttribute() ? esc_attr($text) : htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $block
     */
    private function serializeBlock(array $block): string
    {
        $blockName = isset($block['blockName']) ? (string) $block['blockName'] : '';
        if ( '' === $blockName ) {
            return $this->renderStaticBlock($block);
        }

        $name  = str_starts_with($blockName, 'core/') ? substr($blockName, 5) : $blockName;
        $attrs = empty($block['attrs']) ? '' : ' ' . $this->encodeJson($block['attrs']);
        $inner = $this->renderStaticBlock($block);

        if ( '' === $inner ) {
            return '<!-- wp:' . $name . $attrs . ' /-->';
        }

        return '<!-- wp:' . $name . $attrs . ' -->' . $inner . '<!-- /wp:' . $name . ' -->';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseSerializedBlocks(string $content): array
    {
        if ( ! preg_match_all('/<!--\s*(\/)?wp:([a-z][a-z0-9-]*(?:\/[a-z][a-z0-9-]*)?)(?:\s+(\{.*?\}))?\s*(\/)?\s*-->/s', $content, $matches, PREG_OFFSET_CAPTURE) ) {
            return array();
        }

        $blocks = array();
        $stack  = array();
        $cursor = 0;

        foreach ( $matches[0] as $index => $match ) {
            $raw     = $match[0];
            $offset  = $match[1];
            $between = substr($content, $cursor, $offset - $cursor);
            if ( '' !== $between && array() !== $stack ) {
                $stack[array_key_last($stack)]['innerContent'][] = $between;
            }

            $isClose = '' !== ($matches[1][$index][0] ?? '');
            $name    = $matches[2][$index][0];
            $attrs   = $this->decodeBlockAttrs($matches[3][$index][0] ?? '');
            $isVoid  = '' !== ($matches[4][$index][0] ?? '');

            if ( $isClose ) {
                $frame = array_pop($stack);
                if ( ! is_array($frame) || $frame['name'] !== $name ) {
                    return array();
                }

                $block = $this->createParsedBlock($name, $frame['attrs'], $frame['innerBlocks'], $frame['innerContent']);
                $this->appendParsedBlock($blocks, $stack, $block);
            } elseif ( $isVoid ) {
                $this->appendParsedBlock($blocks, $stack, $this->createParsedBlock($name, $attrs, array(), array()));
            } else {
                $stack[] = array(
                    'name'         => $name,
                    'attrs'        => $attrs,
                    'innerBlocks'  => array(),
                    'innerContent' => array(),
                );
            }

            $cursor = $offset + strlen($raw);
        }

        if ( array() !== $stack ) {
            return array();
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBlockAttrs(string $json): array
    {
        if ( '' === trim($json) ) {
            return array();
        }

        $attrs = json_decode($json, true);

        return is_array($attrs) ? $attrs : array();
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<int, array<string, mixed>> $innerBlocks
     * @param array<int, string|null> $innerContent
     * @return array<string, mixed>
     */
    private function createParsedBlock(string $name, array $attrs, array $innerBlocks, array $innerContent): array
    {
        $innerHTML = '';
        foreach ( $innerContent as $part ) {
            if ( null !== $part ) {
                $innerHTML .= $part;
            }
        }

        return array(
            'blockName'    => str_contains($name, '/') ? $name : 'core/' . $name,
            'attrs'        => $attrs,
            'innerBlocks'  => $innerBlocks,
            'innerHTML'    => $innerHTML,
            'innerContent' => $innerContent,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $stack
     * @param array<string, mixed> $block
     */
    private function appendParsedBlock(array &$blocks, array &$stack, array $block): void
    {
        if ( array() === $stack ) {
            $blocks[] = $block;
            return;
        }

        $key = array_key_last($stack);
        $stack[$key]['innerBlocks'][]  = $block;
        $stack[$key]['innerContent'][] = null;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function renderStaticBlock(array $block): string
    {
        $innerContent = $block['innerContent'] ?? null;
        $innerBlocks  = $block['innerBlocks'] ?? array();

        if ( is_array($innerContent) ) {
            $html       = '';
            $blockIndex = 0;
            foreach ( $innerContent as $part ) {
                if ( null === $part ) {
                    $innerBlock = is_array($innerBlocks) && isset($innerBlocks[$blockIndex]) && is_array($innerBlocks[$blockIndex]) ? $innerBlocks[$blockIndex] : null;
                    $html      .= null === $innerBlock ? '' : $this->renderStaticBlock($innerBlock);
                    ++$blockIndex;
                    continue;
                }

                $html .= (string) $part;
            }

            return $html;
        }

        return isset($block['innerHTML']) ? (string) $block['innerHTML'] : '';
    }

    private function addDiagnostic(string $code, string $message): void
    {
        $this->diagnostics[] = array(
            'code'    => $code,
            'message' => $message,
            'source'  => self::class,
        );
    }
}
