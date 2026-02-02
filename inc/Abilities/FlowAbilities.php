<?php
/**
 * Flow Abilities
 *
 * Facade that loads and registers all modular Flow ability classes.
 * Maintains backward compatibility by delegating to individual ability instances.
 *
 * @package DataMachine\Abilities
 * @since 0.15.3 Refactored to facade pattern with modular ability classes.
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\Flow\GetFlowsAbility;
use DataMachine\Abilities\Flow\CreateFlowAbility;
use DataMachine\Abilities\Flow\UpdateFlowAbility;
use DataMachine\Abilities\Flow\DeleteFlowAbility;
use DataMachine\Abilities\Flow\DuplicateFlowAbility;
use DataMachine\Abilities\Flow\QueueAbility;

defined( 'ABSPATH' ) || exit;

class FlowAbilities {

	private static bool $registered = false;

	private GetFlowsAbility $get_flows;
	private CreateFlowAbility $create_flow;
	private UpdateFlowAbility $update_flow;
	private DeleteFlowAbility $delete_flow;
	private DuplicateFlowAbility $duplicate_flow;
	private QueueAbility $queue;

	public function __construct() {
		// Always initialize queue - CLI commands need it regardless of WP_Ability
		$this->queue = new QueueAbility();

		if ( ! class_exists( 'WP_Ability' ) || self::$registered ) {
			return;
		}

		$this->registerCategory();

		$this->get_flows      = new GetFlowsAbility();
		$this->create_flow    = new CreateFlowAbility();
		$this->update_flow    = new UpdateFlowAbility();
		$this->delete_flow    = new DeleteFlowAbility();
		$this->duplicate_flow = new DuplicateFlowAbility();

		self::$registered = true;
	}

	/**
	 * Register the datamachine ability category.
	 */
	private function registerCategory(): void {
		$category_callback = function () {
			wp_register_ability_category(
				'datamachine',
				array(
					'label'       => __( 'Data Machine', 'data-machine' ),
					'description' => __( 'Data Machine flow and pipeline operations', 'data-machine' ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_categories_init' ) ) {
			$category_callback();
		} else {
			add_action( 'wp_abilities_api_categories_init', $category_callback );
		}
	}

	/**
	 * Permission callback for abilities.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Execute get-flows ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with flows data.
	 */
	public function executeAbility( array $input ): array {
		if ( ! isset( $this->get_flows ) ) {
			$this->get_flows = new GetFlowsAbility();
		}
		return $this->get_flows->execute( $input );
	}

	/**
	 * Execute delete-flow ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with success status.
	 */
	public function executeDeleteFlow( array $input ): array {
		if ( ! isset( $this->delete_flow ) ) {
			$this->delete_flow = new DeleteFlowAbility();
		}
		return $this->delete_flow->execute( $input );
	}

	/**
	 * Execute create-flow ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with flow data.
	 */
	public function executeCreateFlow( array $input ): array {
		if ( ! isset( $this->create_flow ) ) {
			$this->create_flow = new CreateFlowAbility();
		}
		return $this->create_flow->execute( $input );
	}

	/**
	 * Execute update-flow ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with updated flow data.
	 */
	public function executeUpdateFlow( array $input ): array {
		if ( ! isset( $this->update_flow ) ) {
			$this->update_flow = new UpdateFlowAbility();
		}
		return $this->update_flow->execute( $input );
	}

	/**
	 * Execute duplicate-flow ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with duplicated flow data.
	 */
	public function executeDuplicateFlow( array $input ): array {
		if ( ! isset( $this->duplicate_flow ) ) {
			$this->duplicate_flow = new DuplicateFlowAbility();
		}
		return $this->duplicate_flow->execute( $input );
	}

	/**
	 * Execute queue-add ability.
	 *
	 * @param array $input Input parameters (flow_id, prompt).
	 * @return array Result with queue status.
	 */
	public function executeQueueAdd( array $input ): array {
		return $this->queue->executeQueueAdd( $input );
	}

	/**
	 * Execute queue-list ability.
	 *
	 * @param array $input Input parameters (flow_id).
	 * @return array Result with queue items.
	 */
	public function executeQueueList( array $input ): array {
		return $this->queue->executeQueueList( $input );
	}

	/**
	 * Execute queue-clear ability.
	 *
	 * @param array $input Input parameters (flow_id).
	 * @return array Result with cleared count.
	 */
	public function executeQueueClear( array $input ): array {
		return $this->queue->executeQueueClear( $input );
	}

	/**
	 * Execute queue-remove ability.
	 *
	 * @param array $input Input parameters (flow_id, flow_step_id, index).
	 * @return array Result with removed prompt info.
	 */
	public function executeQueueRemove( array $input ): array {
		return $this->queue->executeQueueRemove( $input );
	}

	/**
	 * Execute queue-update ability.
	 *
	 * @param array $input Input parameters (flow_id, flow_step_id, index, prompt).
	 * @return array Result with update status.
	 */
	public function executeQueueUpdate( array $input ): array {
		return $this->queue->executeQueueUpdate( $input );
	}

	/**
	 * Execute queue-move ability.
	 *
	 * @param array $input Input parameters (flow_id, flow_step_id, from_index, to_index).
	 * @return array Result with move status.
	 */
	public function executeQueueMove( array $input ): array {
		return $this->queue->executeQueueMove( $input );
	}
}
