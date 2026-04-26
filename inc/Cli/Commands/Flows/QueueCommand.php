<?php
/**
 * WP-CLI Flows Queue Command
 *
 * Manages per-step queues attached to flow steps. Two queue slots are
 * supported (#1292), both gated by a single `queue_mode` enum (#1291):
 *
 *   - prompt_queue       — string prompts, consumed by AI steps
 *   - config_patch_queue — object patches, consumed by fetch steps
 *
 * The CLI is consumer-aware: it inspects the target flow step's
 * `step_type` and routes to the slot that step consumes. Fetch steps
 * accept patches via `--patch=<json>`; AI steps accept prompts as a
 * positional argument. Mixing the two (a string prompt against a
 * fetch step, or `--patch=` against an AI step) errors loudly with a
 * pointer to the right flag.
 *
 * The `mode` subcommand sets the access pattern (drain | loop | static)
 * for whichever slot the step consumes — the same enum drives both
 * AI and Fetch behaviour.
 *
 * @package DataMachine\Cli\Commands\Flows
 * @since 0.31.0
 * @see https://github.com/Extra-Chill/data-machine/issues/345
 * @see https://github.com/Extra-Chill/data-machine/issues/1291
 * @see https://github.com/Extra-Chill/data-machine/issues/1292
 */

namespace DataMachine\Cli\Commands\Flows;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Abilities\Flow\QueueAbility;

defined( 'ABSPATH' ) || exit;

class QueueCommand extends BaseCommand {

	/**
	 * Dispatch a queue subcommand.
	 *
	 * Called from FlowsCommand to route queue operations to this class.
	 *
	 * @param array $args       Positional arguments (action, flow_id, ...).
	 * @param array $assoc_args Associative arguments.
	 */
	public function dispatch( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine flows queue <add|list|clear|remove|update|move|mode|validate> <flow_id> [args...]' );
			return;
		}

		$action    = $args[0];
		$remaining = array_slice( $args, 1 );

