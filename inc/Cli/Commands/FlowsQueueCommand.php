<?php
/**
 * WP-CLI Flows Queue Command
 *
 * Manages the prompt queue for flow steps.
 * Extracted from FlowsCommand to follow the focused command pattern.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.31.0
 * @see https://github.com/Extra-Chill/data-machine/issues/345
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;

defined( 'ABSPATH' ) || exit;

class FlowsQueueCommand extends BaseCommand {

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
			WP_CLI::error( 'Usage: wp datamachine flows queue <add|list|clear|remove|update|move> <flow_id> [args...]' );
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
			default:
				WP_CLI::error( "Unknown queue action: {$action}. Use: add, list, clear, remove, update, move" );
		}
	}

	/**
	 * Add a prompt to the flow queue.
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : The flow ID.
	 *
	 * <prompt>
	 * : The prompt text to enqueue.
	 *
	 * [--step=<flow_step_id>]
	 * : Target a specific flow step. Auto-resolved if the flow has exactly one queueable step.
	 *
	 * ## EXAMPLES
	 *
	 *     # Add a prompt to the queue
	 *     wp datamachine flows queue add 42 "Generate a blog post about AI"
	 *
	 *     # Add with explicit step
	 *     wp datamachine flows queue add 42 --step=flow-42-step-abc "Write about cats"
	 *
	 * @subcommand add
	 */
	public function add( array $args, array $assoc_args ): void {
		if ( count( $args ) < 2 ) {
			WP_CLI::error( 'Usage: wp datamachine flows queue add <flow_id> "prompt text"' );
			return;
		}

		$flow_id      = (int) $args[0];
		$flow_step_id = $assoc_args['step'] ?? null;
		$prompt       = $args[1];

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

		if ( empty( trim( $prompt ) ) ) {
			WP_CLI::error( 'prompt cannot be empty' );
			return;
		}

		$ability = new \DataMachine\Abilities\FlowAbilities();
		$result  = $ability->executeQueueAdd(
			array(
				'flow_id'      => $flow_id,
				'flow_step_id' => $flow_step_id,
				'prompt'       => $prompt,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to add prompt to queue' );
			return;
		}

		WP_CLI::success( $result['message'] ?? 'Prompt added to queue.' );
	}

	/**
	 * List all prompts in the flow queue.
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
	 *     # List queued prompts
	 *     wp datamachine flows queue list 42
	 *
	 *     # List queued prompts as JSON
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
		$result  = $ability->executeQueueList(
			array(
				'flow_id'      => $flow_id,
				'flow_step_id' => $flow_step_id,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to list queue' );
			return;
		}

		$queue         = $result['queue'] ?? array();
		$queue_enabled = $result['queue_enabled'] ?? false;

		if ( empty( $queue ) ) {
			WP_CLI::log( sprintf( 'Queue is empty. (queue_enabled: %s)', $queue_enabled ? 'yes' : 'no' ) );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $queue, JSON_PRETTY_PRINT ) );
			return;
		}

		// Transform for table display.
		$items = array();
		foreach ( $queue as $index => $item ) {
			$prompt_preview = mb_strlen( $item['prompt'] ) > 60
				? mb_substr( $item['prompt'], 0, 57 ) . '...'
				: $item['prompt'];

			$items[] = array(
				'index'    => $index,
				'prompt'   => $prompt_preview,
				'added_at' => $item['added_at'] ?? '',
			);
		}

		$this->format_items( $items, array( 'index', 'prompt', 'added_at' ), $assoc_args, 'index' );
		WP_CLI::log( sprintf( 'Total: %d prompt(s) in queue. (queue_enabled: %s)', count( $queue ), $queue_enabled ? 'yes' : 'no' ) );
	}

	/**
	 * Clear all prompts from the flow queue.
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
	 *     # Clear all prompts from queue
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

		$ability = new \DataMachine\Abilities\FlowAbilities();
		$result  = $ability->executeQueueClear(
			array(
				'flow_id'      => $flow_id,
				'flow_step_id' => $flow_step_id,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to clear queue' );
			return;
		}

		WP_CLI::success( $result['message'] ?? 'Queue cleared.' );
	}

	/**
	 * Remove a specific prompt from the queue by index.
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : The flow ID.
	 *
	 * <index>
	 * : Zero-based index of the prompt to remove.
	 *
	 * [--step=<flow_step_id>]
	 * : Target a specific flow step. Auto-resolved if the flow has exactly one queueable step.
	 *
	 * ## EXAMPLES
	 *
	 *     # Remove the first prompt
	 *     wp datamachine flows queue remove 42 0
	 *
	 *     # Remove from specific step
	 *     wp datamachine flows queue remove 42 3 --step=flow-42-step-abc
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

		$ability = new \DataMachine\Abilities\FlowAbilities();
		$result  = $ability->executeQueueRemove(
			array(
				'flow_id'      => $flow_id,
				'flow_step_id' => $flow_step_id,
				'index'        => $index,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to remove prompt from queue' );
			return;
		}

		WP_CLI::success( $result['message'] ?? 'Prompt removed from queue.' );
		if ( ! empty( $result['removed_prompt'] ) ) {
			$preview = mb_strlen( $result['removed_prompt'] ) > 80
				? mb_substr( $result['removed_prompt'], 0, 77 ) . '...'
				: $result['removed_prompt'];
			WP_CLI::log( sprintf( 'Removed: %s', $preview ) );
		}
	}

	/**
	 * Update a prompt at a specific index in the queue.
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : The flow ID.
	 *
	 * <index>
	 * : Zero-based index of the prompt to update.
	 *
	 * <prompt>
	 * : The replacement prompt text.
	 *
	 * [--step=<flow_step_id>]
	 * : Target a specific flow step. Auto-resolved if the flow has exactly one queueable step.
	 *
	 * ## EXAMPLES
	 *
	 *     # Update the first prompt
	 *     wp datamachine flows queue update 42 0 "Updated prompt text"
	 *
	 * @subcommand update
	 */
	public function update( array $args, array $assoc_args ): void {
		if ( count( $args ) < 3 ) {
			WP_CLI::error( 'Usage: wp datamachine flows queue update <flow_id> <index> "new prompt text"' );
			return;
		}

		$flow_id      = (int) $args[0];
		$flow_step_id = $assoc_args['step'] ?? null;
		$index        = (int) $args[1];
		$prompt       = $args[2];

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

		$ability = new \DataMachine\Abilities\FlowAbilities();
		$result  = $ability->executeQueueUpdate(
			array(
				'flow_id'      => $flow_id,
				'flow_step_id' => $flow_step_id,
				'index'        => $index,
				'prompt'       => $prompt,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to update prompt in queue' );
			return;
		}

		WP_CLI::success( $result['message'] ?? 'Prompt updated in queue.' );
	}

	/**
	 * Move a prompt from one position to another in the queue.
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : The flow ID.
	 *
	 * <from_index>
	 * : Current zero-based index of the prompt.
	 *
	 * <to_index>
	 * : Desired zero-based index.
	 *
	 * [--step=<flow_step_id>]
	 * : Target a specific flow step. Auto-resolved if the flow has exactly one queueable step.
	 *
	 * ## EXAMPLES
	 *
	 *     # Move prompt from position 2 to front of queue
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

		$ability = new \DataMachine\Abilities\FlowAbilities();
		$result  = $ability->executeQueueMove(
			array(
				'flow_id'      => $flow_id,
				'flow_step_id' => $flow_step_id,
				'from_index'   => $from_index,
				'to_index'     => $to_index,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to move item in queue' );
			return;
		}

		WP_CLI::success( $result['message'] ?? 'Item moved in queue.' );
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

		$queueable = array();
		foreach ( $config as $step_id => $step_data ) {
			if ( ! empty( $step_data['queue_enabled'] ) ) {
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
}
