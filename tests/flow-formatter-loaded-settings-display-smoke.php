<?php
/**
 * Smoke test for full-flow formatting settings displays from loaded config.
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core\Database\Flows {
	class Flows {
		public function get_flow_step_config( string $flow_step_id ): array {
			throw new \RuntimeException( "Unexpected flow step config lookup for {$flow_step_id}." );
		}

		public static function is_flow_enabled( array $scheduling_config ): bool {
			return (bool) ( $scheduling_config['enabled'] ?? false );
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'WPINC', 'wp-includes' );

	require_once __DIR__ . '/smoke-wp-stubs.php';

	if ( ! function_exists( '__' ) ) {
		function __( string $text, string $domain = 'default' ): string {
			return $text;
		}
	}

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( string $hook, ...$args ): void {
			// no-op
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, $value, ...$args ) {
			if ( 'datamachine_step_types' === $hook ) {
				return array(
					'fetch'   => array(
						'uses_handler'          => true,
						'multi_handler'         => false,
						'show_settings_display' => true,
					),
					'publish' => array(
						'uses_handler'          => true,
						'multi_handler'         => true,
						'show_settings_display' => true,
					),
				);
			}

			if ( 'datamachine_handler_settings' === $hook ) {
				$handler_slug = $args[0] ?? '';
				return array(
					$handler_slug => new FlowFormatterLoadedConfigSettings(),
				);
			}

			return $value;
		}
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( string $option, $default = false ) {
			return $default;
		}
	}

	if ( ! function_exists( 'wp_date' ) ) {
		function wp_date( string $format, int $timestamp, ?\DateTimeZone $timezone = null ): string {
			$date = new \DateTimeImmutable( '@' . $timestamp );
			return $date->setTimezone( $timezone ?? new \DateTimeZone( 'UTC' ) )->format( $format );
		}
	}

	require_once __DIR__ . '/../inc/Abilities/HandlerAbilities.php';
	require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfig.php';
	require_once __DIR__ . '/../inc/Core/Steps/Settings/SettingsDisplayService.php';
	require_once __DIR__ . '/../inc/Core/Admin/DateFormatter.php';
	require_once __DIR__ . '/../inc/Core/Admin/FlowFormatter.php';

	class FlowFormatterLoadedConfigSettings {
		public static function get_fields(): array {
			return array(
				'choice' => array(
					'label'   => 'Choice',
					'type'    => 'select',
					'options' => array(
						'a' => 'Aye',
						'b' => 'Bee',
					),
				),
				'flag'   => array(
					'label' => 'Flag',
					'type'  => 'checkbox',
				),
			);
		}
	}

	function flow_formatter_loaded_config_expect( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	$flow = array(
		'flow_id'           => 123,
		'flow_name'         => 'Loaded settings display smoke',
		'pipeline_id'       => 456,
		'scheduling_config' => array( 'enabled' => true ),
		'flow_config'       => array(
			'fetch_123'   => array(
				'step_type'       => 'fetch',
				'handler_slugs'   => array( 'alpha' ),
				'handler_configs' => array(
					'alpha' => array(
						'choice' => 'b',
						'flag'   => true,
					),
				),
			),
			'publish_123' => array(
				'step_type'       => 'publish',
				'handler_slugs'   => array( 'alpha', 'beta' ),
				'handler_configs' => array(
					'alpha' => array( 'choice' => 'a' ),
					'beta'  => array( 'choice' => 'b' ),
				),
			),
		),
	);

	$formatted = \DataMachine\Core\Admin\FlowFormatter::format_flow_for_response( $flow, null, array( 123 => null ) );
	$config    = $formatted['flow_config'];

	flow_formatter_loaded_config_expect( 'Choice: Bee | Flag: True' === $config['fetch_123']['settings_summary'], 'Single-handler settings summary should match loaded config.' );
	flow_formatter_loaded_config_expect( 'Bee' === $config['fetch_123']['settings_display'][0]['display_value'], 'Single-handler settings display should use option labels.' );
	flow_formatter_loaded_config_expect( 'Aye' === $config['publish_123']['handler_settings_displays']['alpha'][0]['display_value'], 'Multi-handler alpha display should match loaded config.' );
	flow_formatter_loaded_config_expect( 'Bee' === $config['publish_123']['handler_settings_displays']['beta'][0]['display_value'], 'Multi-handler beta display should match loaded config.' );

	echo "flow-formatter-loaded-settings-display-smoke passed\n";
}
