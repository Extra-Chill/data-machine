<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\VisualParity;

use Automattic\BlocksEngine\PhpTransformer\Contract\VisualParityReportContract;

/**
 * Compares source and target typography probe sets and emits a
 * {@see VisualParityReportContract::REPORT_SCHEMA} report: typography matches
 * (component kind "generic"), findings when font-family / font-weight / type
 * scale drift between source and target, and aligned recommendations.
 *
 * Mirrors the additive shape of {@see ButtonMenuVisualProbeComparator} while
 * going one step further by emitting through the shared visual-parity report
 * contract. Generic; no fixture-specific assumptions.
 */
final class TypographyVisualProbeComparator
{
    /**
     * Typography properties compared between matched source/target probes.
     * Each entry maps the probe property to the report category label.
     */
    private const COMPARED_PROPERTIES = array(
        'font-family' => 'family',
        'font-weight' => 'weight',
        'font-size' => 'scale',
    );

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

        $matches = array();
        $findings = array();
        $recommendations = array();
        $unmatchedSource = array();

        foreach ( $sourceProbes as $sourceProbe ) {
            $targetIndex = $this->bestTargetIndex($sourceProbe, $targetProbes, $available);
            if ( null === $targetIndex ) {
                $unmatchedSource[] = $this->candidateSummary($sourceProbe);
                $finding = $this->missingRoleFinding($sourceProbe, count($findings) + 1);
                $recommendation = $this->recommendationFor($finding, count($recommendations) + 1);
                $finding['recommendation_ids'] = array($recommendation['id']);
                $findings[] = $finding;
                $recommendations[] = $recommendation;
                continue;
            }

            unset($available[$targetIndex]);
            $targetProbe = $targetProbes[$targetIndex];

            $deltas = $this->typographyDeltas($sourceProbe, $targetProbe);
            $match = array(
                'kind' => 'generic',
                'source_selector' => $this->selector($sourceProbe),
                'target_selector' => $this->selector($targetProbe),
                'confidence' => $this->confidence($sourceProbe, $targetProbe),
                'typography' => array_filter(array(
                    'role' => (string) ($sourceProbe['role'] ?? ''),
                    'level' => (int) ($sourceProbe['level'] ?? 0),
                    'source' => $this->typography($sourceProbe),
                    'target' => $this->typography($targetProbe),
                    'deltas' => $deltas,
                ), static fn ($value): bool => array() !== $value && '' !== $value),
            );
            $matches[] = $match;

            foreach ( $deltas as $delta ) {
                $finding = $this->deltaFinding($sourceProbe, $delta, count($findings) + 1);
                $recommendation = $this->recommendationFor($finding, count($recommendations) + 1);
                $finding['recommendation_ids'] = array($recommendation['id']);
                $findings[] = $finding;
                $recommendations[] = $recommendation;
            }
        }

