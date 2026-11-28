<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\CorpusDiagnostics;

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionFindingContract;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CoverPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CoverStyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter;

/**
 * Pure, read-only detectors that turn a transformer result envelope into a flat
 * list of cluster-ready findings plus per-document metrics.
 *
 * Every detector keys off structure and syntax only — never fixture names — so
 * the same signals surface across the entire website-fixture corpus. None of
 * these methods mutate the transformer or its output; they exclusively read the
 * canonical result array produced by HtmlTransformer::transform()->toArray()
 * (plus, for layout-direction faithfulness, the immutable source HTML string).
 *
 * Each finding carries a `severity` (high | medium | info) so the worklist can
 * rank real defects above bulk-but-acceptable behavior, rather than ranking by
 * raw occurrence count alone.
 */
final class CorpusDetectors
{
    /**
     * WordPress preset custom properties (var(--wp--...)) are materialized by the
     * theme/global-styles layer and are not part of any gap, so they are tracked
     * for visibility but excluded from the actionable worklist.
     */
    private const PRESET_VAR_PREFIX = '--wp--';

    public const SEVERITY_HIGH   = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_INFO   = 'info';

    /**
     * Severity ordering used by the runner to rank clusters by importance before
     * raw count. Higher wins.
     */
    private const SEVERITY_RANK = array(
        self::SEVERITY_HIGH   => 3,
        self::SEVERITY_MEDIUM => 2,
        self::SEVERITY_INFO   => 1,
    );

    /**
     * Repair lanes that represent genuine, editor-visible defects: missing
     * artwork, content the block editor would mark invalid, serialization
     * invalidity, and layout that is rendered in the wrong direction. These rank
     * above generic conversion gaps.
     */
    private const HIGH_SEVERITY_BUCKETS = array(
        'svg_content_lost',
        'richtext_invalid_content_risk',
        'block_serialization_validity_repair',
        'layout_direction_misrecognition',
    );

    /**
     * Repair lanes that describe working downstream behavior rather than a defect
     * — they are reported for visibility but kept out of the actionable worklist.
     */
    private const INFO_BUCKETS = array(
        'cover_gate_rejection',
        'informational_var_density',
    );

    /**
     * Transformer fallback reason codes that mean an inline <svg> was dropped
     * (its artwork lost) rather than preserved. Routed through the dedicated
     * svg_content_lost detector so they rank by severity instead of hiding among
     * generic asset-materialization findings.
     */
    private const SVG_LOSS_REASON_CODES = array(
        'html_inline_svg_fallback',
        'html_unsafe_inline_svg',
    );

    /**
     * Run every detector over one transformer result envelope.
     *
     * @param array<string, mixed>      $result          Canonical transformer result array.
     * @param string                    $sourceHtml      Original source HTML for the document (for source-aware detectors).
     * @param callable(string): bool|null $columnsVerifier Optional predicate that returns true when a source-element
     *                                                     fragment actually converts to a top-level core/columns block.
     *                                                     Lets the layout-direction detector confirm a misrecognition
     *                                                     instead of guessing from source CSS alone.
     * @return array{
     *     metrics: array<string, int|float>,
     *     findings: array<int, array<string, mixed>>,
     *     var_names: array<int, string>
     * }
     */
    public static function collect(array $result, string $sourceHtml = '', ?callable $columnsVerifier = null): array
    {
        $blocks = is_array($result['blocks'] ?? null) ? $result['blocks'] : array();
        $flat = self::flatten($blocks);

        $native = self::nativeRate($flat);
        $varReport = self::varDependentStyling($flat);
        $validityReport = self::blockValidity($result);

        $richTextRisk = self::richTextInvalidRisk($flat);
        $svgLost = self::svgContentLost($result, $flat);
        $layoutMisrecognition = self::layoutDirectionMisrecognition($sourceHtml, $columnsVerifier);
        $coverGateRejections = self::coverGateRejections($sourceHtml, $flat);
        $mediaTextMetrics = self::mediaTextMetrics($sourceHtml, $flat);

        $findings = array();
        foreach ( self::transformerFindings($result) as $finding ) {
            $findings[] = $finding;
        }
        foreach ( $validityReport['findings'] as $finding ) {
            $findings[] = $finding;
        }
        foreach ( $varReport['findings'] as $finding ) {
            $findings[] = $finding;
        }
        foreach ( $richTextRisk as $finding ) {
            $findings[] = $finding;
        }
        foreach ( $svgLost as $finding ) {
            $findings[] = $finding;
        }
        foreach ( $layoutMisrecognition as $finding ) {
            $findings[] = $finding;
        }
        foreach ( $coverGateRejections as $finding ) {
            $findings[] = $finding;
        }
        foreach ( self::emptyCoreHtml($flat) as $finding ) {
            $findings[] = $finding;
        }
        foreach ( self::coreHtmlFallback($flat) as $finding ) {
            $findings[] = $finding;
        }

        $metrics = array(
            'block_count'                 => $native['total'],
            'native_count'                => $native['native'],
            'core_html_count'             => $native['html'],
            'freeform_count'              => $native['freeform'],
            'native_rate'                 => $native['rate'],
            'var_ref_count'               => $varReport['count'],
            'var_custom_ref_count'        => $varReport['custom_count'],
            // Structural serialization round-trip count. Kept for transparency,
            // but it is NOT the editor-invalidity signal — see the RichText risk
            // metric below.
            'invalid_block_count'         => $validityReport['invalid_block_count'],
            // The authoritative "the editor would flag this as invalid/unexpected
            // content" signal: blocks whose RichText content carries a
            // class/style-bearing inline <span> or a styled <a> that RichText will not
            // preserve on parse.
            'richtext_invalid_risk_count' => count($richTextRisk),
            'svg_content_lost_count'      => count($svgLost),
            'layout_direction_misrecognition_count' => count($layoutMisrecognition),
        );
        foreach ( $mediaTextMetrics as $name => $value ) {
            $metrics[ $name ] = $value;
        }

        return array(
            'metrics'   => $metrics,
            'findings'  => $findings,
            'var_names' => $varReport['names'],
        );
    }

    /**
     * Map a repair lane to its severity tier.
     */
    public static function severityForBucket(string $bucket): string
    {
        if ( in_array($bucket, self::HIGH_SEVERITY_BUCKETS, true) ) {
            return self::SEVERITY_HIGH;
        }
        if ( in_array($bucket, self::INFO_BUCKETS, true) ) {
            return self::SEVERITY_INFO;
        }

        return self::SEVERITY_MEDIUM;
    }

