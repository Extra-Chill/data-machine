<?php
/**
 * Trait for steps that consume from a per-flow-step queue.
 *
 * Two consumption surfaces are exposed, each backed by its own storage
 * slot on the flow_step_config (see QueueAbility for the storage
 * contract):
 *
 * - {@see consumeFromPromptQueue()} — for steps that consume scalar
 *   prompts (AI step's per-flow user message). Reads from the
 *   `prompt_queue` slot. Each entry is `{ prompt: string, added_at }`.
 *
 * - {@see consumeFromConfigPatchQueue()} — for steps that consume
 *   structured config patches (Fetch step's handler params). Reads
 *   from the `config_patch_queue` slot. Each entry is
 *   `{ patch: array, added_at }` — the patch is a decoded object
 *   stored verbatim, so no JSON-decode happens at read time.
 *
 * Both share a single `queue_mode` enum on the step config and the
 * same retry-on-failure backup semantics. The mode is consumer-agnostic
 * — `drain`, `loop`, and `static` all map to honest use cases on
 * either consumer (see issue #1291 for the access-pattern table).
 *
 *   - drain  → pop the head, discard. Empty queue → null result with
 *              `mutated: true` so the caller can branch on no-items.
 *   - loop   → pop the head, append the same entry to the tail. Empty
 *              queue → null result.
 *   - static → peek the head, do not mutate. Empty queue → null result
 *              with `mutated: false` so the caller can fall through to
 *              its non-queue defaults.
 *
 * Pre-#1291 this trait took a `queue_enabled` boolean and exposed
 * `popFromQueueIfEmpty()` / `popQueuedConfigPatch()`. Storage rewrites
 * happened via QueueAbility::popFromQueueSlot() which always pop-and-
 * discarded (no peek path, no rotate path). The boolean shadowed two
 * unrelated decisions: "should the slot be consumed at all?" and "does
 * the head get popped or peeked?". The mode enum names the access
 * pattern explicitly and unblocks rotate-without-discard (loop).
 *
 * @package DataMachine\Core\Steps
 * @since 0.19.0
 */

namespace DataMachine\Core\Steps;

use DataMachine\Abilities\Flow\QueueAbility;
use DataMachine\Core\Database\Flows\Flows as DB_Flows;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queueable trait for steps that consume from a per-step queue.
 *
 * Usage:
 *   class MyStep extends Step {
 *       use QueueableTrait;
 *
 *       protected function executeStep(): array {
 *           $mode   = $this->flow_step_config['queue_mode'] ?? 'static';
 *           $result = $this->consumeFromPromptQueue( $mode );
 *           // Use $result['value'] / $result['from_queue'] / $result['mutated']...
 *       }
 *   }
 */
trait QueueableTrait {

	/**
	 * Consume from the prompt queue (AI consumer).
	 *
	 * Reads from the `prompt_queue` slot. Mode-driven:
	 *
	 *   - drain  → pop+discard
	 *   - loop   → pop+append-to-tail
	 *   - static → peek, no mutation
	 *
	 * Empty queue in any mode returns `value: ''`, `from_queue: false`.
	 * For drain and loop, callers typically interpret an empty result
	 * as "no work this tick" and short-circuit with COMPLETED_NO_ITEMS.
	 * For static, callers fall through to whatever non-queue default
	 * applies (e.g. system prompt + data packets only on AIStep).
	 *
	 * @param string $queue_mode One of "drain" | "loop" | "static".
	 *                           Unknown values are treated as "static"
	 *                           (peek without mutating) — same fail-safe
	 *                           default the migration uses for absent
	 *                           queue_mode keys.
	 * @return array{value: string, from_queue: bool, added_at: string|null, mutated: bool}
	 */
	protected function consumeFromPromptQueue( string $queue_mode ): array {
		$queued = $this->consumeOnceFromPromptQueue( $queue_mode );

		if ( null === $queued ) {
			return array(
				'value'      => '',
				'from_queue' => false,
				'added_at'   => null,
				'mutated'    => 'static' !== $queue_mode,
			);
		}

		return array(
			'value'      => $queued['prompt'],
			'from_queue' => true,
			'added_at'   => $queued['added_at'] ?? null,
			'mutated'    => 'static' !== $queue_mode,
		);
	}

