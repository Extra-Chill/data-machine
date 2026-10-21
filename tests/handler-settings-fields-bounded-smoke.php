<?php
/**
 * Smoke test: handler settings field schemas stay bounded on large sites.
 *
 * Covers #3653: taxonomy select fields must not enumerate huge taxonomies,
 * and SettingsDisplayService must build each handler's field schema once per
 * request instead of once per flow step.
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core\Database\Flows {
	class Flows {
		public static function is_flow_enabled( array $scheduling_config ): bool {
			return (bool) ( $scheduling_config['enabled'] ?? false );
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'WPINC', 'wp-includes' );

	require_once __DIR__ . '/smoke-wp-stubs.php';

	$GLOBALS['bounded_smoke_term_counts']    = array();
	$GLOBALS['bounded_smoke_get_terms_args'] = array();
	$GLOBALS['bounded_smoke_get_fields']     = 0;
	$GLOBALS['bounded_smoke_limit_filter']   = null;

	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}

	function do_action( string $hook, ...$args ): void {}

	function apply_filters( string $hook, $value, ...$args ) {
		if ( 'datamachine_step_types' === $hook ) {
			return array(
				'upsert' => array(
					'uses_handler'          => true,
					'multi_handler'         => false,
					'show_settings_display' => true,
				),
			);
		}
		if ( 'datamachine_handler_settings' === $hook ) {
			return array( $args[0] ?? '' => new BoundedSmokeSettings() );
		}
		if ( 'datamachine_taxonomy_term_option_limit' === $hook && null !== $GLOBALS['bounded_smoke_limit_filter'] ) {
			return $GLOBALS['bounded_smoke_limit_filter'];
		}
		return $value;
	}

	function get_option( string $option, $default = false ) {
		return $default;
	}

	function is_wp_error( $thing ): bool {
		return false;
	}

	function wp_count_terms( array $args ) {
		return $GLOBALS['bounded_smoke_term_counts'][ $args['taxonomy'] ] ?? 0;
	}

	function get_terms( array $args ) {
		$GLOBALS['bounded_smoke_get_terms_args'][] = $args;
		$count                                     = min( $GLOBALS['bounded_smoke_term_counts'][ $args['taxonomy'] ] ?? 0, $args['number'] ?? PHP_INT_MAX );
		$terms                                     = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$terms[ $i ] = "{$args['taxonomy']} {$i}";
		}
		return $terms;
	}

	require_once __DIR__ . '/../inc/Core/WordPress/WordPressSettingsHandler.php';
	require_once __DIR__ . '/../inc/Abilities/AbilityRegistration.php';
	require_once __DIR__ . '/../inc/Abilities/HandlerAbilities.php';
	require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfig.php';
	require_once __DIR__ . '/../inc/Core/Steps/Settings/SettingsDisplayService.php';

	use DataMachine\Core\WordPress\WordPressSettingsHandler;
	use DataMachine\Core\Steps\Settings\SettingsDisplayService;

	class BoundedSmokeSettings {
		public static function get_fields(): array {
			++$GLOBALS['bounded_smoke_get_fields'];
			return array(
				'post_status' => array(
					'label'   => 'Post Status',
					'type'    => 'select',
					'options' => array(
						'draft'   => 'Draft',
						'publish' => 'Publish',
					),
				),
			);
		}
	}

	function bounded_smoke_expect( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	}

	// 1. Small taxonomies are enumerated as id => name options.
	$GLOBALS['bounded_smoke_term_counts'] = array(
		'location' => 3,
		'artist'   => 98401,
	);
	$location                             = WordPressSettingsHandler::get_bounded_term_options( 'location' );
	bounded_smoke_expect( array(
		1 => 'location 1',
		2 => 'location 2',
		3 => 'location 3',
	) === $location, 'Small taxonomy should enumerate all terms.' );
	bounded_smoke_expect( 'id=>name' === $GLOBALS['bounded_smoke_get_terms_args'][0]['fields'], 'Term options should fetch id=>name, not full term objects.' );

	// 2. Huge taxonomies are not enumerated at all.
	$GLOBALS['bounded_smoke_get_terms_args'] = array();
	$artist                                  = WordPressSettingsHandler::get_bounded_term_options( 'artist' );
	bounded_smoke_expect( array() === $artist, 'Taxonomy above the limit should return no term options.' );
	bounded_smoke_expect( array() === $GLOBALS['bounded_smoke_get_terms_args'], 'Taxonomy above the limit must not call get_terms().' );

	// 3. Limit is filterable.
	$GLOBALS['bounded_smoke_limit_filter'] = 2;
	bounded_smoke_expect( array() === WordPressSettingsHandler::get_bounded_term_options( 'location' ), 'Filtered limit should cap enumeration.' );
	$GLOBALS['bounded_smoke_limit_filter'] = null;

	// 4. Display service builds each handler field schema once per request.
	$service = new SettingsDisplayService();
	$step    = array(
		'step_type'       => 'upsert',
		'handler_slugs'   => array( 'upsert_event' ),
		'handler_configs' => array( 'upsert_event' => array( 'post_status' => 'publish' ) ),
	);
	for ( $i = 0; $i < 50; $i++ ) {
		$display = $service->getDisplaySettingsForStepConfig( $step, 'upsert' );
		$service->getDisplaySettingsForHandlersConfig( $step, 'upsert' );
		$service->getFieldState( 'upsert_event', array( 'post_status' => 'draft' ) );
	}
	bounded_smoke_expect( 'Publish' === ( $display[0]['display_value'] ?? null ), 'Memoized schema should still produce option labels.' );
	bounded_smoke_expect( 1 === $GLOBALS['bounded_smoke_get_fields'], 'get_fields() should run once per handler per request, ran ' . $GLOBALS['bounded_smoke_get_fields'] . ' times.' );

	// 5. Clearing handler caches rebuilds the schema.
	\DataMachine\Abilities\HandlerAbilities::clearCache();
	$service->getDisplaySettingsForStepConfig( $step, 'upsert' );
	bounded_smoke_expect( 2 === $GLOBALS['bounded_smoke_get_fields'], 'HandlerAbilities::clearCache() should invalidate memoized schemas.' );

	echo "handler-settings-fields-bounded-smoke passed\n";
}
