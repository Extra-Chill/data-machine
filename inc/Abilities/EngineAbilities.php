<?php
/**
 * Engine Abilities
 *
 * Facade that registers all engine execution abilities.
 * These are internal abilities backing the four core action hooks
 * that drive pipeline execution via Action Scheduler.
 *
 * @package DataMachine\Abilities
 * @since 0.30.0
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\Engine\RunFlowAbility;
use DataMachine\Abilities\Engine\ExecuteStepAbility;
use DataMachine\Abilities\Engine\ScheduleNextStepAbility;
use DataMachine\Abilities\Engine\ScheduleFlowAbility;

defined( 'ABSPATH' ) || exit;

class EngineAbilities {

	private static bool $registered = false;

	private RunFlowAbility $run_flow;
	private ExecuteStepAbility $execute_step;
	private ScheduleNextStepAbility $schedule_next_step;
	private ScheduleFlowAbility $schedule_flow;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) || self::$registered ) {
			return;
		}

		$this->run_flow           = new RunFlowAbility();
		$this->execute_step       = new ExecuteStepAbility();
		$this->schedule_next_step = new ScheduleNextStepAbility();
		$this->schedule_flow      = new ScheduleFlowAbility();

		self::$registered = true;
	}
}
