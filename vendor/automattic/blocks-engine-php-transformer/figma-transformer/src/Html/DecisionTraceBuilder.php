<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Builds stable decision traces for diagnostics without changing trace schema.
 */
final class DecisionTraceBuilder
{
    /**
     * @param array<string, array<string, mixed>> $traces
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, mixed> $evidence
     * @param callable(array<string, mixed>): string|null $classResolver
     */
    public static function recordEmitterTrace(array &$traces, string $domain, string $reasonCode, array $node, string $decision, ?array $parentNode, array $evidence, string $currentPagePath, ?callable $classResolver = null): void
    {
        if ( '' === $reasonCode ) {
            $reasonCode = 'unknown';
        }

        $nodeId = (string) ($node['id'] ?? '');
        $parentId = null === $parentNode ? '' : (string) ($parentNode['id'] ?? '');
        $pagePath = (string) ($evidence['page_path'] ?? $currentPagePath);
        $key = implode('|', array($domain, $reasonCode, $decision, $nodeId, $parentId, $pagePath));
        if ( isset($traces[$key]) ) {
            $traces[$key]['count'] = (int) ($traces[$key]['count'] ?? 1) + 1;
            return;
        }

        $class = null;
        if ( null !== $classResolver && ('' !== $nodeId || ! empty($node['name'] ?? '')) ) {
            $class = $classResolver($node);
        }

        $traces[$key] = array_filter(array(
            'domain' => $domain,
            'reason_code' => $reasonCode,
            'decision' => $decision,
            'node_id' => $nodeId,
            'name' => (string) ($node['name'] ?? ''),
            'type' => strtoupper((string) ($node['type'] ?? '')),
            'class' => $class,
            'parent_id' => $parentId,
            'page_path' => $pagePath,
            'evidence' => self::boundedEvidence($evidence),
            'count' => 1,
        ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value);
    }

    /**
     * @param array<string, array<string, mixed>> $traces
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<int, string> $declarations
     * @param array<string, mixed> $evidence
     */
    public static function recordResponsiveTrace(array &$traces, array $node, ?array $parentNode, string $reasonCode, float $viewportWidth, array $declarations, string $class = '', array $evidence = array()): void
    {
        if ( '' === $reasonCode ) {
            $reasonCode = 'responsive_safety_override';
        }

        $nodeId = (string) ($node['id'] ?? '');
        $key = implode('|', array($reasonCode, $nodeId, (string) ($parentNode['id'] ?? ''), (string) $viewportWidth));
        if ( isset($traces[$key]) ) {
            $traces[$key]['count'] = (int) ($traces[$key]['count'] ?? 1) + 1;
            return;
        }

        $traces[$key] = array_filter(array(
            'domain' => 'responsive_decision',
            'reason_code' => $reasonCode,
            'decision' => 'emit_media_override',
            'node_id' => $nodeId,
            'name' => (string) ($node['name'] ?? ''),
            'type' => strtoupper((string) ($node['type'] ?? '')),
            'class' => $class,
            'parent_id' => null === $parentNode ? null : (string) ($parentNode['id'] ?? ''),
            'viewport_width' => $viewportWidth,
            'declarations' => array_values($declarations),
            'evidence' => self::boundedEvidence($evidence),
            'count' => 1,
        ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value);
    }

    /**
     * @param array<string, array<string, mixed>> $traces
     * @return array<string, mixed>
     */
    public static function summary(array $traces): array
    {
        $countsByReason = array();
        $countsByDomain = array();
        $samplesByDomain = array();
        foreach ( $traces as $trace ) {
            $reason = (string) ($trace['reason_code'] ?? 'unknown');
            $domain = (string) ($trace['domain'] ?? 'unknown');
            $countsByReason[$reason] = (int) ($countsByReason[$reason] ?? 0) + 1;
            $countsByDomain[$domain] = (int) ($countsByDomain[$domain] ?? 0) + 1;
            if ( ! isset($samplesByDomain[$domain]) ) {
                $samplesByDomain[$domain] = array();
            }
            if ( count($samplesByDomain[$domain]) < 20 ) {
                $samplesByDomain[$domain][] = $trace;
            }
        }
        ksort($countsByReason);
        ksort($countsByDomain);
        ksort($samplesByDomain);

        $samplesByReason = array();
        foreach ( $traces as $trace ) {
            $reason = (string) ($trace['reason_code'] ?? 'unknown');
            if ( isset($samplesByReason[$reason]) ) {
                continue;
            }

            $samplesByReason[$reason] = $trace;
        }
        ksort($samplesByReason);

        return array(
            'schema' => 'blocks-engine/figma-transformer/decision-traces/v1',
            'trace_count' => count($traces),
            'reason_counts' => $countsByReason,
            'domain_counts' => $countsByDomain,
            'samples' => array_slice(array_values($traces), 0, 100),
            'samples_by_reason' => $samplesByReason,
            'samples_by_domain' => $samplesByDomain,
        );
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    public static function boundedEvidence(array $evidence): array
    {
        unset($evidence['domain'], $evidence['reason_code'], $evidence['decision'], $evidence['node_id'], $evidence['name'], $evidence['type']);
        foreach ( $evidence as $key => $value ) {
            if ( is_array($value) ) {
                $evidence[$key] = array_slice($value, 0, 10);
            }
        }

        return array_filter($evidence, static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value);
    }
}
