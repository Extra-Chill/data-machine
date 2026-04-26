<?php
/**
 * Trait for steps that can consume prompts/tasks from the flow queue.
 *
 * Provides shared queue pop functionality that can be used by any step type
 * that needs to pull work items from the prompt queue.
 *
 * Two consumption modes are exposed:
 *
 * - {@see popFromQueueIfEmpty()} — for steps that consume scalar prompts
 *   (AI step's user_message). Returns the popped string verbatim.
 *
 * - {@see popQueuedConfigPatch()} — for steps that consume structured
 *   config patches (Fetch step's handler params). Decodes the popped
 *   prompt as JSON and returns it as an array suitable for deep-merging
 *   into the step's existing handler configuration.
 *
 * Both share the same persistence (per-flow-step FIFO queue), the same
 * `queue_enabled` toggle, and the same retry-on-failure backup semantics.
 *
 * @package DataMachine\Core\Steps
 * @since 0.19.0
 */

namespace DataMachine\Core\Steps;

use DataMachine\Abilities\Flow\QueueAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queueable trait for steps that consume from prompt queue.
 *
 * Usage:
 *   class MyStep extends Step {
 *       use QueueableTrait;
 *
 *       protected function executeStep(): array {
 *           $task = $this->popFromQueueIfEmpty( '', true );
 *           // Use $task...
 *       }
 *   }
 */
trait QueueableTrait {

	/**
	 * Pop from queue if the provided value is empty and queue is enabled.
	 *
	 * @param string $current_value The current value (e.g., user_message or prompt).
	 * @param bool   $queue_enabled Whether queue pop is enabled for this step.
	 * @return array{value: string, from_queue: bool, added_at: string|null} Result with value and source info.
	 */
	protected function popFromQueueIfEmpty( string $current_value, bool $queue_enabled = false ): array {
		if ( ! $queue_enabled ) {
			return array(
				'value'      => $current_value,
				'from_queue' => false,
				'added_at'   => null,
			);
		}

		$queued = $this->popOnceFromFlowQueue();

		if ( null === $queued ) {
			return array(
				'value'      => '',
				'from_queue' => false,
				'added_at'   => null,
			);
		}

		return array(
			'value'      => $queued['prompt'],
			'from_queue' => true,
			'added_at'   => $queued['added_at'] ?? null,
		);
	}

	/**
	 * Pop a structured config patch from the queue.
	 *
	 * Sibling of {@see popFromQueueIfEmpty()} for steps whose unit of work
	 * is a structured config dict rather than a scalar prompt. The popped
	 * prompt string is JSON-decoded and returned as an array.
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
	 * If the patch is shaped at the wrong nesting level the keys will
	 * land on top-level config slots the handler never reads, and they
	 * will be silently ignored downstream. The merge log line includes
	 * both `patch_keys` and `merged_keys` to make this kind of
	 * mis-shaping visible at debug time.
	 *
	 * Empty queue and disabled queue both return `from_queue: false` with
	 * an empty patch — callers can branch on that to either skip the tick
	 * or fall through to the static handler config.
	 *
	 * @param bool $queue_enabled Whether queue pop is enabled for this step.
	 * @return array{patch: array, from_queue: bool, added_at: string|null, raw_prompt: string} Result with the decoded patch and source info.
	 */
	protected function popQueuedConfigPatch( bool $queue_enabled = false ): array {
		if ( ! $queue_enabled ) {
			return array(
				'patch'      => array(),
				'from_queue' => false,
				'added_at'   => null,
				'raw_prompt' => '',
			);
		}

		$queued = $this->popOnceFromFlowQueue();

		if ( null === $queued ) {
			return array(
				'patch'      => array(),
				'from_queue' => false,
				'added_at'   => null,
				'raw_prompt' => '',
			);
		}

		$decoded = json_decode( $queued['prompt'], true );
		$patch   = is_array( $decoded ) ? $decoded : array();

		if ( ! is_array( $decoded ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Queueable fetch: queued item is not a JSON object — treating as empty patch',
				array(
					'flow_step_id' => $this->flow_step_id,
					'raw_prompt'   => substr( $queued['prompt'], 0, 200 ),
				)
			);
		}

		return array(
			'patch'      => $patch,
			'from_queue' => true,
			'added_at'   => $queued['added_at'] ?? null,
			'raw_prompt' => $queued['prompt'],
		);
	}

	/**
	 * Pop one item from the flow queue and back it up to engine data.
	 *
	 * Internal helper shared by both pop variants. Returns null when the
	 * flow_id is unavailable or the queue is empty.
	 *
	 * @return array{prompt: string, added_at: string|null}|null Popped item, or null if no item.
	 */
	private function popOnceFromFlowQueue(): ?array {
		$job_context = $this->engine->getJobContext();
		$flow_id     = $job_context['flow_id'] ?? null;

		if ( ! $flow_id ) {
			return null;
		}

		$queued_item = QueueAbility::popFromQueue( (int) $flow_id, $this->flow_step_id );

		if ( ! $queued_item || empty( $queued_item['prompt'] ) ) {
			return null;
		}

		do_action(
			'datamachine_log',
			'info',
			'Using prompt from queue',
			array(
				'flow_id'   => $flow_id,
				'step_type' => $this->step_type ?? 'unknown',
				'added_at'  => $queued_item['added_at'] ?? '',
			)
		);

		// Store backup of the popped prompt in engine data for retry on failure.
		if ( property_exists( $this, 'job_id' ) && ! empty( $this->job_id ) ) {
			\datamachine_merge_engine_data(
				$this->job_id,
				array(
					'queued_prompt_backup' => array(
						'prompt'       => $queued_item['prompt'],
						'flow_id'      => (int) $flow_id,
						'flow_step_id' => $this->flow_step_id,
						'added_at'     => $queued_item['added_at'] ?? null,
					),
				)
			);
		}

		return array(
			'prompt'   => $queued_item['prompt'],
			'added_at' => $queued_item['added_at'] ?? null,
		);
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
