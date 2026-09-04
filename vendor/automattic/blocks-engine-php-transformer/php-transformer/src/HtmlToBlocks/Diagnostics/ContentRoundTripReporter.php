<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics;

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionFindingContract;

/**
 * Forward-direction content round-trip / hallucination check (#1, ported from
 * the JS blocks-engine `output-verify.ts verifyComposedOutput()`).
 *
 * The structural validators ({@see \Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator})
 * assert the OUTPUT is well-formed; the {@see SemanticParityReporter} compares
 * landmarks and navigation menus. Neither checks that the visible COPY survived
 * conversion intact. This reporter closes that gap: every visible text node in
 * the serialized block output (>=3 alphanumeric characters, normalized) must
 * appear as a substring of the normalized source plaintext. Output text that is
 * absent from the source is "invented" copy — a sign that conversion mangled,
 * synthesized, or duplicated content — and surfaces as a `content_not_in_source`
 * finding.
 *
 * The check is deliberately one-directional (output ⊆ source). It mirrors the
 * blocks-engine reference exactly: block-delimiter comments are stripped first,
 * so text that lives only inside a dynamic block's JSON attributes (e.g. a
 * core/navigation-link label) is NOT scanned as a visible node — that belongs to
 * the navigation-parity check, not here.
 *
 * Pure: no DOM, no I/O, no global state; the same inputs always yield the same
 * report.
 */
final class ContentRoundTripReporter
{
    public const SCHEMA = 'blocks-engine/php-transformer/content-round-trip/v1';

    /**
     * Minimum alphanumeric character count for an output text node to be worth
     * checking. Short fragments (punctuation, single glyphs, "&middot;") carry no
     * meaningful copy and would only produce noise.
     */
    private const MIN_ALNUM = 3;

    /**
     * Maximum stored length of a finding's offending text snippet.
     */
    private const MAX_SNIPPET = 200;

    /**
     * @param array<int, string> $ignoredTexts Producer-declared text the transformer
     *        SYNTHESIZED rather than extracted from visible source (e.g. form-control
     *        echoes built from placeholder/value/required attributes). Such text is
     *        legitimately absent from the source's visible copy, so flagging it would
     *        be noise. Each entry is normalized with the same pipeline as output nodes.
     * @return array<string, mixed>
     */
    public function report(string $serializedBlocks, string $sourceHtml, array $ignoredTexts = array()): array
    {
        $sourceText = $this->normalize($this->htmlToPlainText($sourceHtml));
        $ignored = $this->normalizedIgnoreSet($ignoredTexts);
        $findings = array();

        foreach ( $this->extractTextNodes($serializedBlocks) as $node ) {
            $needle = $this->normalize($node);
            if ( '' === $needle ) {
                continue;
            }

            if ( isset($ignored[$needle]) ) {
                continue;
            }

            if ( ! str_contains($sourceText, $needle) ) {
                $findings[] = ConversionFindingContract::withClassification(array(
                    'code'     => 'content_not_in_source',
                    'severity' => 'warning',
                    'text'     => $this->boundedSnippet($node),
                    'summary'  => 'Generated block text does not appear in the source content.',
                ));
            }
        }

        return array(
            'schema'         => self::SCHEMA,
            'finding_schema' => ConversionFindingContract::SCHEMA,
            'status'         => array() === $findings ? 'pass' : 'warning',
            'findings'       => $findings,
        );
    }

    /**
     * Visible text nodes from serialized block markup: drop the block-delimiter
     * comments, split on tags, and keep fragments carrying at least
     * {@see self::MIN_ALNUM} alphanumeric characters.
     *
     * @return array<int, string>
     */
    private function extractTextNodes(string $markup): array
    {
        $stripped = $this->stripBlockComments($markup);
        $nodes = array();
        foreach ( preg_split('/<[^>]+>/', $stripped) ?: array() as $raw ) {
            $text = $this->htmlToPlainText($raw);
            if ( '' === $text ) {
                continue;
            }

            if ( strlen((string) preg_replace('/[^a-zA-Z0-9]/', '', $text)) < self::MIN_ALNUM ) {
                continue;
            }

            $nodes[] = $text;
        }

        return $nodes;
    }

    private function stripBlockComments(string $markup): string
    {
        $markup = (string) preg_replace('/<!--\s*\/?wp:[^>]*-->/', ' ', $markup);

        return (string) preg_replace('/<!--.*?-->/s', ' ', $markup);
    }

    private function htmlToPlainText(string $html): string
    {
        if ( '' === $html ) {
            return '';
        }

        $text = (string) preg_replace('/<[^>]+>/', ' ', $html);

        // Decode the FULL HTML entity set, not a hand-picked subset. The
        // transformer parses the source DOM, so its output carries the literal
        // glyph (©, →, —, ’) while raw-HTML sources still hold the entity
        // (&copy;, &rarr;, &mdash;, &rsquo;). Decoding both sides identically is
        // what keeps the substring comparison from drowning in false positives.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Fold non-breaking and other fixed-width Unicode spaces to a regular
        // space; PCRE's \s does not match U+00A0 et al., so collapse them first.
        $text = (string) preg_replace('/[\x{00A0}\x{2007}\x{202F}]/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strtolower($text)));
    }

    /**
     * Normalize the producer-declared ignore list into a lookup keyed by the same
     * representation output nodes are compared as, so membership tests are O(1)
     * and tolerate the entity/whitespace/case differences the pipeline folds out.
     *
     * @param array<int, string> $ignoredTexts
     * @return array<string, true>
     */
    private function normalizedIgnoreSet(array $ignoredTexts): array
    {
        $set = array();
        foreach ( $ignoredTexts as $text ) {
            $key = $this->normalize($this->htmlToPlainText((string) $text));
            if ( '' !== $key ) {
                $set[$key] = true;
            }
        }

        return $set;
    }

    private function boundedSnippet(string $text): string
    {
        if ( strlen($text) <= self::MAX_SNIPPET ) {
            return $text;
        }

        return rtrim(substr($text, 0, self::MAX_SNIPPET)) . '…';
    }
}
