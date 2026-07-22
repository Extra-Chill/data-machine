<?php
/**
 * Action Scheduler action fenced by a persisted schedule generation.
 *
 * @package DataMachine\Engine\Tasks
 */

namespace DataMachine\Engine\Tasks;

defined( 'ABSPATH' ) || exit;

class GenerationFencedAction extends \ActionScheduler_Action {

	private string $generation_option;
	private string $expected_generation;

	/**
	 * @param string                    $hook                Action hook.
	 * @param array                     $args                Action arguments.
	 * @param \ActionScheduler_Schedule $schedule            Original schedule.
	 * @param string                    $group               Action group.
	 * @param string                    $generation_option   Persisted generation option.
	 * @param string                    $expected_generation Generation captured when fetched.
	 */
	public function __construct(
		string $hook,
		array $args,
		\ActionScheduler_Schedule $schedule,
		string $group,
		string $generation_option,
		string $expected_generation
	) {
		parent::__construct( $hook, $args, $schedule, $group );
		$this->generation_option   = $generation_option;
		$this->expected_generation = $expected_generation;
	}

	/**
	 * Prevent Action Scheduler from repeating a superseded fetched recurrence.
	 *
	 * Action Scheduler fetches the action before invoking its callback and asks
	 * this object for its schedule again afterward. A transition that advances
	 * the generation during callback execution therefore turns the old fetched
	 * schedule into a non-recurring canceled schedule before AS can clone it.
	 *
	 * @return \ActionScheduler_Schedule
	 */
	public function get_schedule(): \ActionScheduler_Schedule {
		$schedule = parent::get_schedule();
		$current  = get_option( $this->generation_option, '' );

		if ( is_string( $current ) && hash_equals( $this->expected_generation, $current ) ) {
			return $schedule;
		}

		return new \ActionScheduler_CanceledSchedule( $schedule->get_date() );
	}

	public function getGenerationOption(): string {
		return $this->generation_option;
	}

	public function getExpectedGeneration(): string {
		return $this->expected_generation;
	}
}
