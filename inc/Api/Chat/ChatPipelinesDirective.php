<?php
/**
 * Chat Pipelines Directive
 *
 * Injects a lightweight inventory of pipelines and their configured steps into
 * chat agent requests. This grounds the chat agent in what already exists
 * without requiring it to guess pipeline names, step types, or step names.
 *
 * @package DataMachine\Api\Chat
 */

namespace DataMachine\Api\Chat;

defined( 'ABSPATH' ) || exit;

class ChatPipelinesDirective implements \DataMachine\Engine\AI\Directives\DirectiveInterface {

	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		$selected_pipeline_id = $payload['selected_pipeline_id'] ?? null;
		$inventory            = self::getPipelinesInventory( $selected_pipeline_id );
		if ( empty( $inventory['pipelines'] ) ) {
			return array();
		}

		return array(
			array(
				'type'  => 'system_json',
				'label' => 'DATAMACHINE PIPELINES INVENTORY',
				'data'  => $inventory,
			),
		);
	}

	private static function getPipelinesInventory( ?int $selected_pipeline_id = null ): array {

		$db_pipelines = new \DataMachine\Core\Database\Pipelines\Pipelines();
		$db_flows     = new \DataMachine\Core\Database\Flows\Flows();
		$pipelines    = $db_pipelines->get_all_pipelines();

		$inventory = array(
			'selected_pipeline_id' => $selected_pipeline_id,
			'pipelines'            => array(),
		);

		foreach ( $pipelines as $pipeline ) {
			$pipeline_id     = (int) ( $pipeline['pipeline_id'] ?? 0 );
			$pipeline_name   = (string) ( $pipeline['pipeline_name'] ?? '' );
			$pipeline_config = $pipeline['pipeline_config'] ?? array();

			if ( $pipeline_id <= 0 ) {
				continue;
			}

			$steps = array();
			if ( is_array( $pipeline_config ) ) {
				foreach ( $pipeline_config as $pipeline_step_id => $step_config ) {
					if ( ! is_array( $step_config ) ) {
						continue;
					}

					$steps[] = array(
						'pipeline_step_id' => (string) ( $step_config['pipeline_step_id'] ?? $pipeline_step_id ),
						'step_name'        => (string) ( $step_config['label'] ?? '' ),
						'step_type'        => (string) ( $step_config['step_type'] ?? '' ),
						'execution_order'  => (int) ( $step_config['execution_order'] ?? 0 ),
					);
				}
			}

			usort(
				$steps,
				static function ( array $a, array $b ): int {
					return ( $a['execution_order'] ?? 0 ) <=> ( $b['execution_order'] ?? 0 );
				}
			);

			$flows = self::getFlowSummaries( $db_flows, $pipeline_id );

			$inventory['pipelines'][] = array(
				'pipeline_id'   => $pipeline_id,
				'pipeline_name' => $pipeline_name,
				'steps'         => $steps,
				'flows'         => $flows,
			);
		}

		return $inventory;
	}

	/**
	 * Get lightweight flow summaries for a pipeline.
	 *
	 * @param \DataMachine\Core\Database\Flows\Flows $db_flows Flows database instance
	 * @param int                                    $pipeline_id Pipeline ID
	 * @return array Flow summaries with id, name, and handler slugs
	 */
	private static function getFlowSummaries( \DataMachine\Core\Database\Flows\Flows $db_flows, int $pipeline_id ): array {
		$flows     = $db_flows->get_flows_for_pipeline( $pipeline_id );
		$summaries = array();

		foreach ( $flows as $flow ) {
			$flow_config = $flow['flow_config'] ?? array();
			$handlers    = array();

			foreach ( $flow_config as $step_config ) {
				// Data is normalized at the DB layer — handler_slugs is canonical.
				foreach ( $step_config['handler_slugs'] ?? array() as $slug ) {
					if ( ! in_array( $slug, $handlers, true ) ) {
						$handlers[] = $slug;
					}
				}
			}

			$summaries[] = array(
				'flow_id'   => (int) $flow['flow_id'],
				'flow_name' => (string) ( $flow['flow_name'] ?? '' ),
				'handlers'  => $handlers,
			);
		}

		return $summaries;
	}
}

add_filter(
	'datamachine_directives',
	function ( $directives ) {
		$directives[] = array(
			'class'       => ChatPipelinesDirective::class,
			'priority'    => 45,
			'agent_types' => array( 'chat' ),
		);

		return $directives;
	}
);