	/**
	 * Consume a structured config patch from the fetch step queue.
	 *
	 * Sibling of {@see consumeFromPromptQueue()} for steps whose unit
	 * of work is a structured config dict rather than a scalar prompt.
	 * Reads from the `config_patch_queue` slot (Fetch consumer). The
	 * `patch` field is stored as a decoded array verbatim, so no
	 * JSON-decode happens here.
	 *
	 * Typical use: fetch step pops a config patch and the caller
	 * deep-merges it into the existing handler config to drive windowed
	 * retroactive backfills, rotating sources, or any other per-tick
	 * config rotation.
	 *
	 * **Patch shape must mirror the handler's static config shape.** The
	 * merge is opaque (it knows nothing about handler-specific layout),
	 * so a key in the patch lands at the same nesting depth in the
	 * handler config. For example, the MCP fetch handler stores its tool
	 * parameters as a JSON-encoded string under the `params` key, so a
	 * date-window patch for an MCP flow must nest the date keys inside
	 * `params`:
	 *
	 *   {"params":{"after":"2015-05-01","before":"2015-06-01"}}
	 *
	 * For a handler whose params live at the top level (e.g. RSS, which
	 * reads `$config['feed_url']` directly), a top-level patch shape is
	 * correct:
	 *
	 *   {"feed_url":"https://example.com/feed.xml"}
	 *
	 * Empty queue in any mode returns `from_queue: false` with an empty
	 * patch — callers can branch on that to either skip the tick or
	 * fall through to the static handler config.
	 *
	 * @param string $queue_mode One of "drain" | "loop" | "static".
	 * @return array{patch: array, from_queue: bool, added_at: string|null, mutated: bool}
	 */
	protected function consumeFromConfigPatchQueue( string $queue_mode ): array {
		$queued = $this->consumeOnceFromConfigPatchQueue( $queue_mode );

		if ( null === $queued ) {
			return array(
				'patch'      => array(),
				'from_queue' => false,
				'added_at'   => null,
				'mutated'    => 'static' !== $queue_mode,
			);
		}

		$patch = isset( $queued['patch'] ) && is_array( $queued['patch'] ) ? $queued['patch'] : array();

		if ( empty( $patch ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Queueable fetch: queued config patch is empty or malformed — treating as no-op tick',
				array(
					'flow_step_id' => $this->flow_step_id,
				)
			);
		}

		return array(
			'patch'      => $patch,
			'from_queue' => true,
			'added_at'   => $queued['added_at'] ?? null,
			'mutated'    => 'static' !== $queue_mode,
		);
	}

	/**
	 * Consume one item from the AI prompt queue per the given mode and
	 * back it up to engine data when the consumption mutates storage.
	 *
	 * @param string $queue_mode "drain" | "loop" | "static".
	 * @return array{prompt: string, added_at: string|null}|null
	 */
	private function consumeOnceFromPromptQueue( string $queue_mode ): ?array {
		$job_context = $this->engine->getJobContext();
		$flow_id     = $job_context['flow_id'] ?? null;

		if ( ! $flow_id ) {
			return null;
		}

		$entry = self::consumeFromQueueSlot(
			(int) $flow_id,
			$this->flow_step_id,
			QueueAbility::SLOT_PROMPT_QUEUE,
			$queue_mode
		);

		if ( ! $entry || empty( $entry['prompt'] ) ) {
			return null;
		}

		do_action(
			'datamachine_log',
			'info',
			'Using prompt from queue',
			array(
				'flow_id'    => $flow_id,
				'step_type'  => $this->step_type ?? 'unknown',
				'queue_mode' => $queue_mode,
				'added_at'   => $entry['added_at'] ?? '',
			)
		);

		// Store backup of the popped prompt in engine data for retry on
		// failure. Static mode never mutates so no rollback is needed —
		// re-running the static tick re-peeks the same entry naturally.
		if ( 'static' !== $queue_mode
			&& property_exists( $this, 'job_id' )
			&& ! empty( $this->job_id )
		) {
			\datamachine_merge_engine_data(
				$this->job_id,
				array(
					'queued_prompt_backup' => array(
						'slot'         => QueueAbility::SLOT_PROMPT_QUEUE,
						'mode'         => $queue_mode,
						'prompt'       => $entry['prompt'],
						'flow_id'      => (int) $flow_id,
						'flow_step_id' => $this->flow_step_id,
						'added_at'     => $entry['added_at'] ?? null,
					),
				)
			);
		}

		return array(
			'prompt'   => $entry['prompt'],
			'added_at' => $entry['added_at'] ?? null,
		);
	}

