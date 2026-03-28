//! handle_bulk — extracted from Flows.php.


	/**
	 * Handle bulk pause request (by pipeline or agent).
	 *
	 * POST /datamachine/v1/flows/pause
	 *
	 * @since 0.59.0
	 */
	public static function handle_bulk_pause( $request ) {
		$pipeline_id = $request->get_param( 'pipeline_id' );
		$agent_id    = $request->get_param( 'agent_id' );

		if ( ! $pipeline_id && ! $agent_id ) {
			return new \WP_Error(
				'missing_scope',
				__( 'Must provide pipeline_id or agent_id.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$ability = wp_get_ability( 'datamachine/pause-flow' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$input = array();
		if ( $pipeline_id ) {
			$input['pipeline_id'] = (int) $pipeline_id;
		}
		if ( $agent_id ) {
			$input['agent_id'] = (int) $agent_id;
		}

		$result = $ability->execute( $input );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'bulk_pause_failed',
				$result['error'] ?? __( 'Failed to pause flows.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Handle bulk resume request (by pipeline or agent).
	 *
	 * POST /datamachine/v1/flows/resume
	 *
	 * @since 0.59.0
	 */
	public static function handle_bulk_resume( $request ) {
		$pipeline_id = $request->get_param( 'pipeline_id' );
		$agent_id    = $request->get_param( 'agent_id' );

		if ( ! $pipeline_id && ! $agent_id ) {
			return new \WP_Error(
				'missing_scope',
				__( 'Must provide pipeline_id or agent_id.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$ability = wp_get_ability( 'datamachine/resume-flow' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$input = array();
		if ( $pipeline_id ) {
			$input['pipeline_id'] = (int) $pipeline_id;
		}
		if ( $agent_id ) {
			$input['agent_id'] = (int) $agent_id;
		}

		$result = $ability->execute( $input );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'bulk_resume_failed',
				$result['error'] ?? __( 'Failed to resume flows.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $result );
	}