        $hasFindings = array() !== $findings;
        $report = array(
            'schema' => VisualParityReportContract::REPORT_SCHEMA,
            'status' => $hasFindings ? 'warning' : 'pass',
            'severity' => $hasFindings ? 'warning' : 'none',
            'source_render' => array('kind' => 'source', 'renderer' => 'php-transformer'),
            'target_render' => array('kind' => 'target', 'renderer' => 'php-transformer'),
            'viewports' => array(),
            'matches' => $matches,
            'findings' => $findings,
            'recommendations' => $recommendations,
            'summary' => array(
                'source_total' => count($sourceProbes),
                'target_total' => count($targetProbes),
                'matched_total' => count($matches),
                'unmatched_source_total' => count($unmatchedSource),
                'finding_total' => count($findings),
            ),
            'unmatched_source' => $unmatchedSource,
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
     */
    private function bestTargetIndex(array $sourceProbe, array $targetProbes, array $available): ?int
    {
        $bestIndex = null;
        $bestScore = 0;
        foreach ( array_keys($available) as $index ) {
            $score = $this->matchScore($sourceProbe, $targetProbes[$index]);
            if ( $score > $bestScore ) {
                $bestScore = $score;
                $bestIndex = $index;
            }
        }

        return $bestScore >= 3 ? $bestIndex : null;
    }

    /**
     * @param array<string, mixed> $sourceProbe
     * @param array<string, mixed> $targetProbe
     */
    private function matchScore(array $sourceProbe, array $targetProbe): int
    {
        if ( (string) ($sourceProbe['role'] ?? '') !== (string) ($targetProbe['role'] ?? '') ) {
            return 0;
        }

        $score = 3;
        if ( (int) ($sourceProbe['level'] ?? 0) === (int) ($targetProbe['level'] ?? 0) ) {
            $score += 2;
        }
        if ( '' !== $this->normalizedText($sourceProbe) && $this->normalizedText($sourceProbe) === $this->normalizedText($targetProbe) ) {
            $score += 5;
        }

        return $score;
    }

    private function confidence(array $sourceProbe, array $targetProbe): float
    {
        $score = $this->matchScore($sourceProbe, $targetProbe);
        return round(min(1.0, $score / 10), 2);
    }

    /**
     * @param array<string, mixed> $sourceProbe
     * @param array<string, mixed> $targetProbe
     * @return array<int, array<string, string>>
     */
    private function typographyDeltas(array $sourceProbe, array $targetProbe): array
    {
        $sourceStyle = $this->typography($sourceProbe);
        $targetStyle = $this->typography($targetProbe);
        $deltas = array();

        foreach ( self::COMPARED_PROPERTIES as $property => $group ) {
            $sourceValue = $this->normalizeValue($property, (string) ($sourceStyle[$property] ?? ''));
            $targetValue = $this->normalizeValue($property, (string) ($targetStyle[$property] ?? ''));
            if ( '' === $sourceValue || $sourceValue === $targetValue ) {
                continue;
            }

            $deltas[] = array(
                'group' => $group,
                'property' => $property,
                'source' => (string) ($sourceStyle[$property] ?? ''),
                'target' => (string) ($targetStyle[$property] ?? ''),
            );
        }

        return $deltas;
    }

    /**
     * @param array<string, mixed> $sourceProbe
     * @param array<string, string> $delta
     * @return array<string, mixed>
     */
    private function deltaFinding(array $sourceProbe, array $delta, int $sequence): array
    {
        $label = $this->roleLabel($sourceProbe);
        $target = '' === $delta['target'] ? 'no declared value' : $delta['target'];

        return array(
            'id' => 'typography-' . $delta['group'] . '-' . $sequence,
            'severity' => 'warning',
            'category' => 'typography',
            'summary' => sprintf('%s %s changed from "%s" to "%s".', $label, $delta['property'], $delta['source'], $target),
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
    private function missingRoleFinding(array $sourceProbe, int $sequence): array
    {
        $label = $this->roleLabel($sourceProbe);

        return array(
            'id' => 'typography-missing-' . $sequence,
            'severity' => 'warning',
            'category' => 'typography',
            'summary' => sprintf('%s present in source has no matching target element.', $label),
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
        $property = (string) ($finding['property'] ?? 'typography');
        $summary = 'presence' === $property
            ? 'Restore the missing typographic element so source and target structure match.'
            : sprintf('Align the target %s with the source typography treatment.', $property);

        return array(
            'id' => 'rec-typography-' . $sequence,
            'priority' => 'medium',
            'summary' => $summary,
            'finding_ids' => array((string) $finding['id']),
        );
    }

    /**
     * @param array<string, mixed> $probe
     * @return array<string, string>
     */
    private function typography(array $probe): array
    {
        $style = $probe['typography'] ?? array();
        return is_array($style) ? array_filter($style, 'is_string') : array();
    }

    private function normalizeValue(string $property, string $value): string
    {
        $value = strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
        if ( '' === $value ) {
            return '';
        }

        if ( 'font-family' === $property ) {
            $value = str_replace(array('"', "'"), '', $value);
            $value = preg_replace('/\s*,\s*/', ',', $value) ?? $value;
            return $value;
        }

        if ( 'font-weight' === $property ) {
            return array(
                'normal' => '400',
                'bold' => '700',
                'lighter' => '300',
                'bolder' => '700',
            )[$value] ?? $value;
        }

        return $value;
    }

    /** @param array<string, mixed> $probe */
    private function roleLabel(array $probe): string
    {
        $role = (string) ($probe['role'] ?? 'text');
        if ( 'heading' === $role ) {
            $level = (int) ($probe['level'] ?? 0);
            return $level > 0 ? 'Heading level ' . $level : 'Heading';
        }

        return ucfirst($role);
    }

    /**
     * @param array<string, mixed> $probe
     * @return array<string, mixed>
     */
    private function candidateSummary(array $probe): array
    {
        return array_filter(array(
            'id' => $probe['id'] ?? null,
            'role' => $probe['role'] ?? null,
            'level' => $probe['level'] ?? null,
            'text' => $probe['text'] ?? null,
            'selector' => $probe['selector'] ?? null,
        ), static fn ($value): bool => null !== $value && '' !== $value);
    }

    /** @param array<string, mixed> $probe */
    private function selector(array $probe): string
    {
        $selector = trim((string) ($probe['selector'] ?? ''));
        return '' !== $selector ? $selector : (string) ($probe['tag'] ?? '');
    }

    /** @param array<string, mixed> $probe */
    private function normalizedText(array $probe): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', (string) ($probe['text'] ?? '')) ?? ''));
    }
}