	/**
	 * Consume one item from the fetch config-patch queue per the given
	 * mode and back it up to engine data when the consumption mutates
	 * storage.
	 *
	 * @param string $queue_mode "drain" | "loop" | "static".
	 * @return array{patch: array, added_at: string|null}|null
	 */
	private function consumeOnceFromConfigPatchQueue( string $queue_mode ): ?array {
		$job_context = $this->engine->getJobContext();
		$flow_id     = $job_context['flow_id'] ?? null;

		if ( ! $flow_id ) {
			return null;
		}

		$entry = self::consumeFromQueueSlot(
			(int) $flow_id,
			$this->flow_step_id,
			QueueAbility::SLOT_CONFIG_PATCH_QUEUE,
			$queue_mode
		);

		if ( ! $entry || ! isset( $entry['patch'] ) || ! is_array( $entry['patch'] ) ) {
			return null;
		}

		do_action(
			'datamachine_log',
			'info',
			'Using config patch from queue',
			array(
				'flow_id'    => $flow_id,
				'step_type'  => $this->step_type ?? 'unknown',
				'queue_mode' => $queue_mode,
				'patch_keys' => array_keys( $entry['patch'] ),
				'added_at'   => $entry['added_at'] ?? '',
			)
		);

		if ( 'static' !== $queue_mode
			&& property_exists( $this, 'job_id' )
			&& ! empty( $this->job_id )
		) {
			\datamachine_merge_engine_data(
				$this->job_id,
				array(
					'queued_prompt_backup' => array(
						'slot'         => QueueAbility::SLOT_CONFIG_PATCH_QUEUE,
						'mode'         => $queue_mode,
						'patch'        => $entry['patch'],
						'flow_id'      => (int) $flow_id,
						'flow_step_id' => $this->flow_step_id,
						'added_at'     => $entry['added_at'] ?? null,
					),
				)
			);
		}

		return array(
			'patch'    => $entry['patch'],
			'added_at' => $entry['added_at'] ?? null,
		);
	}

	/**
	 * Consume one item from a named queue slot per the given mode.
	 *
	 * Centralizes the mode-aware read so prompt and config-patch
	 * consumers share the same mutation/peek/rotate semantics. For
	 * `drain` and `loop` the slot is rewritten in place; for `static`
	 * no DB write happens.
	 *
	 * @param int      $flow_id      Flow ID.
	 * @param string   $flow_step_id Flow step ID.
	 * @param string   $slot         Queue slot name.
	 * @param string   $queue_mode   "drain" | "loop" | "static".
	 * @param DB_Flows $db_flows     Optional database instance.
	 * @return array|null The consumed entry, or null if the queue was empty.
	 */
	private static function consumeFromQueueSlot(
		int $flow_id,
		string $flow_step_id,
		string $slot,
		string $queue_mode,
		?DB_Flows $db_flows = null
	): ?array {
		if ( null === $db_flows ) {
			$db_flows = new DB_Flows();
		}

		$flow = $db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return null;
		}

		$flow_config = $flow['flow_config'] ?? array();
		if ( ! isset( $flow_config[ $flow_step_id ] ) ) {
			return null;
		}

		$step_config = $flow_config[ $flow_step_id ];
		$queue       = $step_config[ $slot ] ?? array();

		if ( empty( $queue ) ) {
			return null;
		}

