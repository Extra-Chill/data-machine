<?php
/**
 * Iteration Budget — generic bounded-iteration primitive.
 *
 * Counts a named dimension (turns, tool calls, wall-clock seconds, retries)
 * and exposes a uniform API for checking exceedance. A budget is a
 * stateful value object — call {@see increment()} at each iteration,
 * then {@see exceeded()} to decide whether to continue.
 *
 * The substrate ships only the per-execution value object. Registries,
 * configuration persistence, and ceiling policies are consumer concerns.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Agent_Iteration_Budget {

	/**
	 * Budget name (e.g. "turns", "tool_calls", "chain_depth").
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Maximum allowed value (inclusive ceiling — exceeded when current >= ceiling).
	 *
	 * @var int
	 */
	private int $ceiling;

	/**
	 * Current counter value.
	 *
	 * @var int
	 */
	private int $current;

	/**
	 * @param string $name    Budget name. Used in result status and event payloads.
	 * @param int    $ceiling Maximum allowed value (must be >= 1).
	 * @param int    $current Starting counter value (default 0). Negative values are clamped to 0.
	 */
	public function __construct( string $name, int $ceiling, int $current = 0 ) {
		$this->name    = $name;
		$this->ceiling = max( 1, $ceiling );
		$this->current = max( 0, $current );
	}

	/**
	 * Increment the counter by one.
	 */
	public function increment(): void {
		++$this->current;
	}

	/**
	 * Replace the current counter value.
	 *
	 * Wall-clock budgets are measured externally by the loop, then recorded on
	 * this value object so event payloads and diagnostics share the same shape
	 * as iteration budgets.
	 *
	 * @param int $current Current counter value. Negative values are clamped to 0.
	 */
	public function set_current( int $current ): void {
		$this->current = max( 0, $current );
	}

	/**
	 * Whether the counter has reached or exceeded the ceiling.
	 *
	 * @return bool True when current >= ceiling.
	 */
	public function exceeded(): bool {
		return $this->current >= $this->ceiling;
	}

	/**
	 * Remaining iterations before exceedance.
	 *
	 * @return int Zero when exceeded, otherwise ceiling - current.
	 */
	public function remaining(): int {
		return max( 0, $this->ceiling - $this->current );
	}

	/**
	 * Budget name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Current counter value.
	 */
	public function current(): int {
		return $this->current;
	}

	/**
	 * Ceiling for this budget.
	 */
	public function ceiling(): int {
		return $this->ceiling;
	}
}
