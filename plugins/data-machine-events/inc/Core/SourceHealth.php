<?php
/**
 * Read-only source health, using retained root jobs rather than child run totals.
 *
 * @package DataMachineEvents
 */
namespace DataMachineEvents\Core;

class SourceHealth {
	public const STATUSES = array( 'blocked', 'broken_url', 'failing', 'extraction_incomplete', 'empty_source', 'dark', 'never_produced', 'filtered_non_music', 'saturated', 'quiet', 'healthy' );

	public static function failure_class( string $exception ): string {
		if ( preg_match( '/HTTP(?:\/\d(?:\.\d)?)?\s*(?:status\s*[:=]?\s*)?([45]\d\d)\b/i', $exception, $match ) ) {
			return in_array( $match[1], array( '403', '404', '410' ), true ) ? 'http_' . $match[1] : 'http_' . $match[1][0] . 'xx';
		}
		return preg_match( '/curl|timed?\s*out|timeout|could not resolve|connection (?:refused|reset)/i', $exception ) ? 'network' : ( $exception ? 'other' : '' );
	}

	/** Missing telemetry is deliberately not interpreted as a zero-item source. */
	public static function classify( array $row, array $engine = array(), array $children = array() ): array {
		$failure      = self::failure_class( $row['exception'] ?? '' );
		$reason       = self::rejection_reason( $engine );
		$materialized = null;
		$skipped      = 0;
		foreach ( $engine['step_results'] ?? array() as $step ) {
			if ( isset( $step['fetch_stages']['source_materialized'] ) ) {
				$materialized = ( $materialized ?? 0 ) + (int) $step['fetch_stages']['source_materialized'];
				$skipped     += (int) ( $step['fetch_stages']['processed_skipped'] ?? 0 );
			}
		}
		$counts    = $engine['run_metrics']['counts'] ?? array();
		$published = 'completed' === ( $row['latest_status'] ?? '' ) ? 1 : (int) ( $counts['published'] ?? 0 );
		$status    = '';
		if ( str_starts_with( $row['latest_status'] ?? '', 'failed' ) ) {
			$status = 'http_403' === $failure ? 'blocked' : ( in_array( $failure, array( 'http_404', 'http_410' ), true ) ? 'broken_url' : 'failing' );
		} elseif ( ! $published && $materialized > 0 && $children && count( array_filter( $children, static fn( $child ) => ( 'source_rejected' === $child['status'] || str_contains( str_replace( '_', '-', $child['status'] ), 'source-rejected' ) ) && self::is_content_rejection( $child['reason'] ) ) ) === count( $children ) ) {
			$status = 'filtered_non_music';
			$reason = implode( '; ', array_unique( array_column( $children, 'reason' ) ) );
		} elseif ( ! $published && $materialized > 0 && ! $children && ! empty( $counts['source_rejected'] ) && self::is_content_rejection( $reason ) ) {
			$status = 'filtered_non_music';
		} elseif ( ! $published && $materialized > 0 && ( ! empty( $counts['source_rejected'] ) || ! empty( $counts['item_deferred'] ) ) ) {
			$status = 'extraction_incomplete';
		} elseif ( $materialized > 0 && $skipped === $materialized ) {
			$status = 'saturated';
		} elseif ( 0 === $materialized ) {
			$status = 'empty_source';
		} elseif ( ! empty( $row['with_items'] ) ) {
			$status = 'healthy';
		} elseif ( ! empty( $row['upcoming_count'] ) ) {
			$status = 'quiet';
		} elseif ( ! empty( $row['failed'] ) ) {
			$status = 'failing';
		} else {
			$status = empty( $row['lifetime_count'] ) ? 'never_produced' : 'dark';
		}
		return array(
			'status'              => $status,
			'failure_class'       => $failure,
			'rejection_reason'    => (string) $reason,
			'source_materialized' => $materialized,
			'needs_attention'     => in_array( $status, array( 'blocked', 'broken_url', 'failing', 'extraction_incomplete' ), true ) || ( 'empty_source' === $status && str_contains( $row['handlers'] ?? '', 'universal_web_scraper' ) ),
		);
	}

	/** A rejection on content grounds (not music), as opposed to missing data. */
	public static function is_content_rejection( string $reason ): bool {
		return (bool) preg_match( '/non[- ]?music|not (?:a )?music/i', $reason );
	}

