<?php
/**
 * Behavioral smoke for handler-config partial update parity (#3024).
 *
 * Run with: php tests/flow-update-handler-config-deep-merge-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$GLOBALS['handler_config_smoke_flow']   = array();
	$GLOBALS['handler_config_smoke_logs']   = array();
	$GLOBALS['handler_config_smoke_writes'] = array();

	class WP_Error {}

	function apply_filters( $hook, $value, ...$args ) {
		if ( 'datamachine_split_flow_step_id' === $hook ) {
			return array(
				'pipeline_step_id' => 'fetch_1',
				'flow_id'          => 806,
			);
		}
		return $value;
	}

	function do_action( $hook, ...$args ): void {
		if ( 'datamachine_log' === $hook ) {
			$GLOBALS['handler_config_smoke_logs'][] = $args;
		}
	}
}

namespace DataMachine\Core\Database\Flows {
	class Flows {
		public function get_flow( $flow_id ) {
			return $GLOBALS['handler_config_smoke_flow'];
		}

		public function update_flow( $flow_id, $data ) {
			$GLOBALS['handler_config_smoke_writes'][] = $data;
			$GLOBALS['handler_config_smoke_flow']['flow_config'] = $data['flow_config'];
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

	class TicketmasterLikeSettings {
		public static function sanitize( array $raw ): array {
			return array(
				'classification_type' => (string) ( $raw['classification_type'] ?? '' ),
				'location'            => (string) ( $raw['location'] ?? '' ),
				'radius'              => (string) ( $raw['radius'] ?? '50' ),
				'genre'               => (string) ( $raw['genre'] ?? '' ),
				'venue_id'            => (string) ( $raw['venue_id'] ?? '' ),
				'search'              => (string) ( $raw['search'] ?? '' ),
				'exclude_keywords'    => (string) ( $raw['exclude_keywords'] ?? '' ),
				'max_items'           => (int) ( $raw['max_items'] ?? 100 ),
				'params'              => is_array( $raw['params'] ?? null ) ? $raw['params'] : array(),
			);
		}
	}

	class HandlerAbilities {
		public function getSettingsClass( $slug ) {
			return new TicketmasterLikeSettings();
		}

		public function getConfigFields( $slug ): array {
			return array_fill_keys(
				array( 'classification_type', 'location', 'radius', 'genre', 'venue_id', 'search', 'exclude_keywords', 'max_items', 'params' ),
				array()
			);
		}

		public function applyDefaults( $slug, array $config ): array {
			return $config;
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

	function parity_assert_same( $expected, $actual, string $name ): void {
		global $failures, $passes;
		if ( $expected === $actual ) {
			++$passes;
			return;
		}
		$failures[] = $name;
		fwrite( STDERR, "FAIL: {$name}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
	}

	$stored = array(
		'classification_type' => 'music',
		'location'            => '51.5074,-0.1278',
		'radius'              => '15',
		'genre'               => 'rock',
		'venue_id'            => 'KovZpZA6tFlA',
		'search'              => 'live',
		'exclude_keywords'    => 'tribute',
		'max_items'           => 100,
		'params'              => array(
			'filters' => array(
				'city'    => 'London',
				'keyword' => 'original',
			),
			'mode'    => 'strict',
		),
	);

	$GLOBALS['handler_config_smoke_flow'] = array(
		'flow_id'     => 806,
		'pipeline_id' => 208,
		'flow_config' => array(
			'fetch_1_806' => array(
				'step_type'       => 'fetch',
				'handler_slugs'    => array( 'ticketmaster' ),
				'handler_configs'  => array( 'ticketmaster' => $stored ),
			),
		),
	);

	$patch = array(
		'max_items' => 1,
		'params'    => array( 'filters' => array( 'keyword' => 'updated' ) ),
	);
	$expected = $stored;
	$expected['max_items'] = 1;
	$expected['params']['filters']['keyword'] = 'updated';

	$ability = new \DataMachine\Abilities\FlowStep\UpdateFlowStepAbility();
	$before  = serialize( $GLOBALS['handler_config_smoke_flow'] );
	$preview = $ability->execute(
		array(
			'flow_step_id'   => 'fetch_1_806',
			'handler_config' => $patch,
			'validate_only'  => true,
		)
	);

	parity_assert_same( array(), $GLOBALS['handler_config_smoke_writes'], 'dry-run performs no persistence' );
	parity_assert_same( array(), $GLOBALS['handler_config_smoke_logs'], 'dry-run writes no database-backed logs' );
	parity_assert_same( $before, serialize( $GLOBALS['handler_config_smoke_flow'] ), 'dry-run leaves storage byte-identical' );
	parity_assert_same( $expected, $preview['effective_handler_config'] ?? null, 'dry-run returns effective deep-merged config' );

	$applied_result = $ability->execute(
		array(
			'flow_step_id'   => 'fetch_1_806',
			'handler_config' => $patch,
		)
	);
	parity_assert_same( true, $applied_result['success'] ?? false, 'apply succeeds' );
	$applied = $GLOBALS['handler_config_smoke_flow']['flow_config']['fetch_1_806']['handler_configs']['ticketmaster'];
	parity_assert_same( $preview['effective_handler_config'], $applied, 'apply exactly matches dry-run preview' );
	parity_assert_same( '51.5074,-0.1278', $applied['location'], 'omitted non-default location is preserved exactly' );
	parity_assert_same( '15', $applied['radius'], 'omitted non-default radius type and value are preserved exactly' );
	parity_assert_same( 'London', $applied['params']['filters']['city'], 'omitted nested params sibling is preserved' );
	parity_assert_same( 'strict', $applied['params']['mode'], 'omitted params sibling is preserved' );

	$full = array(
		'classification_type' => 'sports',
		'location'            => '40.7128,-74.0060',
		'radius'              => '25',
		'genre'               => '',
		'venue_id'            => '',
		'search'              => 'finals',
		'exclude_keywords'    => '',
		'max_items'           => 50,
		'params'              => array( 'filters' => array( 'city' => 'New York', 'keyword' => 'finals' ), 'mode' => 'broad' ),
	);
	$full_result = $ability->execute(
		array(
			'flow_step_id'   => 'fetch_1_806',
			'handler_config' => $full,
		)
	);
	parity_assert_same( true, $full_result['success'] ?? false, 'full-object update succeeds' );
	parity_assert_same( $full, $GLOBALS['handler_config_smoke_flow']['flow_config']['fetch_1_806']['handler_configs']['ticketmaster'], 'full-object update remains exact' );

	if ( ! empty( $failures ) ) {
		exit( 1 );
	}

	echo "{$passes} handler-config parity assertions passed.\n";
}
