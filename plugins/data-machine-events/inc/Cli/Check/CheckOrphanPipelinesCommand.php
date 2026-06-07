<?php
/**
 * `wp data-machine-events check orphan-pipelines`
 *
 * Audit + repair pass for pipelines whose `pipeline_config` is empty or
 * invalid JSON while one or more flows are still scheduled against them.
 *
 * Per Extra-Chill/data-machine-events#363, pipeline 20 ("Nashville
 * Events") on events.extrachill.com had its `pipeline_config` wiped to a
 * zero-length string while 13 flows kept running daily/twice-daily
 * against it. Every scheduled run walked the flow's steps, failed to
 * resolve them against the (empty) pipeline config, and the Data Machine
 * engine logged a `Pipeline step not found in pipeline config` ERROR per
 * step, per run — 734 errors in ~2.6 days, plus hundreds of doomed jobs.
 *
 * The flows themselves remained intact: each carries a full, well-formed
 * `flow_config` describing its steps (e.g. event_import -> ai -> upsert),
 * including the `pipeline_step_id`, `step_type` and `execution_order` for
 * each. The pipeline_config — which is the engine's source of truth for
 * step resolution — is what got emptied. Because every flow on a pipeline
 * shares the same set of pipeline_step_ids, the pipeline_config can be
 * reconstructed from the surviving flow_config of any one of its flows.
 *
 * Behavior per broken pipeline, in order:
 *
 *   1. DETECT pipelines where `pipeline_config` is NULL, empty, or fails
 *      JSON_VALID, AND at least one flow references the pipeline.
 *
 *   2. RECONSTRUCT a candidate pipeline_config from the flow_config of
 *      the pipeline's flows: for each distinct `pipeline_step_id` found
 *      across the flows, emit a pipeline-level step entry keyed by that
 *      id, carrying step_type, execution_order, pipeline_step_id and the
 *      (flow-agnostic) step settings. If no flow carries usable step data
 *      the pipeline cannot be auto-repaired and is flagged for manual
 *      review instead.
 *
 *   3. REPAIR (opt-in via --rebuild-config + --apply): write the
 *      reconstructed config back to `pipeline_config`. Default behavior is
 *      AUDIT-ONLY — report the broken pipelines and what would be rebuilt
 *      without writing anything.
 *
 * Default `--dry-run`; require `--apply` to commit. `--rebuild-config` is
 * opt-in even with `--apply` (mirrors `check orphan-venues --delete-orphans`).
 *
 * NOTE: this command repairs the DATA. The engine-side resilience gap —
 * the fact that `get_pipeline_step_config()` in data-machine core logs a
 * hard ERROR on every run instead of skipping/pausing a flow that
 * references a missing step — is a separate concern tracked against
 * Extra-Chill/data-machine (engine step resolution). This command does
 * not and cannot fix that; it removes the data condition that triggers it.
 *
 * @package DataMachineEvents\Cli\Check
 * @since   0.41.0
 */

namespace DataMachineEvents\Cli\Check;

defined( 'ABSPATH' ) || exit;

class CheckOrphanPipelinesCommand {

