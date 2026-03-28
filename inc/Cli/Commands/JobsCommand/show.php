//! show — extracted from JobsCommand.php.


	/**
	 * List jobs with optional status filter.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by status (pending, processing, completed, failed, agent_skipped, completed_no_items).
	 *
	 * [--flow=<flow_id>]
	 * : Filter by flow ID.
	 *
	 * [--source=<source>]
	 * : Filter by source (pipeline, system).
	 *
	 * [--since=<datetime>]
	 * : Show jobs created after this time. Accepts ISO datetime or relative strings (e.g., "1 hour ago", "today", "yesterday").
	 *
	 * [--limit=<limit>]
	 * : Number of jobs to show.
	 * ---
	 * default: 20
	 * ---
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
	 *   - ids
	 *   - count
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit output to specific fields (comma-separated).
	 *
	 * ## EXAMPLES
	 *
	 *     # List recent jobs
	 *     wp datamachine jobs list
	 *
	 *     # List processing jobs
	 *     wp datamachine jobs list --status=processing
	 *
	 *     # List jobs for a specific flow
	 *     wp datamachine jobs list --flow=98 --limit=50
	 *
	 *     # Output as CSV
	 *     wp datamachine jobs list --format=csv
	 *
	 *     # Output only IDs (space-separated)
	 *     wp datamachine jobs list --format=ids
	 *
	 *     # Count total jobs
	 *     wp datamachine jobs list --format=count
	 *
	 *     # JSON output
	 *     wp datamachine jobs list --format=json
	 *
	 *     # Show failed jobs from the last 2 hours
	 *     wp datamachine jobs list --status=failed --since="2 hours ago"
	 *
	 *     # Show all jobs since midnight
	 *     wp datamachine jobs list --since=today
	 *
	 * @subcommand list
	 */
	public function list_jobs( array $args, array $assoc_args ): void {
		$status  = $assoc_args['status'] ?? null;
		$flow_id = isset( $assoc_args['flow'] ) ? (int) $assoc_args['flow'] : null;
		$limit   = (int) ( $assoc_args['limit'] ?? 20 );
		$format  = $assoc_args['format'] ?? 'table';

		if ( $limit < 1 ) {
			$limit = 20;
		}
		if ( $limit > 500 ) {
			$limit = 500;
		}

		$scoping = AgentResolver::buildScopingInput( $assoc_args );

		$input = array_merge(
			$scoping,
			array(
				'per_page' => $limit,
				'offset'   => 0,
				'orderby'  => 'j.job_id',
				'order'    => 'DESC',
			)
		);

		if ( $status ) {
			$input['status'] = $status;
		}

		if ( $flow_id ) {
			$input['flow_id'] = $flow_id;
		}

		$since = $assoc_args['since'] ?? null;
		if ( $since ) {
			$timestamp = strtotime( $since );
			if ( false === $timestamp ) {
				WP_CLI::error( sprintf( 'Invalid --since value: "%s". Use ISO datetime or relative string (e.g., "1 hour ago", "today").', $since ) );
				return;
			}
			$input['since'] = gmdate( 'Y-m-d H:i:s', $timestamp );
		}

		$result = $this->abilities->executeGetJobs( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Unknown error occurred' );
			return;
		}

		$jobs = $result['jobs'] ?? array();

		if ( empty( $jobs ) ) {
			WP_CLI::warning( 'No jobs found.' );
			return;
		}

		// Filter by source if specified.
		$source_filter = $assoc_args['source'] ?? null;
		if ( $source_filter ) {
			$jobs = array_filter(
				$jobs,
				function ( $j ) use ( $source_filter ) {
					return ( $j['source'] ?? 'pipeline' ) === $source_filter;
				}
			);
			$jobs = array_values( $jobs );

			if ( empty( $jobs ) ) {
				WP_CLI::warning( sprintf( 'No %s jobs found.', $source_filter ) );
				return;
			}
		}

		// Transform jobs to flat row format.
		$items = array_map(
			function ( $j ) {
				$source         = $j['source'] ?? 'pipeline';
				$status_display = strlen( $j['status'] ?? '' ) > 40 ? substr( $j['status'], 0, 40 ) . '...' : ( $j['status'] ?? '' );

				if ( 'system' === $source ) {
					$flow_display = $j['label'] ?? $j['display_label'] ?? 'System Task';
				} else {
					$flow_display = $j['flow_name'] ?? ( isset( $j['flow_id'] ) ? "Flow {$j['flow_id']}" : '' );
				}

				return array(
					'id'        => $j['job_id'] ?? '',
					'source'    => $source,
					'flow'      => $flow_display,
					'status'    => $status_display,
					'created'   => $j['created_at'] ?? '',
					'completed' => $j['completed_at'] ?? '-',
				);
			},
			$jobs
		);

		$this->format_items( $items, $this->default_fields, $assoc_args, 'id' );

		if ( 'table' === $format ) {
			WP_CLI::log( sprintf( 'Showing %d jobs.', count( $jobs ) ) );
		}
	}

	/**
	 * Show detailed information about a specific job.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : The job ID to display.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Show job details
	 *     wp datamachine jobs show 844
	 *
	 *     # Show job as JSON (includes full engine_data)
	 *     wp datamachine jobs show 844 --format=json
	 *
	 * @subcommand show
	 */
	public function show( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Job ID is required.' );
			return;
		}

		$job_id = $args[0];

		if ( ! is_numeric( $job_id ) || (int) $job_id <= 0 ) {
			WP_CLI::error( 'Job ID must be a positive integer.' );
			return;
		}

		$result = $this->abilities->executeGetJobs( array( 'job_id' => (int) $job_id ) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Unknown error occurred' );
			return;
		}

		$jobs = $result['jobs'] ?? array();

		if ( empty( $jobs ) ) {
			WP_CLI::error( sprintf( 'Job %d not found.', (int) $job_id ) );
			return;
		}

		$job    = $jobs[0];
		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( 'yaml' === $format ) {
			WP_CLI::log( \Spyc::YAMLDump( $job, false, false, true ) );
			return;
		}

		$this->outputJobTable( $job );
	}

	/**
	 * Extract a summary of engine_data for CLI display.
	 *
	 * Iterates all top-level keys and formats each value by type:
	 * scalars display directly (strings truncated at 120 chars),
	 * arrays show item count and serialized size, bools/nulls display
	 * as literals. No hardcoded key list — works for any job type.
	 *
	 * @param array $engine_data Full engine data array.
	 * @return array Key-value pairs for display.
	 */
	private function extractEngineDataSummary( array $engine_data ): array {
		$summary = array();

		foreach ( $engine_data as $key => $value ) {
			$label = ucwords( str_replace( '_', ' ', $key ) );

			if ( is_array( $value ) ) {
				$count             = count( $value );
				$json              = wp_json_encode( $value );
				$size              = strlen( $json );
				$summary[ $label ] = sprintf( 'array (%d items, %s)', $count, size_format( $size ) );
			} elseif ( is_bool( $value ) ) {
				$summary[ $label ] = $value ? 'true' : 'false';
			} elseif ( is_null( $value ) ) {
				$summary[ $label ] = '(null)';
			} elseif ( is_string( $value ) && strlen( $value ) > 120 ) {
				$summary[ $label ] = substr( $value, 0, 117 ) . '...';
			} else {
				$summary[ $label ] = (string) $value;
			}
		}

		return $summary;
	}

	/**
	 * Show job status summary grouped by status.
	 *
	 * ## OPTIONS
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
	 * [--fields=<fields>]
	 * : Limit output to specific fields (comma-separated).
	 *
	 * ## EXAMPLES
	 *
	 *     # Show status summary
	 *     wp datamachine jobs summary
	 *
	 *     # Output as CSV
	 *     wp datamachine jobs summary --format=csv
	 *
	 *     # JSON output
	 *     wp datamachine jobs summary --format=json
	 *
	 * @subcommand summary
	 */
	public function summary( array $args, array $assoc_args ): void {
		$result = $this->abilities->executeGetJobsSummary( array() );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Unknown error occurred' );
			return;
		}

		$summary = $result['summary'] ?? array();

		if ( empty( $summary ) ) {
			WP_CLI::warning( 'No job summary data available.' );
			return;
		}

		// Transform summary to row format.
		$items = array();
		foreach ( $summary as $status => $count ) {
			$items[] = array(
				'status' => $status,
				'count'  => $count,
			);
		}

		$this->format_items( $items, array( 'status', 'count' ), $assoc_args );
	}