		// Static peek: read head, do not mutate storage.
		if ( 'static' === $queue_mode ) {
			return $queue[0];
		}

		// Drain or loop: pop the head, optionally rotate.
		$entry = array_shift( $queue );

		if ( 'loop' === $queue_mode ) {
			$queue[] = $entry;
		}

		$flow_config[ $flow_step_id ][ $slot ] = $queue;

		$db_flows->update_flow(
			$flow_id,
			array( 'flow_config' => $flow_config )
		);

		do_action(
			'datamachine_log',
			'info',
			'Item consumed from queue',
			array(
				'flow_id'         => $flow_id,
				'slot'            => $slot,
				'queue_mode'      => $queue_mode,
				'remaining_count' => count( $queue ),
			)
		);

		return $entry;
	}

	/**
	 * Deep-merge a config patch into existing handler settings.
	 *
	 * Handles two patterns:
	 *
	 * 1. Top-level keys in the patch overlay the same keys in $config
	 *    (recursive merge for arrays, replacement for scalars).
	 *
	 * 2. JSON-string-encoded sub-fields (e.g. MCPFetchHandler's `params`
	 *    field which serializes its tool params as a JSON string) are
	 *    decoded, merged, and re-encoded as the same JSON string shape
	 *    the handler expects to read.
	 *
	 * Empty patches return $config unchanged.
	 *
	 * @param array $config The current handler configuration.
	 * @param array $patch  The decoded patch from the queue.
	 * @return array Merged configuration.
	 */
	protected function mergeQueuedConfigPatch( array $config, array $patch ): array {
		if ( empty( $patch ) ) {
			return $config;
		}

		foreach ( $patch as $key => $value ) {
			$existing = $config[ $key ] ?? null;

			// JSON-encoded string field on the existing config — decode,
			// merge, re-encode. Allows queued patches to drop into the
			// MCP handler's `params` JSON string transparently.
			if ( is_string( $existing ) && is_array( $value ) ) {
				$decoded = json_decode( $existing, true );
				if ( is_array( $decoded ) ) {
					$config[ $key ] = wp_json_encode( $this->deepArrayMerge( $decoded, $value ) );
					continue;
				}
			}

			// Both arrays — recursive deep merge.
			if ( is_array( $existing ) && is_array( $value ) ) {
				// Numeric-keyed (list-shaped) arrays: concatenate to
				// preserve list semantics rather than overwriting indices.
				if ( $this->isList( $existing ) && $this->isList( $value ) ) {
					$config[ $key ] = array_merge( $existing, $value );
				} else {
					$config[ $key ] = $this->deepArrayMerge( $existing, $value );
				}
				continue;
			}

			// Otherwise, queued value wins (including when it's the same
			// scalar shape as the existing scalar, or when there's no
			// existing key at all).
			$config[ $key ] = $value;
		}

		return $config;
	}

	/**
	 * Recursive array deep-merge. Numeric-keyed (list-shaped) arrays are
	 * concatenated; associative arrays merge by key.
	 *
	 * @param array $base    Base array.
	 * @param array $overlay Overlay (wins on key collision unless both are arrays).
	 * @return array Merged array.
	 */
	private function deepArrayMerge( array $base, array $overlay ): array {
		foreach ( $overlay as $k => $v ) {
			if ( is_array( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) ) {
				if ( $this->isList( $base[ $k ] ) && $this->isList( $v ) ) {
					$base[ $k ] = array_merge( $base[ $k ], $v );
				} else {
					$base[ $k ] = $this->deepArrayMerge( $base[ $k ], $v );
				}
			} else {
				$base[ $k ] = $v;
			}
		}
		return $base;
	}

	/**
	 * Whether an array is list-shaped (sequential 0..N-1 integer keys).
	 *
	 * Polyfill for PHP 8.1's array_is_list(); avoids assuming the
	 * runtime supports it.
	 *
	 * @param array $arr Array to check.
	 * @return bool True if list-shaped, false otherwise.
	 */
	private function isList( array $arr ): bool {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $arr );
		}
		if ( array() === $arr ) {
			return true;
		}
		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}
}
