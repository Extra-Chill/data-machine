<?php
/**
 * Flow Step Abilities
 *
 * Facade that loads and registers all modular FlowStep ability classes.
 * Maintains backward compatibility by delegating to individual ability instances.
 *
 * @package DataMachine\Abilities
 * @since 0.15.3 Refactored to facade pattern with modular ability classes.
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\PermissionHelper;

use DataMachine\Abilities\FlowStep\GetFlowStepsAbility;
use DataMachine\Abilities\FlowStep\UpdateFlowStepAbility;
use DataMachine\Abilities\FlowStep\ConfigureFlowStepsAbility;
use DataMachine\Abilities\FlowStep\ValidateFlowStepsConfigAbility;

defined( 'ABSPATH' ) || exit;

class FlowStepAbilities {

	private static bool $registered = false;

	private GetFlowStepsAbility $get_flow_steps;
	private UpdateFlowStepAbility $update_flow_step;
	private ConfigureFlowStepsAbility $configure_flow_steps;
	private ValidateFlowStepsConfigAbility $validate_flow_steps_config;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) || self::$registered ) {
			return;
		}

		$this->get_flow_steps             = new GetFlowStepsAbility();
		$this->update_flow_step           = new UpdateFlowStepAbility();
		$this->configure_flow_steps       = new ConfigureFlowStepsAbility();
		$this->validate_flow_steps_config = new ValidateFlowStepsConfigAbility();

		self::$registered = true;
	}

	/**
	 * Permission callback for abilities.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute get-flow-steps ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with steps data.
	 */
	public function executeGetFlowSteps( array $input ): array {
		if ( ! isset( $this->get_flow_steps ) ) {
			$this->get_flow_steps = new GetFlowStepsAbility();
		}
		return $this->get_flow_steps->execute( $input );
	}

	/**
	 * Execute update-flow-step ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with update status.
	 */
	public function executeUpdateFlowStep( array $input ): array {
		if ( ! isset( $this->update_flow_step ) ) {
			$this->update_flow_step = new UpdateFlowStepAbility();
		}
		return $this->update_flow_step->execute( $input );
	}

	/**
	 * Execute configure-flow-steps ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with configuration status.
	 */
	public function executeConfigureFlowSteps( array $input ): array {
		if ( ! isset( $this->configure_flow_steps ) ) {
			$this->configure_flow_steps = new ConfigureFlowStepsAbility();
		}
		return $this->configure_flow_steps->execute( $input );
	}

	/**
	 * Execute validate-flow-steps-config ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Validation result.
	 */
	public function executeValidateFlowStepsConfig( array $input ): array {
		if ( ! isset( $this->validate_flow_steps_config ) ) {
			$this->validate_flow_steps_config = new ValidateFlowStepsConfigAbility();
		}
		return $this->validate_flow_steps_config->execute( $input );
	}
}
