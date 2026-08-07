<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\FormatBridge;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use League\HTMLToMarkdown\HtmlConverter;
use Throwable;

/**
 * @internal Bundled adapters are implementation details of FormatBridge.
 */
final class MarkdownAdapter implements FormatAdapterInterface
{
    public function __construct(
        private readonly HtmlAdapter $htmlAdapter = new HtmlAdapter(),
        private readonly BlocksAdapter $blocksAdapter = new BlocksAdapter()
    ) {
    }

    public function slug(): string
    {
        return 'markdown';
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int|string, array<string, mixed>>
     */
    public function toBlocks(string $content, array $options = array()): array
    {
        if ( '' === trim($content) ) {
            return array();
        }

        $html = $this->markdownToHtml($content);
        if ( '' === trim($html) ) {
            return array();
        }

        return $this->htmlAdapter->toBlocks($html, $options);
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @param array<string, mixed> $options
     */
    public function fromBlocks(array $blocks, array $options = array()): string
    {
        if ( array() === $blocks ) {
            return '';
        }

        $html = $this->blocksAdapter->fromBlocks($blocks, $options);
        if ( '' === trim($html) ) {
            return '';
        }

        $html = $this->normalizePreBlocks($html);
        $markdown = $this->htmlToMarkdown($html, $options);
        $placeholders = $this->emptyDynamicBlockPlaceholders($blocks);
        if ( array() !== $placeholders ) {
            $markdown = trim($markdown . "\n\n" . implode("\n\n", $placeholders));
        }

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $markdown));
    }

    public function detect(string $content): bool
    {
        return (bool) preg_match('/(^|\n)\s*(#{1,6}\s+|[-*+]\s+\S|\d+\.\s+\S|>\s+\S|```)/', $content);
    }

    private function markdownToHtml(string $markdown): string
    {
        try {
            return (string) (new GithubFlavoredMarkdownConverter())->convert($markdown);
        } catch ( Throwable ) {
            return '';
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function htmlToMarkdown(string $html, array $options): string
    {
        $converterOptions = array_replace(
            array(
                'header_style' => 'atx',
                'strip_tags'   => true,
                'remove_nodes' => 'script style',
                'hard_break'   => true,
            ),
            isset($options['html_to_markdown']) && is_array($options['html_to_markdown']) ? $options['html_to_markdown'] : array()
        );

        try {
            $converter = new HtmlConverter($converterOptions);
            $this->registerTableConverter($converter);

            return (string) $converter->convert($html);
        } catch ( Throwable ) {
            return '';
        }
    }

    private function normalizePreBlocks(string $html): string
    {
        return (string) preg_replace_callback(
            '#<pre\b[^>]*>(.*?)</pre>#is',
            static function (array $match): string {
                $languageClass = '';
                if ( preg_match('/\blanguage-([A-Za-z0-9_-]+)/', $match[0], $languageMatch) ) {
                    $languageClass = ' class="language-' . htmlspecialchars($languageMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
                }

                $inner = html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return '<pre><code' . $languageClass . '>' . htmlspecialchars($inner, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</code></pre>';
            },
            $html
        );
    }

    private function registerTableConverter(HtmlConverter $converter): void
    {
        if ( ! class_exists('League\\HTMLToMarkdown\\Converter\\TableConverter') ) {
            return;
        }

        try {
            $converter->getEnvironment()->addConverter(new \League\HTMLToMarkdown\Converter\TableConverter());
        } catch ( Throwable ) {
        }
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @return array<int, string>
     */
    private function emptyDynamicBlockPlaceholders(array $blocks): array
    {
        $placeholders = array();
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            $innerBlocks = $block['innerBlocks'] ?? array();
            if ( is_array($innerBlocks) && array() !== $innerBlocks ) {
                $placeholders = array_merge($placeholders, $this->emptyDynamicBlockPlaceholders($innerBlocks));
            }

            $blockName = (string) ($block['blockName'] ?? '');
            if ( '' === $blockName || $this->hasStaticHtml($block) || $this->isKnownStaticCoreBlock($blockName) ) {
                continue;
            }

            $attrs = empty($block['attrs']) || ! is_array($block['attrs']) ? '' : ' ' . (json_encode($block['attrs'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
            $name = str_starts_with($blockName, 'core/') ? substr($blockName, 5) : $blockName;
            $placeholders[] = '<!-- wp:' . $name . $attrs . ' /-->';
        }

        return $placeholders;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function hasStaticHtml(array $block): bool
    {
        if ( '' !== trim((string) ($block['innerHTML'] ?? '')) ) {
            return true;
        }

        $innerContent = $block['innerContent'] ?? array();
        if ( ! is_array($innerContent) ) {
            return false;
        }

        foreach ( $innerContent as $part ) {
            if ( null !== $part && '' !== trim((string) $part) ) {
                return true;
            }
        }

        return false;
    }

    private function isKnownStaticCoreBlock(string $blockName): bool
    {
        return in_array($blockName, array(
            'core/audio',
            'core/button',
            'core/buttons',
            'core/code',
            'core/column',
            'core/columns',
            'core/details',
            'core/embed',
            'core/file',
            'core/gallery',
            'core/group',
            'core/heading',
            'core/html',
            'core/image',
            'core/list',
            'core/list-item',
            'core/navigation',
            'core/navigation-link',
            'core/paragraph',
            'core/preformatted',
            'core/pullquote',
            'core/quote',
            'core/separator',
            'core/shortcode',
            'core/table',
            'core/video',
        ), true);
    }
}
