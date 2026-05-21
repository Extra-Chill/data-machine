<?php
/**
 * WP-CLI Data Machine cycle command.
 *
 * @package DataMachine\Cli\Commands
 */

namespace DataMachine\Cli\Commands;

use DataMachine\Cli\BaseCommand;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Flows\CycleFlowSelector;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Run flows that are due during an external cycle.
 */
class CycleCommand extends BaseCommand {

	/**
	 * Run all flows due for an external cycle.
	 *
	 * This is the orchestration primitive for environments that wake on a single
	 * external trigger, such as a CI or Playground day cycle. Interval and cron
	 * flows use Data Machine's existing jobs-table readiness logic. Manual flows
	 * only join the cycle when their scheduling config explicitly includes
	 * `cycle_policy: every_cycle`.
	 *
	 * ## OPTIONS
	 *
	 * [<cycle>]
	 * : Human-readable cycle slug for logs/output.
	 * ---
	 * default: default
	 * ---
	 *
	 * [--dry-run]
	 * : Select due flows without starting jobs.
	 *
	 * [--[no-]drain]
	 * : Drain due Data Machine actions after starting due flows.
	 * ---
	 * default: true
	 * ---
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
	 *     wp datamachine cycle run world-of-wordpress
	 *     wp datamachine cycle run world-of-wordpress --dry-run --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Keyed arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! empty( $args ) && 'run' === $args[0] ) {
			array_shift( $args );
		}

		$cycle   = sanitize_key( (string) ( $args[0] ?? 'default' ) );
		$format  = (string) ( $assoc_args['format'] ?? 'table' );
		$dry_run = isset( $assoc_args['dry-run'] );
		$drain   = ! $dry_run && \WP_CLI\Utils\get_flag_value( $assoc_args, 'drain', true );

		$flows_repo            = new Flows();
		$scheduled_ready_flows = $flows_repo->get_flows_ready_for_execution();
		$all_flows             = $flows_repo->get_all_flows();
		$selected              = CycleFlowSelector::select_due_flows( $scheduled_ready_flows, $all_flows );

		$rows = array();
		foreach ( $selected as $item ) {
			$flow   = $item['flow'];
			$rows[] = array(
				'flow_id'       => (int) ( $flow['flow_id'] ?? 0 ),
				'flow_name'     => (string) ( $flow['flow_name'] ?? '' ),
				'portable_slug' => (string) ( $flow['portable_slug'] ?? '' ),
				'reason'        => $item['reason'],
				'status'        => $dry_run ? 'would_run' : 'pending',
				'job_id'        => null,
			);
		}

		if ( ! $dry_run && ! empty( $rows ) ) {
			$ability = wp_get_ability( 'datamachine/run-flow' );
			if ( ! $ability ) {
				WP_CLI::error( 'Run flow ability not registered.' );
				return;
			}

			foreach ( $rows as &$row ) {
				$result = $ability->execute( array( 'flow_id' => (int) $row['flow_id'] ) );
				if ( ! ( $result['success'] ?? false ) ) {
					$row['status'] = 'failed_to_start';
					$row['error']  = (string) ( $result['error'] ?? 'Failed to run flow' );
					continue;
				}

				if ( ! empty( $result['skipped'] ) ) {
					$row['status'] = 'suppressed';
					$row['reason'] = (string) ( $result['reason'] ?? $row['reason'] );
					continue;
				}

				$row['status'] = 'started';
				$row['job_id'] = isset( $result['job_id'] ) ? (int) $result['job_id'] : null;
			}
			unset( $row );
		}

		$drain_stats = null;
		if ( $drain ) {
			$drain_stats = DrainCommand::drain(
				array(
					'hooks' => array(
						DrainCommand::HOOK_BATCH_CHUNK,
						DrainCommand::HOOK_EXECUTE_STEP,
					),
				)
			);
		}

		$summary = array(
			'cycle'       => $cycle,
			'dry_run'     => $dry_run,
			'selected'    => count( $rows ),
			'flows'       => $rows,
			'drain_stats' => $drain_stats,
		);

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $summary, JSON_PRETTY_PRINT ) );
			return;
		}

		WP_CLI::log( sprintf( 'Cycle: %s', $cycle ) );
		if ( empty( $rows ) ) {
			WP_CLI::success( 'No flows due for this cycle.' );
		} else {
			$this->format_items( $rows, array( 'flow_id', 'flow_name', 'portable_slug', 'reason', 'status', 'job_id' ), array( 'format' => 'table' ) );
		}

		if ( null !== $drain_stats ) {
			WP_CLI::log(
				sprintf(
					'Drained Data Machine actions: %d batch chunks, %d step executions, %d completions, %d failures, %d due pending remain.',
					$drain_stats['batch_chunks'],
					$drain_stats['step_executions'],
					$drain_stats['completions'],
					$drain_stats['failures'],
					$drain_stats['remaining_pending']
				)
			);
		}
	}
}
