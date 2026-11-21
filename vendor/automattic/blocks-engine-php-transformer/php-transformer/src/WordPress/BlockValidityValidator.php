<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPress;

final class BlockValidityValidator
{
    public const SCHEMA = 'blocks-engine/php-transformer/wp-block-validity-report/v1';

    /**
     * Dynamic, server-rendered core blocks whose registered `save()` returns
     * null, so WordPress stores no static markup for them — only the block
     * comment delimiters plus serialized inner blocks. `wp.blocks.validateBlock`
     * re-runs `save()` (empty) and flags the block invalid the moment the stored
     * markup carries any static wrapper HTML. The fast loop asserts these blocks
     * round-trip as empty so the regression is caught off the editor gate.
     *
     * @var array<int, string>
     */
    private const DYNAMIC_EMPTY_SAVE_BLOCKS = array(
        'core/navigation',
        'core/navigation-link',
        'core/navigation-submenu',
        'core/search',
    );

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<string, mixed>
     */
    public function validateBlocks(array $blocks): array
    {
        $findings = array();
        $checkedBlockTypes = array();

        foreach ( $blocks as $index => $block ) {
            if ( is_array($block) ) {
                $this->validateBlock($block, 'blocks.' . $index, $findings, $checkedBlockTypes);
            }
        }

        sort($checkedBlockTypes);

        return array(
            'schema'   => self::SCHEMA,
            'status'   => array() === $findings ? 'pass' : 'warning',
            'summary'  => array(
                'block_count'         => $this->countBlocks($blocks),
                'finding_count'       => count($findings),
                'checked_block_types' => $checkedBlockTypes,
            ),
            'findings' => $findings,
        );
    }

