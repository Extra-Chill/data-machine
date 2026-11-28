<?php
/**
 * Bundle step ID remapping helpers.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites bundle-local pipeline and flow step IDs after import.
 */
class BundleStepIdRemapper {

	/**
	 * Remap pipeline step IDs inside a pipeline config.
	 *
	 * @param array $pipeline_config Pipeline config.
	 * @param int   $old_pipeline_id Original pipeline ID.
	 * @param int   $new_pipeline_id New pipeline ID.
	 * @return array Updated pipeline config.
	 */
	public static function remap_pipeline_step_ids( array $pipeline_config, int $old_pipeline_id, int $new_pipeline_id ): array {
		$remapped = array();

		foreach ( $pipeline_config as $pipeline_step_id => $step_config ) {
			$new_pipeline_step_id = self::remap_step_id_prefix( (string) $pipeline_step_id, $old_pipeline_id, $new_pipeline_id );
			if ( is_array( $step_config ) ) {
				$step_config['pipeline_step_id'] = $new_pipeline_step_id;
			}

			$remapped[ $new_pipeline_step_id ] = $step_config;
		}

		return $remapped;
	}

	/**
	 * Remap pipeline step IDs inside a flow config.
	 *
	 * Pipeline step IDs have the format {pipeline_id}_{uuid}. Flow step IDs add
	 * the installed flow ID as the final suffix. Bundle-local IDs must be
	 * rewritten after install or runtime lookups resolve the wrong pipeline.
	 *
	 * @param array $flow_config     Flow config.
	 * @param int   $old_pipeline_id Original pipeline ID.
	 * @param int   $new_pipeline_id New pipeline ID.
	 * @param int   $new_flow_id     New flow ID.
	 * @return array Updated flow config.
	 */
	public static function remap_flow_step_ids( array $flow_config, int $old_pipeline_id, int $new_pipeline_id, int $new_flow_id ): array {
		$remapped = array();

		foreach ( $flow_config as $flow_step_id => $step_config ) {
			$pipeline_step_id = is_array( $step_config ) && is_string( $step_config['pipeline_step_id'] ?? null )
				? $step_config['pipeline_step_id']
				: preg_replace( '/_\d+$/', '', (string) $flow_step_id );
			$pipeline_step_id = self::remap_step_id_prefix( (string) $pipeline_step_id, $old_pipeline_id, $new_pipeline_id );
			$new_flow_step_id = $pipeline_step_id . '_' . $new_flow_id;

			if ( is_array( $step_config ) ) {
				$step_config['pipeline_step_id'] = $pipeline_step_id;
				$step_config['pipeline_id']      = $new_pipeline_id;
				$step_config['flow_id']          = $new_flow_id;
				$step_config['flow_step_id']     = $new_flow_step_id;
			}

			$remapped[ $new_flow_step_id ] = $step_config;
		}

		return $remapped;
	}

	/**
	 * Remap the pipeline ID prefix of a step ID.
	 *
	 * @param string $step_id         Step ID.
	 * @param int    $old_pipeline_id Original pipeline ID.
	 * @param int    $new_pipeline_id New pipeline ID.
	 * @return string Remapped step ID.
	 */
	public static function remap_step_id_prefix( string $step_id, int $old_pipeline_id, int $new_pipeline_id ): string {
		$prefix = $old_pipeline_id . '_';
		if ( $old_pipeline_id === $new_pipeline_id || ! str_starts_with( $step_id, $prefix ) ) {
			return $step_id;
		}

		return $new_pipeline_id . '_' . substr( $step_id, strlen( $prefix ) );
	}
}
