<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

use InvalidArgumentException;

/**
 * Versioned contract for the generic conversion-finding shape emitted by the
 * transformer's diagnostic producers.
 *
 * The transformer surfaces conversion findings/diagnostics from several
 * producers — the fallback emitter, the flat diagnostics collector, the
 * semantic-parity reporter, the runtime-dependency parity report, and the
 * conversion-report projection. Historically each producer emitted a loose
 * array with informally matched keys, with no formal schema, which is a
 * silent-drift risk: a producer can rename a value, drop the identifier, or
 * emit an out-of-band severity and nothing fails.
 *
 * This contract formalizes that shape. It is intentionally TOLERANT: it
 * captures only the invariants that actually hold across every finding produced
 * today, so existing valid findings keep validating. It mirrors
 * {@see VisualParityReportContract} (a `SCHEMA` version constant plus static
 * `assert*()` methods that throw {@see InvalidArgumentException}).
 *
 * Boundary note: this is blocks-engine's OWN generic output schema. It carries
 * no knowledge of any consumer — producers emit findings conforming to this
 * shape and downstream consumers adapt to it. Nothing here references a consumer
 * concept.
 *
 * Reality of the finding shape (the union observed across producers):
 *  - A stable identifier is the ONLY universally present field. Producers carry
 *    it under `code` (diagnostics collector, semantic parity, runtime
 *    dependency parity) OR `diagnostic_code` (fallback emitter, conversion
 *    report fallback projection). At least one, non-empty, is REQUIRED.
 *  - `severity`, when present, is drawn from a closed set; this is the highest
 *    value invariant the contract guards against drift.
 *  - A human-readable descriptor is carried under `message` OR `summary`; it is
 *    present on the producer-owned findings but absent from the compact
 *    conversion-report fallback projection, so it is OPTIONAL (type-checked when
 *    present).
 *  - Everything else (provenance, classification, repair guidance, structural
 *    counts, nested context) is OPTIONAL and producer-specific; well-known
 *    fields are type-checked when present, and unknown fields are tolerated so
 *    producers can carry additive metadata without breaking the contract.
 */
final class ConversionFindingContract
{
    public const SCHEMA = 'blocks-engine/php-transformer/conversion-finding/v1';

    /**
     * Closed set of finding severities. Forward-compatible with
     * {@see VisualParityReportContract}; producers emit `info` and `warning`
     * today, with the rest reserved.
     */
    private const SEVERITIES = array('none', 'info', 'warning', 'error', 'critical');

    /**
     * Identifier keys, in resolution priority. A finding must carry a non-empty
     * string under at least one of these.
     */
    private const CODE_KEYS = array('code', 'diagnostic_code');

    /**
     * Human-readable descriptor keys. Optional; type-checked when present.
     */
    private const MESSAGE_KEYS = array('message', 'summary');

    /**
     * Well-known optional scalar string fields, validated for type only when
     * present. Unknown fields are tolerated.
     *
     * @var array<int, string>
     */
    private const STRING_FIELDS = array(
        'message', 'summary', 'source', 'source_format', 'scope',
        'reason', 'reason_code', 'tag', 'kind',
        'selector', 'source_selector', 'source_path',
        'pattern_family', 'pattern_family_detail', 'parent_reason', 'ancestor_reason',
        'conversion_classification', 'loss_class', 'diagnostic_class', 'preservation_strategy',
        'runtime_requirement', 'recoverability', 'actionability',
        'repair_bucket', 'suggested_repair_class', 'suggested_generic_repair_class',
        'suggested_primitive', 'materialization_hint', 'runtime_island_type',
        'script_role', 'block_name', 'path',
    );

    /**
     * Well-known optional array (object/list) fields, validated for type only
     * when present.
     *
     * @var array<int, string>
     */
    private const ARRAY_FIELDS = array(
        'source_selector_specificity', 'context', 'events', 'classification',
        'controls', 'control', 'form', 'readable_blocks', 'signals', 'materialization_target', 'products',
        'source_items', 'block_items', 'source_item', 'block_item',
    );