    /**
     * @param array<string, mixed> $block
     * @param array<int, array<string, mixed>> $findings
     * @param array<string, string> $checkedBlockTypes
     */
    private function validateBlock(array $block, string $path, array &$findings, array &$checkedBlockTypes): void
    {
        $blockName = is_string($block['blockName'] ?? null) ? $block['blockName'] : null;
        if ( null !== $blockName ) {
            $checkedBlockTypes[$blockName] = $blockName;
        }

        $innerBlocks = is_array($block['innerBlocks'] ?? null) ? array_values($block['innerBlocks']) : array();
        $innerContent = is_array($block['innerContent'] ?? null) ? $block['innerContent'] : null;
        $innerHTML = is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : null;

        if ( null === $innerContent ) {
            $this->addFinding($findings, 'missing_inner_content', 'warning', $path, $blockName, 'Block is missing innerContent, so serialization cannot prove child placeholder boundaries.');
        } else {
            $nullCount = 0;
            $staticInnerHTML = '';
            foreach ( $innerContent as $part ) {
                if ( null === $part ) {
                    ++$nullCount;
                    continue;
                }

                $part = (string) $part;
                $staticInnerHTML .= $part;
                if ( str_contains($part, '<!-- wp:') ) {
                    $this->addFinding($findings, 'serialized_block_comment_in_inner_content', 'warning', $path, $blockName, 'innerContent contains serialized block comments instead of null child placeholders.');
                }
            }

            if ( null !== $blockName && in_array($blockName, self::DYNAMIC_EMPTY_SAVE_BLOCKS, true) && '' !== trim($staticInnerHTML) ) {
                $this->addFinding(
                    $findings,
                    'dynamic_block_static_markup',
                    'warning',
                    $path,
                    $blockName,
                    'Dynamic block carries static wrapper markup, but its save() returns null; WordPress stores only the block comment, so wp.blocks.validateBlock flags the stored markup invalid in the editor.'
                );
            }

            if ( count($innerBlocks) !== $nullCount ) {
                $this->addFinding(
                    $findings,
                    'inner_content_child_count_mismatch',
                    'warning',
                    $path,
                    $blockName,
                    'innerContent null placeholders must match innerBlocks count.',
                    array('inner_block_count' => count($innerBlocks), 'placeholder_count' => $nullCount)
                );
            }

            if ( null !== $innerHTML && $staticInnerHTML !== $innerHTML ) {
                $this->addFinding($findings, 'inner_html_inner_content_mismatch', 'warning', $path, $blockName, 'innerHTML must equal the non-child string parts of innerContent.');
            }

            if ( array() !== $innerBlocks && 0 === $nullCount ) {
                $this->addFinding($findings, 'missing_child_placeholders', 'warning', $path, $blockName, 'Block has innerBlocks but no null placeholders in innerContent.');
            }
        }

        if ( null !== $innerHTML && str_contains($innerHTML, '<!-- wp:') ) {
            $this->addFinding($findings, 'serialized_block_comment_in_inner_html', 'warning', $path, $blockName, 'innerHTML contains serialized child block comments; WordPress expects child blocks to be represented through innerBlocks and innerContent placeholders.');
        }

        if ( 'core/button' === $blockName ) {
            $this->validateLinkLikeBlock($block, $path, $findings, 'text', 'url', 'button');
        }

        foreach ( $innerBlocks as $index => $innerBlock ) {
            if ( is_array($innerBlock) ) {
                $this->validateBlock($innerBlock, $path . '.innerBlocks.' . $index, $findings, $checkedBlockTypes);
            }
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param array<int, array<string, mixed>> $findings
     */
    private function validateLinkLikeBlock(array $block, string $path, array &$findings, string $textAttr, string $urlAttr, string $kind): void
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        $html = is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';
        $blockName = is_string($block['blockName'] ?? null) ? $block['blockName'] : null;

        $expectedText = $this->normalizeText(strip_tags((string) ($attrs[$textAttr] ?? '')));
        $actualText = $this->normalizeText(strip_tags($html));
        if ( '' !== $expectedText && '' !== $actualText && $expectedText !== $actualText ) {
            $this->addFinding(
                $findings,
                $kind . '_text_markup_mismatch',
                'warning',
                $path,
                $blockName,
                'Serialized markup text does not match the block attribute text WordPress will validate against.',
                array('attribute_text' => $expectedText, 'markup_text' => $actualText)
            );
        }

        $expectedUrl = (string) ($attrs[$urlAttr] ?? '');
        $actualUrl = $this->firstHref($html);
        if ( '' !== $expectedUrl && '' !== $actualUrl && $expectedUrl !== $actualUrl ) {
            $this->addFinding(
                $findings,
                $kind . '_url_markup_mismatch',
                'warning',
                $path,
                $blockName,
                'Serialized markup href does not match the block URL attribute WordPress will validate against.',
                array('attribute_url' => $expectedUrl, 'markup_url' => $actualUrl)
            );
        }

        $linkInnerHtml = $this->firstAnchorInnerHtml($html);
        if ( '' !== $linkInnerHtml && preg_match('/<(?:address|article|aside|blockquote|div|dl|fieldset|figcaption|figure|footer|form|h[1-6]|header|hr|main|nav|ol|p|pre|section|table|ul)\b/i', $linkInnerHtml) ) {
            $this->addFinding(
                $findings,
                $kind . '_block_level_link_markup',
                'warning',
                $path,
                $blockName,
                'Serialized link text contains block-level markup that WordPress static link blocks do not save as valid inline RichText.'
            );
        }
    }

    private function firstAnchorInnerHtml(string $html): string
    {
        if ( preg_match('/<a\b[^>]*>(.*?)<\/a>/is', $html, $matches) ) {
            return (string) ($matches[1] ?? '');
        }

        return '';
    }

    private function normalizeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    private function firstHref(string $html): string
    {
        if ( preg_match('/<a\b[^>]*\shref=("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $html, $matches) ) {
            return html_entity_decode($matches[2] ?? $matches[3] ?? $matches[4] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private function countBlocks(array $blocks): int
    {
        $count = 0;
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            ++$count;
            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $count += $this->countBlocks($block['innerBlocks']);
            }
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $findings
     * @param array<string, mixed> $details
     */
    private function addFinding(array &$findings, string $code, string $severity, string $path, ?string $blockName, string $summary, array $details = array()): void
    {
        $findings[] = array_filter(
            array(
                'code'       => $code,
                'severity'   => $severity,
                'category'   => 'wp_block_validity',
                'path'       => $path,
                'block_name' => $blockName,
                'summary'    => $summary,
                'details'    => $details,
            ),
            static fn (mixed $value): bool => null !== $value && array() !== $value
        );
    }
}
