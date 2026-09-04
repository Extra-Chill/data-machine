<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPress;

final class Runtime
{
    /**
     * @var array<int, string>
     */
    private const FALLBACK_CORE_BLOCK_NAMES = array(
        'core/accordion',
        'core/audio',
        'core/breadcrumbs',
        'core/button',
        'core/buttons',
        'core/categories',
        'core/code',
        'core/column',
        'core/columns',
        'core/details',
        'core/embed',
        'core/file',
        'core/footnotes',
        'core/gallery',
        'core/group',
        'core/heading',
        'core/icon',
        'core/image',
        'core/list',
        'core/list-item',
        'core/math',
        'core/media-text',
        'core/navigation',
        'core/navigation-link',
        'core/navigation-submenu',
        'core/paragraph',
        'core/post-terms',
        'core/preformatted',
        'core/pullquote',
        'core/query-total',
        'core/quote',
        'core/search',
        'core/separator',
        'core/shortcode',
        'core/spacer',
        'core/table',
        'core/tag-cloud',
        'core/term-description',
        'core/video',
    );

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $diagnostics = array();

    /** @var array<string, array<string, mixed>>|null */
    private ?array $fallbackCoreBlockSupports = null;

    /** @var array<string, array<string, array<string, mixed>>>|null */
    private ?array $fallbackCoreBlockAttributes = null;

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
     * Native core block names available as potential WordPress targets.
     *
     * @return array<int, string>
     */
    public function availableCoreBlockNames(): array
    {
        $registered = $this->registeredCoreBlockNames();
        if ( array() !== $registered ) {
            return $registered;
        }

        return self::FALLBACK_CORE_BLOCK_NAMES;
    }

    /**
     * Whether the registered block type declares support for one authored border
     * component. WordPress still exposes border support under the historical
     * `__experimentalBorder` key in block.json; accept the stabilized `border`
     * key as well. Unknown block metadata fails closed so callers can retain the
     * declaration through a CSS carrier instead of emitting an ignored attribute.
     */
    public function blockSupportsBorder(string $blockName, string $component): bool
    {
        if ( ! in_array($component, array( 'color', 'style', 'width' ), true) ) {
            return false;
        }

        $supports = $this->registeredBlockSupports($blockName);
        if ( null === $supports ) {
            $supports = $this->fallbackBlockSupports($blockName);
        }
        if ( null === $supports ) {
            return false;
        }

        $border = $supports['border'] ?? $supports['__experimentalBorder'] ?? false;
        if ( true === $border ) {
            return true;
        }

        return is_array($border) && true === ($border[ $component ] ?? false);
    }