    /**
     * Validate a single conversion finding against the contract.
     *
     * @param array<string, mixed> $finding
     */
    public static function assertFinding(array $finding, string $label = 'conversion finding'): void
    {
        if ( '' === self::findingCode($finding) ) {
            throw new InvalidArgumentException(sprintf('%s is missing a non-empty "code"/"diagnostic_code" identifier.', ucfirst($label)));
        }

        // Optional fields may be carried as an explicit `null` placeholder: the
        // flat diagnostics projection emits a fixed key set with `?? null` for
        // fields that do not apply to a given finding, so `null` is a legitimate
        // emitted value and is tolerated wherever a typed value is also allowed.
        if ( array_key_exists('severity', $finding) && null !== $finding['severity'] && ! in_array($finding['severity'], self::SEVERITIES, true) ) {
            throw new InvalidArgumentException(sprintf('%s has an unsupported severity.', ucfirst($label)));
        }

        foreach ( self::STRING_FIELDS as $field ) {
            if ( array_key_exists($field, $finding) && null !== $finding[$field] && ! is_string($finding[$field]) ) {
                throw new InvalidArgumentException(sprintf('%s field "%s" must be a string.', ucfirst($label), $field));
            }
        }

        foreach ( self::ARRAY_FIELDS as $field ) {
            if ( array_key_exists($field, $finding) && null !== $finding[$field] && ! is_array($finding[$field]) ) {
                throw new InvalidArgumentException(sprintf('%s field "%s" must be an array.', ucfirst($label), $field));
            }
        }

        // `observed_block` is the one field producers emit as either a string
        // ("none") or an array (the observed block payload).
        if ( array_key_exists('observed_block', $finding) && null !== $finding['observed_block'] && ! is_string($finding['observed_block']) && ! is_array($finding['observed_block']) ) {
            throw new InvalidArgumentException(sprintf('%s field "observed_block" must be a string or an array.', ucfirst($label)));
        }
    }

    /**
     * Validate every entry in a list of conversion findings.
     *
     * @param array<int, mixed> $findings
     */
    public static function assertFindings(array $findings, string $label = 'conversion findings'): void
    {
        if ( array_values($findings) !== $findings ) {
            throw new InvalidArgumentException(sprintf('%s must be a list.', ucfirst($label)));
        }

        foreach ( $findings as $index => $finding ) {
            if ( ! is_array($finding) ) {
                throw new InvalidArgumentException(sprintf('%s.%d must be an object.', $label, $index));
            }

            self::assertFinding($finding, sprintf('%s.%d', $label, $index));
        }
    }

    /**
     * Canonical classification fields. Every emitted finding is expected to carry
     * a non-empty value for each of these so downstream tooling can cluster by
     * ROOT cause and route to a repair lane instead of bucketing the finding into
     * a generic catch-all. They are derived purely from the structural/semantic
     * signals a finding already carries — never from fixture names, specific class
     * strings, or URLs.
     *
     * @var array<int, string>
     */
    public const CLASSIFICATION_FIELDS = array('reason_code', 'repair_bucket', 'pattern_family');