		switch ( $action ) {
			case 'add':
				$this->add( $remaining, $assoc_args );
				break;
			case 'list':
				$this->list_queue( $remaining, $assoc_args );
				break;
			case 'clear':
				$this->clear( $remaining, $assoc_args );
				break;
			case 'remove':
				$this->remove( $remaining, $assoc_args );
				break;
			case 'update':
				$this->update( $remaining, $assoc_args );
				break;
			case 'move':
				$this->move( $remaining, $assoc_args );
				break;
			case 'mode':
				$this->mode( $remaining, $assoc_args );
				break;
			case 'validate':
				$this->validate( $remaining, $assoc_args );
				break;
			default:
				WP_CLI::error( "Unknown queue action: {$action}. Use: add, list, clear, remove, update, move, mode, validate" );
		}
	}

	/**
	 * Add a prompt or config patch to a flow step's queue.
	 *
	 * Routes by target step type:
	 *  - AI step    → writes to prompt_queue (string prompt argument)
	 *  - Fetch step → writes to config_patch_queue (--patch=<json>)
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : The flow ID.
	 *
	 * [<prompt>]
	 * : Prompt text to enqueue (for AI steps). Conflicts with --patch.
	 *
	 * [--patch=<json>]
	 * : JSON-encoded config patch object to enqueue (for fetch steps).
	 * : The patch is deep-merged into the handler config when the step runs.
	 *
	 * [--step=<flow_step_id>]
	 * : Target a specific flow step. Auto-resolved if the flow has exactly one queueable step.
	 *
	 * ## EXAMPLES
	 *
	 *     # Add a prompt to an AI step queue
	 *     wp datamachine flows queue add 42 "Generate a blog post about AI"
	 *
	 *     # Add a config patch to a fetch step queue
	 *     wp datamachine flows queue add 42 --patch='{"params":{"after":"2015-05-01"}}'
	 *
	 * @subcommand add
	 */
	public function add( array $args, array $assoc_args ): void {
		if ( count( $args ) < 1 ) {
			WP_CLI::error( 'Usage: wp datamachine flows queue add <flow_id> "prompt text"  or  wp datamachine flows queue add <flow_id> --patch=\'{...}\'' );
			return;
		}

		$flow_id      = (int) $args[0];
		$flow_step_id = $assoc_args['step'] ?? null;
		$patch_json   = $assoc_args['patch'] ?? null;
		$prompt       = $args[1] ?? null;

		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		if ( null !== $patch_json && null !== $prompt ) {
			WP_CLI::error( 'Cannot use both a positional prompt and --patch. Pick one based on the target step type.' );
			return;
		}

		if ( null === $patch_json && null === $prompt ) {
			WP_CLI::error( 'Provide either a positional prompt (AI step) or --patch=<json> (fetch step).' );
			return;
		}

		if ( empty( $flow_step_id ) ) {
			$resolved = $this->resolveQueueableStep( $flow_id );
			if ( $resolved['error'] ) {
				WP_CLI::error( $resolved['error'] );
				return;
			}
			$flow_step_id = $resolved['step_id'];
		}

		$step_type = $this->getStepType( $flow_id, $flow_step_id );
		if ( null === $step_type ) {
			WP_CLI::error( sprintf( 'Flow step %s not found in flow %d.', $flow_step_id, $flow_id ) );
			return;
		}

		$ability = new \DataMachine\Abilities\FlowAbilities();

		if ( null !== $patch_json ) {
			if ( 'fetch' !== $step_type ) {
				WP_CLI::error( sprintf(
					'--patch is only valid for fetch steps; this step is "%s". For AI steps, pass a positional prompt instead.',
					$step_type
				) );
				return;
			}

			$patch = json_decode( $patch_json, true );
			if ( ! is_array( $patch ) ) {
				WP_CLI::error( 'Invalid --patch: not a JSON object.' );
				return;
			}

			$result = $ability->executeConfigPatchAdd(
				array(
					'flow_id'      => $flow_id,
					'flow_step_id' => $flow_step_id,
					'patch'        => $patch,
				)
			);
		} else {
			if ( 'fetch' === $step_type ) {
				WP_CLI::error( 'Fetch steps consume config patches, not string prompts. Use --patch=\'{...}\' instead.' );
				return;
			}

			if ( empty( trim( (string) $prompt ) ) ) {
				WP_CLI::error( 'prompt cannot be empty' );
				return;
			}

			$result = $ability->executeQueueAdd(
				array(
					'flow_id'      => $flow_id,
					'flow_step_id' => $flow_step_id,
					'prompt'       => $prompt,
				)
			);
		}

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to add item to queue' );
			return;
		}

		WP_CLI::success( $result['message'] ?? 'Item added to queue.' );
	}

	/**
	 * List queued items for a flow step.
	 *
	 * Renders both prompt_queue (AI) and config_patch_queue (fetch)
	 * when the step has either populated. Per-step routing decides
	 * which slot to query, but `flow queue list` is allowed to show
	 * either — convenient for inspecting flows without remembering the
	 * step type.
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : The flow ID.
	 *
	 * [--step=<flow_step_id>]
	 * : Target a specific flow step. Auto-resolved if the flow has exactly one queueable step.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List queued items
	 *     wp datamachine flows queue list 42
	 *
	 *     # List as JSON
	 *     wp datamachine flows queue list 42 --format=json
	 *
	 * @subcommand list
	 */
	public function list_queue( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine flows queue list <flow_id>' );
			return;
		}

		$flow_id      = (int) $args[0];
		$flow_step_id = $assoc_args['step'] ?? null;
		$format       = $assoc_args['format'] ?? 'table';

		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		if ( empty( $flow_step_id ) ) {
			$resolved = $this->resolveQueueableStep( $flow_id );
			if ( $resolved['error'] ) {
				WP_CLI::error( $resolved['error'] );
				return;
			}
			$flow_step_id = $resolved['step_id'];
		}

		$ability = new \DataMachine\Abilities\FlowAbilities();

		$prompt_result = $ability->executeQueueList(
			array(
				'flow_id'      => $flow_id,
				'flow_step_id' => $flow_step_id,
			)
		);

		$patch_result = $ability->executeConfigPatchList(
			array(
				'flow_id'      => $flow_id,
				'flow_step_id' => $flow_step_id,
			)
		);

		if ( ! $prompt_result['success'] && ! $patch_result['success'] ) {
			WP_CLI::error( $prompt_result['error'] ?? $patch_result['error'] ?? 'Failed to list queue' );
			return;
		}

		$prompt_queue = $prompt_result['queue'] ?? array();
		$patch_queue  = $patch_result['queue'] ?? array();
		$queue_mode   = $prompt_result['queue_mode'] ?? $patch_result['queue_mode'] ?? 'static';

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode(
				array(
					'flow_id'            => $flow_id,
					'flow_step_id'       => $flow_step_id,
					'queue_mode'         => $queue_mode,
					'prompt_queue'       => $prompt_queue,
					'config_patch_queue' => $patch_queue,
				),
				JSON_PRETTY_PRINT
			) );
			return;
		}

		if ( empty( $prompt_queue ) && empty( $patch_queue ) ) {
			WP_CLI::log( sprintf( 'Queue is empty. (queue_mode: %s)', $queue_mode ) );
			return;
		}

		if ( ! empty( $prompt_queue ) ) {
			WP_CLI::log( '== AI prompts (prompt_queue) ==' );
			$items = array();
			foreach ( $prompt_queue as $index => $item ) {
				$prompt_preview = mb_strlen( $item['prompt'] ?? '' ) > 60
					? mb_substr( $item['prompt'], 0, 57 ) . '...'
					: ( $item['prompt'] ?? '' );

				$items[] = array(
					'index'    => $index,
					'prompt'   => $prompt_preview,
					'added_at' => $item['added_at'] ?? '',
				);
			}
			$this->format_items( $items, array( 'index', 'prompt', 'added_at' ), $assoc_args, 'index' );
		}

		if ( ! empty( $patch_queue ) ) {
			WP_CLI::log( '== Config patches (config_patch_queue) ==' );
			foreach ( $patch_queue as $index => $item ) {
				$patch     = $item['patch'] ?? array();
				$added_at  = $item['added_at'] ?? '';
				$patch_str = is_array( $patch ) ? wp_json_encode( $patch, JSON_PRETTY_PRINT ) : (string) $patch;
				WP_CLI::log( sprintf( '[%d]  added_at: %s', $index, $added_at ) );
				foreach ( explode( "\n", $patch_str ) as $line ) {
					WP_CLI::log( '      ' . $line );
				}
			}
		}

		WP_CLI::log( sprintf(
			'Total: %d prompt(s), %d patch(es). (queue_mode: %s)',
			count( $prompt_queue ),
			count( $patch_queue ),
			$queue_mode
		) );
	}

	/**
	 * Clear all queued items for a flow step.
	 *
	 * Routes by step type. Fetch steps clear `config_patch_queue`;
	 * other step types clear `prompt_queue`.
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : The flow ID.
	 *
	 * [--step=<flow_step_id>]
	 * : Target a specific flow step. Auto-resolved if the flow has exactly one queueable step.
	 *
	 * ## EXAMPLES
	 *
	 *     # Clear all queued items
	 *     wp datamachine flows queue clear 42
	 *
	 * @subcommand clear
	 */
	public function clear( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine flows queue clear <flow_id>' );
			return;
		}

		$flow_id      = (int) $args[0];
		$flow_step_id = $assoc_args['step'] ?? null;

		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		if ( empty( $flow_step_id ) ) {
			$resolved = $this->resolveQueueableStep( $flow_id );
			if ( $resolved['error'] ) {
				WP_CLI::error( $resolved['error'] );
				return;
			}
			$flow_step_id = $resolved['step_id'];
		}

		$step_type = $this->getStepType( $flow_id, $flow_step_id );
		$ability   = new \DataMachine\Abilities\FlowAbilities();

		if ( 'fetch' === $step_type ) {
			$result = $ability->executeConfigPatchClear(
				array(
					'flow_id'      => $flow_id,
					'flow_step_id' => $flow_step_id,
				)
			);
		} else {
			$result = $ability->executeQueueClear(
				array(
					'flow_id'      => $flow_id,
					'flow_step_id' => $flow_step_id,
				)
			);
		}

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to clear queue' );
			return;
		}

		WP_CLI::success( $result['message'] ?? 'Queue cleared.' );
	}

	/**
	 * Remove a queued item by index.
	 *
	 * Routes by step type.
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : The flow ID.
	 *
	 * <index>
	 * : Zero-based index of the item to remove.
	 *
	 * [--step=<flow_step_id>]
	 * : Target a specific flow step. Auto-resolved if the flow has exactly one queueable step.
	 *
	 * ## EXAMPLES
	 *
	 *     # Remove the first item
	 *     wp datamachine flows queue remove 42 0
	 *
	 * @subcommand remove
	 */
	public function remove( array $args, array $assoc_args ): void {
		if ( count( $args ) < 2 ) {
			WP_CLI::error( 'Usage: wp datamachine flows queue remove <flow_id> <index>' );
			return;
		}

		$flow_id      = (int) $args[0];
		$flow_step_id = $assoc_args['step'] ?? null;
		$index        = (int) $args[1];

		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		if ( empty( $flow_step_id ) ) {
			$resolved = $this->resolveQueueableStep( $flow_id );
			if ( $resolved['error'] ) {
				WP_CLI::error( $resolved['error'] );
				return;
			}
			$flow_step_id = $resolved['step_id'];
		}

		if ( $index < 0 ) {
			WP_CLI::error( 'index must be a non-negative integer' );
			return;
		}

		$step_type = $this->getStepType( $flow_id, $flow_step_id );
		$ability   = new \DataMachine\Abilities\FlowAbilities();

		if ( 'fetch' === $step_type ) {
			$result = $ability->executeConfigPatchRemove(
				array(
					'flow_id'      => $flow_id,
					'flow_step_id' => $flow_step_id,
					'index'        => $index,
				)
			);
		} else {
			$result = $ability->executeQueueRemove(
				array(
					'flow_id'      => $flow_id,
					'flow_step_id' => $flow_step_id,
					'index'        => $index,
				)
			);
		}

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to remove item from queue' );
			return;
		}

		WP_CLI::success( $result['message'] ?? 'Item removed from queue.' );
		if ( ! empty( $result['removed_prompt'] ) ) {
			$preview = mb_strlen( $result['removed_prompt'] ) > 80
				? mb_substr( $result['removed_prompt'], 0, 77 ) . '...'
				: $result['removed_prompt'];
			WP_CLI::log( sprintf( 'Removed: %s', $preview ) );
		} elseif ( ! empty( $result['removed_patch'] ) && is_array( $result['removed_patch'] ) ) {
			WP_CLI::log( 'Removed patch: ' . wp_json_encode( $result['removed_patch'] ) );
		}
	}

	/**
	 * Update a queued item at a specific index.
	 *
	 * Routes by step type. AI steps accept a positional prompt; fetch
	 * steps accept --patch=<json>.
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : The flow ID.
	 *
	 * <index>
	 * : Zero-based index of the item to update.
	 *
	 * [<prompt>]
	 * : Replacement prompt text (for AI steps).
	 *
	 * [--patch=<json>]
	 * : JSON-encoded replacement patch (for fetch steps).
	 *
	 * [--step=<flow_step_id>]
	 * : Target a specific flow step. Auto-resolved if the flow has exactly one queueable step.
	 *
	 * ## EXAMPLES
	 *
	 *     # Update an AI prompt at index 0
	 *     wp datamachine flows queue update 42 0 "Updated prompt text"
	 *
	 *     # Update a fetch patch
	 *     wp datamachine flows queue update 42 0 --patch='{"params":{"after":"2016-01-01"}}'
	 *
	 * @subcommand update
	 */
	public function update( array $args, array $assoc_args ): void {
		if ( count( $args ) < 2 ) {
			WP_CLI::error( 'Usage: wp datamachine flows queue update <flow_id> <index> "new prompt text"  or  --patch=\'{...}\'' );
			return;
		}

		$flow_id      = (int) $args[0];
		$flow_step_id = $assoc_args['step'] ?? null;
		$index        = (int) $args[1];
		$patch_json   = $assoc_args['patch'] ?? null;
		$prompt       = $args[2] ?? null;

		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		if ( null !== $patch_json && null !== $prompt ) {
			WP_CLI::error( 'Cannot use both a positional prompt and --patch.' );
			return;
		}

		if ( null === $patch_json && null === $prompt ) {
			WP_CLI::error( 'Provide either a positional prompt or --patch=<json>.' );
			return;
		}

		if ( empty( $flow_step_id ) ) {
			$resolved = $this->resolveQueueableStep( $flow_id );
			if ( $resolved['error'] ) {
				WP_CLI::error( $resolved['error'] );
				return;
			}
			$flow_step_id = $resolved['step_id'];
		}

		if ( $index < 0 ) {
			WP_CLI::error( 'index must be a non-negative integer' );
			return;
		}

		$step_type = $this->getStepType( $flow_id, $flow_step_id );
		$ability   = new \DataMachine\Abilities\FlowAbilities();

		if ( null !== $patch_json ) {
			if ( 'fetch' !== $step_type ) {
				WP_CLI::error( sprintf( '--patch is only valid for fetch steps; this step is "%s".', $step_type ) );
				return;
			}
			$patch = json_decode( $patch_json, true );
			if ( ! is_array( $patch ) ) {
				WP_CLI::error( 'Invalid --patch: not a JSON object.' );
				return;
			}
			$result = $ability->executeConfigPatchUpdate(
				array(
					'flow_id'      => $flow_id,
					'flow_step_id' => $flow_step_id,
					'index'        => $index,
					'patch'        => $patch,
				)
			);
		} else {
			if ( 'fetch' === $step_type ) {
				WP_CLI::error( 'Fetch steps consume config patches, not string prompts. Use --patch=\'{...}\' instead.' );
				return;
			}
			$result = $ability->executeQueueUpdate(
				array(
					'flow_id'      => $flow_id,
					'flow_step_id' => $flow_step_id,
					'index'        => $index,
					'prompt'       => $prompt,
				)
			);
		}

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to update queue item' );
			return;
		}

		WP_CLI::success( $result['message'] ?? 'Queue item updated.' );
	}

	/**
	 * Move an item from one position to another in the queue.
	 *
	 * Routes by step type.
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : The flow ID.
	 *
	 * <from_index>
	 * : Current zero-based index of the item.
	 *
	 * <to_index>
	 * : Desired zero-based index.
	 *
	 * [--step=<flow_step_id>]
	 * : Target a specific flow step. Auto-resolved if the flow has exactly one queueable step.
	 *
	 * ## EXAMPLES
	 *
	 *     # Move item from position 2 to front of queue
	 *     wp datamachine flows queue move 42 2 0
	 *
	 * @subcommand move
	 */
	public function move( array $args, array $assoc_args ): void {
		if ( count( $args ) < 3 ) {
			WP_CLI::error( 'Usage: wp datamachine flows queue move <flow_id> <from_index> <to_index>' );
			return;
		}

		$flow_id      = (int) $args[0];
		$flow_step_id = $assoc_args['step'] ?? null;
		$from_index   = (int) $args[1];
		$to_index     = (int) $args[2];

		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		if ( empty( $flow_step_id ) ) {
			$resolved = $this->resolveQueueableStep( $flow_id );
			if ( $resolved['error'] ) {
				WP_CLI::error( $resolved['error'] );
				return;
			}
			$flow_step_id = $resolved['step_id'];
		}

		if ( $from_index < 0 || $to_index < 0 ) {
			WP_CLI::error( 'indices must be non-negative integers' );
			return;
		}

		$step_type = $this->getStepType( $flow_id, $flow_step_id );
		$ability   = new \DataMachine\Abilities\FlowAbilities();

		if ( 'fetch' === $step_type ) {
			$result = $ability->executeConfigPatchMove(
				array(
					'flow_id'      => $flow_id,
					'flow_step_id' => $flow_step_id,
					'from_index'   => $from_index,
					'to_index'     => $to_index,
				)
			);
		} else {
			$result = $ability->executeQueueMove(
				array(
					'flow_id'      => $flow_id,
					'flow_step_id' => $flow_step_id,
					'from_index'   => $from_index,
					'to_index'     => $to_index,
				)
			);
		}

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to move item in queue' );
			return;
		}

		WP_CLI::success( $result['message'] ?? 'Item moved in queue.' );
	}

	/**
	 * Set the queue access mode for a flow step.
	 *
	 * Replaces the pre-#1291 `flow queue settings --queue-enabled=...`
	 * verb. Mode is one of:
	 *
	 *   - drain  — pop the head per tick, discard. Empty queue → skip
	 *              with COMPLETED_NO_ITEMS.
	 *   - loop   — pop the head per tick, append to tail. The queue
	 *              cycles indefinitely.
	 *   - static — peek the head every tick, do not mutate. Position 0
	 *              is the active entry; positions 1..N stay staged.
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : The flow ID.
	 *
	 * <mode>
	 * : One of: drain, loop, static.
	 *
	 * [--step=<flow_step_id>]
	 * : Target a specific flow step. Auto-resolved if the flow has exactly one queueable step.
	 *
	 * ## EXAMPLES
	 *
	 *     # Make the AI step drain its prompt queue per tick
	 *     wp datamachine flows queue mode 42 drain
	 *
	 *     # Cycle through queued patches forever
	 *     wp datamachine flows queue mode 42 loop --step=fetch_42_abc
	 *
	 *     # Pin position 0 — runs every tick without mutating the queue
	 *     wp datamachine flows queue mode 42 static
	 *
	 * @subcommand mode
	 */
	public function mode( array $args, array $assoc_args ): void {
		if ( count( $args ) < 2 ) {
			WP_CLI::error( 'Usage: wp datamachine flows queue mode <flow_id> <drain|loop|static>' );
			return;
		}

		$flow_id      = (int) $args[0];
		$mode         = strtolower( (string) $args[1] );
		$flow_step_id = $assoc_args['step'] ?? null;

		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		if ( ! in_array( $mode, array( 'drain', 'loop', 'static' ), true ) ) {
			WP_CLI::error( 'mode must be one of: drain, loop, static' );
			return;
		}

		if ( empty( $flow_step_id ) ) {
			$resolved = $this->resolveQueueableStep( $flow_id );
			if ( $resolved['error'] ) {
				WP_CLI::error( $resolved['error'] );
				return;
			}
			$flow_step_id = $resolved['step_id'];
		}

		$ability = new \DataMachine\Abilities\FlowAbilities();
		$result  = $ability->executeQueueMode(
			array(
				'flow_id'      => $flow_id,
				'flow_step_id' => $flow_step_id,
				'mode'         => $mode,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to set queue mode' );
			return;
		}

		WP_CLI::success( $result['message'] ?? sprintf( 'Queue mode set to %s.', $mode ) );
	}

	/**
	 * Validate a topic against published posts and queue items for duplicates.
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : The flow ID (used for queue duplicate checking).
	 *
	 * <topic>
	 * : The topic or title to validate.
	 *
	 * [--post_type=<post_type>]
	 * : Post type to check against. Default: 'post'.
	 *
	 * [--threshold=<threshold>]
	 * : Jaccard similarity threshold (0.0 to 1.0). Default: 0.65.
	 *
	 * [--step=<flow_step_id>]
	 * : Target a specific flow step for queue checking. Auto-resolved if the flow has exactly one queueable step.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Validate a quiz topic
	 *     wp datamachine flows queue validate 48 "Spider ID Quiz" --post_type=quiz
	 *
	 *     # Validate with stricter threshold
	 *     wp datamachine flows queue validate 25 "Craving Bagels" --threshold=0.5
	 *
	 * @subcommand validate
	 */
	public function validate( array $args, array $assoc_args ): void {
		if ( count( $args ) < 2 ) {
			WP_CLI::error( 'Usage: wp datamachine flows queue validate <flow_id> "topic"' );
			return;
		}

		$flow_id      = (int) $args[0];
		$topic        = $args[1];
		$post_type    = $assoc_args['post_type'] ?? 'post';
		$threshold    = $assoc_args['threshold'] ?? null;
		$flow_step_id = $assoc_args['step'] ?? null;
		$format       = $assoc_args['format'] ?? 'table';

		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		if ( empty( trim( $topic ) ) ) {
			WP_CLI::error( 'topic cannot be empty' );
			return;
		}

		// Resolve flow step for queue checking.
		if ( empty( $flow_step_id ) ) {
			$resolved = $this->resolveQueueableStep( $flow_id );
			if ( $resolved['error'] ) {
				WP_CLI::warning( 'Queue check skipped: ' . $resolved['error'] );
				// Continue — still check published posts.
			} else {
				$flow_step_id = $resolved['step_id'];
			}
		}

		$validator = new \DataMachine\Engine\AI\Tools\Global\QueueValidator();
		$params    = array(
			'topic'     => $topic,
			'post_type' => $post_type,
		);

		if ( null !== $threshold ) {
			$params['similarity_threshold'] = (float) $threshold;
		}

		if ( $flow_id > 0 && ! empty( $flow_step_id ) ) {
			$params['flow_id']      = $flow_id;
			$params['flow_step_id'] = $flow_step_id;
		}

		$result = $validator->validate( $params );

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		// Human-readable output.
		if ( 'clear' === $result['verdict'] ) {
			WP_CLI::success( $result['reason'] );
		} elseif ( 'duplicate' === $result['verdict'] ) {
			WP_CLI::warning( $result['reason'] );
			if ( ! empty( $result['match'] ) ) {
				foreach ( $result['match'] as $key => $value ) {
					WP_CLI::log( sprintf( '  %s: %s', $key, $value ) );
				}
			}
		} else {
			WP_CLI::error( $result['reason'] ?? 'Unknown validation error.' );
		}
	}

	/**
	 * Resolve the queueable step for a flow when --step is not provided.
	 *
	 * @param int $flow_id Flow ID.
	 * @return array{step_id: string|null, error: string|null}
	 */
	private function resolveQueueableStep( int $flow_id ): array {
		global $wpdb;

		$flow = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT flow_config FROM {$wpdb->prefix}datamachine_flows WHERE flow_id = %d",
				$flow_id
			),
			ARRAY_A
		);

		if ( ! $flow ) {
			return array(
				'step_id' => null,
				'error'   => "Flow {$flow_id} not found.",
			);
		}

		$config = json_decode( $flow['flow_config'], true );
		if ( ! is_array( $config ) ) {
			return array(
				'step_id' => null,
				'error'   => 'Invalid flow configuration.',
			);
		}

		// Post-#1291 a step is "queueable" by virtue of its step type
		// (AI consumes prompt_queue, Fetch consumes config_patch_queue).
		// Pre-#1291 the queueable signal was the `queue_enabled` flag,
		// which conflated the access mode with the step's eligibility.
		$queueable = array();
		foreach ( $config as $step_id => $step_data ) {
			if ( ! is_array( $step_data ) ) {
				continue;
			}
			$step_type = $step_data['step_type'] ?? '';
			if ( 'ai' === $step_type || 'fetch' === $step_type ) {
				$queueable[] = $step_id;
			}
		}

		if ( count( $queueable ) === 0 ) {
			return array(
				'step_id' => null,
				'error'   => "Flow {$flow_id} has no queueable steps.",
			);
		}

		if ( count( $queueable ) > 1 ) {
			return array(
				'step_id' => null,
				'error'   => sprintf( 'Flow %d has multiple queueable steps. Use --step: %s', $flow_id, implode( ', ', $queueable ) ),
			);
		}

		return array(
			'step_id' => $queueable[0],
			'error'   => null,
		);
	}

	/**
	 * Look up the step_type of a specific flow step.
	 *
	 * Used to route consumer-aware operations (AI vs Fetch) to the
	 * correct queue slot.
	 *
	 * @param int    $flow_id      Flow ID.
	 * @param string $flow_step_id Flow step ID.
	 * @return string|null Step type, or null if not found.
	 */
	private function getStepType( int $flow_id, string $flow_step_id ): ?string {
		global $wpdb;

		$flow = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT flow_config FROM {$wpdb->prefix}datamachine_flows WHERE flow_id = %d",
				$flow_id
			),
			ARRAY_A
		);

		if ( ! $flow ) {
			return null;
		}

		$config = json_decode( $flow['flow_config'], true );
		if ( ! is_array( $config ) || ! isset( $config[ $flow_step_id ] ) ) {
			return null;
		}

		$step_type = $config[ $flow_step_id ]['step_type'] ?? '';
		return is_string( $step_type ) && '' !== $step_type ? $step_type : null;
	}
}
