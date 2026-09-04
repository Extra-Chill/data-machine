<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Resolves Figma Dev Mode "Ready for dev" / "Completed" status from the raw
 * Kiwi-decoded scenegraph fields.
 *
 * The public Figma REST `devStatus` is a projection of internal Kiwi schema
 * state. The `.fig` schema (version 106) carries the same signal under internal
 * names on `NodeChange`/section nodes and a file-level handoff map:
 *
 *   - `sectionStatus` / `sectionStatusInfo` (type `SectionStatusInfo`) —
 *     Ready-for-dev status on SECTION nodes.
 *   - `handoffStatus` (type `HandoffStatusMap` of `HandoffStatusMapEntry`) —
 *     dev-handoff state keyed by node.
 *   - `NodeStatusChange` with `currentStatus` / `statusInfo`.
 *
 * The `SectionStatus` enum carries tokens `NONE`, `BUILD`, and `COMPLETED`
 * (`BUILD` is Figma's internal name for the public "Ready for dev" status).
 * These are normalized onto a clean public value while the raw internal token
 * is kept for auditability. The class never invents a normalized value: when an
 * enum token is not in the known map, the raw token is carried and the
 * normalized value stays null.
 */
final class ScenegraphDevStatus
{
    public const READY_FOR_DEV = 'ready_for_dev';
    public const COMPLETED     = 'completed';

    /**
     * Internal Kiwi enum tokens → normalized public dev status.
     *
     * The `SectionStatus` enum has exactly three members: `NONE`, `BUILD`,
     * `COMPLETED` (verified against real `.fig` schema + the FSE Pilot fixture
     * decoded on the lab). Figma's internal token for the public "Ready for dev"
     * status is `BUILD`; `COMPLETED` is "Completed"; `NONE` is unset. The
     * public-API names (`READY_FOR_DEV`) are kept as defensive aliases for
     * REST/plugin-sourced scenegraphs. Tokens outside this map (e.g. section
     * TYPE values like `DEV_HANDOFF`, which are not statuses) carry their raw
     * value with a null normalized status rather than guessing.
     *
     * @var array<string, string>
     */
    private const ENUM_MAP = array(
        'BUILD'         => self::READY_FOR_DEV,
        'COMPLETED'     => self::COMPLETED,
        'READY_FOR_DEV' => self::READY_FOR_DEV,
    );

    /**
     * Status-bearing field names that may appear directly on a node.
     *
     * @var array<int, string>
     */
    private const STATUS_KEYS = array(
        'sectionStatus',
        'sectionStatusInfo',
        'handoffStatus',
        'currentStatus',
        'statusInfo',
        'devStatus',
    );

    /**
     * Nested keys that carry the status enum inside a status struct/entry.
     *
     * @var array<int, string>
     */
    private const NESTED_STATUS_KEYS = array(
        'status',
        'currentStatus',
        'statusInfo',
        'type',
        'value',
    );

    private const MAX_DEPTH = 5;

    /**
     * Resolve a node's dev status from its raw Kiwi-decoded fields.
     *
     * @param array<string, mixed> $node
     * @return array{normalized: ?string, raw: string}|null Null when the node carries no status field.
     */
    public static function resolve(array $node): ?array
    {
        $raw = self::extractRawToken($node, 0);
        if ( null === $raw ) {
            return null;
        }

        return array(
            'normalized' => self::ENUM_MAP[strtoupper($raw)] ?? null,
            'raw'        => $raw,
        );
    }

    /**
     * Normalize a single raw enum token in isolation.
     */
    public static function normalizeToken(string $token): ?string
    {
        $trimmed = trim($token);
        if ( '' === $trimmed ) {
            return null;
        }

        return self::ENUM_MAP[strtoupper($trimmed)] ?? null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function extractRawToken(array $node, int $depth): ?string
    {
        foreach ( self::STATUS_KEYS as $key ) {
            if ( ! array_key_exists($key, $node) ) {
                continue;
            }

            $token = self::tokenFromValue($node[$key], $depth + 1);
            if ( null !== $token ) {
                return $token;
            }
        }

        return null;
    }

    private static function tokenFromValue(mixed $value, int $depth): ?string
    {
        if ( $depth > self::MAX_DEPTH ) {
            return null;
        }

        if ( is_string($value) ) {
            $trimmed = trim($value);
            return '' === $trimmed ? null : $trimmed;
        }

        if ( is_int($value) ) {
            // An enum value the generic decoder could not map to a name still
            // carries a real signal; keep the numeric token as a string.
            return (string) $value;
        }

        if ( ! is_array($value) ) {
            return null;
        }

        foreach ( self::NESTED_STATUS_KEYS as $key ) {
            if ( array_key_exists($key, $value) ) {
                $token = self::tokenFromValue($value[$key], $depth + 1);
                if ( null !== $token ) {
                    return $token;
                }
            }
        }

        // Handoff maps store entries as a list; dig each entry by status keys.
        foreach ( $value as $entry ) {
            if ( is_array($entry) ) {
                $token = self::tokenFromValue($entry, $depth + 1);
                if ( null !== $token ) {
                    return $token;
                }
            }
        }

        return null;
    }
}