    /**
     * Numeric rank for a severity label (higher = more important).
     */
    public static function severityRank(string $severity): int
    {
        return self::SEVERITY_RANK[$severity] ?? self::SEVERITY_RANK[self::SEVERITY_MEDIUM];
    }

    /**
     * Flatten the recursive block tree into a depth-first list of block arrays.
     *
     * @param array<int, mixed> $blocks
     * @return array<int, array<string, mixed>>
     */
    public static function flatten(array $blocks): array
    {
        $flat = array();
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }
            $flat[] = $block;
            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                foreach ( self::flatten($block['innerBlocks']) as $child ) {
                    $flat[] = $child;
                }
            }
        }

        return $flat;
    }

    /**
     * Native-rate metric: structured core/native blocks over total blocks.
     * core/html and core/freeform (raw HTML escape hatches) count against the
     * native rate, as do name-less blocks.
     *
     * @param array<int, array<string, mixed>> $flat Flattened block list.
     * @return array{total: int, native: int, html: int, freeform: int, rate: float}
     */
    public static function nativeRate(array $flat): array
    {
        $total = 0;
        $html = 0;
        $freeform = 0;
        $native = 0;

        foreach ( $flat as $block ) {
            ++$total;
            $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';
            if ( 'core/html' === $name ) {
                ++$html;
                continue;
            }
            if ( 'core/freeform' === $name ) {
                ++$freeform;
                continue;
            }
            if ( '' === $name ) {
                continue;
            }
            ++$native;
        }

        return array(
            'total'    => $total,
            'native'   => $native,
            'html'     => $html,
            'freeform' => $freeform,
            'rate'     => $total > 0 ? round($native / $total, 4) : 0.0,
        );
    }

    /**
     * Count emitted core/media-text blocks and source-derived outcomes for
     * strict two-pane candidates. A candidate has exactly two direct element
     * children and exactly one img/video across those two sides.
     *
     * Outcome counters are exclusive. width_oob is intentionally not a decline:
     * MediaTextPattern still emits the block and merely omits an out-of-bounds
     * mediaWidth attribute.
     *
     * @param string                           $sourceHtml Original source HTML for the document.
     * @param array<int, array<string, mixed>> $flat       Flattened emitted block list.
     * @return array{
     *     media_text_count: int,
     *     media_text_decline_media_impure_count: int,
     *     media_text_decline_no_text_side_count: int,
     *     media_text_decline_vertical_or_reversed_count: int,
     *     media_text_decline_unsafe_url_count: int,
     *     media_text_width_oob_count: int,
     *     media_text_decline_linked_video_count: int,
     *     media_text_decline_other_count: int,
     *     media_text_diagnostic_error_count: int
     * }
     */
    private static function mediaTextMetrics(string $sourceHtml, array $flat): array
    {
        $metrics = array(
            'media_text_count'                               => 0,
            'media_text_decline_media_impure_count'          => 0,
            'media_text_decline_no_text_side_count'          => 0,
            'media_text_decline_vertical_or_reversed_count'  => 0,
            'media_text_decline_unsafe_url_count'            => 0,
            'media_text_width_oob_count'                     => 0,
            'media_text_decline_linked_video_count'          => 0,
            'media_text_decline_other_count'                 => 0,
            'media_text_diagnostic_error_count'              => 0,
        );

        foreach ( $flat as $block ) {
            if ( 'core/media-text' === ($block['blockName'] ?? null) ) {
                ++$metrics['media_text_count'];
            }
        }

        if ( '' === trim($sourceHtml) ) {
            return $metrics;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $loaded = $doc->loadHTML('<?xml encoding="utf-8"?>' . $sourceHtml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ( ! $loaded ) {
            return $metrics;
        }

        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query('//body//*');
        if ( false === $nodes ) {
            return $metrics;
        }
        $textTransformer = null;
        $sourceStyleMarkup = '';
        $sourceCss = '';
        foreach ( $doc->getElementsByTagName('style') as $styleElement ) {
            $styleCss = (string) $styleElement->textContent;
            $sourceCss .= ( '' === $sourceCss ? '' : "\n" ) . $styleCss;
            $styleMarkup = $doc->saveHTML($styleElement);
            if ( is_string($styleMarkup) ) {
                $sourceStyleMarkup .= $styleMarkup;
            }
        }
        $styleRules = self::mediaTextStaticStyleRules($sourceCss);
        if ( null === $styleRules ) {
            // A PCRE failure erased the CSS cascade; gate outcomes computed
            // without it would be fabrications, not approximations.
            ++$metrics['media_text_diagnostic_error_count'];
            return $metrics;
        }

        $candidates = array();
        foreach ( $nodes as $node ) {
            if ( ! $node instanceof \DOMElement ) {
                continue;
            }

            $children = self::directElementChildren($node);
            if ( 2 !== count($children) ) {
                continue;
            }

            $mediaCounts = array(
                self::mediaElementCount($children[0]),
                self::mediaElementCount($children[1]),
            );
            if ( 1 !== $mediaCounts[0] + $mediaCounts[1] ) {
                continue;
            }

            $candidates[] = array(
                'node'       => $node,
                'children'   => $children,
                'mediaIndex' => 1 === $mediaCounts[0] ? 0 : 1,
            );
        }

        // A wrapper whose descendant is itself a candidate is not a two-pane
        // candidate — evaluating it fabricates declines for markup that
        // converts through the descendant.
        $candidates = array_values(array_filter(
            $candidates,
            static function (array $candidate) use ($candidates): bool {
                foreach ( $candidates as $other ) {
                    if ( $other['node'] === $candidate['node'] ) {
                        continue;
                    }
                    for ( $ancestor = $other['node']->parentNode; $ancestor instanceof \DOMElement; $ancestor = $ancestor->parentNode ) {
                        if ( $ancestor === $candidate['node'] ) {
                            return false;
                        }
                    }
                }

                return true;
            }
        ));

        $sourcePasserCount = 0;
        $widthOobCandidateCount = 0;
        $directionCache = array();
        foreach ( $candidates as $candidate ) {
            $node = $candidate['node'];
            $children = $candidate['children'];
            $mediaIndex = $candidate['mediaIndex'];
            $textIndex = 0 === $mediaIndex ? 1 : 0;

            if ( self::hasNonIgnorableDirectNodes($node) ) {
                ++$metrics['media_text_decline_other_count'];
                continue;
            }

            $resolution = self::diagnosticPureMediaResolution($children[ $mediaIndex ]);
            if ( null === $resolution ) {
                ++$metrics['media_text_decline_media_impure_count'];
                continue;
            }

            $media = $resolution['media'];
            if ( 'video' === strtolower($media->tagName) && $resolution['anchor'] instanceof \DOMElement ) {
                ++$metrics['media_text_decline_linked_video_count'];
                continue;
            }

            $containerStyle = self::mediaTextResolvedDeclarations(
                $node,
                $styleRules,
                array( 'display', 'flex-direction', 'direction', 'grid-template-columns' )
            );
            $childStyles = array(
                self::mediaTextResolvedDeclarations($children[0], $styleRules, array( 'order', 'flex-basis', 'width' )),
                self::mediaTextResolvedDeclarations($children[1], $styleRules, array( 'order', 'flex-basis', 'width' )),
            );
            if (
                self::mediaTextHasVerticalOrReversedLayout($containerStyle, $childStyles)
                || self::diagnosticInheritedRtlBlocks($node, $styleRules, $directionCache)
            ) {
                ++$metrics['media_text_decline_vertical_or_reversed_count'];
                continue;
            }

            if ( '' === self::diagnosticSafeMediaUrl($media->getAttribute('src')) ) {
                ++$metrics['media_text_decline_unsafe_url_count'];
                continue;
            }

            $textBearing = self::mediaTextSideHasTextBearingBlock(
                $doc,
                $children[ $textIndex ],
                $sourceStyleMarkup,
                $textTransformer
            );
            if ( false === $textBearing ) {
                ++$metrics['media_text_decline_no_text_side_count'];
                continue;
            }
            if ( null === $textBearing ) {
                // A crash inside the isolated text-side transform is a
                // diagnostic failure, not a conversion decline.
                ++$metrics['media_text_diagnostic_error_count'];
                continue;
            }

            ++$sourcePasserCount;
            $mediaWidth = self::diagnosticMediaWidth($containerStyle, $childStyles[ $mediaIndex ], $mediaIndex);
            if ( null !== $mediaWidth && ( 15 > $mediaWidth || 85 < $mediaWidth ) ) {
                ++$widthOobCandidateCount;
            }
        }

        // Source-only gates cannot expose transform-time failures. Approximate
        // `other` as otherwise-eligible candidates that did not emit, at document
        // granularity; emitted blocks consume source passers, never known declines.
        // The width diagnostic is capped by emitted adoption so it cannot also be
        // counted as an `other` decline for the same document-level candidate.
        $metrics['media_text_width_oob_count'] = min(
            $widthOobCandidateCount,
            $metrics['media_text_count']
        );
        $metrics['media_text_decline_other_count'] += max(
            0,
            $sourcePasserCount - $metrics['media_text_count']
        );

        return $metrics;
    }

    /**
     * var(--x) references in the emitted block markup.
     *
     * These references are materialized downstream (the SSI compile layer
     * resolves them end-to-end), so a high density of resolved var() references
     * is NOT a repair gap — it is working behavior. The findings are therefore
     * labeled informational (`informational_var_density`) and tracked for
     * visibility, kept out of the actionable defect worklist. WordPress preset
     * properties (var(--wp--...)) are excluded from the findings entirely.
     *
     * @param array<int, array<string, mixed>> $flat Flattened block list.
     * @return array{
     *     count: int,
     *     custom_count: int,
     *     names: array<int, string>,
     *     findings: array<int, array<string, mixed>>
     * }
     */
    public static function varDependentStyling(array $flat): array
    {
        $occurrences = array();
        $total = 0;

        foreach ( $flat as $block ) {
            $haystack = self::blockMarkup($block);
            if ( '' !== $haystack && preg_match_all('/var\(\s*(--[A-Za-z0-9_-]+)/', $haystack, $matches) ) {
                foreach ( $matches[1] as $name ) {
                    ++$total;
                    $occurrences[$name] = ($occurrences[$name] ?? 0) + 1;
                }
            }

            foreach ( self::presetVarNamesFromAttrs($block) as $name ) {
                ++$total;
                $occurrences[$name] = ($occurrences[$name] ?? 0) + 1;
            }
        }

        $findings = array();
        $customCount = 0;
        foreach ( $occurrences as $name => $count ) {
            if ( self::isPresetVar($name) ) {
                continue;
            }
            $customCount += $count;
            $findings[] = array(
                'source'        => 'detector',
                'detector'      => 'var_dependent_styling',
                'repair_bucket' => 'informational_var_density',
                'severity'      => self::SEVERITY_INFO,
                'pattern'       => $name,
                'count'         => $count,
            );
        }

        $names = array_keys($occurrences);
        sort($names);

        return array(
            'count'        => $total,
            'custom_count' => $customCount,
            'names'        => $names,
            'findings'     => $findings,
        );
    }

    /**
     * RichText editor-invalidity risk: paragraph/heading/list-item blocks whose
     * RichText `content` carries an inline <span> with a class or style attribute,
     * or an inline <a> with a style attribute. RichText normalizes unsupported
     * span attributes on parse, while source identity is safely retained on links.
     * The resulting mismatch means the
     * editor shows "unexpected/invalid content" even though the structural
     * serialization round-trip (wp_block_validity) reports the block as valid.
     *
     * This is the authoritative editor-invalid-risk signal, ranked HIGH. The
     * structural `invalid_block_count` of 0 does NOT mean there is no invalid
     * content.
     *
     * @param array<int, array<string, mixed>> $flat Flattened block list.
     * @return array<int, array<string, mixed>>
     */
    public static function richTextInvalidRisk(array $flat): array
    {
        $richTextBlocks = array( 'core/paragraph', 'core/heading', 'core/list-item' );

        $findings = array();
        foreach ( $flat as $block ) {
            $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';
            if ( ! in_array($name, $richTextBlocks, true) ) {
                continue;
            }
            $content = self::richTextContent($block);
            if ( '' === $content ) {
                continue;
            }
            if ( preg_match('/<span\b[^>]*\s(?:class|style)\s*=|<a\b[^>]*\sstyle\s*=/i', $content) ) {
                $findings[] = array(
                    'source'        => 'detector',
                    'detector'      => 'richtext_invalid_risk',
                    'repair_bucket' => 'richtext_invalid_content_risk',
                    'severity'      => self::SEVERITY_HIGH,
                    'pattern'       => $name,
                    'count'         => 1,
                );
            }
        }

        return $findings;
    }

    /**
     * SVG-loss detector (HIGH severity): the recurring missing-image signal.
     *
     * Two complementary sources are routed into one `svg_content_lost` lane:
     *   1. Transformer inline-SVG fallback diagnostics — an <svg> whose artwork
     *      was dropped (no drawable/safe content left) rather than preserved.
     *   2. core/html blocks that are empty or whitespace+HTML-comments-only yet
     *      whose raw content still bears an SVG remnant/marker (the image was
     *      stripped, leaving a dead block).
     *
     * A core/html that PRESERVES an <svg> with real shape elements
     * (path/circle/rect/...) is acceptable and is NOT flagged here.
     *
     * @param array<string, mixed>             $result Canonical transformer result array.
     * @param array<int, array<string, mixed>> $flat   Flattened block list.
     * @return array<int, array<string, mixed>>
     */
    public static function svgContentLost(array $result, array $flat): array
    {
        $findings = array();

        $diagnostics = is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : array();
        foreach ( $diagnostics as $diagnostic ) {
            if ( ! is_array($diagnostic) ) {
                continue;
            }
            $code = (string) ($diagnostic['reason_code'] ?? $diagnostic['code'] ?? '');
            if ( ! in_array($code, self::SVG_LOSS_REASON_CODES, true) ) {
                continue;
            }
            $findings[] = array(
                'source'        => 'detector',
                'detector'      => 'svg_content_lost',
                'repair_bucket' => 'svg_content_lost',
                'severity'      => self::SEVERITY_HIGH,
                'pattern'       => 'html_unsafe_inline_svg' === $code ? 'unsafe_inline_svg_dropped' : 'inline_svg_dropped',
                'count'         => 1,
            );
        }

        foreach ( $flat as $block ) {
            $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';
            if ( 'core/html' !== $name ) {
                continue;
            }
            $content = self::rawContent($block);
            if ( ! self::looksLikeSvgSource($content) ) {
                continue;
            }
            if ( self::svgHasPreservedShapes($content) ) {
                // SVG preserved as core/html with real shape elements — acceptable.
                continue;
            }
            $stripped = trim(preg_replace('/<!--.*?-->/s', '', $content) ?? '');
            if ( '' !== $stripped ) {
                continue;
            }
            $findings[] = array(
                'source'        => 'detector',
                'detector'      => 'svg_content_lost',
                'repair_bucket' => 'svg_content_lost',
                'severity'      => self::SEVERITY_HIGH,
                'pattern'       => 'empty_core_html_from_svg',
                'count'         => 1,
            );
        }

        return $findings;
    }

    /**
     * Layout-direction faithfulness (HIGH severity): a vertical stack
     * (display:flex; flex-direction:column / column-reverse) emitted as a
     * horizontal core/columns block is a misrecognition — the content renders in
     * the wrong direction.
     *
     * Detection is conservative: it only inspects source container elements
     * (div/section/article/...) whose inline style explicitly declares a column
     * flex direction and that hold two or more element children. Genuine
     * horizontal flex (row / default) and grid layouts are never matched, so
     * faithful horizontal columns are not flagged. When a verifier callback is
     * supplied, each candidate is confirmed to actually convert to a top-level
     * core/columns block before it is reported, eliminating cases the transformer
     * routes to core/group or core/list instead.
     *
     * @param string                       $sourceHtml Original source HTML for the document.
     * @param callable(string): bool|null  $verifier   Optional confirmation predicate over an element fragment.
     * @return array<int, array<string, mixed>>
     */
    public static function layoutDirectionMisrecognition(string $sourceHtml, ?callable $verifier = null): array
    {
        if ( '' === trim($sourceHtml) ) {
            return array();
        }

        $containerTags = array( 'div', 'section', 'article', 'aside', 'main', 'header', 'footer', 'nav' );

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $loaded = $doc->loadHTML('<?xml encoding="utf-8"?>' . $sourceHtml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ( ! $loaded ) {
            return array();
        }

        $findings = array();
        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query('//*[@style]');
        if ( false === $nodes ) {
            return array();
        }

        foreach ( $nodes as $node ) {
            if ( ! $node instanceof \DOMElement ) {
                continue;
            }
            if ( ! in_array(strtolower($node->tagName), $containerTags, true) ) {
                continue;
            }
            $style = strtolower($node->getAttribute('style'));
            if ( ! preg_match('/(?:^|;)\s*display\s*:\s*(?:inline-)?flex\b/', $style) ) {
                continue;
            }
            if ( ! preg_match('/flex-direction\s*:\s*column(?:-reverse)?\b/', $style) ) {
                continue;
            }
            $elementChildren = 0;
            foreach ( $node->childNodes as $child ) {
                if ( $child instanceof \DOMElement ) {
                    ++$elementChildren;
                }
            }
            if ( $elementChildren < 2 ) {
                continue;
            }
            if ( null !== $verifier ) {
                $fragment = $doc->saveHTML($node);
                if ( ! is_string($fragment) || ! $verifier($fragment) ) {
                    continue;
                }
            }
            $findings[] = array(
                'source'        => 'detector',
                'detector'      => 'layout_direction_misrecognition',
                'repair_bucket' => 'layout_direction_misrecognition',
                'severity'      => self::SEVERITY_HIGH,
                'pattern'       => 'columns_from_vertical_flex',
                'count'         => 1,
            );
        }

        return $findings;
    }

    /**
     * Informational candidates whose inline background-image container fails a
     * source-derivable core/cover gate.
     *
     * Inline-style-only under-count: this source-side scan cannot see the
     * transformer's merged cascade, so it surfaces tuning candidates rather
     * than exact rejection rates. It reports only the style-derivable rejection
     * keys no_background_url|not_hero_sized|repeating_background|no_text_content|
     * multi_layer_background. Context gates columns_layout and nav_shell are
     * intentionally absent because they cannot be reproduced from style alone.
     *
     * @param string                           $sourceHtml Original source HTML for the document.
     * @param array<int, array<string, mixed>> $flat       Flattened block list.
     * @return array<int, array<string, mixed>>
     */
    public static function coverGateRejections(string $sourceHtml, array $flat): array
    {
        if ( '' === trim($sourceHtml) ) {
            return array();
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $loaded = $doc->loadHTML('<?xml encoding="utf-8"?>' . $sourceHtml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ( ! $loaded ) {
            return array();
        }

        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query('//div[@style] | //section[@style] | //article[@style]');
        if ( false === $nodes ) {
            return array();
        }

        $styleResolver = new CoverStyleResolver();
        $coverPattern = new CoverPattern();
        $findings = array();

        foreach ( $nodes as $node ) {
            if ( ! $node instanceof \DOMElement ) {
                continue;
            }

            $style = $node->getAttribute('style');
            if ( '' === $styleResolver->backgroundUrlFromStyle($style) ) {
                continue;
            }
            if ( '' === trim($node->textContent) ) {
                continue;
            }

            $gate = $coverPattern->rejectionGate($style, true);
            if ( null === $gate ) {
                continue;
            }

            $findings[] = array(
                'source'        => 'detector',
                'detector'      => 'cover_gate_rejection',
                'repair_bucket' => 'cover_gate_rejection',
                'severity'      => self::SEVERITY_INFO,
                'pattern'       => $gate,
                'count'         => 1,
                'detail'        => array(
                    'gate'  => $gate,
                    'tag'   => strtolower($node->tagName),
                    'class' => $node->getAttribute('class'),
                ),
            );
        }

        return $findings;
    }

    /**
     * core/html blocks whose content is only whitespace and/or HTML comments —
     * dead blocks. SVG-sourced empties are excluded here because they are the
     * higher-severity svg_content_lost signal.
     *
     * @param array<int, array<string, mixed>> $flat Flattened block list.
     * @return array<int, array<string, mixed>>
     */
    public static function emptyCoreHtml(array $flat): array
    {
        $findings = array();
        foreach ( $flat as $block ) {
            $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';
            if ( 'core/html' !== $name ) {
                continue;
            }
            $content = self::rawContent($block);
            $hadComment = (bool) preg_match('/<!--.*?-->/s', $content);
            $stripped = trim(preg_replace('/<!--.*?-->/s', '', $content) ?? '');
            if ( '' !== $stripped ) {
                continue;
            }
            if ( self::looksLikeSvgSource($content) ) {
                // Reported by svgContentLost (HIGH) instead of as a generic empty.
                continue;
            }
            $findings[] = array(
                'source'        => 'detector',
                'detector'      => 'empty_core_html',
                'repair_bucket' => 'drop_empty_html_block',
                'severity'      => self::SEVERITY_MEDIUM,
                'pattern'       => $hadComment ? 'comment_only_or_stripped' : 'whitespace_only',
                'count'         => 1,
            );
        }

        return $findings;
    }

    /**
     * Non-empty core/html escape hatches, clustered by the leading element of
     * their raw content. Surfaces which raw-HTML families still bypass native
     * block conversion.
     *
     * @param array<int, array<string, mixed>> $flat Flattened block list.
     * @return array<int, array<string, mixed>>
     */
    public static function coreHtmlFallback(array $flat): array
    {
        $findings = array();
        foreach ( $flat as $block ) {
            $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';
            if ( 'core/html' !== $name ) {
                continue;
            }
            $content = self::rawContent($block);
            $stripped = trim(preg_replace('/<!--.*?-->/s', '', $content) ?? '');
            if ( '' === $stripped ) {
                continue;
            }
            $tag = preg_match('/<\s*([a-zA-Z][a-zA-Z0-9-]*)/', $stripped, $matches)
                ? '<' . strtolower($matches[1]) . '>'
                : 'text';
            $findings[] = array(
                'source'        => 'detector',
                'detector'      => 'core_html_fallback',
                'repair_bucket' => 'native_block_recognition',
                'severity'      => self::SEVERITY_MEDIUM,
                'pattern'       => $tag,
                'count'         => 1,
            );
        }

        return $findings;
    }

    /**
     * Block-validity findings drawn from the transformer's own
     * source_reports.wp_block_validity report — the same serialization round-trip
     * check the parity suite asserts on. Each finding records the block name and
     * the cause code as its pattern.
     *
     * @param array<string, mixed> $result Canonical transformer result array.
     * @return array{invalid_block_count: int, findings: array<int, array<string, mixed>>}
     */
    public static function blockValidity(array $result): array
    {
        $report = $result['source_reports']['wp_block_validity'] ?? array();
        $rawFindings = is_array($report['findings'] ?? null) ? $report['findings'] : array();

        $findings = array();
        foreach ( $rawFindings as $finding ) {
            if ( ! is_array($finding) ) {
                continue;
            }
            $code = (string) ($finding['code'] ?? 'wp_block_validity_warning');
            $blockName = is_string($finding['block_name'] ?? null) && '' !== $finding['block_name']
                ? $finding['block_name']
                : 'unknown';
            $findings[] = array(
                'source'        => 'validity',
                'detector'      => 'wp_block_validity',
                'repair_bucket' => 'block_serialization_validity_repair',
                'severity'      => self::SEVERITY_HIGH,
                'pattern'       => $code . '@' . $blockName,
                'count'         => 1,
            );
        }

        return array(
            'invalid_block_count' => count($findings),
            'findings'            => $findings,
        );
    }

    /**
     * The transformer's own emitted diagnostics, normalized through the canonical
     * finding contract so each carries the (reason_code, pattern_family,
     * repair_bucket) classification triplet. Purely informational summary
     * findings (no_repair_needed) are dropped from the worklist, and inline-SVG
     * loss diagnostics are routed to the dedicated svg_content_lost detector.
     *
     * @param array<string, mixed> $result Canonical transformer result array.
     * @return array<int, array<string, mixed>>
     */
    public static function transformerFindings(array $result): array
    {
        $diagnostics = is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : array();

        $findings = array();
        foreach ( $diagnostics as $diagnostic ) {
            if ( ! is_array($diagnostic) ) {
                continue;
            }
            $reasonCode = (string) ($diagnostic['reason_code'] ?? $diagnostic['code'] ?? '');
            if ( in_array($reasonCode, self::SVG_LOSS_REASON_CODES, true) ) {
                continue;
            }
            $classified = ConversionFindingContract::withClassification($diagnostic);
            $repairBucket = (string) ($classified['repair_bucket'] ?? '');
            if ( 'no_repair_needed' === $repairBucket ) {
                continue;
            }
            $pattern = (string) ($classified['pattern_family'] ?? '');
            if ( '' === $pattern ) {
                $pattern = ConversionFindingContract::findingCode($classified);
            }
            if ( '' === $pattern ) {
                $pattern = 'unclassified';
            }
            $findings[] = array(
                'source'        => 'transformer',
                'detector'      => 'emitted_finding',
                'repair_bucket' => $repairBucket,
                'severity'      => self::severityForBucket($repairBucket),
                'pattern'       => $pattern,
                'count'         => 1,
            );
        }

        return $findings;
    }

    /**
     * Cluster key for a finding: the repair lane (falling back to the detector
     * name) paired with the structural pattern.
     *
     * @param array<string, mixed> $finding
     */
    public static function clusterKey(array $finding): string
    {
        $bucket = (string) ($finding['repair_bucket'] ?? '');
        if ( '' === $bucket ) {
            $bucket = (string) ($finding['detector'] ?? 'unclassified');
        }
        $pattern = (string) ($finding['pattern'] ?? 'unclassified');

        return $bucket . ' :: ' . $pattern;
    }

    /**
     * Keep only top-level author rules, matching HtmlTransformer's strict-gate
     * cascade. Conditional and other at-rules remain available to the isolated
     * text-side transform through the separately preserved source markup.
     */
    private static function mediaTextTopLevelCss(string $css): string
    {
        $css = preg_replace('@/\*.*?\*/@s', '', $css) ?? $css;
        $output = '';
        $length = strlen($css);
        $depth = 0;

        for ( $offset = 0; $offset < $length; ++$offset ) {
            $char = $css[ $offset ];
            if ( '"' === $char || "'" === $char ) {
                $output .= $char;
                for ( ++$offset; $offset < $length; ++$offset ) {
                    $output .= $css[ $offset ];
                    if ( '\\' === $css[ $offset ] && $offset + 1 < $length ) {
                        $output .= $css[ ++$offset ];
                        continue;
                    }
                    if ( $char === $css[ $offset ] ) {
                        break;
                    }
                }
                continue;
            }

            if ( 0 !== $depth || '@' !== $char ) {
                if ( '{' === $char ) {
                    ++$depth;
                } elseif ( '}' === $char && 0 < $depth ) {
                    --$depth;
                }
                $output .= $char;
                continue;
            }

            // One forward scan for whichever terminator comes first. Two
            // independent scans go quadratic when the other token is absent —
            // each @ re-scans to end-of-css.
            $terminator = self::mediaTextCssFirstToken($css, $offset);
            if ( null === $terminator ) {
                break;
            }
            if ( ';' === $terminator['token'] ) {
                $offset = $terminator['position'];
                continue;
            }
            $blockStart = $terminator['position'];

            $atRuleDepth = 1;
            for ( $inner = $blockStart + 1; $inner < $length; ++$inner ) {
                if ( '"' === $css[ $inner ] || "'" === $css[ $inner ] ) {
                    $quote = $css[ $inner ];
                    for ( ++$inner; $inner < $length; ++$inner ) {
                        if ( '\\' === $css[ $inner ] ) {
                            ++$inner;
                            continue;
                        }
                        if ( $quote === $css[ $inner ] ) {
                            break;
                        }
                    }
                    continue;
                }
                if ( '{' === $css[ $inner ] ) {
                    ++$atRuleDepth;
                } elseif ( '}' === $css[ $inner ] && 0 === --$atRuleDepth ) {
                    $offset = $inner;
                    continue 2;
                }
            }
            break;
        }

        return $output;
    }

    /**
     * Parse production-equivalent ordered static rules for media-text gate
     * properties. Dynamic pseudo-state rules never affect resting strict gates.
     *
     * Returns null when PCRE itself fails — an empty ruleset means "no rules",
     * which callers must not conflate with "could not read the rules".
     *
     * @return array<int, array{selector: array<string, mixed>, declarations: array<string, string>}>|null
     */
    private static function mediaTextStaticStyleRules(string $css): ?array
    {
        $css = self::mediaTextTopLevelCss($css);
        $ruleCount = preg_match_all('/([^{}]+)\{([^{}]+)\}/', $css, $matches, PREG_SET_ORDER);
        if ( false === $ruleCount ) {
            return null;
        }
        if ( 0 === $ruleCount ) {
            return array();
        }

        $requested = array_flip(array(
            'display',
            'flex-direction',
            'direction',
            'grid-template-columns',
            'order',
            'flex-basis',
            'width',
        ));
        $rules = array();
        foreach ( $matches as $match ) {
            $declarations = array_intersect_key(
                self::mediaTextCssDeclarations((string) $match[2]),
                $requested
            );
            if ( array() === $declarations ) {
                continue;
            }
            foreach ( explode(',', (string) $match[1]) as $selectorSource ) {
                $selectorSource = trim($selectorSource);
                if (
                    '' === $selectorSource
                    || preg_match('/:{1,2}(?:hover|focus-visible|focus-within|focus|active|visited|before|after)\b/i', $selectorSource)
                ) {
                    continue;
                }
                $selector = CssSelectorMatcher::parse($selectorSource);
                if ( $selector['supported'] ?? false ) {
                    $rules[] = array(
                        'selector'     => $selector,
                        'declarations' => $declarations,
                    );
                }
            }
        }

        return $rules;
    }

    /**
     * Merge matching rules in source order, then inline declarations, exactly as
     * StyleResolutionTrait::structuralPresentationDeclarations().
     *
     * @param array<int, array{selector: array<string, mixed>, declarations: array<string, string>}> $rules
     * @param array<int, string> $requested
     * @return array<string, string>
     */
    private static function mediaTextResolvedDeclarations(\DOMElement $element, array $rules, array $requested): array
    {
        $declarations = array();
        foreach ( $rules as $rule ) {
            $match = CssSelectorMatcher::matches($element, $rule['selector']);
            if ( $match['supported'] && $match['matches'] ) {
                $declarations = array_merge($declarations, $rule['declarations']);
            }
        }
        $declarations = array_merge(
            $declarations,
            self::mediaTextCssDeclarations($element->getAttribute('style'))
        );

        return array_intersect_key($declarations, array_flip($requested));
    }

    /** @return array<string, string> */
    private static function mediaTextCssDeclarations(string $style): array
    {
        $declarations = array();
        foreach ( CssValueSplitter::splitTopLevel($style, array( ';' )) as $declaration ) {
            if ( ! str_contains($declaration, ':') ) {
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $declaration, 2));
            $name = strtolower($name);
            $value = preg_replace('/\s+/', ' ', $value) ?? $value;
            $allowsImageUrl = in_array($name, array( 'background', 'background-image' ), true)
                && ! preg_match('/(?:expression\s*\(|javascript\s*:)/i', $value);
            if (
                '' !== $name
                && '' !== $value
                && ( $allowsImageUrl || ! preg_match('/(?:expression\s*\(|javascript\s*:|url\s*\()/i', $value) )
            ) {
                $declarations[ $name ] = $value;
            }
        }

        return $declarations;
    }

    /**
     * First unquoted `{` or `;` at or after the offset, in one forward scan.
     *
     * @return array{token: string, position: int}|null
     */
    private static function mediaTextCssFirstToken(string $css, int $offset): ?array
    {
        $length = strlen($css);
        for ( ; $offset < $length; ++$offset ) {
            if ( '"' === $css[ $offset ] || "'" === $css[ $offset ] ) {
                $quote = $css[ $offset ];
                for ( ++$offset; $offset < $length; ++$offset ) {
                    if ( '\\' === $css[ $offset ] ) {
                        ++$offset;
                        continue;
                    }
                    if ( $quote === $css[ $offset ] ) {
                        break;
                    }
                }
                continue;
            }
            if ( '{' === $css[ $offset ] || ';' === $css[ $offset ] ) {
                return array(
                    'token'    => $css[ $offset ],
                    'position' => $offset,
                );
            }
        }

        return null;
    }

    /**
     * Convert the candidate text side through the production transformer, then
     * apply the same recursive block-name test as PatternGateHelpersTrait.
     * Embedded source styles are retained so selector-driven conversion stays
     * as close as possible to the full-document path.
     */
    private static function mediaTextSideHasTextBearingBlock(
        \DOMDocument $doc,
        \DOMElement $textSide,
        string $sourceStyleMarkup,
        ?HtmlTransformer &$transformer
    ): ?bool {
        $fragment = $doc->saveHTML($textSide);
        if ( ! is_string($fragment) ) {
            return null;
        }

        $transformer ??= new HtmlTransformer();
        try {
            $result = $transformer->transform(
                '<!doctype html><html><head>' . $sourceStyleMarkup . '</head><body>' . $fragment . '</body></html>',
                array()
            )->toArray();
        } catch ( \Throwable ) {
            return null;
        }

        $blocks = is_array($result['blocks'] ?? null) ? $result['blocks'] : array();
        $textBearingNames = array( 'core/heading', 'core/paragraph', 'core/list', 'core/buttons', 'core/quote' );
        foreach ( self::flatten($blocks) as $block ) {
            if ( in_array($block['blockName'] ?? null, $textBearingNames, true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, \DOMElement>
     */
    private static function directElementChildren(\DOMElement $element): array
    {
        $children = array();
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof \DOMElement ) {
                $children[] = $child;
            }
        }

        return $children;
    }

    private static function hasNonIgnorableDirectNodes(\DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( XML_COMMENT_NODE === $child->nodeType || $child instanceof \DOMElement ) {
                continue;
            }
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            return true;
        }

        return false;
    }

    private static function mediaElementCount(\DOMElement $element): int
    {
        return (in_array(strtolower($element->tagName), array( 'img', 'video' ), true) ? 1 : 0)
            + $element->getElementsByTagName('img')->length
            + $element->getElementsByTagName('video')->length;
    }

    /**
     * @return array{media: \DOMElement, anchor: \DOMElement|null}|null
     */
    private static function diagnosticPureMediaResolution(\DOMElement $element, ?\DOMElement $anchor = null): ?array
    {
        $tagName = strtolower($element->tagName);
        if ( in_array($tagName, array( 'img', 'video' ), true) ) {
            if ( array() !== self::directElementChildren($element) || self::hasNonIgnorableDirectNodes($element) ) {
                return null;
            }

            return array(
                'media'  => $element,
                'anchor' => $anchor,
            );
        }

        if ( ! in_array($tagName, array( 'figure', 'div', 'a', 'picture' ), true) ) {
            return null;
        }
        if ( 'a' === $tagName ) {
            if ( $anchor instanceof \DOMElement ) {
                return null;
            }
            $anchor = $element;
        }

        if ( 'picture' === $tagName ) {
            $image = null;
            foreach ( $element->getElementsByTagName('*') as $descendant ) {
                $descendantTag = strtolower($descendant->tagName);
                if ( 'source' === $descendantTag ) {
                    continue;
                }
                if ( 'img' !== $descendantTag || $image instanceof \DOMElement ) {
                    return null;
                }
                $image = $descendant;
            }
            if ( ! $image instanceof \DOMElement || '' !== trim($element->textContent ?? '') ) {
                return null;
            }

            return array( 'media' => $image, 'anchor' => $anchor );
        }

        $children = self::directElementChildren($element);
        if ( 1 !== count($children) || self::hasNonIgnorableDirectNodes($element) ) {
            return null;
        }

        return self::diagnosticPureMediaResolution($children[0], $anchor);
    }

    /**
     * Mirror the production inherited-direction gate: nearest ancestor (self
     * included) with an explicit CSS `direction` or `dir` attribute wins;
     * `dir="auto"` fails closed.
     *
     * Memoized by node path — candidates share ancestor chains, and each
     * unmemoized level re-scans the whole ruleset. Node paths are stable keys
     * here because the diagnostics DOM is never mutated.
     *
     * @param array<int, array{selector: array<string, mixed>, declarations: array<string, string>}> $styleRules
     * @param array<string, bool> $cache
     */
    private static function diagnosticInheritedRtlBlocks(\DOMElement $element, array $styleRules, array &$cache): bool
    {
        $chain = array();
        $result = null;
        for ( $node = $element; $node instanceof \DOMElement; $node = $node->parentNode ) {
            $path = (string) $node->getNodePath();
            if ( array_key_exists($path, $cache) ) {
                $result = $cache[ $path ];
                break;
            }
            $chain[] = $path;

            $declarations = self::mediaTextResolvedDeclarations($node, $styleRules, array( 'direction' ));
            $direction = strtolower(self::mediaTextCssValue((string) ($declarations['direction'] ?? '')));
            if ( in_array($direction, array( 'ltr', 'rtl' ), true) ) {
                $result = 'rtl' === $direction;
                break;
            }

            $dir = strtolower(trim($node->getAttribute('dir')));
            if ( 'auto' === $dir ) {
                $result = true;
                break;
            }
            if ( in_array($dir, array( 'ltr', 'rtl' ), true) ) {
                $result = 'rtl' === $dir;
                break;
            }
        }

        $result ??= false;
        foreach ( $chain as $path ) {
            $cache[ $path ] = $result;
        }

        return $result;
    }

    private static function diagnosticSafeMediaUrl(string $url): string
    {
        $url = trim($url);
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]/', $url) ) {
            return '';
        }
        if ( str_starts_with($url, '//') || ! preg_match('/^([a-z][a-z0-9+.-]*)\s*:/i', $url, $matches) ) {
            return $url;
        }

        $scheme = strtolower($matches[1]);
        if ( in_array($scheme, array( 'http', 'https' ), true) && preg_match('/^' . preg_quote($scheme, '/') . ':/i', $url) ) {
            return $url;
        }
        if ( 'data' === $scheme && preg_match('/^data:image\/[a-z0-9.+-]+(?:[;,])/i', $url) ) {
            return $url;
        }

        return '';
    }

    /**
     * @param array<string, string>             $containerStyle
     * @param array<int, array<string, string>> $childStyles
     */
    private static function mediaTextHasVerticalOrReversedLayout(array $containerStyle, array $childStyles): bool
    {
        $display = strtolower(self::mediaTextCssValue((string) ($containerStyle['display'] ?? '')));
        $flexDirection = strtolower(self::mediaTextCssValue((string) ($containerStyle['flex-direction'] ?? '')));
        $direction = strtolower(self::mediaTextCssValue((string) ($containerStyle['direction'] ?? '')));
        if (
            ( 'flex' === $display && in_array($flexDirection, array( 'column', 'column-reverse', 'row-reverse' ), true) )
            || 'rtl' === $direction
        ) {
            return true;
        }

        foreach ( $childStyles as $style ) {
            if ( array_key_exists('order', $style) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $containerStyle
     * @param array<string, string> $mediaStyle
     */
    private static function diagnosticMediaWidth(array $containerStyle, array $mediaStyle, int $mediaIndex): ?int
    {
        $display = strtolower(self::mediaTextCssValue((string) ($containerStyle['display'] ?? '')));
        $template = self::mediaTextCssValue((string) ($containerStyle['grid-template-columns'] ?? ''));
        if ( 'grid' === $display && '' !== $template ) {
            return self::diagnosticGridMediaWidth($template, $mediaIndex);
        }

        foreach ( array( 'flex-basis', 'width' ) as $property ) {
            $width = self::diagnosticPercentage((string) ($mediaStyle[ $property ] ?? ''));
            if ( null !== $width ) {
                return $width;
            }
        }

        return null;
    }

    private static function diagnosticGridMediaWidth(string $template, int $mediaIndex): ?int
    {
        $tracks = CssValueSplitter::splitTopLevelWhitespace($template);
        if ( 2 !== count($tracks) ) {
            return null;
        }

        $percentage = self::diagnosticPercentage($tracks[ $mediaIndex ]);
        if ( null !== $percentage ) {
            return $percentage;
        }

        $firstFr = self::diagnosticFrValue($tracks[0]);
        $secondFr = self::diagnosticFrValue($tracks[1]);
        if ( null === $firstFr || null === $secondFr || 0.0 >= $firstFr + $secondFr || ! is_finite($firstFr + $secondFr) ) {
            return null;
        }

        return (int) round(100 * ( 0 === $mediaIndex ? $firstFr : $secondFr ) / ($firstFr + $secondFr));
    }

    private static function diagnosticPercentage(string $value): ?int
    {
        $value = self::mediaTextCssValue($value);
        if ( ! preg_match('/^-?(?:\d+(?:\.\d*)?|\.\d+)%$/', $value) ) {
            return null;
        }

        return (int) round((float) rtrim($value, '%'));
    }

    private static function diagnosticFrValue(string $value): ?float
    {
        $value = self::mediaTextCssValue($value);
        if ( ! preg_match('/^(?:\d+(?:\.\d*)?|\.\d+)fr$/i', $value) ) {
            return null;
        }

        return (float) substr($value, 0, -2);
    }

    private static function mediaTextCssValue(string $value): string
    {
        return trim(preg_replace('/\s*!\s*important\s*$/i', '', $value) ?? $value);
    }

    /**
     * Whether the raw content of a core/html block carries an SVG remnant/marker
     * — either a literal <svg ...> tag or an HTML comment that names svg (the
     * trace left when SVG artwork is stripped out of a wrapper block).
     */
    private static function looksLikeSvgSource(string $content): bool
    {
        if ( preg_match('/<\s*svg\b/i', $content) ) {
            return true;
        }

        return (bool) preg_match('/<!--[^>]*\bsvg\b[^>]*-->/i', $content);
    }

    /**
     * Whether SVG content was preserved with real, renderable shape elements
     * (as opposed to an empty/stripped shell).
     */
    private static function svgHasPreservedShapes(string $content): bool
    {
        $stripped = preg_replace('/<!--.*?-->/s', '', $content) ?? $content;

        return (bool) preg_match(
            '/<\s*(?:path|circle|rect|polygon|polyline|line|ellipse|g|use|text|image|symbol|defs|tspan)\b/i',
            $stripped
        );
    }

    /**
     * Emitted markup for one block — the saved innerHTML, which is the single
     * source of the rendered style="..." declarations. Reading only innerHTML
     * (rather than also the attribute JSON, which carries the same values) keeps
     * each var() reference counted exactly once.
     *
     * @param array<string, mixed> $block
     */
    private static function blockMarkup(array $block): string
    {
        return is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';
    }

    /**
     * Native preset color attrs are the valid form of CSS preset vars, so they no
     * longer appear in innerHTML. Keep them in var_names for corpus visibility.
     *
     * @param array<string, mixed> $block
     * @return array<int, string>
     */
    private static function presetVarNamesFromAttrs(array $block): array
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        $names = array();
        foreach ( array( 'textColor', 'backgroundColor' ) as $attrName ) {
            $slug = is_string($attrs[ $attrName ] ?? null) ? strtolower(trim($attrs[ $attrName ])) : '';
            if ( '' !== $slug && preg_match('/^[a-z0-9_-]+$/', $slug) ) {
                $names[] = '--wp--preset--color--' . $slug;
            }
        }

        return $names;
    }

    /**
     * RichText content for a paragraph/heading/list-item block: the explicit
     * content attribute, falling back to saved innerHTML.
     *
     * @param array<string, mixed> $block
     */
    private static function richTextContent(array $block): string
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        if ( is_string($attrs['content'] ?? null) && '' !== $attrs['content'] ) {
            return $attrs['content'];
        }

        return is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';
    }

    /**
     * Raw content for a core/html block.
     *
     * @param array<string, mixed> $block
     */
    private static function rawContent(array $block): string
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        if ( is_string($attrs['content'] ?? null) ) {
            return $attrs['content'];
        }

        return is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';
    }

    private static function isPresetVar(string $name): bool
    {
        return str_starts_with($name, self::PRESET_VAR_PREFIX);
    }
}
