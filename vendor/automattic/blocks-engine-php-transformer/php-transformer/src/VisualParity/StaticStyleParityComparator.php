<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\VisualParity;

use Automattic\BlocksEngine\PhpTransformer\Contract\VisualParityReportContract;

/**
 * Deterministic, render-free visual-parity comparator.
 *
 * Compares the {@see StaticStyleParityProbe} fingerprints of a SOURCE document
 * and a transformed CANDIDATE document and emits a
 * {@see VisualParityReportContract::REPORT_SCHEMA} report carrying:
 *
 *  - a stable parity SCORE (property agreement × element coverage, 0..1),
 *  - per-element MATCHES with the matched selector pair and confidence,
 *  - per-element / per-PROPERTY findings naming exactly which CSS property on
 *    which selector diverges (far more actionable than a pixel ratio).
 *
 * Element correspondence is solved deterministically (never by pixels). Because
 * the transformer preserves source classes (class -> className) and semantic
 * tags, source and candidate elements are matched by stable identity in a fixed
 * tier order, consuming the first still-unmatched candidate in document order:
 *
 *   1. selector   — identical stable selector path (tag + id/classes + nth-of-type)
 *   2. class+text — same tag, same sorted class set, same trimmed text
 *   3. class      — same tag, same sorted class set
 *   4. structural — same class-free structural path (tag + nth-of-type chain)
 *
 * COVERAGE then accounts for the transformer's intentional collapse of
 * presentational wrappers. A source element that owns no 1:1 candidate is not
 * automatically parity loss: if some candidate carries every one of its
 * effective tracked-style values and subsumes its content, the wrapper was
 * faithfully absorbed under collapse and earns coverage credit (no findings).
 * Only genuinely dropped or restyled elements — whose style is absent from every
 * candidate, or whose content has no home — stay counted as loss, so the score
 * still falls for real divergence. Coverage = (matched + absorbed) / source.
 *
 * Same inputs -> byte-identical report on every run. No screenshots, no
 * dimensions, no scroll/animation flakiness, no OOM.
 */
final class StaticStyleParityComparator
{
    public const DEFAULT_WARN_AT = 0.98;
    public const DEFAULT_FAIL_AT = 0.85;

    /**
     * Match tiers in priority order. Each maps a tier id to a confidence weight.
     */
    private const TIERS = array(
        'selector' => 1.0,
        'class+text' => 0.9,
        'class' => 0.75,
        'structural' => 0.6,
    );

    private float $warnAt;
    private float $failAt;

