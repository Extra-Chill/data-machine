<?php
/**
 * Legacy Handler Shape Migrator
 *
 * Operator-invoked one-shot migrator that rewrites stored `flow_config` JSON
 * from the pre-1.0 scalar handler shape (`handler`, `handler_slug`,
 * `handler_config`) into the canonical shape (`handler_slugs`,
 * `handler_configs`, `flow_step_settings`).
 *
 * This class intentionally lives beside the `flows migrate-legacy-handler-shape`
 * WP-CLI command. Runtime readers, writers, validators, importers, and step
 * config factories must stay canonical-only; this is not a compatibility layer.
 *
 * @package DataMachine\Cli\Commands\Flows
 * @since 0.124.6
 */

namespace DataMachine\Cli\Commands\Flows;

use DataMachine\Core\Steps\FlowStepConfig;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrite legacy scalar handler shapes inside `flow_config` JSON into the
 * canonical `handler_slugs` / `handler_configs` / `flow_step_settings` shape.
 *
 * @internal Operator repair helper for `wp datamachine flows migrate-legacy-handler-shape` only.
 */
class LegacyHandlerShapeMigrator {

	/**
	 * Migrate a single decoded `flow_config` array in place.
	 *
	 * Returns a report describing what changed. The migrator never invents
	 * handler data: if a step is in handler-free territory (or has no
	 * resolvable slug to lift), legacy keys are dropped without synthesising
	 * a new slug list. Callers can opt into stricter behaviour by inspecting
	 * the report's `dropped_orphan_legacy_config` counter.
	 *
	 * @param array<string, mixed> $flow_config Decoded `flow_config` JSON keyed by flow step ID.
	 * @return array{
	 *     changed: bool,
	 *     steps_migrated: int,
	 *     steps_already_canonical: int,
	 *     steps_skipped_non_step: int,
	 *     dropped_orphan_legacy_config: int,
	 *     migrated_step_ids: array<int, string>,
	 *     config: array<string, mixed>
	 * }
	 */
	public static function migrate_flow_config( array $flow_config ): array {
		$report = array(
			'changed'                      => false,
			'steps_migrated'               => 0,
			'steps_already_canonical'      => 0,
			'steps_skipped_non_step'       => 0,
			'dropped_orphan_legacy_config' => 0,
			'migrated_step_ids'            => array(),
			'config'                       => $flow_config,
		);

		foreach ( $flow_config as $step_id => $step ) {
			// Flow-level metadata keys (e.g. memory_files) live alongside steps but are not steps themselves.
			if ( ! is_array( $step ) || ! isset( $step['step_type'] ) ) {
				++$report['steps_skipped_non_step'];
				continue;
			}

			$has_legacy = array_key_exists( 'handler', $step )
				|| array_key_exists( 'handler_slug', $step )
				|| array_key_exists( 'handler_config', $step );

			if ( ! $has_legacy ) {
				++$report['steps_already_canonical'];
				continue;
			}

			$report['config'][ $step_id ] = self::migrate_step( $step, $report );
			$report['changed']            = true;
			++$report['steps_migrated'];
			$report['migrated_step_ids'][] = (string) $step_id;
		}

		return $report;
	}

	/**
	 * Migrate a single step array.
	 *
	 * Lifts scalar `handler` / `handler_slug` into `handler_slugs[]` and scalar
	 * `handler_config` into either `handler_configs[<slug>]` (handler-backed
	 * steps) or `flow_step_settings` (handler-free steps), then strips the
	 * legacy keys via {@see FlowStepConfig::normalizeHandlerShape()}.
	 *
	 * @param array<string, mixed>                  $step   Step config row.
	 * @param array{dropped_orphan_legacy_config:int,...} $report In/out report counters.
	 * @return array<string, mixed> Migrated step config row.
	 */
	private static function migrate_step( array $step, array &$report ): array {
		$legacy_slug   = self::extract_legacy_slug( $step );
		$legacy_config = is_array( $step['handler_config'] ?? null ) ? $step['handler_config'] : array();

		$uses_handler = FlowStepConfig::usesHandler( $step );

		if ( $uses_handler ) {
			$handler_slugs   = is_array( $step['handler_slugs'] ?? null ) ? array_values( $step['handler_slugs'] ) : array();
			$handler_configs = is_array( $step['handler_configs'] ?? null ) ? $step['handler_configs'] : array();

			if ( '' !== $legacy_slug && ! in_array( $legacy_slug, $handler_slugs, true ) ) {
				$handler_slugs[] = $legacy_slug;
			}

			if ( '' !== $legacy_slug && ! empty( $legacy_config ) && ! array_key_exists( $legacy_slug, $handler_configs ) ) {
				$handler_configs[ $legacy_slug ] = $legacy_config;
			}

			// Orphan: legacy_config but no slug we can attribute it to. Track it; do not invent a slot.
			if ( '' === $legacy_slug && ! empty( $legacy_config ) ) {
				++$report['dropped_orphan_legacy_config'];
			}

			$step['handler_slugs']   = $handler_slugs;
			$step['handler_configs'] = $handler_configs;
		} else {
			// Handler-free step: lift legacy_config into flow_step_settings only if the canonical slot is empty.
			$existing_settings = is_array( $step['flow_step_settings'] ?? null ) ? $step['flow_step_settings'] : array();

			if ( ! empty( $legacy_config ) && empty( $existing_settings ) ) {
				$step['flow_step_settings'] = $legacy_config;
			} elseif ( ! empty( $legacy_config ) && ! empty( $existing_settings ) ) {
				// Both slots present: canonical wins; the redundant legacy copy is dropped.
				++$report['dropped_orphan_legacy_config'];
			}

			// A handler-free step with a legacy `handler_slug` (e.g. an older synthetic
			// `handler_slugs => [step_type]` rewrite from #712) carried no real handler.
			// We do not preserve it; the canonical handler-free shape has no slug list.
			if ( '' !== $legacy_slug ) {
				++$report['dropped_orphan_legacy_config'];
			}
		}

		// Strip every legacy key plus reapply canonical normalization.
		return FlowStepConfig::normalizeHandlerShape( $step );
	}

	/**
	 * Extract a legacy scalar handler slug, preferring `handler_slug` over `handler`.
	 *
	 * @param array<string, mixed> $step Step config row.
	 * @return string Legacy slug, or empty string when none is present/string.
	 */
	private static function extract_legacy_slug( array $step ): string {
		foreach ( array( 'handler_slug', 'handler' ) as $key ) {
			if ( isset( $step[ $key ] ) && is_string( $step[ $key ] ) && '' !== $step[ $key ] ) {
				return $step[ $key ];
			}
		}
		return '';
	}
}
