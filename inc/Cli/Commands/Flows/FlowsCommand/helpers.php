//! helpers — extracted from FlowsCommand.php.


	/**
	 * Get flows with optional filtering.
	 *
	 * ## OPTIONS
	 *
	 * [<args>...]
	 * : Subcommand and arguments. Accepts: list [pipeline_id], get <flow_id>, run <flow_id>, create, delete <flow_id>, update <flow_id>.
	 *
	 * [--handler=<slug>]
	 * : Filter flows using this handler slug (any step that uses this handler).
	 *
	 * [--per_page=<number>]
	 * : Number of flows to return. 0 = all (default).
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--offset=<number>]
	 * : Offset for pagination.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--id=<flow_id>]
	 * : Get a specific flow by ID.
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
	 * [--count=<number>]
	 * : Number of times to run the flow (1-10, immediate execution only).
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--timestamp=<unix>]
	 * : Unix timestamp for delayed execution (future time required).
	 *
	 * [--pipeline_id=<id>]
	 * : Pipeline ID for flow creation (create subcommand).
	 *
	 * [--name=<name>]
	 * : Flow name (create subcommand).
	 *
	 * [--step_configs=<json>]
	 * : JSON object with step configurations keyed by step_type (create subcommand).
	 *
	 * [--scheduling=<interval>]
	 * : Scheduling interval (manual, hourly, daily, one_time, etc.) or cron expression (e.g. "0 9 * * 1-5").
	 *
	 * [--scheduled-at=<datetime>]
	 * : ISO-8601 datetime for one-time scheduling (e.g. "2026-03-20T15:00:00Z"). Implies --scheduling=one_time.
	 *
	 * [--set-prompt=<text>]
	 * : Update the prompt for a handler step (requires handler step to exist).
	 *
	 * [--handler-config=<json>]
	 * : JSON object of handler config key-value pairs to update (merged with existing config).
	 *   Requires --step to identify the target flow step.
	 *
	 * [--step=<flow_step_id>]
	 * : Target a specific flow step for prompt update or handler config update (auto-resolved if flow has exactly one handler step).
	 *
	 * [--add=<filename>]
	 * : Attach a memory file to a flow (memory-files subcommand).
	 *
	 * [--remove=<filename>]
	 * : Detach a memory file from a flow (memory-files subcommand).
	 *
	 * [--post_type=<post_type>]
	 * : Post type to check against (validate subcommand). Default: 'post'.
	 *
	 * [--threshold=<threshold>]
	 * : Jaccard similarity threshold 0.0-1.0 (validate subcommand). Default: 0.65.
	 *
	 * [--dry-run]
	 * : Validate without creating (create subcommand).
	 *
	 * [--pipeline=<id>]
	 * : Pipeline ID for pause/resume scoping.
	 *
	 * [--agent=<slug_or_id>]
	 * : Agent slug or ID for scoping (pause/resume/list).
	 *
	 * [--yes]
	 * : Skip confirmation prompt (delete subcommand).
	 *
	 * ## EXAMPLES
	 *
	 *     # List all flows
	 *     wp datamachine flows
	 *
	 *     # List flows for pipeline 5
	 *     wp datamachine flows 5
	 *
	 *     # List flows using rss handler
	 *     wp datamachine flows --handler=rss
	 *
	 *     # Get a specific flow by ID
	 *     wp datamachine flows get 42
	 *
	 *     # Run a flow immediately
	 *     wp datamachine flows run 42
	 *
	 *     # Create a new flow
	 *     wp datamachine flows create --pipeline_id=3 --name="My Flow"
	 *
	 *     # Delete a flow
	 *     wp datamachine flows delete 141
	 *
	 *     # Update flow name
	 *     wp datamachine flows update 141 --name="New Name"
	 *
	 *     # Update flow prompt
	 *     wp datamachine flows update 42 --set-prompt="New prompt text"
	 *
	 *     # Add a handler to a flow step
	 *     wp datamachine flows add-handler 42 --handler=rss
	 *
	 *     # Remove a handler from a flow step
	 *     wp datamachine flows remove-handler 42 --handler=rss
	 *
	 *     # List handlers on a flow
	 *     wp datamachine flows list-handlers 42
	 *
	 *     # List memory files for a flow
	 *     wp datamachine flows memory-files 42
	 *
	 *     # Attach a memory file to a flow
	 *     wp datamachine flows memory-files 42 --add=content-briefing.md
	 *
	 *     # Detach a memory file from a flow
	 *     wp datamachine flows memory-files 42 --remove=content-briefing.md
	 *
	 *     # Pause a single flow
	 *     wp datamachine flows pause 42
	 *
	 *     # Pause all flows in a pipeline
	 *     wp datamachine flows pause --pipeline=12
	 *
	 *     # Pause all flows for an agent
	 *     wp datamachine flows pause --agent=my-agent
	 *
	 *     # Resume a single flow
	 *     wp datamachine flows resume 42
	 *
	 *     # Resume all flows for an agent
	 *     wp datamachine flows resume --agent=my-agent
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$flow_id     = null;
		$pipeline_id = null;

		// Handle 'create' subcommand: `flows create --pipeline_id=3 --name="Test"`.
		if ( ! empty( $args ) && 'create' === $args[0] ) {
			$this->createFlow( $assoc_args );
			return;
		}

		// Delegate 'queue' subcommand to QueueCommand.
		if ( ! empty( $args ) && 'queue' === $args[0] ) {
			$queue = new QueueCommand();
			$queue->dispatch( array_slice( $args, 1 ), $assoc_args );
			return;
		}

		// Delegate 'webhook' subcommand to WebhookCommand.
		if ( ! empty( $args ) && 'webhook' === $args[0] ) {
			$webhook = new WebhookCommand();
			$webhook->dispatch( array_slice( $args, 1 ), $assoc_args );
			return;
		}

		// Delegate 'bulk-config' subcommand to BulkConfigCommand.
		if ( ! empty( $args ) && 'bulk-config' === $args[0] ) {
			$bulk_config = new BulkConfigCommand();
			$bulk_config->dispatch( array_slice( $args, 1 ), $assoc_args );
			return;
		}

		// Handle 'memory-files' subcommand.
		if ( ! empty( $args ) && 'memory-files' === $args[0] ) {
			if ( ! isset( $args[1] ) ) {
				WP_CLI::error( 'Usage: wp datamachine flows memory-files <flow_id> [--add=<filename>] [--remove=<filename>]' );
				return;
			}
			$this->memoryFiles( (int) $args[1], $assoc_args );
			return;
		}

		// Handle 'pause' subcommand: `flows pause 42` or `flows pause --pipeline=12`.
		if ( ! empty( $args ) && 'pause' === $args[0] ) {
			$this->pauseFlows( array_slice( $args, 1 ), $assoc_args );
			return;
		}

		// Handle 'resume' subcommand: `flows resume 42` or `flows resume --pipeline=12`.
		if ( ! empty( $args ) && 'resume' === $args[0] ) {
			$this->resumeFlows( array_slice( $args, 1 ), $assoc_args );
			return;
		}

		// Handle 'delete' subcommand: `flows delete 42`.
		if ( ! empty( $args ) && 'delete' === $args[0] ) {
			if ( ! isset( $args[1] ) ) {
				WP_CLI::error( 'Usage: wp datamachine flows delete <flow_id> [--yes]' );
				return;
			}
			$this->deleteFlow( (int) $args[1], $assoc_args );
			return;
		}

		// Handle 'update' subcommand: `flows update 42 --name="New Name"`.
		if ( ! empty( $args ) && 'update' === $args[0] ) {
			if ( ! isset( $args[1] ) ) {
				WP_CLI::error( 'Usage: wp datamachine flows update <flow_id> [--name=<name>] [--scheduling=<interval>] [--set-prompt=<text>] [--handler-config=<json>] [--step=<flow_step_id>]' );
				return;
			}
			$this->updateFlow( (int) $args[1], $assoc_args );
			return;
		}

		// Handle 'add-handler' subcommand.
		if ( ! empty( $args ) && 'add-handler' === $args[0] ) {
			if ( ! isset( $args[1] ) ) {
				WP_CLI::error( 'Usage: wp datamachine flows add-handler <flow_id> --handler=<slug> [--step=<flow_step_id>] [--config=<json>]' );
				return;
			}
			$this->addHandler( (int) $args[1], $assoc_args );
			return;
		}

		// Handle 'remove-handler' subcommand.
		if ( ! empty( $args ) && 'remove-handler' === $args[0] ) {
			if ( ! isset( $args[1] ) ) {
				WP_CLI::error( 'Usage: wp datamachine flows remove-handler <flow_id> --handler=<slug> [--step=<flow_step_id>]' );
				return;
			}
			$this->removeHandler( (int) $args[1], $assoc_args );
			return;
		}

		// Handle 'list-handlers' subcommand.
		if ( ! empty( $args ) && 'list-handlers' === $args[0] ) {
			if ( ! isset( $args[1] ) ) {
				WP_CLI::error( 'Usage: wp datamachine flows list-handlers <flow_id> [--step=<flow_step_id>]' );
				return;
			}
			$this->listHandlers( (int) $args[1], $assoc_args );
			return;
		}

		// Handle 'get'/'show' subcommand: `flows get 42` or `flows show 42`.
		if ( ! empty( $args ) && ( 'get' === $args[0] || 'show' === $args[0] ) ) {
			if ( isset( $args[1] ) ) {
				$flow_id = (int) $args[1];
			}
		} elseif ( ! empty( $args ) && 'run' === $args[0] ) {
			// Handle 'run' subcommand: `flows run 42`.
			if ( ! isset( $args[1] ) ) {
				WP_CLI::error( 'Usage: wp datamachine flows run <flow_id> [--count=N] [--timestamp=T]' );
				return;
			}
			$this->runFlow( (int) $args[1], $assoc_args );
			return;
		} elseif ( ! empty( $args ) && 'list' !== $args[0] ) {
			$pipeline_id = (int) $args[0];
		}

		// Handle --id flag (takes precedence if both provided).
		if ( isset( $assoc_args['id'] ) ) {
			$flow_id = (int) $assoc_args['id'];
		}

		$handler_slug = $assoc_args['handler'] ?? null;
		$per_page     = (int) ( $assoc_args['per_page'] ?? 0 );
		$offset       = (int) ( $assoc_args['offset'] ?? 0 );
		$format       = $assoc_args['format'] ?? 'table';

		if ( $per_page < 0 ) {
			$per_page = 0;
		}
		if ( $offset < 0 ) {
			$offset = 0;
		}

		$scoping = AgentResolver::buildScopingInput( $assoc_args );
		$ability = new \DataMachine\Abilities\FlowAbilities();

		// Use 'list' mode for multi-flow views (skips expensive handler enrichment).
		// Use 'full' mode for single-flow detail views.
		$output_mode = $flow_id ? 'full' : 'list';

		$result  = $ability->executeAbility(
			array_merge(
				$scoping,
				array(
					'flow_id'      => $flow_id,
					'pipeline_id'  => $pipeline_id,
					'handler_slug' => $handler_slug,
					'per_page'     => $per_page,
					'offset'       => $offset,
					'output_mode'  => $output_mode,
				)
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to get flows' );
			return;
		}

		$flows = $result['flows'] ?? array();
		$total = $result['total'] ?? 0;

		if ( empty( $flows ) ) {
			WP_CLI::warning( 'No flows found matching your criteria.' );
			return;
		}

		// Single flow detail view: show full data including step configs.
		if ( $flow_id && 1 === count( $flows ) ) {
			$this->showFlowDetail( $flows[0], $format );
			return;
		}

		// Transform flows to flat row format.
		$items = array_map(
			function ( $flow ) {
				return array(
					'id'          => $flow['flow_id'],
					'name'        => $flow['flow_name'],
					'pipeline_id' => $flow['pipeline_id'],
					'handlers'    => $this->extractHandlers( $flow ),
					'config'      => $this->extractConfigSummary( $flow ),
					'schedule'    => $this->extractSchedule( $flow ),
					'max_items'   => $this->extractMaxItems( $flow ),
					'prompt'      => $this->extractPrompt( $flow ),
					'status'      => $flow['last_run_status'] ?? 'Never',
					'next_run'    => $flow['next_run_display'] ?? 'Not scheduled',
				);
			},
			$flows
		);

		$this->format_items( $items, $this->default_fields, $assoc_args, 'id' );
		$this->output_pagination( $offset, count( $flows ), $total, $format, 'flows' );
		$this->outputFilters( $result['filters_applied'] ?? array(), $format );
	}

	/**
	 * Run a flow immediately or with scheduling.
	 *
	 * @param int   $flow_id    Flow ID to execute.
	 * @param array $assoc_args Associative arguments (count, timestamp).
	 */
	private function runFlow( int $flow_id, array $assoc_args ): void {
		$count     = isset( $assoc_args['count'] ) ? (int) $assoc_args['count'] : 1;
		$timestamp = isset( $assoc_args['timestamp'] ) ? (int) $assoc_args['timestamp'] : null;

		// Validate count range (1-10).
		if ( $count < 1 || $count > 10 ) {
			WP_CLI::error( 'Count must be between 1 and 10.' );
			return;
		}

		$ability = new \DataMachine\Abilities\JobAbilities();
		$result  = $ability->executeWorkflow(
			array(
				'flow_id'   => $flow_id,
				'count'     => $count,
				'timestamp' => $timestamp,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to run flow' );
			return;
		}

		// Output success message.
		WP_CLI::success( $result['message'] ?? 'Flow execution scheduled.' );

		// Show job ID(s) for follow-up.
		if ( isset( $result['job_id'] ) ) {
			WP_CLI::log( sprintf( 'Job ID: %d', $result['job_id'] ) );
		} elseif ( isset( $result['job_ids'] ) ) {
			WP_CLI::log( sprintf( 'Job IDs: %s', implode( ', ', $result['job_ids'] ) ) );
		}
	}

	/**
	 * Delete a flow.
	 *
	 * @param int   $flow_id    Flow ID to delete.
	 * @param array $assoc_args Associative arguments (--yes).
	 */
	private function deleteFlow( int $flow_id, array $assoc_args ): void {
		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		$skip_confirm = isset( $assoc_args['yes'] );

		if ( ! $skip_confirm ) {
			WP_CLI::confirm( sprintf( 'Are you sure you want to delete flow %d?', $flow_id ) );
		}

		$ability = new \DataMachine\Abilities\FlowAbilities();
		$result  = $ability->executeDeleteFlow( array( 'flow_id' => $flow_id ) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to delete flow' );
			return;
		}

		WP_CLI::success( sprintf( 'Flow %d deleted.', $flow_id ) );

		if ( isset( $result['pipeline_id'] ) ) {
			WP_CLI::log( sprintf( 'Pipeline ID: %d', $result['pipeline_id'] ) );
		}
	}

	/**
	 * Output filter info (table format only).
	 *
	 * @param array  $filters_applied Applied filters.
	 * @param string $format          Current output format.
	 */
	private function outputFilters( array $filters_applied, string $format ): void {
		if ( 'table' !== $format ) {
			return;
		}

		if ( $filters_applied['flow_id'] ?? null ) {
			WP_CLI::log( "Filtered by flow ID: {$filters_applied['flow_id']}" );
		}
		if ( $filters_applied['pipeline_id'] ?? null ) {
			WP_CLI::log( "Filtered by pipeline ID: {$filters_applied['pipeline_id']}" );
		}
		if ( $filters_applied['handler_slug'] ?? null ) {
			WP_CLI::log( "Filtered by handler slug: {$filters_applied['handler_slug']}" );
		}
	}

	/**
	 * Extract handler slugs from flow config.
	 *
	 * @param array $flow Flow data.
	 * @return string Comma-separated handler slugs.
	 */
	private function extractHandlers( array $flow ): string {
		$flow_config = $flow['flow_config'] ?? array();
		$handlers    = array();

		foreach ( $flow_config as $step_data ) {
			// Data is normalized at the DB layer — handler_slugs is canonical.
			$handlers = array_merge( $handlers, $step_data['handler_slugs'] ?? array() );
		}

		return implode( ', ', array_unique( $handlers ) );
	}

	/**
	 * Extract a concise config summary from flow handler configs.
	 *
	 * Domain-agnostic: reads raw config values without assuming any
	 * specific taxonomy, handler, or post type. Surfaces distinguishing
	 * values like coordinates, city names, URLs, and taxonomy selections.
	 *
	 * @param array $flow Flow data.
	 * @return string Config summary (max ~60 chars).
	 */
	private function extractConfigSummary( array $flow ): string {
		$flow_config = $flow['flow_config'] ?? array();
		$parts       = array();

		foreach ( $flow_config as $step_data ) {
			$handler_configs = $step_data['handler_configs'] ?? array();

			foreach ( $handler_configs as $hconfig ) {
				if ( ! is_array( $hconfig ) ) {
					continue;
				}

				// Coordinates (location field with lat,lon).
				if ( ! empty( $hconfig['location'] ) && strpos( $hconfig['location'], ',' ) !== false ) {
					$loc = $hconfig['location'];
					$rad = $hconfig['radius'] ?? '';
					$parts[] = $loc . ( $rad ? " r={$rad}" : '' );
				}

				// City name.
				if ( ! empty( $hconfig['city'] ) ) {
					$parts[] = "city={$hconfig['city']}";
				}

				// Source URL — show domain only.
				if ( ! empty( $hconfig['source_url'] ) ) {
					$host = wp_parse_url( $hconfig['source_url'], PHP_URL_HOST );
					$parts[] = $host ?: $hconfig['source_url'];
				}

				// Venue/source name.
				if ( ! empty( $hconfig['venue_name'] ) ) {
					$parts[] = $hconfig['venue_name'];
				}

				// Feed URL — show domain only.
				$feed_url = $hconfig['feed_url'] ?? $hconfig['url'] ?? '';
				if ( $feed_url && empty( $hconfig['source_url'] ) ) {
					$host = wp_parse_url( $feed_url, PHP_URL_HOST );
					$parts[] = $host ?: $feed_url;
				}

				// Taxonomy term selections (any taxonomy_*_selection key).
				foreach ( $hconfig as $key => $val ) {
					if ( strpos( $key, 'taxonomy_' ) === 0 && strpos( $key, '_selection' ) !== false ) {
						if ( ! empty( $val ) && 'skip' !== $val && 'ai_decides' !== $val ) {
							$tax_name = str_replace( array( 'taxonomy_', '_selection' ), '', $key );
							$parts[] = "{$tax_name}={$val}";
						}
					}
				}
			}
		}

		$summary = implode( ' | ', array_unique( $parts ) );

		if ( mb_strlen( $summary ) > 60 ) {
			$summary = mb_substr( $summary, 0, 57 ) . '...';
		}

		return $summary ?: '—';
	}

	/**
	 * Extract the first prompt from flow config for display.
	 *
	 * @param array $flow Flow data.
	 * @return string Prompt preview.
	 */
	private function extractPrompt( array $flow ): string {
		$flow_config = $flow['flow_config'] ?? array();

		foreach ( $flow_config as $step_data ) {
			$primary_slug   = $step_data['handler_slugs'][0] ?? '';
			$primary_config = ! empty( $primary_slug ) ? ( $step_data['handler_configs'][ $primary_slug ] ?? array() ) : array();
			if ( ! empty( $primary_config['prompt'] ) ) {
				$prompt = $primary_config['prompt'];
				return mb_strlen( $prompt ) > 50
					? mb_substr( $prompt, 0, 47 ) . '...'
					: $prompt;
			}
			if ( ! empty( $step_data['pipeline_config']['prompt'] ) ) {
				$prompt = $step_data['pipeline_config']['prompt'];
				return mb_strlen( $prompt ) > 50
					? mb_substr( $prompt, 0, 47 ) . '...'
					: $prompt;
			}
		}

		return '';
	}

	/**
	 * Extract scheduling summary from flow scheduling config.
	 *
	 * @param array $flow Flow data.
	 * @return string Scheduling summary for list view.
	 */
	private function extractSchedule( array $flow ): string {
		$scheduling_config = $flow['scheduling_config'] ?? array();
		$interval          = $scheduling_config['interval'] ?? 'manual';
		$is_paused         = isset( $scheduling_config['enabled'] ) && false === $scheduling_config['enabled'];

		$label = $interval;
		if ( 'cron' === $interval && ! empty( $scheduling_config['cron_expression'] ) ) {
			$label = 'cron:' . $scheduling_config['cron_expression'];
		}

		if ( $is_paused ) {
			$label .= ' (paused)';
		}

		return $label;
	}

	/**
	 * Extract max_items values from handler configs in a flow.
	 *
	 * @param array $flow Flow data.
	 * @return string Comma-separated handler=max_items pairs, or empty string.
	 */
	private function extractMaxItems( array $flow ): string {
		$flow_config = $flow['flow_config'] ?? array();
		$pairs       = array();

		foreach ( $flow_config as $step_data ) {
			if ( ! is_array( $step_data ) ) {
				continue;
			}

			$handler_configs = $step_data['handler_configs'] ?? array();
			if ( ! is_array( $handler_configs ) ) {
				continue;
			}

			foreach ( $handler_configs as $handler_slug => $handler_config ) {
				if ( ! is_array( $handler_config ) || ! array_key_exists( 'max_items', $handler_config ) ) {
					continue;
				}

				$pairs[] = $handler_slug . '=' . (string) $handler_config['max_items'];
			}
		}

		$pairs = array_values( array_unique( $pairs ) );

		return implode( ', ', $pairs );
	}

	/**
	 * List handlers on flow steps.
	 *
	 * @param int   $flow_id    Flow ID.
	 * @param array $assoc_args Arguments (step, format).
	 */
	private function listHandlers( int $flow_id, array $assoc_args ): void {
		$step_id = $assoc_args['step'] ?? null;

		$db   = new \DataMachine\Core\Database\Flows\Flows();
		$flow = $db->get_flow( $flow_id );

		if ( ! $flow ) {
			WP_CLI::error( "Flow {$flow_id} not found" );
			return;
		}

		$config = $flow['flow_config'] ?? array();
		$rows   = array();

		foreach ( $config as $sid => $step ) {
			// Skip flow-level metadata keys.
			if ( ! is_array( $step ) || ! isset( $step['step_type'] ) ) {
				continue;
			}

			if ( $step_id && $sid !== $step_id ) {
				continue;
			}

			$step_type = $step['step_type'] ?? '';

			$slugs   = $step['handler_slugs'] ?? array();
			$configs = $step['handler_configs'] ?? array();

			if ( empty( $slugs ) && ! $step_id ) {
				continue; // Skip steps with no handlers unless specifically requested.
			}

			foreach ( $slugs as $slug ) {
				$handler_config = $configs[ $slug ] ?? array();
				$config_summary = array();
				foreach ( $handler_config as $k => $v ) {
					if ( is_string( $v ) && strlen( $v ) > 30 ) {
						$v = substr( $v, 0, 27 ) . '...';
					}
					$config_summary[] = "{$k}=" . ( is_array( $v ) ? wp_json_encode( $v ) : $v );
				}

				$rows[] = array(
					'flow_step_id' => $sid,
					'step_type'    => $step_type,
					'handler'      => $slug,
					'config'       => implode( ', ', $config_summary ) ? implode( ', ', $config_summary ) : '(default)',
				);
			}
		}

		if ( empty( $rows ) ) {
			WP_CLI::warning( 'No handlers found.' );
			return;
		}

		$this->format_items( $rows, array( 'flow_step_id', 'step_type', 'handler', 'config' ), $assoc_args );
	}

	/**
	 * Manage memory files attached to a flow.
	 *
	 * Without --add or --remove, lists current memory files.
	 * With --add, attaches a file. With --remove, detaches a file.
	 *
	 * @param int   $flow_id    Flow ID.
	 * @param array $assoc_args Arguments (add, remove, format).
	 */
	private function memoryFiles( int $flow_id, array $assoc_args ): void {
		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		$format   = $assoc_args['format'] ?? 'table';
		$add_file = $assoc_args['add'] ?? null;
		$rm_file  = $assoc_args['remove'] ?? null;

		$db = new \DataMachine\Core\Database\Flows\Flows();

		// Verify flow exists.
		$flow = $db->get_flow( $flow_id );
		if ( ! $flow ) {
			WP_CLI::error( "Flow {$flow_id} not found" );
			return;
		}

		$current_files = $db->get_flow_memory_files( $flow_id );

		// Add a file.
		if ( $add_file ) {
			$add_file = sanitize_file_name( $add_file );

			if ( in_array( $add_file, $current_files, true ) ) {
				WP_CLI::warning( sprintf( '"%s" is already attached to flow %d.', $add_file, $flow_id ) );
				return;
			}

			$current_files[] = $add_file;
			$result          = $db->update_flow_memory_files( $flow_id, $current_files );

			if ( ! $result ) {
				WP_CLI::error( 'Failed to update memory files' );
				return;
			}

			WP_CLI::success( sprintf( 'Added "%s" to flow %d. Files: %s', $add_file, $flow_id, implode( ', ', $current_files ) ) );
			return;
		}

		// Remove a file.
		if ( $rm_file ) {
			$rm_file = sanitize_file_name( $rm_file );

			if ( ! in_array( $rm_file, $current_files, true ) ) {
				WP_CLI::warning( sprintf( '"%s" is not attached to flow %d.', $rm_file, $flow_id ) );
				return;
			}

			$current_files = array_values( array_diff( $current_files, array( $rm_file ) ) );
			$result        = $db->update_flow_memory_files( $flow_id, $current_files );

			if ( ! $result ) {
				WP_CLI::error( 'Failed to update memory files' );
				return;
			}

			WP_CLI::success( sprintf( 'Removed "%s" from flow %d.', $rm_file, $flow_id ) );

			if ( ! empty( $current_files ) ) {
				WP_CLI::log( sprintf( 'Remaining: %s', implode( ', ', $current_files ) ) );
			} else {
				WP_CLI::log( 'No memory files attached.' );
			}
			return;
		}

		// List files.
		if ( empty( $current_files ) ) {
			WP_CLI::log( sprintf( 'Flow %d has no memory files attached.', $flow_id ) );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $current_files, JSON_PRETTY_PRINT ) );
			return;
		}

		$items = array_map(
			function ( $filename ) {
				return array( 'filename' => $filename );
			},
			$current_files
		);

		\WP_CLI\Utils\format_items( $format, $items, array( 'filename' ) );
	}
