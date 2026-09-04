<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Normalizes Figma text payloads into the scenegraph text contract.
 */
final class TextNormalizer
{
    private const MAX_TEXT_GLYPH_COMMAND_BLOB_BYTES = 262144;
    private const MAX_TEXT_GLYPH_COMMAND_BLOB_BYTES_PER_NODE = 262144;
    private const MAX_LOGICAL_CHARACTER_OFFSET_SAMPLES = 256;
    private const MAX_TEXT_LINE_SAMPLES = 256;

    public function __construct(
        private readonly VectorGeometryNormalizer $vectorGeometryNormalizer = new VectorGeometryNormalizer()
    ) {
    }

    /**
     * @param array<string, mixed>             $node
     * @param array<int|string, mixed>         $blobs
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, mixed>
     */
    public function normalizeText(array $node, array $blobs = array(), string $nodeId = '', array &$diagnostics = array(), array $paintStyles = array(), array $textStyles = array(), array $options = array()): array
    {
        $text = array();

        foreach ( array('characters', 'text') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                $text['characters'] = (string) $node[$key];
                break;
            }
        }

        if ( ! isset($text['characters']) && isset($node['textData']['characters']) && is_scalar($node['textData']['characters']) ) {
            $text['characters'] = (string) $node['textData']['characters'];
        }

        $style = array();
        $hasLocalStyle = is_array($node['style'] ?? null);
        $styleId = $this->readStyleGuidId($node['styleIdForText'] ?? null);
        if ( null !== $styleId && is_array($textStyles[$styleId] ?? null) ) {
            $style = $this->normalizeTextStyle($textStyles[$styleId]);
        } elseif ( null !== $styleId ) {
            $this->appendMissingTextStyleDiagnostic($diagnostics, $nodeId, $styleId, $hasLocalStyle);
        }

        if ( is_array($node['style'] ?? null) ) {
            $style = $this->normalizeTextStyle($node['style']);
        }

        $rootStyle = $this->normalizeTextStyle($node);
        foreach ( $rootStyle as $key => $value ) {
            if ( ! array_key_exists($key, $style) ) {
                $style[$key] = $value;
            }
        }
        $this->applyExplicitOriginalTextCaseOverride($node, $style, $style);
        $style = $this->removeInheritedUppercaseForMixedCaseOverrideText($node, $style, (string) ($text['characters'] ?? ''));

        if ( ! isset($style['color']) ) {
            $fillColor = $this->styleFillColor($node['styleIdForFill'] ?? null, $paintStyles);
            if ( null !== $fillColor ) {
                $style['color'] = $fillColor;
            }
        }

        if ( ! empty($style) ) {
            $text['style'] = $style;
        }

        if ( isset($text['characters']) && is_scalar($text['characters']) ) {
            $iconFallback = $this->fontAwesomeIconNameFallback((string) $text['characters'], $style);
            if ( null !== $iconFallback ) {
                $text['icon_name'] = (string) $text['characters'];
                $text['characters'] = $iconFallback;
            }
        }

        $derivedLayout = $this->normalizeDerivedTextLayout($node, $blobs, $nodeId, $diagnostics, true === ($options['render_text_glyph_paths'] ?? false));
        if ( ! empty($derivedLayout) ) {
            $text['derived_layout'] = $derivedLayout;
            $style = $this->fillMissingStyleFromDerivedFonts($style, $derivedLayout);
            if ( ! empty($style) ) {
                $text['style'] = $style;
            }
        }

