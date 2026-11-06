<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

final class DeterministicRowDeduplicator
{
    /**
     * Retains first occurrences in input order, using JSON bytes as row identity.
     * Rows that cannot be JSON encoded are excluded.
     *
     * @param array<int, mixed> $rows
     * @return array<int, mixed>
     */
    public static function dedupe(array $rows): array
    {
        $seen = array();
        $deduped = array();
        foreach ( $rows as $row ) {
            $key = json_encode($row, JSON_UNESCAPED_SLASHES);
            if ( ! is_string($key) || isset($seen[$key]) ) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $row;
        }

        return $deduped;
    }
}