    public function __construct(float $warnAt = self::DEFAULT_WARN_AT, float $failAt = self::DEFAULT_FAIL_AT)
    {
        $this->warnAt = $warnAt;
        $this->failAt = $failAt;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    public function compare(array $source, array $target): array
    {
        $sourceProbes = $this->probes($source);
        $targetProbes = $this->probes($target);
        $available = array_fill_keys(array_keys($targetProbes), true);
        $normalizedTargets = $this->normalizedTargets($targetProbes);

        $matches = array();
        $findings = array();
        $recommendations = array();
        $unmatchedSource = array();
        $absorbedSource = array();

        $comparedProperties = 0;
        $agreedProperties = 0;

        foreach ( $sourceProbes as $sourceProbe ) {
            [$targetIndex, $tier] = $this->bestTargetIndex($sourceProbe, $targetProbes, $available);
            if ( null === $targetIndex ) {
                // No 1:1 candidate remained. Before counting this as parity loss,
                // test for collapsed-wrapper equivalence: the transformer
                // intentionally merges presentational wrappers, so a source
                // element that owns no 1:1 candidate is faithfully preserved when
                // some candidate carries every one of its effective tracked-style
                // values AND subsumes its content. Such an element lost no visual
                // signal under collapse, so it earns coverage credit rather than
                // being scored as a drop. Non-consuming: the wrapper legitimately
                // shares the merged element with the content match. A genuinely
                // dropped/regressed element (its style absent from every candidate
                // or its content gone) finds no absorbing candidate and stays a
                // counted loss, so real divergence still lowers the score.
                $absorbIndex = $this->absorbingTargetIndex($sourceProbe, $normalizedTargets);
                if ( null !== $absorbIndex ) {
                    $absorbedSource[] = $this->absorbedSummary($sourceProbe, $targetProbes[$absorbIndex]);
                    continue;
                }

                $unmatchedSource[] = $this->candidateSummary($sourceProbe);
                $finding = $this->missingElementFinding($sourceProbe, count($findings) + 1);
                $recommendation = $this->recommendationFor($finding, count($recommendations) + 1);
                $finding['recommendation_ids'] = array($recommendation['id']);
                $findings[] = $finding;
                $recommendations[] = $recommendation;
                continue;
            }

            unset($available[$targetIndex]);
            $targetProbe = $targetProbes[$targetIndex];

            $sourceStyle = $this->style($sourceProbe);
            $deltas = array();
            foreach ( self::sortedKeys($sourceStyle) as $property ) {
                $sourceValue = $this->normalizeValue($property, (string) $sourceStyle[$property]);
                if ( '' === $sourceValue ) {
                    continue;
                }
                ++$comparedProperties;
                $targetRaw = (string) ($this->style($targetProbe)[$property] ?? '');
                $targetValue = $this->normalizeValue($property, $targetRaw);
                if ( $sourceValue === $targetValue ) {
                    ++$agreedProperties;
                    continue;
                }
                $deltas[] = array(
                    'property' => $property,
                    'source' => (string) $sourceStyle[$property],
                    'target' => $targetRaw,
                );
            }

            $matches[] = array(
                'kind' => 'generic',
                'source_selector' => $this->selector($sourceProbe),
                'target_selector' => $this->selector($targetProbe),
                'confidence' => self::TIERS[$tier],
                'match_tier' => $tier,
                'style_deltas' => $deltas,
            );

            foreach ( $deltas as $delta ) {
                $finding = $this->deltaFinding($sourceProbe, $delta, count($findings) + 1);
                $recommendation = $this->recommendationFor($finding, count($recommendations) + 1);
                $finding['recommendation_ids'] = array($recommendation['id']);
                $findings[] = $finding;
                $recommendations[] = $recommendation;
            }
        }

        $sourceTotal = count($sourceProbes);
        $matchedTotal = count($matches);
        $absorbedTotal = count($absorbedSource);
        // Coverage measures whether each source element's visual signal found a
        // home in the candidate, either as a 1:1 style comparison (matched) or as
        // a faithfully-preserved collapsed wrapper (absorbed). Only genuinely
        // dropped/regressed elements are excluded from the numerator.
        $coveredTotal = $matchedTotal + $absorbedTotal;
        $propertyParity = $comparedProperties > 0 ? $agreedProperties / $comparedProperties : 1.0;
        $coverage = $sourceTotal > 0 ? $coveredTotal / $sourceTotal : 1.0;
        $score = round($propertyParity * $coverage, 4);

        $status = $this->status($score, array() === $findings);
        $severity = $this->severity($status);

        $report = array(
            'schema' => VisualParityReportContract::REPORT_SCHEMA,
            'status' => $status,
            'severity' => $severity,
            'source_render' => array('kind' => 'source', 'renderer' => 'php-transformer-static'),
            'target_render' => array('kind' => 'target', 'renderer' => 'php-transformer-static'),
            'viewports' => array(),
            'matches' => $matches,
            'findings' => $findings,
            'recommendations' => $recommendations,
            'parity' => array(
                'score' => $score,
                'property_parity' => round($propertyParity, 4),
                'coverage' => round($coverage, 4),
                'warn_at' => $this->warnAt,
                'fail_at' => $this->failAt,
            ),
            'summary' => array(
                'source_total' => $sourceTotal,
                'target_total' => count($targetProbes),
                'matched_total' => $matchedTotal,
                'absorbed_source_total' => $absorbedTotal,
                'covered_total' => $coveredTotal,
                'unmatched_source_total' => count($unmatchedSource),
                'compared_properties' => $comparedProperties,
                'agreed_properties' => $agreedProperties,
                'finding_total' => count($findings),
            ),
            'unmatched_source' => $unmatchedSource,
            'absorbed_source' => $absorbedSource,
            'diagnostics' => array(),
        );

        return $report;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<int, array<string, mixed>>
     */
    private function probes(array $result): array
    {
        $probes = $result['probes'] ?? array();
        return is_array($probes) ? array_values(array_filter($probes, 'is_array')) : array();
    }

    /**
     * @param array<string, mixed> $sourceProbe
     * @param array<int, array<string, mixed>> $targetProbes
     * @param array<int, bool> $available
     * @return array{0: int|null, 1: string}
     */
    private function bestTargetIndex(array $sourceProbe, array $targetProbes, array $available): array
    {
        foreach ( array_keys(self::TIERS) as $tier ) {
            foreach ( array_keys($available) as $index ) {
                if ( $this->tierMatches($tier, $sourceProbe, $targetProbes[$index]) ) {
                    return array($index, $tier);
                }
            }
        }

        return array(null, '');
    }

    /**
     * Pre-normalize every candidate's tracked style and text once so the
     * absorption pass is a cheap, deterministic lookup. Keyed by the same indices
     * as $targetProbes; iteration order is document order.
     *
     * @param array<int, array<string, mixed>> $targetProbes
     * @return array<int, array{style: array<string, string>, text: string}>
     */
    private function normalizedTargets(array $targetProbes): array
    {
        $normalized = array();
        foreach ( $targetProbes as $index => $targetProbe ) {
            $style = array();
            foreach ( $this->style($targetProbe) as $property => $value ) {
                $normalizedValue = $this->normalizeValue((string) $property, (string) $value);
                if ( '' !== $normalizedValue ) {
                    $style[(string) $property] = $normalizedValue;
                }
            }
            $normalized[$index] = array(
                'style' => $style,
                'text' => $this->text($targetProbe),
            );
        }

        return $normalized;
    }

    /**
     * Collapsed-wrapper equivalence test.
     *
     * Returns the first candidate (document order) that faithfully absorbs the
     * source element, or null when none does. A candidate absorbs the source when:
     *
     *  - STYLE is preserved: every declared, non-empty tracked-style value on the
     *    source equals the candidate's normalized value for that property
     *    (candidate is a style superset), and
     *  - CONTENT has a home: the source has no text, or one of the two texts
     *    contains the other (the merged candidate subsumes the wrapper's content;
     *    the bidirectional check tolerates the probe's 80-char text truncation).
     *
     * This credits presentational wrappers the transformer intentionally merges
     * without crediting drops: if any declared style value is missing from every
     * candidate (a real style regression) or the content is gone (a real drop),
     * no candidate qualifies and the element remains a counted loss. Non-consuming
     * by design — the wrapper and its content legitimately share one merged
     * candidate element.
     *
     * @param array<string, mixed> $sourceProbe
     * @param array<int, array{style: array<string, string>, text: string}> $normalizedTargets
     */
    private function absorbingTargetIndex(array $sourceProbe, array $normalizedTargets): ?int
    {
        $sourceStyle = array();
        foreach ( $this->style($sourceProbe) as $property => $value ) {
            $normalizedValue = $this->normalizeValue((string) $property, (string) $value);
            if ( '' !== $normalizedValue ) {
                $sourceStyle[(string) $property] = $normalizedValue;
            }
        }
        $sourceText = $this->text($sourceProbe);

        foreach ( $normalizedTargets as $index => $target ) {
            if ( ! $this->styleIsSuperset($sourceStyle, $target['style']) ) {
                continue;
            }
            if ( $this->contentSubsumed($sourceText, $target['text']) ) {
                return $index;
            }
        }

        return null;
    }

    /**
     * True when every declared source value is reproduced on the candidate.
     *
     * @param array<string, string> $sourceStyle    Normalized, non-empty values.
     * @param array<string, string> $candidateStyle Normalized, non-empty values.
     */
    private function styleIsSuperset(array $sourceStyle, array $candidateStyle): bool
    {
        foreach ( $sourceStyle as $property => $value ) {
            if ( ($candidateStyle[$property] ?? null) !== $value ) {
                return false;
            }
        }

        return true;
    }

    /**
     * True when the absorbing candidate carries the source element's content.
     * Empty source text (a layout wrapper or an SVG shape) carries no textual
     * content to lose and is always subsumed. Otherwise the merged candidate must
     * contain the source's full text — a directional check, so a short candidate
     * (e.g. a one-letter icon) can never spuriously "absorb" a text-bearing
     * wrapper merely because its single character appears somewhere in the source.
     * Equal texts satisfy containment, which also covers the probe's shared
     * 80-char truncation boundary.
     *
     * Comparison is whitespace-insensitive. The transform serializes blocks as
     * minified markup, so adjacent block children render back-to-back with no
     * separating whitespace ("JanuaryThe"), whereas the source carried inter-
     * element whitespace from its indentation ("January The"). That spacing is
     * not visual content — it is a serialization artifact of how the two
     * documents lay out the SAME text. Collapsing all whitespace before the
     * containment test lets a collapsed parent wrapper (a `<li>` merged into a
     * group whose children are absorbed) still be recognized as carrying its
     * content, while a genuine drop — whose characters are simply absent from
     * every candidate — still fails containment and stays a counted loss.
     */
    private function contentSubsumed(string $sourceText, string $candidateText): bool
    {
        $sourceText = $this->stripWhitespace($sourceText);
        if ( '' === $sourceText ) {
            return true;
        }

        return str_contains($this->stripWhitespace($candidateText), $sourceText);
    }

    private function stripWhitespace(string $text): string
    {
        return preg_replace('/\s+/', '', $text) ?? $text;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $target
     */
    private function tierMatches(string $tier, array $source, array $target): bool
    {
        switch ( $tier ) {
            case 'selector':
                return '' !== $this->selector($source) && $this->selector($source) === $this->selector($target);
            case 'class+text':
                return $this->tag($source) === $this->tag($target)
                    && $this->classes($source) === $this->classes($target)
                    && '' !== $this->text($source)
                    && $this->text($source) === $this->text($target);
            case 'class':
                return $this->tag($source) === $this->tag($target)
                    && array() !== $this->classes($source)
                    && $this->classes($source) === $this->classes($target);
            case 'structural':
                return '' !== $this->structuralPath($source) && $this->structuralPath($source) === $this->structuralPath($target);
            default:
                return false;
        }
    }

    private function status(float $score, bool $noFindings): string
    {
        if ( $noFindings && $score >= 1.0 ) {
            return 'pass';
        }
        if ( $score < $this->failAt ) {
            return 'fail';
        }
        if ( $score < $this->warnAt ) {
            return 'warning';
        }

        return $noFindings ? 'pass' : 'warning';
    }

    private function severity(string $status): string
    {
        return array(
            'pass' => 'none',
            'warning' => 'warning',
            'fail' => 'error',
        )[$status] ?? 'none';
    }

    /**
     * @param array<string, mixed> $sourceProbe
     * @param array{property: string, source: string, target: string} $delta
     * @return array<string, mixed>
     */
    private function deltaFinding(array $sourceProbe, array $delta, int $sequence): array
    {
        $target = '' === $delta['target'] ? 'no declared value' : $delta['target'];

        return array(
            'id' => 'static-parity-' . $delta['property'] . '-' . $sequence,
            'severity' => 'warning',
            'category' => 'visual-style',
            'summary' => sprintf('%s %s changed from "%s" to "%s".', $this->selector($sourceProbe), $delta['property'], $delta['source'], $target),
            'kind' => 'generic',
            'property' => $delta['property'],
            'source_value' => $delta['source'],
            'target_value' => $delta['target'],
            'selector' => $this->selector($sourceProbe),
        );
    }

    /**
     * @param array<string, mixed> $sourceProbe
     * @return array<string, mixed>
     */
    private function missingElementFinding(array $sourceProbe, int $sequence): array
    {
        return array(
            'id' => 'static-parity-missing-' . $sequence,
            'severity' => 'warning',
            'category' => 'visual-style',
            'summary' => sprintf('Source element %s has no matching candidate element.', $this->selector($sourceProbe)),
            'kind' => 'generic',
            'property' => 'presence',
            'selector' => $this->selector($sourceProbe),
        );
    }

    /**
     * @param array<string, mixed> $finding
     * @return array<string, mixed>
     */
    private function recommendationFor(array $finding, int $sequence): array
    {
        $property = (string) ($finding['property'] ?? 'visual-style');
        $summary = 'presence' === $property
            ? 'Preserve the source element so candidate structure matches.'
            : sprintf('Align the candidate %s with the source value.', $property);

        return array(
            'id' => 'rec-static-parity-' . $sequence,
            'priority' => 'medium',
            'summary' => $summary,
            'finding_ids' => array((string) $finding['id']),
        );
    }

    /**
     * @param array<string, mixed> $probe
     * @return array<string, string>
     */
    private function style(array $probe): array
    {
        $style = $probe['style'] ?? array();
        return is_array($style) ? array_filter($style, 'is_string') : array();
    }

    /**
     * @param array<string, string> $style
     * @return array<int, string>
     */
    private static function sortedKeys(array $style): array
    {
        $keys = array_keys($style);
        sort($keys);
        return $keys;
    }

    private function normalizeValue(string $property, string $value): string
    {
        $value = strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
        if ( '' === $value ) {
            return '';
        }

        if ( 'font-family' === $property ) {
            $value = str_replace(array('"', "'"), '', $value);
            return preg_replace('/\s*,\s*/', ',', $value) ?? $value;
        }

        if ( 'font-weight' === $property ) {
            return array('normal' => '400', 'bold' => '700', 'lighter' => '300', 'bolder' => '700')[$value] ?? $value;
        }

        // Light, deterministic color normalization: drop spaces inside rgb()/rgba()
        // and lowercase hex so equivalent declarations compare equal.
        $value = preg_replace('/\s*,\s*/', ',', $value) ?? $value;
        $value = preg_replace('/\(\s+/', '(', $value) ?? $value;
        $value = preg_replace('/\s+\)/', ')', $value) ?? $value;

        return $value;
    }

    /** @param array<string, mixed> $probe */
    private function selector(array $probe): string
    {
        $selector = trim((string) ($probe['selector'] ?? ''));
        return '' !== $selector ? $selector : (string) ($probe['tag'] ?? '');
    }

    /** @param array<string, mixed> $probe */
    private function structuralPath(array $probe): string
    {
        return trim((string) ($probe['structural_path'] ?? ''));
    }

    /** @param array<string, mixed> $probe */
    private function tag(array $probe): string
    {
        return strtolower(trim((string) ($probe['tag'] ?? '')));
    }

    /**
     * @param array<string, mixed> $probe
     * @return array<int, string>
     */
    private function classes(array $probe): array
    {
        $classes = $probe['classes'] ?? array();
        if ( ! is_array($classes) ) {
            return array();
        }
        // Generated geometry carriers are implementation detail classes, not
        // source identity. Excluding them keeps correspondence anchored to the
        // source classes they supplement.
        $classes = array_values(array_filter($classes, static fn ($class): bool => is_string($class) && ! str_starts_with($class, 'be-inline-geometry-')));
        sort($classes);
        return $classes;
    }

    /** @param array<string, mixed> $probe */
    private function text(array $probe): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', (string) ($probe['text'] ?? '')) ?? ''));
    }

    /**
     * @param array<string, mixed> $probe
     * @return array<string, mixed>
     */
    private function candidateSummary(array $probe): array
    {
        return array_filter(array(
            'id' => $probe['id'] ?? null,
            'tag' => $probe['tag'] ?? null,
            'selector' => $probe['selector'] ?? null,
            'text' => $probe['text'] ?? null,
        ), static fn ($value): bool => null !== $value && '' !== $value);
    }

    /**
     * Transparency record for a source element whose styling and content the
     * transform faithfully preserved on a merged candidate (collapsed wrapper).
     * Names the absorbing candidate selector so the credit is auditable.
     *
     * @param array<string, mixed> $sourceProbe
     * @param array<string, mixed> $targetProbe
     * @return array<string, mixed>
     */
    private function absorbedSummary(array $sourceProbe, array $targetProbe): array
    {
        return array_filter(array(
            'id' => $sourceProbe['id'] ?? null,
            'tag' => $sourceProbe['tag'] ?? null,
            'source_selector' => $this->selector($sourceProbe),
            'absorbed_into_selector' => $this->selector($targetProbe),
            'text' => $sourceProbe['text'] ?? null,
        ), static fn ($value): bool => null !== $value && '' !== $value);
    }
}
