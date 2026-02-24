<?php
/**
 * Handler for auto-writing daily memory on job completion.
 *
 * Appends a concise summary to today's daily memory file when a pipeline
 * job completes and the daily_memory_enabled setting is active.
 *
 * @package DataMachine\Engine\Actions\Handlers
 * @since 0.32.0
 * @see https://github.com/Extra-Chill/data-machine/issues/353
 */

namespace DataMachine\Engine\Actions\Handlers;

use DataMachine\Core\PluginSettings;
use DataMachine\Core\FilesRepository\DailyMemory;
use DataMachine\Core\Database\Jobs\JobsOperations;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;

class DailyMemoryHandler {

	/**
	 * Handle the datamachine_job_complete action.
	 *
	 * Checks daily_memory_enabled, looks up job/flow/pipeline context,
	 * formats a concise entry, and appends it to today's daily file.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $status Final job status.
	 */
	public static function handle( int $job_id, string $status ): void {
		if ( ! PluginSettings::get( 'daily_memory_enabled', false ) ) {
			return;
		}

		$job = self::getJob( $job_id );
		if ( ! $job ) {
			return;
		}

		$entry = self::formatEntry( $job, $status );
		if ( empty( $entry ) ) {
			return;
		}

		$daily = new DailyMemory();
		$now   = gmdate( 'Y-m-d' );
		$parts = explode( '-', $now );

		$daily->append( $parts[0], $parts[1], $parts[2], $entry );
	}

	/**
	 * Get job data with flow and pipeline names resolved.
	 *
	 * @param int $job_id Job ID.
	 * @return array|null Enriched job data or null.
	 */
	private static function getJob( int $job_id ): ?array {
		$jobs_ops = new JobsOperations();
		$job      = $jobs_ops->get_job( $job_id );

		if ( ! $job ) {
			return null;
		}

		// Resolve flow name.
		$job['flow_name'] = null;
		if ( ! empty( $job['flow_id'] ) && 'direct' !== $job['flow_id'] ) {
			$flows_db = new Flows();
			$flow     = $flows_db->get_flow( (int) $job['flow_id'] );
			if ( $flow ) {
				$job['flow_name'] = $flow['flow_name'] ?? null;
			}
		}

		// Resolve pipeline name.
		$job['pipeline_name'] = null;
		if ( ! empty( $job['pipeline_id'] ) && 'direct' !== $job['pipeline_id'] ) {
			$pipelines_db = new Pipelines();
			$pipeline     = $pipelines_db->get_pipeline( (int) $job['pipeline_id'] );
			if ( $pipeline ) {
				$job['pipeline_name'] = $pipeline['pipeline_name'] ?? null;
			}
		}

		return $job;
	}

	/**
	 * Format a concise daily memory entry for a completed job.
	 *
	 * @param array  $job    Enriched job data.
	 * @param string $status Final job status.
	 * @return string Formatted markdown entry.
	 */
	private static function formatEntry( array $job, string $status ): string {
		// Build the heading — prefer flow name, fall back to pipeline name.
		$label = $job['label'] ?? null;
		$flow  = $job['flow_name'] ?? null;
		$pipe  = $job['pipeline_name'] ?? null;

		$heading = $label ?? $flow ?? $pipe ?? 'Job #' . $job['job_id'];

		// Compute duration if timestamps exist.
		$duration = '';
		if ( ! empty( $job['created_at'] ) && ! empty( $job['completed_at'] ) ) {
			$start   = strtotime( $job['created_at'] );
			$end     = strtotime( $job['completed_at'] );
			$seconds = $end - $start;

			if ( $seconds < 60 ) {
				$duration = $seconds . 's';
			} elseif ( $seconds < 3600 ) {
				$duration = round( $seconds / 60, 1 ) . 'm';
			} else {
				$duration = round( $seconds / 3600, 1 ) . 'h';
			}
		}

		// Build bullet points.
		$bullets = array();

		$bullets[] = '- Status: ' . $status;

		if ( $flow && $pipe ) {
			$bullets[] = '- Pipeline: ' . $pipe;
		}

		if ( $duration ) {
			$bullets[] = '- Duration: ' . $duration;
		}

		$source = $job['source'] ?? 'pipeline';
		if ( 'direct' === $source ) {
			$bullets[] = '- Source: direct execution';
		}

		return '### ' . $heading . "\n" . implode( "\n", $bullets ) . "\n";
	}
}
