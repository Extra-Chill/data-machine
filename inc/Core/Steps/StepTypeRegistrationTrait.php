<?php
/**
 * Step Type Registration Trait
 *
 * Provides standardized step type registration functionality to eliminate
 * boilerplate code across all step type registration files.
 *
 * @package DataMachine\Core\Steps
 * @since 0.7.2
 */

namespace DataMachine\Core\Steps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait StepTypeRegistrationTrait {
	/**
	 * Track registered step types to prevent duplicate filter registration.
	 *
	 * Step classes are instantiated multiple times: once during plugin load
	 * and again during step execution. This guard ensures filters are only
	 * added once per step type.
	 *
	 * @var array<string, bool>
	 */
	private static array $registered_step_types = array();

	/**
	 * Register a step type with all required filters.
	 *
	 * Provides a standardized way to register step types, eliminating
	 * repetitive filter registration code across all step types.
	 *
	 * @param string     $slug Step type slug identifier
	 * @param string     $label Display label
	 * @param string     $description Step type description
	 * @param string     $class_name Step class name
	 * @param int        $position Position in step type list (lower = earlier)
	 * @param bool       $usesHandler Whether step type uses handlers
	 * @param bool       $hasPipelineConfig Whether step has pipeline-level configuration
	 * @param bool       $consumeAllPackets Whether step consumes all packets at once
	 * @param array|null $stepSettings Optional UI settings for pipeline step configuration
	 */
	protected static function registerStepType(
		string $slug,
		string $label,
		string $description,
		string $class,
		int $position = 50,
		bool $usesHandler = true,
		bool $hasPipelineConfig = false,
		bool $consumeAllPackets = false,
		?array $stepSettings = null,
		bool $showSettingsDisplay = true
	): void {
		// Prevent duplicate registration when step class is instantiated multiple times
		if ( isset( self::$registered_step_types[ $slug ] ) ) {
			return;
		}
		self::$registered_step_types[ $slug ] = true;

		add_filter(
			'datamachine_step_types',
			function ( $steps ) use (
				$slug,
				$label,
				$description,
			$class,
			$position,
				$usesHandler,
				$hasPipelineConfig,
				$consumeAllPackets,
				$showSettingsDisplay
			) {
				$steps[ $slug ] = array(
					'label'                 => $label,
					'description'           => $description,
					'class'                 => $class,
					'position'              => $position,
					'uses_handler'          => $usesHandler,
					'has_pipeline_config'   => $hasPipelineConfig,
					'consume_all_packets'   => $consumeAllPackets,
					'show_settings_display' => $showSettingsDisplay,
				);
				return $steps;
			}
		);

		if ( null !== $stepSettings ) {
			add_filter(
				'datamachine_step_settings',
				function ( $configs ) use ( $slug, $stepSettings ) {
					$configs[ $slug ] = $stepSettings;
					return $configs;
				}
			);
		}

		do_action( 'datamachine_step_type_registered', $slug );
	}
}
