<?php
/**
 * Flow execution order planning.
 *
 * @package DataMachine\Engine
 */

namespace DataMachine\Engine;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Normalized positional plan for a flow's execution order.
 */
class ExecutionPlan {

	/**
	 * Flow step IDs sorted by execution_order.
	 *
	 * @var string[]
	 */
	private array $step_ids;

	/**
	 * @param string[] $step_ids Flow step IDs sorted by execution_order.
	 */
	private function __construct( array $step_ids ) {
		$this->step_ids = array_values( $step_ids );
	}

	/**
	 * Build a normalized execution plan from a flow config.
	 *
	 * @param array $flow_config Flow step config keyed by flow step ID.
	 * @return self
	 * @throws \InvalidArgumentException When execution_order is missing, invalid, or duplicated.
	 */
	public static function from_flow_config( array $flow_config ): self {
		$steps_by_order = array();

		foreach ( $flow_config as $step_id => $step_config ) {
			if ( ! is_array( $step_config ) || ! array_key_exists( 'execution_order', $step_config ) ) {
				throw new \InvalidArgumentException( sprintf( 'Flow step "%s" is missing execution_order.', (string) $step_id ) );
			}

			$order = self::normalize_order( $step_config['execution_order'], (string) $step_id );
			if ( array_key_exists( $order, $steps_by_order ) ) {
				throw new \InvalidArgumentException(
					sprintf(
						'Duplicate execution_order %d for flow steps "%s" and "%s".',
						$order,
						$steps_by_order[ $order ],
						(string) $step_id
					)
				);
			}

			$steps_by_order[ $order ] = (string) $step_id;
		}

		ksort( $steps_by_order, SORT_NUMERIC );

		return new self( array_values( $steps_by_order ) );
	}

	/**
	 * Get the first flow step ID in the plan.
	 */
	public function first_step_id(): ?string {
		return $this->step_ids[0] ?? null;
	}

	/**
	 * Get the next flow step ID after the current step.
	 *
	 * @param string $flow_step_id Current flow step ID.
	 */
	public function next_step_id( string $flow_step_id ): ?string {
		$position = array_search( $flow_step_id, $this->step_ids, true );
		if ( false === $position ) {
			return null;
		}

		return $this->step_ids[ $position + 1 ] ?? null;
	}

	/**
	 * Get the previous flow step ID before the current step.
	 *
	 * @param string $flow_step_id Current flow step ID.
	 */
	public function previous_step_id( string $flow_step_id ): ?string {
		$position = array_search( $flow_step_id, $this->step_ids, true );
		if ( false === $position || 0 === $position ) {
			return null;
		}

		return $this->step_ids[ $position - 1 ] ?? null;
	}

	/**
	 * Normalize an execution order value to an integer.
	 *
	 * @param mixed  $raw_order Raw execution_order value.
	 * @param string $step_id   Flow step ID for error messages.
	 */
	private static function normalize_order( mixed $raw_order, string $step_id ): int {
		if ( is_int( $raw_order ) ) {
			return $raw_order;
		}

		if ( is_string( $raw_order ) && preg_match( '/^-?\d+$/', $raw_order ) ) {
			return (int) $raw_order;
		}

		throw new \InvalidArgumentException( sprintf( 'Flow step "%s" has invalid execution_order.', $step_id ) );
	}
}
