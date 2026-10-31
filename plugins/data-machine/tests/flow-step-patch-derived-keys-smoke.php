<?php
/**
 * Behavioral smoke for sparse handler-config patch derived keys (#3449).
 *
 * Run with: php tests/flow-step-patch-derived-keys-smoke.php
 *
 * Pins three behaviours of prepareHandlerConfigPatch():
 *
 *   1. A settings class that derives a key the caller did not pass (term ID
 *      resolution) declares it via derived_fields(); the derived key survives
 *      the sparse-patch intersect on both the create and update paths.
 *   2. A sparse patch of one key still does not overwrite a different stored
 *      key with its sanitize-time default (the original intersect intent).
 *   3. A settings class WITHOUT derived_fields() keeps legacy behaviour: its
 *      derived keys are dropped (the contract is strictly opt-in).
 *
 * @package DataMachine\Tests
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$GLOBALS['derived_keys_smoke_flow']   = array();
	$GLOBALS['derived_keys_smoke_logs']   = array();
	$GLOBALS['derived_keys_smoke_writes'] = array();

	class WP_Error {}

	function apply_filters( $hook, $value, ...$args ) {
		if ( 'datamachine_split_flow_step_id' === $hook ) {
			return array(
				'pipeline_step_id' => 'import_1',
				'flow_id'          => 900,
			);
		}
		return $value;
	}

	function do_action( $hook, ...$args ): void {
		if ( 'datamachine_log' === $hook ) {
			$GLOBALS['derived_keys_smoke_logs'][] = $args;
		}
	}
}

namespace DataMachine\Core\Database\Flows {
	class Flows {
		public function get_flow( $flow_id ) {
			return $GLOBALS['derived_keys_smoke_flow'];
		}

		public function update_flow( $flow_id, $data ) {
			$GLOBALS['derived_keys_smoke_writes'][] = $data;
			$GLOBALS['derived_keys_smoke_flow']['flow_config'] = $data['flow_config'];
			return true;
		}
	}
}

namespace DataMachine\Core\Database\Pipelines {
	class Pipelines {}
}

namespace DataMachine\Abilities {
	class AbilityRegistration {
		public static function on_abilities_api_init( callable $callback ): void {}
	}

	class DerivedResolvingSettings {
		public static function get_fields(): array {
			return array(
				'name'        => array( 'type' => 'text' ),
				'limit'       => array( 'type' => 'number', 'default' => 10 ),
				'resolved_id' => array( 'type' => 'number' ),
			);
		}

		public static function sanitize( array $raw ): array {
			return array(
				'name'        => (string) ( $raw['name'] ?? '' ),
				'limit'       => (int) ( $raw['limit'] ?? 10 ),
				'resolved_id' => 42,
			);
		}

		public static function derived_fields(): array {
			return array( 'resolved_id' );
		}
	}

	class LegacyDerivingSettings {
		public static function get_fields(): array {
			return array(
				'name'  => array( 'type' => 'text' ),
				'limit' => array( 'type' => 'number', 'default' => 10 ),
			);
		}

		public static function sanitize( array $raw ): array {
			return array(
				'name'        => (string) ( $raw['name'] ?? '' ),
				'limit'       => (int) ( $raw['limit'] ?? 10 ),
				'resolved_id' => 42,
			);
		}

		// No derived_fields(): pre-contract settings class.
	}

	class HandlerAbilities {
		public function getSettingsClass( $slug ) {
			if ( 'derived_api' === $slug ) {
				return new DerivedResolvingSettings();
			}
			if ( 'legacy_api' === $slug ) {
				return new LegacyDerivingSettings();
			}
			return null;
		}

		public function getConfigFields( $slug ): array {
			if ( 'derived_api' === $slug ) {
				return DerivedResolvingSettings::get_fields();
			}
			if ( 'legacy_api' === $slug ) {
				return LegacyDerivingSettings::get_fields();
			}
			return array();
		}

		public function applyDefaults( $slug, array $config ): array {
			$complete = array();
			foreach ( $this->getConfigFields( $slug ) as $key => $field ) {
				if ( array_key_exists( $key, $config ) ) {
					$complete[ $key ] = $config[ $key ];
				} elseif ( isset( $field['default'] ) ) {
					$complete[ $key ] = $field['default'];
				}
			}
			return $complete;
		}
	}

	class PermissionHelper {}
}

namespace DataMachine\Core\Steps {
	class FlowStepConfig {
		public static function usesHandler( array $step ): bool {
			return true;
		}

		public static function getEffectiveSlug( array $step, string $fallback = '' ): string {
			return '' !== $fallback ? $fallback : ( $step['handler_slugs'][0] ?? '' );
		}

		public static function getHandlerConfigForSlug( array $step, string $slug ): array {
			return $step['handler_configs'][ $slug ] ?? array();
		}

		public static function getPrimaryHandlerSlug( array $step ): ?string {
			return $step['handler_slugs'][0] ?? null;
		}

		public static function getHandlerSlugs( array $step ): array {
			return $step['handler_slugs'] ?? array();
		}

		public static function getHandlerConfigs( array $step ): array {
			return $step['handler_configs'] ?? array();
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Abilities/FlowStep/FlowStepHelpers.php';
	require_once dirname( __DIR__ ) . '/inc/Abilities/FlowStep/UpdateFlowStepAbility.php';

	$failures = array();
	$passes   = 0;

	function derived_keys_assert_same( $expected, $actual, string $name ): void {
		global $failures, $passes;
		if ( $expected === $actual ) {
			++$passes;
			return;
		}
		$failures[] = $name;
		fwrite( STDERR, "FAIL: {$name}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
	}

	function derived_keys_assert_false( bool $condition, string $name ): void {
		global $failures, $passes;
		if ( ! $condition ) {
			++$passes;
			return;
		}
		$failures[] = $name;
		fwrite( STDERR, "FAIL: {$name}\n" );
	}

	function derived_keys_reset_flow( array $flow_config = array() ): void {
		$GLOBALS['derived_keys_smoke_flow'] = array(
			'flow_id'     => 900,
			'pipeline_id' => 300,
			'flow_config' => $flow_config,
		);
		$GLOBALS['derived_keys_smoke_writes'] = array();
		$GLOBALS['derived_keys_smoke_logs']   = array();
	}

	function derived_keys_stored_config(): array {
		$step = $GLOBALS['derived_keys_smoke_flow']['flow_config']['import_1_900'] ?? array();
		return $step['handler_configs']['derived_api']
			?? $step['handler_configs']['legacy_api']
			?? array();
	}

	$ability = new \DataMachine\Abilities\FlowStep\UpdateFlowStepAbility();

	// ─── Scenario 1: create path stores sanitizer-derived keys ──────────

	// Step skeleton as left by pipeline sync before any handler config write.
	derived_keys_reset_flow(
		array(
			'import_1_900' => array(
				'flow_step_id'     => 'import_1_900',
				'pipeline_step_id' => 'import_1',
				'pipeline_id'      => 300,
				'flow_id'          => 900,
				'step_type'        => 'event_import',
				'enabled'          => false,
			),
		)
	);
	$create = $ability->execute(
		array(
			'flow_step_id'   => 'import_1_900',
			'handler_slug'   => 'derived_api',
			'handler_config' => array( 'name' => 'x' ),
		)
	);
	derived_keys_assert_same( true, $create['success'] ?? false, 'create succeeds' );
	derived_keys_assert_same( 'x', derived_keys_stored_config()['name'] ?? null, 'create stores patched key' );
	derived_keys_assert_same( 10, derived_keys_stored_config()['limit'] ?? null, 'create fills schema default' );
	derived_keys_assert_same( 42, derived_keys_stored_config()['resolved_id'] ?? null, 'create stores sanitizer-derived key (#3449)' );

	// ─── Scenario 2: sparse patch preserves non-default stored values ───

	derived_keys_reset_flow(
		array(
			'import_1_900' => array(
				'flow_step_id'    => 'import_1_900',
				'pipeline_step_id' => 'import_1',
				'pipeline_id'     => 300,
				'flow_id'         => 900,
				'step_type'       => 'event_import',
				'handler_slugs'   => array( 'derived_api' ),
				'handler_configs' => array(
					'derived_api' => array(
						'name'        => 'orig',
						'limit'       => 99,
						'resolved_id' => 42,
					),
				),
			),
		)
	);

	$before = serialize( $GLOBALS['derived_keys_smoke_flow'] );
	$preview = $ability->execute(
		array(
			'flow_step_id'  => 'import_1_900',
			'handler_config' => array( 'name' => 'updated' ),
			'validate_only' => true,
		)
	);
	derived_keys_assert_same( array(), $GLOBALS['derived_keys_smoke_writes'], 'dry-run performs no persistence' );
	derived_keys_assert_same( $before, serialize( $GLOBALS['derived_keys_smoke_flow'] ), 'dry-run leaves storage byte-identical' );
	derived_keys_assert_same( 99, $preview['effective_handler_config']['limit'] ?? null, 'dry-run preview keeps non-default stored limit' );
	derived_keys_assert_same( 42, $preview['effective_handler_config']['resolved_id'] ?? null, 'dry-run preview keeps derived key' );

	$applied = $ability->execute(
		array(
			'flow_step_id'   => 'import_1_900',
			'handler_config' => array( 'name' => 'updated' ),
		)
	);
	derived_keys_assert_same( true, $applied['success'] ?? false, 'apply succeeds' );
	derived_keys_assert_same( 'updated', derived_keys_stored_config()['name'] ?? null, 'patched key updated' );
	derived_keys_assert_same( 99, derived_keys_stored_config()['limit'] ?? null, 'sparse patch does not overwrite stored key with default' );
	derived_keys_assert_same( 42, derived_keys_stored_config()['resolved_id'] ?? null, 'derived key survives sparse patch' );

	// ─── Scenario 3: legacy settings class keeps old intersect behaviour ─

	derived_keys_reset_flow(
		array(
			'import_1_900' => array(
				'flow_step_id'    => 'import_1_900',
				'pipeline_step_id' => 'import_1',
				'pipeline_id'     => 300,
				'flow_id'         => 900,
				'step_type'       => 'event_import',
				'handler_slugs'   => array( 'legacy_api' ),
				'handler_configs' => array(
					'legacy_api' => array(
						'name'  => 'orig',
						'limit' => 99,
					),
				),
			),
		)
	);

	$legacy = $ability->execute(
		array(
			'flow_step_id'   => 'import_1_900',
			'handler_config' => array( 'name' => 'updated' ),
		)
	);
	derived_keys_assert_same( true, $legacy['success'] ?? false, 'legacy apply succeeds' );
	derived_keys_assert_same( 'updated', derived_keys_stored_config()['name'] ?? null, 'legacy patched key updated' );
	derived_keys_assert_same( 99, derived_keys_stored_config()['limit'] ?? null, 'legacy sparse patch still preserves non-default stored value' );
	derived_keys_assert_false(
		array_key_exists( 'resolved_id', derived_keys_stored_config() ),
		'legacy settings class without derived_fields() still drops derived keys'
	);

	$error_logs = array_filter(
		$GLOBALS['derived_keys_smoke_logs'],
		static fn( array $log ): bool => in_array( $log[0] ?? '', array( 'error', 'warning' ), true )
	);
	derived_keys_assert_same( array(), array_values( $error_logs ), 'no error or warning logs emitted' );

	if ( ! empty( $failures ) ) {
		exit( 1 );
	}

	echo "{$passes} handler-config derived-keys assertions passed.\n";
}
