//! resolveHandlerStep — extracted from FlowsCommand.php.


	/**
	 * Create a new flow.
	 *
	 * @param array $assoc_args Associative arguments (pipeline_id, name, step_configs, scheduling, dry-run).
	 */
	private function createFlow( array $assoc_args ): void {
		$pipeline_id  = isset( $assoc_args['pipeline_id'] ) ? (int) $assoc_args['pipeline_id'] : null;
		$flow_name    = $assoc_args['name'] ?? null;
		$scheduling   = $assoc_args['scheduling'] ?? 'manual';
		$scheduled_at = $assoc_args['scheduled-at'] ?? null;
		$dry_run      = isset( $assoc_args['dry-run'] );
		$format       = $assoc_args['format'] ?? 'table';

		if ( ! $pipeline_id ) {
			WP_CLI::error( 'Required: --pipeline_id=<id>' );
			return;
		}

		if ( ! $flow_name ) {
			WP_CLI::error( 'Required: --name=<name>' );
			return;
		}

		$step_configs = array();
		if ( isset( $assoc_args['step_configs'] ) ) {
			$decoded = json_decode( wp_unslash( $assoc_args['step_configs'] ), true );
			if ( null === $decoded && '' !== $assoc_args['step_configs'] ) {
				WP_CLI::error( 'Invalid JSON in --step_configs' );
				return;
			}
			if ( null !== $decoded && ! is_array( $decoded ) ) {
				WP_CLI::error( '--step_configs must be a JSON object' );
				return;
			}
			$step_configs = $decoded ?? array();
		}

		// Convert --handler-config to step_configs entries.
		// --handler-config accepts handler-keyed JSON, e.g. {"reddit":{"subreddit":"test"}}.
		// Each handler slug is resolved to its step type and merged into step_configs.
		if ( isset( $assoc_args['handler-config'] ) ) {
			$handler_config_input = json_decode( wp_unslash( $assoc_args['handler-config'] ), true );
			if ( ! is_array( $handler_config_input ) ) {
				WP_CLI::error( 'Invalid JSON in --handler-config. Must be a JSON object.' );
				return;
			}

			$handler_abilities = new \DataMachine\Abilities\HandlerAbilities();
			$all_handlers      = $handler_abilities->getAllHandlers();

			foreach ( $handler_config_input as $handler_slug => $config ) {
				if ( ! isset( $all_handlers[ $handler_slug ] ) ) {
					WP_CLI::error( "Unknown handler '{$handler_slug}'. Use --handler-config with valid handler slugs." );
					return;
				}

				$step_type = $all_handlers[ $handler_slug ]['type'] ?? '';
				if ( empty( $step_type ) ) {
					WP_CLI::error( "Cannot determine step type for handler '{$handler_slug}'." );
					return;
				}

				$step_configs[ $step_type ] = array(
					'handler_slug'   => $handler_slug,
					'handler_config' => $config,
				);
			}
		}

		$scheduling_config = self::build_scheduling_config( $scheduling, $scheduled_at );

		$input = array(
			'pipeline_id'       => $pipeline_id,
			'flow_name'         => $flow_name,
			'scheduling_config' => $scheduling_config,
			'step_configs'      => $step_configs,
		);

		if ( $dry_run ) {
			$input['validate_only'] = true;
			$input['flows']         = array(
				array(
					'pipeline_id'       => $pipeline_id,
					'flow_name'         => $flow_name,
					'scheduling_config' => $scheduling_config,
					'step_configs'      => $step_configs,
				),
			);
		}

		$ability = new \DataMachine\Abilities\FlowAbilities();
		$result  = $ability->executeCreateFlow( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to create flow' );
			return;
		}

		if ( $dry_run ) {
			WP_CLI::success( 'Validation passed.' );
			if ( isset( $result['would_create'] ) && 'json' === $format ) {
				WP_CLI::line( wp_json_encode( $result['would_create'], JSON_PRETTY_PRINT ) );
			} elseif ( isset( $result['would_create'] ) ) {
				foreach ( $result['would_create'] as $preview ) {
					WP_CLI::log(
						sprintf(
							'Would create: "%s" on pipeline %d (scheduling: %s)',
							$preview['flow_name'],
							$preview['pipeline_id'],
							$preview['scheduling']
						)
					);
				}
			}
			return;
		}

		WP_CLI::success( sprintf( 'Flow created: ID %d', $result['flow_id'] ) );
		WP_CLI::log( sprintf( 'Name: %s', $result['flow_name'] ) );
		WP_CLI::log( sprintf( 'Pipeline ID: %d', $result['pipeline_id'] ) );
		WP_CLI::log( sprintf( 'Synced steps: %d', $result['synced_steps'] ?? 0 ) );

		if ( ! empty( $result['configured_steps'] ) ) {
			WP_CLI::log( sprintf( 'Configured steps: %s', implode( ', ', $result['configured_steps'] ) ) );
		}

		if ( ! empty( $result['configuration_errors'] ) ) {
			WP_CLI::warning( 'Some step configurations failed:' );
			foreach ( $result['configuration_errors'] as $error ) {
				WP_CLI::log( sprintf( '  - %s: %s', $error['step_type'] ?? 'unknown', $error['error'] ?? 'unknown error' ) );
			}
		}

		if ( 'json' === $format && isset( $result['flow_data'] ) ) {
			WP_CLI::line( wp_json_encode( $result['flow_data'], JSON_PRETTY_PRINT ) );
		}
	}

	/**
	 * Update a flow's name or scheduling.
	 *
	 * @param int   $flow_id    Flow ID to update.
	 * @param array $assoc_args Associative arguments (--name, --scheduling).
	 */
	private function updateFlow( int $flow_id, array $assoc_args ): void {
		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		$name           = $assoc_args['name'] ?? null;
		$scheduling     = $assoc_args['scheduling'] ?? null;
		$scheduled_at   = $assoc_args['scheduled-at'] ?? null;
		$prompt         = isset( $assoc_args['set-prompt'] )
			? wp_kses_post( wp_unslash( $assoc_args['set-prompt'] ) )
			: null;
		$handler_config = isset( $assoc_args['handler-config'] )
			? json_decode( wp_unslash( $assoc_args['handler-config'] ), true )
			: null;
		$step           = $assoc_args['step'] ?? null;

		// --scheduled-at implies --scheduling=one_time.
		if ( $scheduled_at && null === $scheduling ) {
			$scheduling = 'one_time';
		}

		if ( null !== $handler_config && ! is_array( $handler_config ) ) {
			WP_CLI::error( 'Invalid JSON in --handler-config. Must be a JSON object.' );
			return;
		}

		if ( null === $name && null === $scheduling && null === $prompt && null === $handler_config ) {
			WP_CLI::error( 'Must provide --name, --scheduling, --set-prompt, --scheduled-at, or --handler-config to update' );
			return;
		}

		// Validate step resolution BEFORE any writes (atomic: fail fast, change nothing).
		$needs_step = null !== $prompt || null !== $handler_config;

		if ( $needs_step && null === $step ) {
			$resolved = $this->resolveHandlerStep( $flow_id );
			if ( $resolved['error'] ) {
				WP_CLI::error( $resolved['error'] );
				return;
			}
			$step = $resolved['step_id'];
		}

		// Phase 1: Flow-level updates (name, scheduling).
		$input = array( 'flow_id' => $flow_id );

		if ( null !== $name ) {
			$input['flow_name'] = $name;
		}

		if ( null !== $scheduling ) {
			$input['scheduling_config'] = self::build_scheduling_config( $scheduling, $scheduled_at );
		}

		if ( null !== $name || null !== $scheduling ) {
			$ability = new \DataMachine\Abilities\FlowAbilities();
			$result  = $ability->executeUpdateFlow( $input );

			if ( ! $result['success'] ) {
				WP_CLI::error( $result['error'] ?? 'Failed to update flow' );
				return;
			}

			WP_CLI::success( sprintf( 'Flow %d updated.', $flow_id ) );
			WP_CLI::log( sprintf( 'Name: %s', $result['flow_name'] ?? '' ) );

			$sched = $result['flow_data']['scheduling_config'] ?? array();
			if ( 'cron' === ( $sched['interval'] ?? '' ) && ! empty( $sched['cron_expression'] ) ) {
				WP_CLI::log( sprintf( 'Scheduling: cron (%s)', $sched['cron_expression'] ) );
			} elseif ( isset( $sched['interval'] ) ) {
				WP_CLI::log( sprintf( 'Scheduling: %s', $sched['interval'] ) );
			}
		}

		// Phase 2: Step-level updates (prompt, handler config).
		if ( null !== $prompt ) {
			$step_ability = new \DataMachine\Abilities\FlowStep\UpdateFlowStepAbility();
			$step_result  = $step_ability->execute(
				array(
					'flow_step_id'   => $step,
					'handler_config' => array( 'prompt' => $prompt ),
				)
			);

			if ( ! $step_result['success'] ) {
				WP_CLI::error( $step_result['error'] ?? 'Failed to update prompt' );
				return;
			}

			WP_CLI::success( 'Prompt updated for step: ' . $step );
		}

		if ( null !== $handler_config ) {
			// --handler-config accepts handler-keyed JSON, e.g. {"reddit":{"subreddit":"test"}}.
			// Unwrap: the key is the handler slug, the value is the config.
			$handler_slug       = null;
			$unwrapped_config   = $handler_config;
			$handler_config_keys = array_keys( $handler_config );

			// If the top-level keys look like handler slugs (single key wrapping a config object),
			// unwrap the handler slug from the JSON structure.
			if ( count( $handler_config_keys ) === 1 && is_array( $handler_config[ $handler_config_keys[0] ] ) ) {
				$handler_slug     = $handler_config_keys[0];
				$unwrapped_config = $handler_config[ $handler_slug ];
			}

			$step_input = array(
				'flow_step_id'   => $step,
				'handler_config' => $unwrapped_config,
			);

			if ( $handler_slug ) {
				$step_input['handler_slug'] = $handler_slug;
			}

			$step_ability = new \DataMachine\Abilities\FlowStep\UpdateFlowStepAbility();
			$step_result  = $step_ability->execute( $step_input );

			if ( ! $step_result['success'] ) {
				WP_CLI::error( $step_result['error'] ?? 'Failed to update handler config' );
				return;
			}

			$updated_keys = implode( ', ', array_keys( $unwrapped_config ) );
			WP_CLI::success( sprintf( 'Handler config updated for step %s: %s', $step, $updated_keys ) );
		}
	}

	/**
	 * Add a handler to a flow step.
	 *
	 * @param int   $flow_id    Flow ID.
	 * @param array $assoc_args Arguments (handler, step, config).
	 */
	private function addHandler( int $flow_id, array $assoc_args ): void {
		$handler_slug = $assoc_args['handler'] ?? null;
		$step_id      = $assoc_args['step'] ?? null;

		if ( ! $handler_slug ) {
			WP_CLI::error( 'Required: --handler=<slug>' );
			return;
		}

		// Auto-resolve handler step if not specified.
		if ( ! $step_id ) {
			$resolved = $this->resolveHandlerStep( $flow_id );
			if ( ! empty( $resolved['error'] ) ) {
				WP_CLI::error( $resolved['error'] );
				return;
			}
			$step_id = $resolved['step_id'];
		}

		$input = array(
			'flow_step_id' => $step_id,
			'add_handler'  => $handler_slug,
		);

		// Parse --config if provided.
		if ( isset( $assoc_args['config'] ) ) {
			$handler_config = json_decode( wp_unslash( $assoc_args['config'] ), true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				WP_CLI::error( 'Invalid JSON in --config: ' . json_last_error_msg() );
				return;
			}
			$input['add_handler_config'] = $handler_config;
		}

		$ability = new \DataMachine\Abilities\FlowStepAbilities();
		$result  = $ability->executeUpdateFlowStep( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to add handler' );
			return;
		}

		WP_CLI::success( "Added handler '{$handler_slug}' to flow step {$step_id}" );
	}

	/**
	 * Remove a handler from a flow step.
	 *
	 * @param int   $flow_id    Flow ID.
	 * @param array $assoc_args Arguments (handler, step).
	 */
	private function removeHandler( int $flow_id, array $assoc_args ): void {
		$handler_slug = $assoc_args['handler'] ?? null;
		$step_id      = $assoc_args['step'] ?? null;

		if ( ! $handler_slug ) {
			WP_CLI::error( 'Required: --handler=<slug>' );
			return;
		}

		if ( ! $step_id ) {
			$resolved = $this->resolveHandlerStep( $flow_id );
			if ( ! empty( $resolved['error'] ) ) {
				WP_CLI::error( $resolved['error'] );
				return;
			}
			$step_id = $resolved['step_id'];
		}

		$ability = new \DataMachine\Abilities\FlowStepAbilities();
		$result  = $ability->executeUpdateFlowStep(
			array(
				'flow_step_id'   => $step_id,
				'remove_handler' => $handler_slug,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to remove handler' );
			return;
		}

		WP_CLI::success( "Removed handler '{$handler_slug}' from flow step {$step_id}" );
	}

	/**
	 * Resolve the handler step for a flow when --step is not provided.
	 *
	 * @param int $flow_id Flow ID.
	 * @return array{step_id: string|null, error: string|null}
	 */
	private function resolveHandlerStep( int $flow_id ): array {
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
				'error'   => 'Flow not found',
			);
		}

		$flow_config = json_decode( $flow['flow_config'], true );
		if ( empty( $flow_config ) ) {
			return array(
				'step_id' => null,
				'error'   => 'Flow has no steps',
			);
		}

		$handler_steps = array();
		foreach ( $flow_config as $step_id => $step_data ) {
			if ( ! empty( $step_data['handler_slugs'] ) ) {
				$handler_steps[] = $step_id;
			}
		}

		if ( empty( $handler_steps ) ) {
			return array(
				'step_id' => null,
				'error'   => 'Flow has no handler steps',
			);
		}

		if ( count( $handler_steps ) > 1 ) {
			return array(
				'step_id' => null,
				'error'   => sprintf(
					'Flow has multiple handler steps. Use --step=<id> to specify. Available: %s',
					implode( ', ', $handler_steps )
				),
			);
		}

		return array(
			'step_id' => $handler_steps[0],
			'error'   => null,
		);
	}

	/**
	 * Build a scheduling_config array from a CLI --scheduling value.
	 *
	 * Detects cron expressions and routes them correctly:
	 * - Cron expression (e.g. "0 * /3 * * *") → interval=cron + cron_expression
	 * - Interval key (e.g. "daily") → interval=<key>
	 * - One-time (scheduling=one_time) → interval=one_time + timestamp (requires $scheduled_at)
	 *
	 * @param string      $scheduling   Value from --scheduling CLI flag.
	 * @param string|null $scheduled_at ISO-8601 datetime for one-time scheduling.
	 * @return array Scheduling config array.
	 */
	private static function build_scheduling_config( string $scheduling, ?string $scheduled_at = null ): array {
		// If --scheduled-at is provided, treat as one_time regardless of --scheduling value.
		if ( $scheduled_at ) {
			$timestamp = strtotime( $scheduled_at );
			if ( ! $timestamp ) {
				\WP_CLI::error( "Invalid --scheduled-at value: {$scheduled_at}. Use ISO-8601 format (e.g. 2026-03-20T15:00:00Z)." );
			}
			return array(
				'interval'  => 'one_time',
				'timestamp' => $timestamp,
			);
		}

		if ( 'one_time' === $scheduling ) {
			\WP_CLI::error( 'one_time scheduling requires --scheduled-at=<datetime> (ISO-8601 format).' );
		}

		if ( \DataMachine\Api\Flows\FlowScheduling::looks_like_cron_expression( $scheduling ) ) {
			return array(
				'interval'        => 'cron',
				'cron_expression' => $scheduling,
			);
		}

		return array( 'interval' => $scheduling );
	}