	/**
	 * Most specific rejection/defer reason recorded on a run.
	 *
	 * Step-level `reason` holds lifecycle tokens ("completed", "step_exception"),
	 * so it is never treated as a rejection reason.
	 */
	public static function rejection_reason( array $engine ): string {
		foreach ( $engine['step_results'] ?? array() as $step ) {
			foreach ( array( 'source_rejection_reason', 'item_deferred_reason', 'diagnostic_reason' ) as $key ) {
				if ( ! empty( $step[ $key ] ) && is_string( $step[ $key ] ) ) {
					return $step[ $key ];
				}
			}
		}
		// Deferrals record their reason only on the tool trace.
		foreach ( $engine['tool_execution_summary'] ?? array() as $tool ) {
			$reason = $tool['trace']['arguments_redacted']['reason'] ?? '';
			if ( $reason && is_string( $reason ) ) {
				return $reason;
			}
		}
		return (string) ( $engine['source_rejection']['reason'] ?? '' );
	}

	/** Fixed number of set queries; hydrate only the latest root/its children per flow. */
	public function report( int $pipeline = 0, array $statuses = array() ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Table names come from $wpdb->prefix; IN lists are integer IDs read from the database. Read-only report.
		$p     = $wpdb->prefix;
		$flows = $wpdb->get_results( $wpdb->prepare( "SELECT f.flow_id, f.flow_name, f.pipeline_id, f.flow_config, p.pipeline_name FROM {$p}datamachine_flows f LEFT JOIN {$p}datamachine_pipelines p ON p.pipeline_id=f.pipeline_id WHERE (%d=0 OR f.pipeline_id=%d)", $pipeline, $pipeline ), ARRAY_A );
		$rows  = array();
		foreach ( $flows as $flow ) {
			$handlers     = array();
			$urls         = array();
			$event_import = false;
			$config       = json_decode( $flow['flow_config'], true );
			foreach ( is_array( $config ) ? $config : array() as $step ) {
				if ( 'event_import' !== ( $step['step_type'] ?? '' ) || empty( $step['enabled'] ) ) {
					continue;
				}
				$event_import = true;
				foreach ( $step['handler_configs'] ?? array() as $slug => $config ) {
					if ( ! in_array( $slug, $step['handler_slugs'] ?? array(), true ) ) {
						continue;
					}
					$handlers[] = $slug;
					$url        = $config['source_url'] ?? $config['url'] ?? '';
					if ( $url ) {
						$urls[] = $url;
					}
				}
			}
			if ( ! $event_import ) {
				continue;
			}
			unset( $flow['flow_config'] );
			$rows[ (int) $flow['flow_id'] ] = array_merge( $flow, array(
				'handlers'       => implode( ',', array_unique( $handlers ) ),
				'source_url'     => implode( ',', array_unique( $urls ) ),
				'last_published' => null,
				'lifetime_count' => 0,
				'upcoming_count' => 0,
				'total'          => 0,
				'with_items'     => 0,
				'no_items'       => 0,
				'failed'         => 0,
				'latest_status'  => '',
				'exception'      => '',
			) );
		}
		if ( ! $rows ) {
			return self::result( array(), $statuses );
		}
		$ids = implode( ',', array_keys( $rows ) ); // IDs are integers from the database, never raw CLI input.
		// Lifetime and upcoming are separate set queries (one LEFT JOIN with
		// COUNT(DISTINCT) over every event took ~25s on a 765-flow production
		// site). Neither filters on the flow-id list: `meta_value IN (...)` pulls
		// the optimizer off the event_dates range scan, so rows are matched in PHP.
		$posts = $wpdb->get_results( "SELECT m.meta_value flow_id, COUNT(*) lifetime_count, MAX(p.post_date) last_published FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID=m.post_id AND p.post_status='publish' AND p.post_type='data_machine_events' WHERE m.meta_key='_datamachine_post_flow_id' GROUP BY m.meta_value", ARRAY_A );
		foreach ( $posts as $post ) {
			if ( isset( $rows[ (int) $post['flow_id'] ] ) ) {
				$rows[ (int) $post['flow_id'] ] = array_merge( $rows[ (int) $post['flow_id'] ], $post );
			}
		}
		$upcoming = $wpdb->get_results( "SELECT m.meta_value flow_id, COUNT(DISTINCT d.post_id) upcoming_count FROM {$p}datamachine_event_dates d JOIN {$wpdb->postmeta} m ON m.post_id=d.post_id AND m.meta_key='_datamachine_post_flow_id' WHERE d.post_status='publish' AND d.start_datetime >= NOW() GROUP BY m.meta_value", ARRAY_A );
		foreach ( $upcoming as $post ) {
			if ( isset( $rows[ (int) $post['flow_id'] ] ) ) {
				$rows[ (int) $post['flow_id'] ]['upcoming_count'] = $post['upcoming_count'];
			}
		}
		$jobs       = "{$p}datamachine_jobs";
		$runs       = $wpdb->get_results( "SELECT flow_id, COUNT(*) total, SUM(status='completed') with_items, SUM(status LIKE 'completed_no_items%') no_items, SUM(status LIKE 'failed%') failed, MAX(job_id) latest_id FROM $jobs WHERE flow_id IN ($ids) AND (parent_job_id IS NULL OR parent_job_id=0) GROUP BY flow_id", ARRAY_A );
		$latest_ids = array();
		foreach ( $runs as $run ) {
			$rows[ (int) $run['flow_id'] ] = array_merge( $rows[ (int) $run['flow_id'] ], $run );
			$latest_ids[]                  = (int) $run['latest_id'];
		}
		$engines  = array();
		$children = array();
		if ( $latest_ids ) {
			$latest = implode( ',', $latest_ids );
			foreach ( $wpdb->get_results( "SELECT job_id, flow_id, parent_job_id, status, status_reason, engine_data FROM $jobs WHERE job_id IN ($latest) OR parent_job_id IN ($latest)", ARRAY_A ) as $job ) {
				$engine = json_decode( $job['engine_data'] ?? '', true );
				$engine = is_array( $engine ) ? $engine : array();
				if ( empty( $job['parent_job_id'] ) ) {
					$engines[ $job['flow_id'] ]               = $engine;
					$rows[ $job['flow_id'] ]['latest_status'] = $job['status'];
				} else {
					$reason                        = self::rejection_reason( $engine );
					$children[ $job['flow_id'] ][] = array(
						'status' => $job['status'] . ':' . ( $job['status_reason'] ?? '' ),
						'reason' => $reason,
					);
				}
			}
			// Latest retained root failure per flow. Classification still checks the latest run,
			// so a historical exception does not override recovery telemetry.
			foreach ( $wpdb->get_results( "SELECT l.context FROM {$p}datamachine_logs l JOIN (SELECT MAX(logs.id) id FROM {$p}datamachine_logs logs JOIN $jobs j ON j.job_id=JSON_UNQUOTE(JSON_EXTRACT(logs.context, '$.job_id')) WHERE JSON_VALID(logs.context) AND j.flow_id IN ($ids) AND (j.parent_job_id IS NULL OR j.parent_job_id=0) AND JSON_EXTRACT(logs.context, '$.exception') IS NOT NULL GROUP BY j.flow_id) recent ON recent.id=l.id", ARRAY_A ) as $log ) {
				$context = json_decode( $log['context'], true );
				$flow_id = (int) ( $context['flow_id'] ?? 0 );
				if ( isset( $rows[ $flow_id ] ) ) {
					$rows[ $flow_id ]['exception'] = (string) $context['exception'];
				}
			}
		}
		foreach ( $rows as $id => &$row ) {
			$row = array_merge( $row, self::classify( $row, $engines[ $id ] ?? array(), $children[ $id ] ?? array() ) );
			foreach ( array( 'flow_id', 'pipeline_id', 'lifetime_count', 'upcoming_count', 'total', 'with_items', 'no_items', 'failed' ) as $field ) {
				$row[ $field ] = (int) $row[ $field ];
			}
			unset( $row['latest_id'] );
		}
		unset( $row );
		// phpcs:enable
		return self::result( array_values( $rows ), $statuses );
	}

	public static function result( array $rows, array $statuses = array() ): array {
		$summary = array_fill_keys( self::STATUSES, 0 );
		foreach ( $rows as $row ) {
			++$summary[ $row['status'] ];
		}
		$rows = array_values( array_filter( $rows, static fn( $row ) => ! $statuses || in_array( $row['status'], $statuses, true ) ) );
		usort( $rows, static fn( $a, $b ) => array( array_search( $a['status'], self::STATUSES, true ), (int) $a['flow_id'] ) <=> array( array_search( $b['status'], self::STATUSES, true ), (int) $b['flow_id'] ) );
		return array(
			'summary' => $summary,
			'flows'   => $rows,
		);
	}
}