	/**
	 * Audit + repair pipelines with an empty/invalid pipeline_config that
	 * still have flows scheduled against them.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be flagged / rebuilt without writing. Default
	 *   behavior — pass --apply to commit changes.
	 *
	 * [--apply]
	 * : Actually perform the repair work. Without --rebuild-config this
	 *   only re-reports (no destructive default action exists).
	 *
	 * [--rebuild-config]
	 * : Opt-in to REBUILDING the empty/invalid pipeline_config from the
	 *   surviving flow_config of the pipeline's flows. No effect under
	 *   --dry-run.
	 *
	 * [--pipeline-id=<id>]
	 * : Restrict the run to a single pipeline_id.
	 *
	 * [--format=<format>]
	 * : Output format for the per-pipeline table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp data-machine-events check orphan-pipelines --dry-run
	 *     wp data-machine-events check orphan-pipelines --apply --rebuild-config
	 *     wp data-machine-events check orphan-pipelines --pipeline-id=20 --dry-run
	 *     wp data-machine-events check orphan-pipelines --dry-run --format=csv
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$apply          = isset( $assoc_args['apply'] );
		$rebuild_config = isset( $assoc_args['rebuild-config'] );
		$only_pipeline  = isset( $assoc_args['pipeline-id'] ) ? (int) $assoc_args['pipeline-id'] : 0;
		$format         = (string) ( $assoc_args['format'] ?? 'table' );

		$dry_run = ! $apply;

		$candidates = $this->find_broken_pipelines( $only_pipeline );

		if ( empty( $candidates ) ) {
			\WP_CLI::success( 'No pipelines with empty/invalid config and active flows detected.' );
			return;
		}

		\WP_CLI::log( sprintf( 'Detected %d pipeline(s) with empty/invalid config and active flows.', count( $candidates ) ) );

		$rows = array();

		foreach ( $candidates as $candidate ) {
			$rows[] = $this->process_candidate( $candidate, $dry_run, $rebuild_config );
		}

		\WP_CLI\Utils\format_items(
			$format,
			$rows,
			array(
				'pipeline_id',
				'pipeline_name',
				'flow_count',
				'steps_recoverable',
				'action_taken',
				'reason',
			)
		);

		if ( $dry_run ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'DRY RUN — no changes made. Re-run with --apply --rebuild-config to repair.' );
			return;
		}

		$rebuilt = 0;
		$flagged = 0;
		foreach ( $rows as $row ) {
			if ( 'rebuilt' === $row['action_taken'] ) {
				++$rebuilt;
			} elseif ( 'flagged' === $row['action_taken'] ) {
				++$flagged;
			}
		}

		\WP_CLI::success(
			sprintf(
				'Processed %d pipeline(s): %d config(s) rebuilt, %d flagged for manual review.',
				count( $rows ),
				$rebuilt,
				$flagged
			)
		);
	}

	/**
	 * Find pipelines whose pipeline_config is NULL/empty/invalid JSON and
	 * which still have at least one flow referencing them.
	 *
	 * @param int $only_pipeline Optional single pipeline_id to restrict to.
	 * @return array<int,object> Rows with pipeline_id, pipeline_name, flow_count.
	 */
	private function find_broken_pipelines( int $only_pipeline = 0 ): array {
		global $wpdb;

		$pipelines_table = $wpdb->prefix . 'datamachine_pipelines';
		$flows_table     = $wpdb->prefix . 'datamachine_flows';

		$where  = '( p.pipeline_config IS NULL OR LENGTH(p.pipeline_config) = 0 OR JSON_VALID(p.pipeline_config) = 0 )';
		$params = array();

		if ( $only_pipeline > 0 ) {
			$where   .= ' AND p.pipeline_id = %d';
			$params[] = $only_pipeline;
		}

		$sql = "SELECT p.pipeline_id, p.pipeline_name, COUNT(f.flow_id) AS flow_count
				FROM {$pipelines_table} p
				INNER JOIN {$flows_table} f ON f.pipeline_id = p.pipeline_id
				WHERE {$where}
				GROUP BY p.pipeline_id, p.pipeline_name
				HAVING flow_count > 0
				ORDER BY p.pipeline_id ASC";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names interpolated from $wpdb->prefix; user input bound via prepare below.
		$prepared = empty( $params ) ? $sql : $wpdb->prepare( $sql, $params );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( $prepared );

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Audit (and optionally repair) a single broken pipeline.
	 *
	 * @param object $candidate      Row from find_broken_pipelines().
	 * @param bool   $dry_run        When true, do not write.
	 * @param bool   $rebuild_config When true (and not dry-run), write the rebuilt config.
	 * @return array<string,mixed> Result row for the output table.
	 */
	private function process_candidate( object $candidate, bool $dry_run, bool $rebuild_config ): array {
		$pipeline_id = (int) $candidate->pipeline_id;

		$reconstructed = $this->reconstruct_config_from_flows( $pipeline_id );

		$base = array(
			'pipeline_id'       => $pipeline_id,
			'pipeline_name'     => (string) $candidate->pipeline_name,
			'flow_count'        => (int) $candidate->flow_count,
			'steps_recoverable' => count( $reconstructed ),
		);

		if ( empty( $reconstructed ) ) {
			return array_merge(
				$base,
				array(
					'action_taken' => 'flagged',
					'reason'       => 'no recoverable step data in any flow_config; needs manual review',
				)
			);
		}

		if ( $dry_run || ! $rebuild_config ) {
			return array_merge(
				$base,
				array(
					'action_taken' => 'would-rebuild',
					'reason'       => 'pipeline_config rebuildable from flow_config (run --apply --rebuild-config)',
				)
			);
		}

		$written = $this->write_pipeline_config( $pipeline_id, $reconstructed );

		return array_merge(
			$base,
			array(
				'action_taken' => $written ? 'rebuilt' : 'flagged',
				'reason'       => $written
					? sprintf( 'rebuilt pipeline_config with %d step(s) from flow_config', count( $reconstructed ) )
					: 'write to pipeline_config failed',
			)
		);
	}

	/**
	 * Reconstruct a pipeline-level config map from the flow_config of the
	 * pipeline's flows.
	 *
	 * Each flow_config entry carries the pipeline_step_id, step_type,
	 * execution_order and the step settings. The pipeline_config is keyed
	 * by pipeline_step_id. We take the first occurrence of each distinct
	 * pipeline_step_id across all flows (they are identical across a
	 * pipeline's flows by construction) and strip the flow-scoped fields
	 * (flow_step_id, flow_id) so only pipeline-level data remains.
	 *
	 * @param int $pipeline_id Pipeline to rebuild.
	 * @return array<string,array> pipeline_config keyed by pipeline_step_id.
	 */
	private function reconstruct_config_from_flows( int $pipeline_id ): array {
		global $wpdb;

		$flows_table = $wpdb->prefix . 'datamachine_flows';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$flow_configs = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT flow_config FROM {$flows_table} WHERE pipeline_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				$pipeline_id
			)
		);