    /**
     * Derive the canonical classification triplet (`reason_code`,
     * `pattern_family`, `repair_bucket`) for a finding from the signals it already
     * carries. This is the single source of truth for the finding taxonomy: every
     * producer flows its emitted findings through {@see withClassification()} so a
     * given root cause clusters under one stable code/family/bucket regardless of
     * which producer surfaced it.
     *
     * Genericity contract: derivation reads only structural/semantic signals
     * (the stable identifier, the source `tag`/`target_kind`, the runtime `kind`,
     * the `canvas_api` flag, and the producer's own repair hints). Per-instance
     * noise (specific selectors, classes, URLs) is intentionally NOT folded into
     * the clustering keys — it lives in detail fields (`pattern_family_detail`,
     * `selector`, `source_selector`) so the cluster keys stay root-scoped.
     *
     * @param array<string, mixed> $finding
     * @return array{reason_code?: string, pattern_family?: string, repair_bucket?: string}
     */
    public static function classify(array $finding): array
    {
        $reasonCode    = self::reasonCode($finding);
        $patternFamily = self::patternFamily($finding);
        $repairBucket  = self::repairBucket($finding, $patternFamily);

        return array_filter(
            array(
                'reason_code'    => $reasonCode,
                'pattern_family' => $patternFamily,
                'repair_bucket'  => $repairBucket,
            ),
            static fn (mixed $value): bool => is_string($value) && '' !== $value
        );
    }

    /**
     * Return the finding enriched with any missing canonical classification
     * fields. Values a producer already set (e.g. the fallback emitter's richer
     * `pattern_family`, or the runtime-dependency report's specific
     * `repair_bucket`) are authoritative and never overwritten — this only fills
     * the gaps that would otherwise leave a finding classification-less.
     *
     * @param array<string, mixed> $finding
     * @return array<string, mixed>
     */
    public static function withClassification(array $finding): array
    {
        $classification = self::classify($finding);
        foreach ( $classification as $field => $value ) {
            if ( ! isset($finding[$field]) || ! is_string($finding[$field]) || '' === trim($finding[$field]) ) {
                $finding[$field] = $value;
            }
        }

        return $finding;
    }

    /**
     * The stable root identifier used as `reason_code`: an existing `reason_code`,
     * then the producer's `diagnostic_code`/`code`. These identifiers are
     * root-scoped (one per loss type), not per-instance, so they double as the
     * primary clustering key.
     *
     * @param array<string, mixed> $finding
     */
    private static function reasonCode(array $finding): string
    {
        $existing = $finding['reason_code'] ?? null;
        if ( is_string($existing) && '' !== trim($existing) ) {
            return (string) $existing;
        }

        return self::findingCode($finding);
    }

    /**
     * Derive the root structural family for a finding. Honors an existing
     * `pattern_family` (the fallback emitter computes a richer, tag-aware value),
     * otherwise maps the runtime `kind`, the `canvas_api` flag, the stable
     * identifier namespace, and finally the source `tag` onto a stable family.
     *
     * @param array<string, mixed> $finding
     */
    private static function patternFamily(array $finding): string
    {
        $existing = $finding['pattern_family'] ?? null;
        if ( is_string($existing) && '' !== trim($existing) ) {
            return (string) $existing;
        }

        $code = self::reasonCode($finding);
        $kind = strtolower(trim((string) ($finding['kind'] ?? '')));
        $tag  = strtolower(trim((string) ($finding['tag'] ?? ($finding['target_kind'] ?? ''))));

        if ( true === ( $finding['canvas_api'] ?? false ) || 'canvas' === $kind ) {
            return 'runtime_canvas';
        }

        $byKind = match ( $kind ) {
            'script'   => 'runtime_script',
            'template' => 'runtime_template',
            'form', 'control' => 'interactive_form',
            default    => '',
        };
        if ( '' !== $byKind ) {
            return $byKind;
        }

        if ( str_starts_with($code, 'wp_block_validity_') ) {
            return 'block_serialization';
        }

        if ( str_starts_with($code, 'html_semantic_parity_') || in_array($code, array('landmark_count_mismatch', 'navigation_menu_missing', 'navigation_core_block_missing', 'navigation_item_count_mismatch', 'navigation_item_mismatch'), true) ) {
            return self::semanticParityFamily($code . ' ' . (string) ($finding['kind'] ?? ''));
        }

        return match ( $code ) {
            'runtime_dependency_target_missing' => 'runtime_dom_target',
            'runtime_script_not_materialized'   => 'runtime_script',
            'runtime_script_target_missing'     => 'runtime_interactive_behavior',
            'preserved_runtime_island'          => 'runtime_island',
            'html_static_script_metadata'       => 'static_script_metadata',
            'html_to_blocks_core_slice'         => 'conversion_summary',
            default                             => '' !== $tag ? 'html_' . $tag : ( '' !== $code ? $code : 'html_fallback' ),
        };
    }

