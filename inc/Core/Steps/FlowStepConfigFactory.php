<?php
/**
 * Flow step config factory.
 *
 * @package DataMachine\Core\Steps
 */

namespace DataMachine\Core\Steps;

defined( 'ABSPATH' ) || exit;

/**
 * Builds canonical flow step configuration rows from explicit inputs.
 */
class FlowStepConfigFactory {

	/**
	 * Build a canonical flow step configuration row.
	 *
	 * @param array $args Explicit config inputs.
	 * @return array Flow step config row.
	 */
	public static function build( array $args ): array {
		$step_config = array();
		$copy_fields = array(
			'flow_step_id'       => true,
			'pipeline_step_id'   => true,
			'step_type'          => true,
			'execution_order'    => true,
			'enabled_tools'      => true,
			'disabled_tools'     => true,
			'prompt_queue'       => true,
			'config_patch_queue' => true,
			'queue_mode'         => true,
			'pipeline_id'        => true,
			'flow_id'            => true,
			'handler'            => true,
		);

		foreach ( $args as $field => $value ) {
			if ( isset( $copy_fields[ $field ] ) ) {
				$step_config[ $field ] = $value;
			}
		}

		$handler_slug   = $args['handler_slug'] ?? '';
		$handler_config = $args['handler_config'] ?? array();

		if ( is_string( $handler_slug ) && '' !== $handler_slug ) {
			if ( FlowStepConfig::isMultiHandler( $step_config ) ) {
				$step_config['handler_slugs']   = array( $handler_slug );
				$step_config['handler_configs'] = array( $handler_slug => $handler_config );
			} else {
				$step_config['handler_slug']   = $handler_slug;
				$step_config['handler_config'] = $handler_config;
			}
		} elseif ( ! FlowStepConfig::usesHandler( $step_config ) && ! empty( $handler_config ) ) {
			$step_config['handler_config'] = $handler_config;
		}

		return $step_config;
	}
}
