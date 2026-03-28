//! buildPauseResumeInput — extracted from FlowsCommand.php.


	/**
	 * Pause one or more flows.
	 *
	 * Preserves the original schedule so flows can be resumed later.
	 *
	 * ## USAGE
	 *
	 *     wp datamachine flows pause <flow_id>
	 *     wp datamachine flows pause --pipeline=<id>
	 *     wp datamachine flows pause --agent=<slug_or_id>
	 *
	 * @param array $args       Positional args (optional flow_id).
	 * @param array $assoc_args Associative args (--pipeline, --agent).
	 */
	private function pauseFlows( array $args, array $assoc_args ): void {
		$input = $this->buildPauseResumeInput( $args, $assoc_args );
		if ( null === $input ) {
			return; // Error already printed.
		}

		$ability = new \DataMachine\Abilities\FlowAbilities();
		$result  = $ability->executePauseFlow( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to pause flows' );
			return;
		}

		WP_CLI::success( $result['message'] ?? 'Flows paused.' );

		$format = $assoc_args['format'] ?? 'table';
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		} else {
			foreach ( $result['flows'] ?? array() as $detail ) {
				WP_CLI::log( sprintf( '  Flow %d: %s', $detail['flow_id'], $detail['status'] ) );
			}
		}
	}

	/**
	 * Resume one or more paused flows.
	 *
	 * Re-registers Action Scheduler hooks from the preserved schedule.
	 *
	 * ## USAGE
	 *
	 *     wp datamachine flows resume <flow_id>
	 *     wp datamachine flows resume --pipeline=<id>
	 *     wp datamachine flows resume --agent=<slug_or_id>
	 *
	 * @param array $args       Positional args (optional flow_id).
	 * @param array $assoc_args Associative args (--pipeline, --agent).
	 */
	private function resumeFlows( array $args, array $assoc_args ): void {
		$input = $this->buildPauseResumeInput( $args, $assoc_args );
		if ( null === $input ) {
			return; // Error already printed.
		}

		$ability = new \DataMachine\Abilities\FlowAbilities();
		$result  = $ability->executeResumeFlow( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to resume flows' );
			return;
		}

		WP_CLI::success( $result['message'] ?? 'Flows resumed.' );

		$format = $assoc_args['format'] ?? 'table';
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		} else {
			foreach ( $result['flows'] ?? array() as $detail ) {
				$line = sprintf( '  Flow %d: %s', $detail['flow_id'], $detail['status'] );
				if ( ! empty( $detail['error'] ) ) {
					$line .= ' — ' . $detail['error'];
				}
				WP_CLI::log( $line );
			}
		}
	}

	/**
	 * Build input array for pause/resume from CLI args.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return array|null Input array, or null on validation error.
	 */
	private function buildPauseResumeInput( array $args, array $assoc_args ): ?array {
		$flow_id     = ! empty( $args[0] ) ? (int) $args[0] : null;
		$pipeline_id = isset( $assoc_args['pipeline'] ) ? (int) $assoc_args['pipeline'] : ( isset( $assoc_args['pipeline_id'] ) ? (int) $assoc_args['pipeline_id'] : null );
		$agent_id    = AgentResolver::resolve( $assoc_args );

		if ( null === $flow_id && null === $pipeline_id && null === $agent_id ) {
			WP_CLI::error( 'Must provide a flow ID, --pipeline=<id>, or --agent=<slug_or_id>.' );
			return null;
		}

		$input = array();
		if ( null !== $flow_id ) {
			$input['flow_id'] = $flow_id;
		} elseif ( null !== $pipeline_id ) {
			$input['pipeline_id'] = $pipeline_id;
		} elseif ( null !== $agent_id ) {
			$input['agent_id'] = $agent_id;
		}

		return $input;
	}
