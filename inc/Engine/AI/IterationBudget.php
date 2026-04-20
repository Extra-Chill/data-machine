<?php
/**
 * Iteration Budget
 *
 * Generic primitive for bounded iteration with a configurable ceiling.
 * Counts a named dimension (e.g. conversation turns, A2A chain depth,
 * retry attempts) and exposes a uniform API for checking exceedance,
 * formatting warnings, and surfacing response flags.
 *
 * A budget is a stateful value object — call {@see increment()} at each
 * iteration, then {@see exceeded()} to decide whether to continue.
 *
 * Register named budget configurations via
 * {@see IterationBudgetRegistry::register()}, then instantiate per-run
 * via {@see IterationBudgetRegistry::create()}. This separates
 * "how much is allowed" (site config) from "where are we now" (runtime
 * counter) cleanly, and lets consumers share a consistent pattern
 * across turns, chain depth, and whatever else wants bounded iteration
 * semantics.
 *
 * @package DataMachine\Engine\AI
 * @since 0.71.0
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

final class IterationBudget {

	/**
	 * Budget name (e.g. "conversation_turns", "chain_depth").
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
	 * @param string $name    Budget name. Used in response flags and warnings.
	 * @param int    $ceiling Maximum allowed value (must be >= 1).
	 * @param int    $current Starting counter value (default 0). Negative values are clamped to 0.
	 *                        Values already at or above the ceiling are preserved (already-exceeded budget).
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

	/**
	 * Response flag key signaling this budget was exceeded.
	 *
	 * Convention: "max_{name}_reached" — e.g. "max_conversation_turns_reached".
	 *
	 * Callers with legacy response shapes (pre-primitive) may continue to
	 * emit their own flag keys instead; this helper exists for new budgets
	 * that want the standard shape.
	 *
	 * @return string
	 */
	public function toResponseFlag(): string {
		return 'max_' . $this->name . '_reached';
	}

	/**
	 * Human-readable warning describing exceedance.
	 *
	 * Legacy callers with a specific warning format should not use this;
	 * it exists for new budgets that want the standard shape.
	 *
	 * @return string
	 */
	public function toWarning(): string {
		return sprintf(
			'Maximum %s (%d) reached.',
			str_replace( '_', ' ', $this->name ),
			$this->ceiling
		);
	}
}
