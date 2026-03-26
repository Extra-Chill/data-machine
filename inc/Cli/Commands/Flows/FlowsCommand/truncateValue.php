//! truncateValue — extracted from FlowsCommand.php.


	/**
	 * Show detailed view of a single flow including step configs.
	 *
	 * For JSON format: outputs the full flow data with flow_config intact.
	 * For table format: outputs key-value pairs followed by a step configs table.
	 *
	 * @param array  $flow   Full flow data from FlowAbilities.
	 * @param string $format Output format (table, json, csv, yaml).
	 */
	private function showFlowDetail( array $flow, string $format ): void {
		// JSON/YAML: output the full flow data including flow_config.
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $flow, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( 'yaml' === $format ) {
			WP_CLI\Utils\format_items( 'yaml', array( $flow ), array_keys( $flow ) );
			return;
		}

		// Table format: show flow summary, then step configs.
		$scheduling = $flow['scheduling_config'] ?? array();
		$interval   = $scheduling['interval'] ?? 'manual';

		$is_paused = isset( $scheduling['enabled'] ) && false === $scheduling['enabled'];

		WP_CLI::log( sprintf( 'Flow ID:      %d', $flow['flow_id'] ) );
		WP_CLI::log( sprintf( 'Name:         %s', $flow['flow_name'] ) );
		WP_CLI::log( sprintf( 'Pipeline ID:  %s', $flow['pipeline_id'] ?? 'N/A' ) );
		if ( 'cron' === $interval && ! empty( $scheduling['cron_expression'] ) ) {
			$cron_desc = \DataMachine\Api\Flows\FlowScheduling::describe_cron_expression( $scheduling['cron_expression'] );
			WP_CLI::log( sprintf( 'Scheduling:   cron (%s) — %s', $scheduling['cron_expression'], $cron_desc ) );
		} else {
			WP_CLI::log( sprintf( 'Scheduling:   %s', $interval ) );
		}
		if ( $is_paused ) {
			WP_CLI::log( 'Status:       PAUSED' );
		}
		WP_CLI::log( sprintf( 'Last run:     %s', $flow['last_run_display'] ?? 'Never' ) );
		WP_CLI::log( sprintf( 'Next run:     %s', $flow['next_run_display'] ?? 'Not scheduled' ) );
		WP_CLI::log( sprintf( 'Running:      %s', ( $flow['is_running'] ?? false ) ? 'Yes' : 'No' ) );
		WP_CLI::log( '' );

		// Step configs section.
		$config = $flow['flow_config'] ?? array();

		if ( empty( $config ) ) {
			WP_CLI::log( 'Steps: (none)' );
			return;
		}

		// Show memory files if attached.
		$memory_files = $config['memory_files'] ?? array();
		if ( ! empty( $memory_files ) ) {
			WP_CLI::log( sprintf( 'Memory files: %s', implode( ', ', $memory_files ) ) );
			WP_CLI::log( '' );
		}

		$rows = array();
		foreach ( $config as $step_id => $step_data ) {
			// Skip flow-level metadata keys — only display step configs.
			if ( ! is_array( $step_data ) || ! isset( $step_data['step_type'] ) ) {
				continue;
			}

			$step_type = $step_data['step_type'] ?? '';
			$order     = $step_data['execution_order'] ?? '';
			$slugs     = $step_data['handler_slugs'] ?? array();
			$configs   = $step_data['handler_configs'] ?? array();

			// Show pipeline-level prompt if set.
			$pipeline_prompt = $step_data['pipeline_config']['prompt'] ?? '';

			if ( empty( $slugs ) ) {
				// Step with no handlers (e.g. AI step with only pipeline config).
				$config_display = '';

				if ( $pipeline_prompt ) {
					$config_display = 'prompt=' . $this->truncateValue( $pipeline_prompt, 60 );
				}

				$rows[] = array(
					'step_id'   => $step_id,
					'order'     => $order,
					'step_type' => $step_type,
					'handler'   => '—',
					'config'    => $config_display ? $config_display : '(default)',
				);
				continue;
			}

			foreach ( $slugs as $slug ) {
				$handler_config = $configs[ $slug ] ?? array();
				$config_parts   = array();

				foreach ( $handler_config as $key => $value ) {
					$config_parts[] = $key . '=' . $this->formatConfigValue( $value );
				}

				$rows[] = array(
					'step_id'   => $step_id,
					'order'     => $order,
					'step_type' => $step_type,
					'handler'   => $slug,
					'config'    => implode( ', ', $config_parts ) ? implode( ', ', $config_parts ) : '(default)',
				);
			}
		}

		WP_CLI::log( 'Steps:' );

		$step_fields = array( 'step_id', 'order', 'step_type', 'handler', 'config' );
		WP_CLI\Utils\format_items( 'table', $rows, $step_fields );
	}

	/**
	 * Truncate a display value to a maximum length.
	 *
	 * @param string $value Value to truncate.
	 * @param int    $max   Maximum characters.
	 * @return string Truncated value.
	 */
	private function truncateValue( string $value, int $max = 40 ): string {
		$value = str_replace( array( "\n", "\r" ), ' ', $value );
		if ( mb_strlen( $value ) > $max ) {
			return mb_substr( $value, 0, $max - 3 ) . '...';
		}
		return $value;
	}

	/**
	 * Format a config value for display in the step configs table.
	 *
	 * @param mixed $value Config value.
	 * @return string Formatted value.
	 */
	private function formatConfigValue( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_array( $value ) ) {
			return wp_json_encode( $value );
		}
		$str = (string) $value;
		return $this->truncateValue( $str );
	}