    /**
     * @return array<int, string>
     */
    private function registeredCoreBlockNames(): array
    {
        $names = array();
        foreach ( $this->registeredBlockTypes() as $key => $blockType ) {
            $name = is_string($key) ? $key : '';
            if ( '' === $name && is_object($blockType) && isset($blockType->name) && is_string($blockType->name) ) {
                $name = $blockType->name;
            }

            if ( str_starts_with($name, 'core/') ) {
                $names[] = $name;
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * @return array<string|int, object>
     */
    private function registeredBlockTypes(): array
    {
        if ( ! class_exists('WP_Block_Type_Registry') || ! method_exists('WP_Block_Type_Registry', 'get_instance') ) {
            return array();
        }

        $registry = \WP_Block_Type_Registry::get_instance();
        if ( ! is_object($registry) || ! method_exists($registry, 'get_all_registered') ) {
            return array();
        }

        $registered = $registry->get_all_registered();
        return is_array($registered) ? $registered : array();
    }

    /**
     * Resolve support from the block type's registered declaration, never from a
     * transformer-owned block-name allowlist.
     *
     * @return array<string, mixed>|null
     */
    private function registeredBlockSupports(string $blockName): ?array
    {
        $blockType = $this->registeredBlockType($blockName);
        return is_object($blockType) ? (is_array($blockType->supports ?? null) ? $blockType->supports : array()) : null;
    }

    private function registeredBlockType(string $blockName): ?object
    {
        foreach ( $this->registeredBlockTypes() as $key => $blockType ) {
            $name = is_string($key) ? $key : '';
            if ( '' === $name && is_object($blockType) && isset($blockType->name) && is_string($blockType->name) ) {
                $name = $blockType->name;
            }
            if ( $blockName === $name && is_object($blockType) ) {
                return $blockType;
            }
        }

        return null;
    }

    /**
     * Standalone transforms have no WP_Block_Type_Registry. Load the generated
     * latest WordPress declaration snapshot so the same block.json support check
     * is still available. Live registered declarations always take precedence.
     *
     * @return array<string, mixed>|null
     */
    private function fallbackBlockSupports(string $blockName): ?array
    {
        if ( null === $this->fallbackCoreBlockSupports ) {
            $path = dirname(__DIR__, 2) . '/resources/wordpress-latest-core-block-supports.json';
            $registry = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            $this->fallbackCoreBlockSupports = is_array($registry['blocks'] ?? null) ? $registry['blocks'] : array();
        }

        $supports = $this->fallbackCoreBlockSupports[ $blockName ] ?? null;
        return is_array($supports) ? $supports : null;
    }

    /**
     * Resolve attributes from a live registered declaration first, then from
     * the generated core snapshot for standalone transforms. A registered
     * declaration without attributes is authoritative and deliberately does
     * not fall back to a possibly stale snapshot.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private function blockAttributes(string $blockName): ?array
    {
        $blockType = $this->registeredBlockType($blockName);
        if ( is_object($blockType) ) {
            return is_array($blockType->attributes ?? null) ? $blockType->attributes : array();
        }

        if ( null === $this->fallbackCoreBlockAttributes ) {
            $path = dirname(__DIR__, 2) . '/resources/wordpress-latest-core-block-attributes.json';
            $registry = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            $this->fallbackCoreBlockAttributes = is_array($registry['blocks'] ?? null) ? $registry['blocks'] : array();
        }

        $attributes = $this->fallbackCoreBlockAttributes[ $blockName ] ?? null;
        return is_array($attributes) ? $attributes : null;
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
        $blocks = $this->canonicalRuntimeBlocks($blocks);

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
        $block = $this->canonicalRuntimeBlocks(array( $block ))[0];

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
        $blocks = $this->canonicalRuntimeBlocks($blocks);

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

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function canonicalRuntimeBlocks(array $blocks): array
    {
        $canonical = array();
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';
            $attributes = $this->blockAttributes($name);
            if ( is_array($attributes) && is_array($block['attrs'] ?? null) ) {
                foreach ( $attributes as $attribute => $schema ) {
                    if ( is_array($schema) && ( array_key_exists('source', $schema) || 'local' === ($schema['role'] ?? null) ) ) {
                        unset($block['attrs'][ $attribute ]);
                    }
                }
            }

            if ( is_array($block['innerBlocks'] ?? null) ) {
                $block['innerBlocks'] = $this->canonicalRuntimeBlocks($block['innerBlocks']);
            }
            $canonical[] = $block;
        }

        return $canonical;
    }

    /**
     * @param string|array<int, array<string, mixed>> $serializedBlocksOrBlocks
     * @return array<string, mixed>
     */
    public function validateBlockSerialization(string|array $serializedBlocksOrBlocks): array
    {
        if ( is_string($serializedBlocksOrBlocks) ) {
            $blocks = $this->parseBlocks($serializedBlocksOrBlocks);
            $report = $this->buildBlockValidityReport($blocks);

            if ( array() === $blocks && str_contains($serializedBlocksOrBlocks, '<!-- wp:') ) {
                $report['status'] = 'warning';
                $report['summary']['finding_count'] = ((int) ($report['summary']['finding_count'] ?? 0)) + 1;
                $report['findings'][] = array(
                    'code'     => 'serialized_blocks_parse_failed',
                    'severity' => 'warning',
                    'category' => 'wp_block_validity',
                    'path'     => 'serialized_blocks',
                    'summary'  => 'Serialized block comments were present but could not be parsed into a balanced block tree.',
                );
            }

            return $report;
        }

        return $this->buildBlockValidityReport($serializedBlocksOrBlocks);
    }

    /**
     * Run the serialization-structure validator and the canonical save()-shape
     * validator over the same parsed block tree and merge their findings into a
     * single wp_block_validity report. Both are pure-PHP and need no WordPress
     * runtime, so the report stays usable in the standalone transformer loop.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array<string, mixed>
     */
    private function buildBlockValidityReport(array $blocks): array
    {
        $report = ( new BlockValidityValidator() )->validateBlocks($blocks);

        $saveShapeFindings = ( new CanonicalSaveShapeValidator() )->findings($blocks);
        if ( array() !== $saveShapeFindings ) {
            $report['findings'] = array_merge(
                is_array($report['findings'] ?? null) ? $report['findings'] : array(),
                $saveShapeFindings
            );
            $report['summary']['finding_count'] = count($report['findings']);
            $report['status'] = 'warning';
        }

        return $report;
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
     * Serialize a single block to canonical comment-delimited block markup, the
     * way WordPress core's serialize_block()/get_comment_delimited_block_content()
     * do. The inner content is built by {@see serializeInnerContent()}, which walks
     * innerContent and emits each nested block as block MARKUP (not rendered static
     * HTML). This keeps dynamic/nested blocks — core/navigation and its
     * navigation-link/submenu children, and any nested block — present in the
     * serialized string instead of collapsing them to a self-closing comment or
     * dropping them entirely.
     *
     * @param array<string, mixed> $block
     */
    private function serializeBlock(array $block): string
    {
        $blockContent = $this->serializeInnerContent($block);

        $blockName = isset($block['blockName']) ? (string) $block['blockName'] : '';
        if ( '' === $blockName ) {
            return $blockContent;
        }

        $name  = str_starts_with($blockName, 'core/') ? substr($blockName, 5) : $blockName;
        $attrs = empty($block['attrs']) || ! is_array($block['attrs']) ? '' : ' ' . $this->serializeBlockAttributes($block['attrs']);

        if ( '' === $blockContent ) {
            return '<!-- wp:' . $name . $attrs . ' /-->';
        }

        return '<!-- wp:' . $name . $attrs . ' -->' . $blockContent . '<!-- /wp:' . $name . ' -->';
    }

    /**
     * Serialize block attributes for the comment delimiter the way WordPress
     * core's serialize_block_attributes() does: JSON-encode, then escape the
     * characters that could otherwise break out of the surrounding HTML comment
     * (`--`, `<`, `>`, `&`) plus escaped quotes. This keeps the delimiter
     * comment-safe and WP-canonical even when an attribute value embeds raw HTML
     * (e.g. a core/paragraph `content` carrying an inline `<a>`), so the comment
     * stays a single parseable token. The codebase's unescaped-slash/unicode JSON
     * convention is preserved via encodeJson().
     *
     * @param array<string, mixed> $attrs
     */
    private function serializeBlockAttributes(array $attrs): string
    {
        $encoded = $this->encodeJson($attrs);
        $encoded = str_replace('--', '\\u002d\\u002d', $encoded);
        $encoded = preg_replace('/</', '\\u003c', $encoded) ?? $encoded;
        $encoded = preg_replace('/>/', '\\u003e', $encoded) ?? $encoded;
        $encoded = preg_replace('/&/', '\\u0026', $encoded) ?? $encoded;

        return preg_replace('/\\\\"/', '\\u0022', $encoded) ?? $encoded;
    }

    /**
     * Build a block's inner serialized content. Mirrors WordPress core's
     * serialize_block() inner loop: walk innerContent, append literal string
     * chunks verbatim, and replace each null placeholder with the recursively
     * serialized markup of the next inner block. When innerContent is not a
     * structured array, fall back to serializing any inner blocks as markup
     * followed by the saved innerHTML so no nested block is silently dropped.
     *
     * @param array<string, mixed> $block
     */
    private function serializeInnerContent(array $block): string
    {
        $innerBlocks  = isset($block['innerBlocks']) && is_array($block['innerBlocks']) ? array_values($block['innerBlocks']) : array();
        $innerContent = $block['innerContent'] ?? null;

        if ( ! is_array($innerContent) ) {
            $serialized = '';
            foreach ( $innerBlocks as $innerBlock ) {
                if ( is_array($innerBlock) ) {
                    $serialized .= $this->serializeBlock($innerBlock);
                }
            }

            return $serialized . (isset($block['innerHTML']) ? (string) $block['innerHTML'] : '');
        }

        $serialized = '';
        $blockIndex = 0;
        foreach ( $innerContent as $part ) {
            if ( null === $part ) {
                $innerBlock  = $innerBlocks[$blockIndex] ?? null;
                $serialized .= is_array($innerBlock) ? $this->serializeBlock($innerBlock) : '';
                ++$blockIndex;
                continue;
            }

            $serialized .= (string) $part;
        }

        return $serialized;
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