        $segments = $this->normalizeStyledTextSegments($node, $paintStyles);
        if ( ! empty($segments) ) {
            $style = $this->removeTextCaseOverriddenBySegments($style, $segments);
            if ( empty($style) ) {
                unset($text['style']);
            } else {
                $text['style'] = $style;
            }
            $text['segments'] = $segments;
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $style
     * @param array<int, array<string, mixed>> $segments
     * @return array<string, mixed>
     */
    private function removeTextCaseOverriddenBySegments(array $style, array $segments): array
    {
        if ( isset($style['text_transform']) && $this->segmentsExplicitlyResetTextStyle($segments, 'text_transform', 'none') ) {
            unset($style['text_transform']);
        }

        if ( isset($style['font_variant']) && $this->segmentsExplicitlyResetTextStyle($segments, 'font_variant', 'normal') ) {
            unset($style['font_variant']);
        }

        return $style;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $style
     * @return array<string, mixed>
     */
    private function removeInheritedUppercaseForMixedCaseOverrideText(array $node, array $style, string $characters): array
    {
        if ( 'uppercase' !== strtolower((string) ($style['text_transform'] ?? '')) ) {
            return $style;
        }
        if ( true !== ($node['_figma_instance_override_applied'] ?? null) ) {
            return $style;
        }
        if ( 1 !== preg_match('/\p{Ll}/u', $characters) ) {
            return $style;
        }

        unset($style['text_transform']);
        return $style;
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     */
    private function segmentsExplicitlyResetTextStyle(array $segments, string $key, string $resetValue): bool
    {
        $hasText = false;
        foreach ( $segments as $segment ) {
            if ( ! isset($segment['characters']) || ! is_scalar($segment['characters']) || '' === (string) $segment['characters'] ) {
                continue;
            }

            $hasText = true;
            $segmentStyle = is_array($segment['style'] ?? null) ? $segment['style'] : array();
            if ( strtolower((string) ($segmentStyle[$key] ?? '')) !== $resetValue ) {
                return false;
            }
        }

        return $hasText;
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     */
    private function appendMissingTextStyleDiagnostic(array &$diagnostics, string $nodeId, string $styleId, bool $hasLocalStyle): void
    {
        foreach ( $diagnostics as $diagnostic ) {
            if ( 'figma_missing_text_style_reference' !== ($diagnostic['code'] ?? null) || ! is_array($diagnostic['context'] ?? null) ) {
                continue;
            }

            $context = $diagnostic['context'];
            if ( $nodeId === ($context['node_id'] ?? null) && $styleId === ($context['style_id'] ?? null) ) {
                return;
            }
        }

        $diagnostics[] = array(
            'severity' => $hasLocalStyle ? 'info' : 'warning',
            'code'     => 'figma_missing_text_style_reference',
            'message'  => 'Figma text node references a text style that is not present in the decoded source graph.',
            'source'   => 'TextNormalizer',
            'context'  => array(
                'node_id'              => $nodeId,
                'style_id'             => $styleId,
                'local_style_preserved' => $hasLocalStyle,
            ),
        );
    }

    /**
     * @param array<string, mixed> $style
     * @param array<string, mixed> $derivedLayout
     * @return array<string, mixed>
     */
    private function fillMissingStyleFromDerivedFonts(array $style, array $derivedLayout): array
    {
        $fonts = is_array($derivedLayout['fonts'] ?? null) ? $derivedLayout['fonts'] : array();
        if ( 1 !== count($fonts) || ! is_array($fonts[0]) ) {
            return $style;
        }

        $font = $fonts[0];
        if ( isset($font['family']) && is_scalar($font['family']) && '' !== (string) $font['family'] ) {
            $style['font_family'] = (string) $font['family'];
        }
        if ( isset($font['font_weight']) && is_numeric($font['font_weight']) ) {
            $style['font_weight'] = (int) $font['font_weight'];
        }

        return $style;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    public function normalizeSegmentHyperlink(array $node): ?array
    {
        foreach ( array('styledTextSegments', 'segments') as $key ) {
            if ( ! is_array($node[$key] ?? null) ) {
                continue;
            }

            foreach ( $node[$key] as $segment ) {
                if ( ! is_array($segment) || ! array_key_exists('hyperlink', $segment) ) {
                    continue;
                }

                $link = $this->normalizeHyperlinkValue($segment['hyperlink']);
                if ( null !== $link ) {
                    $link['source'] = 'segment';
                    $link['partial'] = true;
                    return $link;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $style
     */
    private function fontAwesomeIconNameFallback(string $characters, array $style): ?string
    {
        $fontFamily = strtolower((string) ($style['font_family'] ?? ''));
        $postscript = strtolower((string) ($style['font_postscript_name'] ?? ''));
        if ( ! str_contains($fontFamily, 'font awesome') && ! str_contains($postscript, 'fontawesome') ) {
            return null;
        }

        return match ( strtolower(trim($characters)) ) {
            'sparkle', 'sparkles' => '✦',
            'circle-check', 'check-circle' => '✓',
            'circle', 'circle-small' => '●',
            'arrow-right' => '→',
            'arrow-left' => '←',
            'arrow-up' => '↑',
            'arrow-down' => '↓',
            'chevron-right' => '›',
            'chevron-left' => '‹',
            default => null,
        };
    }

    /**
     * @param array<string, mixed>             $node
     * @param array<int|string, mixed>         $blobs
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, mixed>
     */
    private function normalizeDerivedTextLayout(array $node, array $blobs = array(), string $nodeId = '', array &$diagnostics = array(), bool $decodeGlyphCommandBlobs = false): array
    {
        $textDataSource = is_array($node['textData'] ?? null) ? $node['textData'] : array();
        $source = is_array($node['derivedTextData'] ?? null) ? $node['derivedTextData'] : $textDataSource;
        if ( empty($source) ) {
            return array();
        }

        $layout = array();
        if ( is_array($source['layoutSize'] ?? null) ) {
            $size = array();
            foreach ( array('x' => 'width', 'y' => 'height', 'width' => 'width', 'height' => 'height') as $sourceKey => $targetKey ) {
                if ( ! isset($size[$targetKey]) && isset($source['layoutSize'][$sourceKey]) && is_numeric($source['layoutSize'][$sourceKey]) ) {
                    $size[$targetKey] = (float) $source['layoutSize'][$sourceKey];
                }
            }
            if ( ! empty($size) ) {
                $layout['size'] = $size;
            }
        }

        if ( is_array($source['baselines'] ?? null) ) {
            $layout['baseline_count'] = count($source['baselines']);
            $baselines = array();
            foreach ( $source['baselines'] as $baseline ) {
                if ( ! is_array($baseline) ) {
                    continue;
                }
                $normalized = array();
                foreach ( array('width', 'lineY', 'lineHeight', 'lineAscent', 'firstCharacter', 'endCharacter') as $key ) {
                    if ( isset($baseline[$key]) && is_numeric($baseline[$key]) ) {
                        $normalized[$key] = (float) $baseline[$key];
                    }
                }
                if ( is_array($baseline['position'] ?? null) ) {
                    foreach ( array('x', 'y') as $axis ) {
                        if ( isset($baseline['position'][$axis]) && is_numeric($baseline['position'][$axis]) ) {
                            $normalized['position_' . $axis] = (float) $baseline['position'][$axis];
                        }
                    }
                }
                if ( ! empty($normalized) ) {
                    $baselines[] = $normalized;
                }
            }
            if ( ! empty($baselines) ) {
                $layout['baselines'] = $baselines;
            }
        }

        foreach ( array('truncationStartIndex' => 'truncation_start_index', 'truncatedHeight' => 'truncated_height', 'minContentHeight' => 'min_content_height', 'layoutVersion' => 'layout_version') as $sourceKey => $targetKey ) {
            $value = $source[$sourceKey] ?? $textDataSource[$sourceKey] ?? null;
            if ( is_numeric($value) ) {
                $layout[$targetKey] = (float) $value;
            }
        }

        if ( is_array($source['logicalIndexToCharacterOffsetMap'] ?? null) ) {
            $offsets = array();
            $offsetCount = 0;
            foreach ( $source['logicalIndexToCharacterOffsetMap'] as $offset ) {
                if ( is_numeric($offset) ) {
                    $offsetCount++;
                    if ( $offsetCount > self::MAX_LOGICAL_CHARACTER_OFFSET_SAMPLES ) {
                        continue;
                    }
                    $offsets[] = (float) $offset;
                }
            }
            if ( ! empty($offsets) ) {
                $layout['logical_character_offsets'] = $offsets;
                $layout['logical_character_offset_count'] = $offsetCount;
                if ( $offsetCount > self::MAX_LOGICAL_CHARACTER_OFFSET_SAMPLES ) {
                    $layout['logical_character_offsets_truncated'] = true;
                }
            }
        }

        foreach ( array('lines' => 'line_count', 'derivedLines' => 'derived_line_count') as $sourceKey => $targetKey ) {
            if ( is_array($source[$sourceKey] ?? null) ) {
                $layout[$targetKey] = count($source[$sourceKey]);
            }
        }

        $lineSource = is_array($textDataSource['lines'] ?? null) ? $textDataSource['lines'] : ( is_array($source['lines'] ?? null) ? $source['lines'] : array() );
        if ( ! empty($lineSource) ) {
            $layout['line_count'] = count($lineSource);
            $lines = $this->normalizeTextLines($lineSource, false);
            if ( ! empty($lines) ) {
                $layout['lines'] = $lines;
                if ( count($lineSource) > self::MAX_TEXT_LINE_SAMPLES ) {
                    $layout['lines_truncated'] = true;
                }
            }
        }

        $derivedLineSource = is_array($source['derivedLines'] ?? null) ? $source['derivedLines'] : ( is_array($textDataSource['derivedLines'] ?? null) ? $textDataSource['derivedLines'] : array() );
        if ( ! empty($derivedLineSource) ) {
            $layout['derived_line_count'] = count($derivedLineSource);
            $derivedLines = $this->normalizeTextLines($derivedLineSource, true);
            if ( ! empty($derivedLines) ) {
                $layout['derived_lines'] = $derivedLines;
                if ( count($derivedLineSource) > self::MAX_TEXT_LINE_SAMPLES ) {
                    $layout['derived_lines_truncated'] = true;
                }
            }
        }

        $decorations = $this->normalizeTextDecorations($source['decorations'] ?? $textDataSource['decorations'] ?? null);
        if ( ! empty($decorations) ) {
            $layout['decorations'] = $decorations;
            $layout['decoration_count'] = count($decorations);
        }

        $hyperlinkBoxes = $this->normalizeTextHyperlinkBoxes($source['hyperlinkBoxes'] ?? $textDataSource['hyperlinkBoxes'] ?? null);
        if ( ! empty($hyperlinkBoxes) ) {
            $layout['hyperlink_boxes'] = $hyperlinkBoxes;
            $layout['hyperlink_box_count'] = count($hyperlinkBoxes);
        }

        if ( is_array($source['glyphs'] ?? null) ) {
            $layout['glyph_count'] = count($source['glyphs']);
            if ( ! $decodeGlyphCommandBlobs ) {
                return $this->appendDerivedTextFonts($layout, $source);
            }

            $glyphPaths = array();
            $characters = isset($node['textData']['characters']) && is_scalar($node['textData']['characters']) ? (string) $node['textData']['characters'] : ( isset($node['characters']) && is_scalar($node['characters']) ? (string) $node['characters'] : '' );
            $characterList = '' !== $characters ? preg_split('//u', $characters, -1, PREG_SPLIT_NO_EMPTY) : array();
            if ( ! is_array($characterList) ) {
                $characterList = array();
            }
            $decodedGlyphCommandBlobBytes = 0;
            foreach ( $source['glyphs'] as $index => $glyph ) {
                if ( ! is_array($glyph) ) {
                    continue;
                }

                $glyphPath = array();
                foreach ( array('x', 'y', 'advance', 'fontSize', 'fontIndex', 'firstCharacter', 'endCharacter') as $key ) {
                    if ( isset($glyph[$key]) && is_numeric($glyph[$key]) ) {
                        $glyphPath[$key] = (float) $glyph[$key];
                    }
                }
                if ( isset($glyph['firstCharacter']) && is_numeric($glyph['firstCharacter']) && isset($characterList[(int) $glyph['firstCharacter']]) ) {
                    $glyphPath['character'] = $characterList[(int) $glyph['firstCharacter']];
                }
                if ( is_array($glyph['position'] ?? null) ) {
                    foreach ( array('x', 'y') as $axis ) {
                        if ( isset($glyph['position'][$axis]) && is_numeric($glyph['position'][$axis]) ) {
                            $glyphPath['position_' . $axis] = (float) $glyph['position'][$axis];
                        }
                    }
                }

                if ( $decodeGlyphCommandBlobs && isset($glyph['commandsBlob']) ) {
                    $bytes = $this->vectorGeometryNormalizer->readCommandBlobBytes($glyph['commandsBlob'], $blobs);
                    if ( null !== $bytes ) {
                        $byteLength = strlen($bytes);
                        if ( $byteLength > self::MAX_TEXT_GLYPH_COMMAND_BLOB_BYTES || $decodedGlyphCommandBlobBytes + $byteLength > self::MAX_TEXT_GLYPH_COMMAND_BLOB_BYTES_PER_NODE ) {
                            $diagnostics[] = array(
                                'severity' => 'warning',
                                'code'     => 'unsupported_text_glyph_command_blob',
                                'message'  => 'Oversized Figma text glyph command blob was omitted from derived glyph metadata.',
                                'context'  => array(
                                    'node_id'     => $nodeId,
                                    'glyph_index' => $index,
                                    'byte_length' => $byteLength,
                                    'reason'      => $byteLength > self::MAX_TEXT_GLYPH_COMMAND_BLOB_BYTES ? 'byte_limit_exceeded' : 'node_byte_budget_exceeded',
                                ),
                            );
                            continue;
                        }
                        $decodedGlyphCommandBlobBytes += $byteLength;

                        $decoded = $this->vectorGeometryNormalizer->classifyVectorCommandBlob($bytes);
                        if ( 'path' === $decoded['status'] ) {
                            $glyphPath['data'] = $decoded['path'];
                        } elseif ( 'unsupported' === $decoded['status'] ) {
                            $diagnostics[] = array(
                                'severity' => 'warning',
                                'code'     => 'unsupported_text_glyph_command_blob',
                                'message'  => 'Unsupported Figma text glyph command blob was omitted from derived glyph metadata.',
                                'context'  => array('node_id' => $nodeId, 'glyph_index' => $index),
                            );
                        }
                        // 'empty' blobs (e.g. whitespace glyphs encoded as a single
                        // 0x00 byte) carry no drawable outline and are not warnings.
                    }
                }

                if ( empty($glyphPath) ) {
                    continue;
                }
                $glyphPaths[] = $glyphPath;
            }
            if ( ! empty($glyphPaths) ) {
                $layout['glyph_paths'] = $glyphPaths;
            }
        }
        return $this->appendDerivedTextFonts($layout, $source);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeTextDecorations(mixed $decorations): array
    {
        if ( ! is_array($decorations) ) {
            return array();
        }

        $normalized = array();
        foreach ( $decorations as $decoration ) {
            if ( ! is_array($decoration) ) {
                continue;
            }

            $entry = array();
            if ( isset($decoration['styleID']) && is_numeric($decoration['styleID']) ) {
                $entry['style_id'] = (int) $decoration['styleID'];
            }
            $rects = $this->normalizeTextRects($decoration['rects'] ?? null);
            if ( ! empty($rects) ) {
                $entry['rects'] = $rects;
            }
            if ( ! empty($entry) ) {
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeTextHyperlinkBoxes(mixed $boxes): array
    {
        if ( ! is_array($boxes) ) {
            return array();
        }

        $normalized = array();
        foreach ( $boxes as $box ) {
            if ( ! is_array($box) ) {
                continue;
            }

            $entry = array();
            $bounds = $this->normalizeTextRect($box['bounds'] ?? null);
            if ( null !== $bounds ) {
                $entry['bounds'] = $bounds;
            }
            if ( isset($box['url']) && is_scalar($box['url']) && '' !== (string) $box['url'] ) {
                $entry['url'] = (string) $box['url'];
            }
            if ( isset($box['hyperlinkID']) && is_numeric($box['hyperlinkID']) ) {
                $entry['hyperlink_id'] = (int) $box['hyperlinkID'];
            }
            if ( isset($box['openInNewTab']) && is_bool($box['openInNewTab']) ) {
                $entry['open_in_new_tab'] = $box['openInNewTab'];
            }
            if ( ! empty($entry) ) {
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }

    /**
     * @return array<int, array<string, float>>
     */
    private function normalizeTextRects(mixed $rects): array
    {
        if ( ! is_array($rects) ) {
            return array();
        }

        $normalized = array();
        foreach ( $rects as $rect ) {
            $normalizedRect = $this->normalizeTextRect($rect);
            if ( null !== $normalizedRect ) {
                $normalized[] = $normalizedRect;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, float>|null
     */
    private function normalizeTextRect(mixed $rect): ?array
    {
        if ( ! is_array($rect) ) {
            return null;
        }

        $normalized = array();
        foreach ( array('x' => 'x', 'y' => 'y', 'w' => 'width', 'width' => 'width', 'h' => 'height', 'height' => 'height') as $sourceKey => $targetKey ) {
            if ( ! isset($normalized[$targetKey]) && isset($rect[$sourceKey]) && is_numeric($rect[$sourceKey]) ) {
                $normalized[$targetKey] = (float) $rect[$sourceKey];
            }
        }

        return empty($normalized) ? null : $normalized;
    }

    /**
     * @param array<int, mixed> $lines
     * @return array<int, array<string, mixed>>
     */
    private function normalizeTextLines(array $lines, bool $derived): array
    {
        $normalizedLines = array();
        foreach ( $lines as $line ) {
            if ( count($normalizedLines) >= self::MAX_TEXT_LINE_SAMPLES ) {
                break;
            }
            if ( ! is_array($line) ) {
                continue;
            }

            $normalized = array();
            foreach ( array('lineType' => 'line_type', 'sourceDirectionality' => 'source_directionality', 'directionality' => 'directionality', 'directionalityIntent' => 'directionality_intent') as $sourceKey => $targetKey ) {
                if ( isset($line[$sourceKey]) && is_scalar($line[$sourceKey]) && '' !== (string) $line[$sourceKey] ) {
                    $normalized[$targetKey] = (string) $line[$sourceKey];
                }
            }

            if ( ! $derived ) {
                foreach ( array('styleId' => 'style_id', 'indentationLevel' => 'indentation_level', 'downgradeStyleId' => 'downgrade_style_id', 'consistencyStyleId' => 'consistency_style_id', 'listStartOffset' => 'list_start_offset') as $sourceKey => $targetKey ) {
                    if ( isset($line[$sourceKey]) && is_numeric($line[$sourceKey]) ) {
                        $normalized[$targetKey] = (int) $line[$sourceKey];
                    }
                }
                if ( isset($line['isFirstLineOfList']) && is_bool($line['isFirstLineOfList']) ) {
                    $normalized['is_first_line_of_list'] = $line['isFirstLineOfList'];
                }
            }

            if ( ! empty($normalized) ) {
                $normalizedLines[] = $normalized;
            }
        }

        return $normalizedLines;
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function appendDerivedTextFonts(array $layout, array $source): array
    {
        if ( is_array($source['fontMetaData'] ?? null) ) {
            $fontMetaData = array_is_list($source['fontMetaData']) ? $source['fontMetaData'] : array($source['fontMetaData']);
            $fonts = array();
            foreach ( $fontMetaData as $font ) {
                if ( ! is_array($font) ) {
                    continue;
                }
                $fonts[] = array(
                    'family' => (string) ($font['key']['family'] ?? ''),
                    'style' => (string) ($font['key']['style'] ?? ''),
                    'font_weight' => isset($font['fontWeight']) && is_numeric($font['fontWeight']) ? (int) $font['fontWeight'] : null,
                    'font_line_height' => isset($font['fontLineHeight']) && is_numeric($font['fontLineHeight']) ? (float) $font['fontLineHeight'] : null,
                    'font_digest' => isset($font['fontDigest']) && is_scalar($font['fontDigest']) ? (string) $font['fontDigest'] : null,
                );
            }
            if ( ! empty($fonts) ) {
                $layout['fonts'] = $fonts;
            }
        }

        return $layout;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function normalizeTextStyle(array $source): array
    {
        $style = array();

        foreach ( array(
            'fontFamily' => 'font_family',
            'fontPostScriptName' => 'font_postscript_name',
            'fontWeight' => 'font_weight',
            'textAlignHorizontal' => 'text_align_horizontal',
            'textAlignVertical' => 'text_align_vertical',
            'textDecoration' => 'text_decoration',
        ) as $sourceKey => $targetKey ) {
            if ( isset($source[$sourceKey]) && is_scalar($source[$sourceKey]) && '' !== (string) $source[$sourceKey] ) {
                $style[$targetKey] = (string) $source[$sourceKey];
            }
        }

        if ( isset($source['fontName']) && is_array($source['fontName']) ) {
            if ( isset($source['fontName']['family']) && is_scalar($source['fontName']['family']) ) {
                $style['font_family'] = (string) $source['fontName']['family'];
            }
            if ( isset($source['fontName']['postscript']) && is_scalar($source['fontName']['postscript']) ) {
                $style['font_postscript_name'] = (string) $source['fontName']['postscript'];
            }
            if ( ! isset($style['font_weight']) && isset($source['fontName']['style']) && is_scalar($source['fontName']['style']) ) {
                $fontWeight = $this->fontWeightFromStyle((string) $source['fontName']['style']);
                if ( null !== $fontWeight ) {
                    $style['font_weight'] = $fontWeight;
                }
            }
        }

        if ( isset($source['textTracking']) && is_numeric($source['textTracking']) && ! isset($style['letter_spacing']) ) {
            $style['letter_spacing'] = (float) $source['textTracking'];
        }

        foreach ( array('fontSize' => 'font_size', 'lineHeightPx' => 'line_height_px', 'lineHeightPercent' => 'line_height_percent') as $sourceKey => $targetKey ) {
            if ( isset($source[$sourceKey]) && is_numeric($source[$sourceKey]) ) {
                $style[$targetKey] = (float) $source[$sourceKey];
            }
        }

        if ( isset($source['letterSpacing']) && is_numeric($source['letterSpacing']) ) {
            $style['letter_spacing'] = (float) $source['letterSpacing'];
        }

        if ( isset($source['lineHeight']) && is_array($source['lineHeight']) && isset($source['lineHeight']['value']) && is_numeric($source['lineHeight']['value']) ) {
            $lineHeightUnits = strtoupper((string) ($source['lineHeight']['units'] ?? ''));
            if ( 'PIXELS' === $lineHeightUnits ) {
                $style['line_height_px'] = (float) $source['lineHeight']['value'];
            } elseif ( 'RAW' === $lineHeightUnits ) {
                $style['line_height_raw'] = (float) $source['lineHeight']['value'];
            } elseif ( str_contains($lineHeightUnits, 'PERCENT') ) {
                $style['line_height_percent'] = (float) $source['lineHeight']['value'];
            }
        }

        if ( isset($source['letterSpacing']) && is_array($source['letterSpacing']) && isset($source['letterSpacing']['value']) && is_numeric($source['letterSpacing']['value']) ) {
            $letterSpacingUnits = strtoupper((string) ($source['letterSpacing']['units'] ?? 'PIXELS'));
            if ( 'PIXELS' === $letterSpacingUnits ) {
                $style['letter_spacing'] = (float) $source['letterSpacing']['value'];
                unset($style['letter_spacing_em']);
            } elseif ( 'RAW' === $letterSpacingUnits ) {
                $style['letter_spacing_em'] = (float) $source['letterSpacing']['value'];
                unset($style['letter_spacing']);
            } elseif ( str_contains($letterSpacingUnits, 'PERCENT') ) {
                $style['letter_spacing_em'] = (float) $source['letterSpacing']['value'] / 100;
                unset($style['letter_spacing']);
            }
        }

        if ( is_array($source['fontVariations'] ?? null) ) {
            $fontVariations = array();
            foreach ( $source['fontVariations'] as $variation ) {
                if ( ! is_array($variation) || ! isset($variation['value']) || ! is_numeric($variation['value']) ) {
                    continue;
                }
                $axis = $this->fontVariationAxis($variation);
                if ( null !== $axis ) {
                    $fontVariations[$axis] = (float) $variation['value'];
                }
            }
            if ( ! empty($fontVariations) ) {
                $style['font_variation_settings'] = $fontVariations;
            }
        }

        $fontFeatures = $this->normalizeFontFeatures($source);
        if ( ! empty($fontFeatures) ) {
            $style['font_feature_settings'] = $fontFeatures;
        }

        foreach ( array('leadingTrim' => 'leading_trim', 'textTruncation' => 'text_truncation', 'textWrapStyle' => 'text_wrap_style') as $sourceKey => $targetKey ) {
            if ( isset($source[$sourceKey]) && is_scalar($source[$sourceKey]) && '' !== (string) $source[$sourceKey] ) {
                $style[$targetKey] = strtolower((string) $source[$sourceKey]);
            }
        }

        if ( isset($source['maxLines']) && is_numeric($source['maxLines']) ) {
            $style['max_lines'] = (int) $source['maxLines'];
        }

        foreach ( array('textBidiVersion' => 'text_bidi_version') as $sourceKey => $targetKey ) {
            if ( isset($source[$sourceKey]) && is_numeric($source[$sourceKey]) ) {
                $style[$targetKey] = (int) $source[$sourceKey];
            }
        }

        foreach ( array('hangingPunctuation' => 'hanging_punctuation', 'hangingList' => 'hanging_list', 'hasHadRTLText' => 'has_had_rtl_text') as $sourceKey => $targetKey ) {
            if ( isset($source[$sourceKey]) && is_bool($source[$sourceKey]) ) {
                $style[$targetKey] = $source[$sourceKey];
            }
        }

        if ( isset($source['listSpacing']) && is_numeric($source['listSpacing']) ) {
            $style['list_spacing'] = (float) $source['listSpacing'];
        }

        foreach ( array('color', 'textColor') as $sourceKey ) {
            $color = $this->normalizeColor($source[$sourceKey] ?? null);
            if ( null !== $color ) {
                $style['color'] = $color;
                break;
            }
        }

        foreach ( array('underline' => 'underline', 'strikethrough' => 'strikethrough') as $sourceKey => $targetKey ) {
            if ( isset($source[$sourceKey]) && is_bool($source[$sourceKey]) ) {
                $style[$targetKey] = $source[$sourceKey];
            }
        }

        // Figma `textCase` enum -> CSS text-transform / font-variant. ORIGINAL and
        // absent values keep the native font casing. SMALL_CAPS_FORCED uppercases
        // before rendering small caps, matching Figma's render behaviour.
        if ( isset($source['textCase']) && is_scalar($source['textCase']) && '' !== (string) $source['textCase'] ) {
            $textCase = strtoupper((string) $source['textCase']);
            $textTransform = match ( $textCase ) {
                'UPPER'             => 'uppercase',
                'LOWER'             => 'lowercase',
                'TITLE'             => 'capitalize',
                'SMALL_CAPS_FORCED' => 'uppercase',
                default             => null,
            };
            if ( null !== $textTransform ) {
                $style['text_transform'] = $textTransform;
            }
            if ( 'SMALL_CAPS' === $textCase || 'SMALL_CAPS_FORCED' === $textCase ) {
                $style['font_variant'] = 'small-caps';
            }
        }

        // Figma `paragraphSpacing` (px between paragraphs). The emitter renders a
        // multi-paragraph text node as a single white-space:pre-line element, so
        // this is captured for downstream consumers and a diagnostic rather than
        // emitted as CSS that would not apply.
        if ( isset($source['paragraphSpacing']) && is_numeric($source['paragraphSpacing']) ) {
            $style['paragraph_spacing'] = (float) $source['paragraphSpacing'];
        }

        // Figma `paragraphIndent` (px first-line indent of each paragraph). Decoded
        // by the Kiwi parser but previously never read here, so it was dropped for
        // .fig input. Maps directly onto CSS `text-indent` in the emitter.
        if ( isset($source['paragraphIndent']) && is_numeric($source['paragraphIndent']) ) {
            $style['paragraph_indent'] = (float) $source['paragraphIndent'];
        }

        if ( isset($source['listSpacing']) && is_numeric($source['listSpacing']) ) {
            $style['list_spacing'] = (float) $source['listSpacing'];
        }

        return $style;
    }

    /**
     * @param array<string, mixed> $variation
     */
    private function fontVariationAxis(array $variation): ?string
    {
        if ( isset($variation['axisName']) && is_scalar($variation['axisName']) && 4 === strlen((string) $variation['axisName']) ) {
            return (string) $variation['axisName'];
        }
        if ( isset($variation['axisTag']) && is_numeric($variation['axisTag']) ) {
            $tag = (int) $variation['axisTag'];
            $bytes = '';
            for ( $shift = 24; $shift >= 0; $shift -= 8 ) {
                $byte = ($tag >> $shift) & 0xff;
                if ( $byte < 32 || $byte > 126 ) {
                    return null;
                }
                $bytes .= chr($byte);
            }
            return $bytes;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, int>
     */
    private function normalizeFontFeatures(array $source): array
    {
        $features = array();
        foreach ( array(
            'fontVariantCommonLigatures' => 'liga',
            'fontVariantContextualLigatures' => 'calt',
            'fontVariantDiscretionaryLigatures' => 'dlig',
            'fontVariantHistoricalLigatures' => 'hlig',
            'fontVariantOrdinal' => 'ordn',
            'fontVariantSlashedZero' => 'zero',
        ) as $sourceKey => $feature ) {
            if ( is_bool($source[$sourceKey] ?? null) ) {
                $features[$feature] = true === $source[$sourceKey] ? 1 : 0;
            }
        }

        foreach ( array('toggledOnOTFeatures' => 1, 'toggledOffOTFeatures' => 0) as $sourceKey => $enabled ) {
            if ( ! is_array($source[$sourceKey] ?? null) ) {
                continue;
            }
            foreach ( $source[$sourceKey] as $feature ) {
                if ( is_scalar($feature) ) {
                    $tag = strtolower((string) $feature);
                    if ( 4 === strlen($tag) ) {
                        $features[$tag] = $enabled;
                    }
                }
            }
        }

        return $features;
    }

    private function fontWeightFromStyle(string $style): ?int
    {
        $style = strtolower(str_replace(array('-', '_'), ' ', $style));
        if ( str_contains($style, 'thin') ) {
            return 100;
        }
        if ( str_contains($style, 'extra light') || str_contains($style, 'ultra light') ) {
            return 200;
        }
        if ( str_contains($style, 'light') ) {
            return 300;
        }
        if ( str_contains($style, 'regular') || str_contains($style, 'normal') ) {
            return 400;
        }
        if ( str_contains($style, 'medium') ) {
            return 500;
        }
        if ( str_contains($style, 'semi bold') || str_contains($style, 'semibold') || str_contains($style, 'demi bold') ) {
            return 600;
        }
        if ( str_contains($style, 'extra bold') || str_contains($style, 'ultra bold') ) {
            return 800;
        }
        if ( str_contains($style, 'bold') ) {
            return 700;
        }
        if ( str_contains($style, 'black') || str_contains($style, 'heavy') ) {
            return 900;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    private function normalizeStyledTextSegments(array $node, array $paintStyles = array()): array
    {
        $segments = array();
        $rawSegments = $this->rawStyledTextSegments($node);

        if ( ! is_array($rawSegments) ) {
            // Fall back to character-level override encoding when no segment list is present.
            return $this->normalizeCharacterStyleOverrideSegments($node, $paintStyles);
        }

        $sourceCharacters = $this->sourceTextCharacters($node);
        foreach ( $rawSegments as $segment ) {
            if ( ! is_array($segment) ) {
                continue;
            }

            $normalized = array();
            foreach ( array('characters', 'text') as $key ) {
                if ( isset($segment[$key]) && is_scalar($segment[$key]) ) {
                    $normalized['characters'] = (string) $segment[$key];
                    break;
                }
            }
            foreach ( array('start', 'end') as $key ) {
                if ( isset($segment[$key]) && is_numeric($segment[$key]) ) {
                    $normalized[$key] = (int) $segment[$key];
                }
            }
            if ( ! isset($normalized['characters']) && isset($normalized['start'], $normalized['end']) ) {
                $characters = $this->sliceTextCharacters($sourceCharacters, $normalized['start'], $normalized['end']);
                if ( null !== $characters ) {
                    $normalized['characters'] = $characters;
                }
            }

            $style = is_array($segment['style'] ?? null) ? $this->normalizeTextStyle($segment['style']) : $this->normalizeTextStyle($segment);
            if ( ! isset($style['color']) ) {
                $segmentFills = $this->firstFillList(array($segment['fills'] ?? null, $segment['fillPaints'] ?? null));
                $fillColor = $this->solidFillColor($segmentFills);
                if ( null !== $fillColor ) {
                    $style['color'] = $fillColor;
                }
            }
            if ( ! isset($style['color']) ) {
                $styleFillColor = $this->styleFillColor($segment['styleIdForFill'] ?? null, $paintStyles);
                if ( null !== $styleFillColor ) {
                    $style['color'] = $styleFillColor;
                }
            }
            if ( ! empty($style) ) {
                $normalized['style'] = $style;
            }

            if ( ! empty($normalized) ) {
                $segments[] = $normalized;
            }
        }

        return $segments;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, mixed>|null
     */
    private function rawStyledTextSegments(array $node): ?array
    {
        foreach ( array($node, $node['textData'] ?? null, $node['derivedTextData'] ?? null) as $source ) {
            if ( ! is_array($source) ) {
                continue;
            }

            foreach ( array('styledTextSegments', 'segments') as $key ) {
                if ( is_array($source[$key] ?? null) ) {
                    return $source[$key];
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function sourceTextCharacters(array $node): string
    {
        foreach ( array(
            $node['characters'] ?? null,
            $node['text'] ?? null,
            $node['textData']['characters'] ?? null,
            $node['derivedTextData']['characters'] ?? null,
        ) as $value ) {
            if ( is_scalar($value) ) {
                return (string) $value;
            }
        }

        return '';
    }

    private function sliceTextCharacters(string $characters, int $start, int $end): ?string
    {
        if ( '' === $characters || $end <= $start || $start < 0 ) {
            return null;
        }

        $chars = preg_split('//u', $characters, -1, PREG_SPLIT_NO_EMPTY);
        if ( ! is_array($chars) || $start >= count($chars) ) {
            return null;
        }

        return implode('', array_slice($chars, $start, $end - $start));
    }

    /**
     * Converts Figma character-level style override encoding into styled segments.
     *
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCharacterStyleOverrideSegments(array $node, array $paintStyles = array()): array
    {
        $textData = is_array($node['textData'] ?? null) ? $node['textData'] : array();
        $derivedTextData = is_array($node['derivedTextData'] ?? null) ? $node['derivedTextData'] : array();

        $overrides = is_array($node['characterStyleOverrides'] ?? null) ? array_values($node['characterStyleOverrides']) : array();
        if ( empty($overrides) && is_array($textData['characterStyleIDs'] ?? null) ) {
            $overrides = array_values($textData['characterStyleIDs']);
        }
        if ( empty($overrides) && is_array($derivedTextData['characterStyleIDs'] ?? null) ) {
            $overrides = array_values($derivedTextData['characterStyleIDs']);
        }

        $overrideTable = is_array($node['styleOverrideTable'] ?? null) ? $node['styleOverrideTable'] : array();
        if ( empty($overrideTable) && is_array($textData['styleOverrideTable'] ?? null) ) {
            $overrideTable = $this->indexKiwiStyleOverrideTable($textData['styleOverrideTable']);
        }
        if ( empty($overrideTable) && is_array($derivedTextData['styleOverrideTable'] ?? null) ) {
            $overrideTable = $this->indexKiwiStyleOverrideTable($derivedTextData['styleOverrideTable']);
        }

        if ( empty($overrides) || empty($overrideTable) ) {
            return array();
        }

        $hasNonZero = false;
        foreach ( $overrides as $id ) {
            if ( 0 !== (int) $id ) {
                $hasNonZero = true;
                break;
            }
        }
        if ( ! $hasNonZero ) {
            return array();
        }

        $characters = '';
        foreach ( array('characters', 'text') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                $characters = (string) $node[$key];
                break;
            }
        }
        if ( '' === $characters && isset($textData['characters']) && is_scalar($textData['characters']) ) {
            $characters = (string) $textData['characters'];
        }
        if ( '' === $characters && isset($derivedTextData['characters']) && is_scalar($derivedTextData['characters']) ) {
            $characters = (string) $derivedTextData['characters'];
        }
        if ( '' === $characters ) {
            return array();
        }

        $baseStyleSource = is_array($node['style'] ?? null) ? $node['style'] : array();
        $baseStyle = $this->normalizeTextStyle($baseStyleSource);
        $rootStyle = $this->normalizeTextStyle($node);
        foreach ( $rootStyle as $key => $value ) {
            if ( ! array_key_exists($key, $baseStyle) ) {
                $baseStyle[$key] = $value;
            }
        }
        if ( ! isset($baseStyle['color']) ) {
            $baseFills = $this->firstFillList(array($baseStyleSource['fills'] ?? null, $node['fills'] ?? null, $node['fillPaints'] ?? null));
            $fillColor = $this->solidFillColor($baseFills);
            if ( null !== $fillColor ) {
                $baseStyle['color'] = $fillColor;
            }
        }
        if ( ! isset($baseStyle['color']) ) {
            $styleFillColor = $this->styleFillColor($node['styleIdForFill'] ?? null, $paintStyles);
            if ( null !== $styleFillColor ) {
                $baseStyle['color'] = $styleFillColor;
            }
        }

        $chars = preg_split('//u', $characters, -1, PREG_SPLIT_NO_EMPTY);
        if ( ! is_array($chars) ) {
            return array();
        }
        $charCount = count($chars);

        $runs = array();
        $runChars = '';
        $runId = null;

        for ( $i = 0; $i < $charCount; $i++ ) {
            $id = isset($overrides[$i]) ? (int) $overrides[$i] : 0;
            if ( null !== $runId && $id !== $runId ) {
                $runs[] = array('characters' => $runChars, 'override_id' => $runId);
                $runChars = '';
            }
            $runChars .= $chars[$i];
            $runId = $id;
        }
        if ( '' !== $runChars && null !== $runId ) {
            $runs[] = array('characters' => $runChars, 'override_id' => $runId);
        }

        if ( empty($runs) ) {
            return array();
        }

        $segments = array();
        foreach ( $runs as $run ) {
            $overrideId = (int) $run['override_id'];
            $segment = array('characters' => $run['characters']);

            if ( 0 !== $overrideId ) {
                $rawOverride = is_array($overrideTable[(string) $overrideId] ?? null)
                    ? $overrideTable[(string) $overrideId]
                    : array();

                if ( ! empty($rawOverride) ) {
                    $overrideStyle = $this->normalizeTextStyle($rawOverride);

                    if ( ! isset($overrideStyle['color']) ) {
                        $overrideFills = $this->firstFillList(array($rawOverride['fills'] ?? null, $rawOverride['fillPaints'] ?? null));
                        $fillColor = $this->solidFillColor($overrideFills);
                        if ( null !== $fillColor ) {
                            $overrideStyle['color'] = $fillColor;
                        }
                    }
                    if ( ! isset($overrideStyle['color']) ) {
                        $styleFillColor = $this->styleFillColor($rawOverride['styleIdForFill'] ?? null, $paintStyles);
                        if ( null !== $styleFillColor ) {
                            $overrideStyle['color'] = $styleFillColor;
                        }
                    }

                    $this->applyExplicitOriginalTextCaseOverride($rawOverride, $baseStyle, $overrideStyle);

                    $delta = array();
                    foreach ( $overrideStyle as $key => $value ) {
                        if ( ! array_key_exists($key, $baseStyle) || $baseStyle[$key] !== $value ) {
                            $delta[$key] = $value;
                        }
                    }

                    if ( ! empty($delta) ) {
                        $segment['style'] = $delta;
                    }
                }
            }

            $segments[] = $segment;
        }

        return $segments;
    }

    /**
     * @param array<string, mixed> $rawOverride
     * @param array<string, mixed> $baseStyle
     * @param array<string, mixed> $overrideStyle
     */
    private function applyExplicitOriginalTextCaseOverride(array $rawOverride, array $baseStyle, array &$overrideStyle): void
    {
        if ( ! $this->hasExplicitOriginalTextCase($rawOverride) ) {
            return;
        }

        if ( isset($baseStyle['text_transform']) ) {
            $overrideStyle['text_transform'] = 'none';
        }
        if ( isset($baseStyle['font_variant']) ) {
            $overrideStyle['font_variant'] = 'normal';
        }
    }

    /**
     * @param array<string, mixed> $source
     */
    private function hasExplicitOriginalTextCase(array $source): bool
    {
        if ( isset($source['textCase']) && is_scalar($source['textCase']) && 'ORIGINAL' === strtoupper((string) $source['textCase']) ) {
            return true;
        }

        return is_array($source['style'] ?? null) && $this->hasExplicitOriginalTextCase($source['style']);
    }

    /**
     * @param array<int|string, mixed> $table
     * @return array<string, array<string, mixed>>
     */
    private function indexKiwiStyleOverrideTable(array $table): array
    {
        $indexed = array();
        foreach ( $table as $entry ) {
            if ( ! is_array($entry) ) {
                continue;
            }
            if ( ! isset($entry['styleID']) || ! is_numeric($entry['styleID']) ) {
                continue;
            }
            $indexed[(string) (int) $entry['styleID']] = $entry;
        }

        return $indexed;
    }

    /**
     * @param array<int, mixed> $candidates
     * @return array<int, mixed>
     */
    private function firstFillList(array $candidates): array
    {
        foreach ( $candidates as $candidate ) {
            if ( is_array($candidate) && ! empty($candidate) ) {
                return $candidate;
            }
        }

        return array();
    }

    /**
     * @param array<int, mixed> $fills
     * @return array{r: float, g: float, b: float, a?: float}|null
     */
    private function solidFillColor(array $fills): ?array
    {
        foreach ( $fills as $fill ) {
            if ( ! is_array($fill) ) {
                continue;
            }
            $type = strtoupper((string) ($fill['type'] ?? 'SOLID'));
            if ( 'SOLID' !== $type ) {
                continue;
            }
            $color = $this->normalizeColor($fill['color'] ?? null);
            if ( null === $color ) {
                continue;
            }
            $opacity = isset($fill['opacity']) && is_numeric($fill['opacity']) ? (float) $fill['opacity'] : 1.0;
            if ( $opacity < 1.0 ) {
                $color['a'] = $opacity * ($color['a'] ?? 1.0);
            }

            return $color;
        }

        return null;
    }

    /**
     * @param array<string, array<string, array<int, array<string, mixed>>>> $paintStyles
     * @return array{r: float, g: float, b: float, a?: float}|null
     */
    private function styleFillColor(mixed $styleIdForFill, array $paintStyles): ?array
    {
        $styleId = $this->readStyleGuidId($styleIdForFill);
        if ( null === $styleId || empty($paintStyles[$styleId]['fills']) ) {
            return null;
        }

        return $this->solidFillColor($paintStyles[$styleId]['fills']);
    }

    private function readStyleGuidId(mixed $style): ?string
    {
        if ( is_array($style) && isset($style['guid']) ) {
            return $this->readGuidId($style['guid']);
        }

        return $this->readGuidId($style);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeHyperlinkValue(mixed $hyperlink): ?array
    {
        if ( is_string($hyperlink) && '' !== trim($hyperlink) ) {
            return array('type' => 'url', 'url' => trim($hyperlink));
        }

        if ( ! is_array($hyperlink) ) {
            return null;
        }

        $type = strtoupper((string) ($hyperlink['type'] ?? ''));
        $url = $this->readString($hyperlink, array('url', 'href'));
        $nodeId = $this->readString($hyperlink, array('nodeID', 'nodeId', 'node_id'))
            ?? $this->readGuidId($hyperlink['nodeID'] ?? ($hyperlink['nodeId'] ?? ($hyperlink['guid'] ?? null)));

        if ( 'URL' === $type && null !== $url ) {
            return array('type' => 'url', 'url' => $url);
        }
        if ( 'NODE' === $type && null !== $nodeId ) {
            return array('type' => 'node', 'target_node_id' => $nodeId);
        }
        if ( null !== $url ) {
            return array('type' => 'url', 'url' => $url);
        }
        if ( null !== $nodeId ) {
            return array('type' => 'node', 'target_node_id' => $nodeId);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string> $keys
     */
    private function readString(array $node, array $keys): ?string
    {
        foreach ( $keys as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
                return (string) $node[$key];
            }
        }

        return null;
    }

    private function readGuidId(mixed $guid): ?string
    {
        if ( is_array($guid) && isset($guid['sessionID'], $guid['localID']) ) {
            return (string) $guid['sessionID'] . ':' . (string) $guid['localID'];
        }

        if ( is_scalar($guid) && '' !== (string) $guid ) {
            return (string) $guid;
        }

        return null;
    }

    /**
     * @return array<string, float>|null
     */
    private function normalizeColor(mixed $value): ?array
    {
        if ( ! is_array($value) ) {
            return null;
        }

        $red = $this->normalizeColorChannel($value['r'] ?? $value['red'] ?? null);
        $green = $this->normalizeColorChannel($value['g'] ?? $value['green'] ?? null);
        $blue = $this->normalizeColorChannel($value['b'] ?? $value['blue'] ?? null);
        if ( null === $red || null === $green || null === $blue ) {
            return null;
        }

        $color = array('r' => $red, 'g' => $green, 'b' => $blue);
        if ( isset($value['a']) && is_numeric($value['a']) ) {
            $color['a'] = (float) $value['a'];
        }

        return $color;
    }

    private function normalizeColorChannel(mixed $value): ?float
    {
        if ( ! is_numeric($value) ) {
            return null;
        }

        $channel = (float) $value;
        if ( $channel > 1 ) {
            $channel /= 255;
        }

        return max(0, min(1, $channel));
    }
}