		if ( empty( $flow_configs ) ) {
			return array();
		}

		$pipeline_config = array();

		foreach ( $flow_configs as $raw ) {
			$decoded = json_decode( (string) $raw, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			foreach ( $decoded as $step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}

				$pipeline_step_id = isset( $step['pipeline_step_id'] ) ? (string) $step['pipeline_step_id'] : '';
				if ( '' === $pipeline_step_id ) {
					continue;
				}

				// First occurrence wins; identical across a pipeline's flows.
				if ( isset( $pipeline_config[ $pipeline_step_id ] ) ) {
					continue;
				}

				$pipeline_step = $step;

				// Strip flow-scoped fields — pipeline_config holds pipeline-level data only.
				unset( $pipeline_step['flow_step_id'], $pipeline_step['flow_id'] );

				$pipeline_config[ $pipeline_step_id ] = $pipeline_step;
			}
		}

		// Order the map by execution_order for readability/parity with healthy configs.
		uasort(
			$pipeline_config,
			static function ( $a, $b ) {
				$ao = isset( $a['execution_order'] ) ? (int) $a['execution_order'] : 0;
				$bo = isset( $b['execution_order'] ) ? (int) $b['execution_order'] : 0;
				return $ao <=> $bo;
			}
		);

		return $pipeline_config;
	}

	/**
	 * Persist a rebuilt pipeline_config back to the pipelines table.
	 *
	 * @param int                  $pipeline_id     Pipeline to update.
	 * @param array<string,array>  $pipeline_config Reconstructed config.
	 * @return bool True on a successful write.
	 */
	private function write_pipeline_config( int $pipeline_id, array $pipeline_config ): bool {
		global $wpdb;

		$pipelines_table = $wpdb->prefix . 'datamachine_pipelines';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$pipelines_table,
			array( 'pipeline_config' => wp_json_encode( $pipeline_config ) ),
			array( 'pipeline_id' => $pipeline_id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}
}