    /**
     * Sub-family for a semantic-parity finding, keyed on the structural concept it
     * compares (landmark, navigation, typography) rather than the specific element.
     */
    private static function semanticParityFamily(string $haystack): string
    {
        $haystack = strtolower($haystack);
        if ( str_contains($haystack, 'landmark') ) {
            return 'semantic_landmark';
        }
        if ( str_contains($haystack, 'navigation') || str_contains($haystack, 'nav') ) {
            return 'navigation_menu';
        }
        if ( str_contains($haystack, 'typography') || str_contains($haystack, 'font') ) {
            return 'typography';
        }

        return 'semantic_structure';
    }

    /**
     * Derive the coarse remediation lane (`repair_bucket`). Honors an existing
     * `repair_bucket`, then the producer's own repair hints
     * (`suggested_repair_class` / `suggested_generic_repair_class`), then maps the
     * structural `pattern_family` onto a stable repair lane. Buckets for the
     * runtime families intentionally match the values the runtime-dependency
     * parity report already emits so the taxonomy stays consistent across
     * producers.
     *
     * @param array<string, mixed> $finding
     */
    private static function repairBucket(array $finding, string $patternFamily): string
    {
        foreach ( array('repair_bucket', 'suggested_repair_class', 'suggested_generic_repair_class') as $key ) {
            $value = $finding[$key] ?? null;
            if ( is_string($value) && '' !== trim($value) ) {
                return (string) $value;
            }
        }

        return match ( $patternFamily ) {
            'block_serialization'           => 'block_serialization_validity_repair',
            'semantic_landmark', 'navigation_menu', 'semantic_structure' => 'semantic_structure_parity_restoration',
            'typography'                    => 'typography_parity_restoration',
            'runtime_canvas'                => 'runtime_canvas_target_preservation',
            'runtime_dom_target'            => 'runtime_dom_target_preservation',
            'runtime_script'                => 'runtime_script_materialization',
            'runtime_interactive_behavior'  => 'runtime_interactive_behavior_restoration',
            'commerce_product_grid'         => 'materialize_commerce_products',
            'commerce_controls'             => 'materialize_commerce_runtime',
            'interactive_control'           => 'restore_interactive_behavior',
            'runtime_template', 'runtime_island', 'interactive_form' => 'preserve_runtime_island',
            'inert_template_metadata', 'static_script_metadata' => 'preserve_static_metadata',
            'inline_svg'                    => 'materialize_static_asset',
            'conversion_summary'            => 'no_repair_needed',
            default                         => str_starts_with($patternFamily, 'unsupported_')
                ? 'add_generic_pattern_recognizer'
                : 'review_generic_mapping',
        };
    }

    /**
     * Resolve a finding's stable identifier, honoring the `code` /
     * `diagnostic_code` producer split. Returns '' when neither is a non-empty
     * string.
     *
     * @param array<string, mixed> $finding
     */
    public static function findingCode(array $finding): string
    {
        foreach ( self::CODE_KEYS as $key ) {
            if ( is_string($finding[$key] ?? null) && '' !== trim($finding[$key]) ) {
                return (string) $finding[$key];
            }
        }

        return '';
    }

    /**
     * Whether a value is a conversion finding that satisfies the contract.
     * Tolerant predicate for walking heterogeneous diagnostic collections.
     */
    public static function isFinding(mixed $finding): bool
    {
        if ( ! is_array($finding) ) {
            return false;
        }

        try {
            self::assertFinding($finding);
        } catch ( InvalidArgumentException ) {
            return false;
        }

        return true;
    }
}
