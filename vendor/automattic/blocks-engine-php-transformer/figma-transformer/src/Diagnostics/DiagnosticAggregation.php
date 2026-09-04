<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Diagnostics;

/**
 * Small helpers for rolling page-local diagnostic counters and samples upward.
 */
final class DiagnosticAggregation
{
    /**
     * @param array<string, mixed>      $target
     * @param array<string, mixed>      $source
     * @param array<int, string>        $keys
     */
    public static function addIntegerCounts(array &$target, array $source, array $keys): void
    {
        foreach ( $keys as $key ) {
            $target[$key] = (int) ($target[$key] ?? 0) + (int) ($source[$key] ?? 0);
        }
    }

    /**
     * @param array<string, mixed>      $target
     * @param array<string, mixed>      $source
     * @param array<string, mixed>      $context
     */
    public static function appendContextSamples(array &$target, string $targetKey, array $source, string $sourceKey, array $context): void
    {
        $samples = is_array($source[$sourceKey] ?? null) ? $source[$sourceKey] : array();
        foreach ( $samples as $sample ) {
            if ( is_array($sample) ) {
                $target[$targetKey][] = array_merge($context, $sample);
            }
        }
    }

    /**
     * @param array<string, int>   $target
     * @param array<string, mixed> $source
     */
    public static function addCounterMap(array &$target, array $source): void
    {
        foreach ( $source as $key => $count ) {
            $target[(string) $key] = (int) ($target[(string) $key] ?? 0) + (int) $count;
        }
    }
}
