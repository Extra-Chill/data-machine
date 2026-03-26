//! handle — extracted from Flows.php.


	/**
	 * Check if user has permission to manage flows
	 */
	public static function check_permission( $request ) {
		$request;
		if ( ! PermissionHelper::can( 'manage_flows' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to create flows.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle flow creation request
	 */
	public static function handle_create_flow( $request ) {
		$ability = wp_get_ability( 'datamachine/create-flow' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$input = array(
			'pipeline_id' => (int) $request->get_param( 'pipeline_id' ),
			'flow_name'   => $request->get_param( 'flow_name' ) ?? 'Flow',
			'user_id'     => PermissionHelper::acting_user_id(),
		);

		// Carry agent_id from body params or query string (agent interceptor).
		$scoped_agent_id = PermissionHelper::resolve_scoped_agent_id( $request );
		if ( null !== $scoped_agent_id ) {
			$input['agent_id'] = $scoped_agent_id;
		}

		if ( $request->get_param( 'flow_config' ) ) {
			$input['flow_config'] = $request->get_param( 'flow_config' );
		}
		if ( $request->get_param( 'scheduling_config' ) ) {
			$input['scheduling_config'] = $request->get_param( 'scheduling_config' );
		}

		$result = $ability->execute( $input );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'flow_creation_failed',
				$result['error'] ?? __( 'Failed to create flow.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}

	/**
	 * Handle flow deletion request
	 */
	public static function handle_delete_flow( $request ) {
		$flow_id = (int) $request->get_param( 'flow_id' );

		// Verify ownership before deleting.
		$db_flows = new \DataMachine\Core\Database\Flows\Flows();
		$flow     = $db_flows->get_flow( $flow_id );

		$resource_agent_id = isset( $flow['agent_id'] ) ? (int) $flow['agent_id'] : null;
		if ( $flow && ! PermissionHelper::owns_agent_resource( $resource_agent_id, (int) ( $flow['user_id'] ?? 0 ) ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to delete this flow.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		$ability = wp_get_ability( 'datamachine/delete-flow' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$result = $ability->execute(
			array(
				'flow_id' => $flow_id,
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'flow_deletion_failed',
				$result['error'] ?? __( 'Failed to delete flow.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Handle flow duplication request
	 */
	public static function handle_duplicate_flow( $request ) {
		$flow_id = (int) $request->get_param( 'flow_id' );

		// Verify ownership before duplicating.
		$db_flows = new \DataMachine\Core\Database\Flows\Flows();
		$flow     = $db_flows->get_flow( $flow_id );

		$resource_agent_id = isset( $flow['agent_id'] ) ? (int) $flow['agent_id'] : null;
		if ( $flow && ! PermissionHelper::owns_agent_resource( $resource_agent_id, (int) ( $flow['user_id'] ?? 0 ) ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to duplicate this flow.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		$ability = wp_get_ability( 'datamachine/duplicate-flow' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$result = $ability->execute(
			array(
				'source_flow_id' => $flow_id,
				'user_id'        => PermissionHelper::acting_user_id(),
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'flow_duplication_failed',
				$result['error'] ?? __( 'Failed to duplicate flow.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Handle flow update request (title and/or scheduling)
	 *
	 * PATCH /datamachine/v1/flows/{id}
	 */
	public static function handle_update_flow( $request ) {
		$flow_id = (int) $request->get_param( 'flow_id' );

		// Verify ownership before updating.
		$db_flows = new \DataMachine\Core\Database\Flows\Flows();
		$flow     = $db_flows->get_flow( $flow_id );

		$resource_agent_id = isset( $flow['agent_id'] ) ? (int) $flow['agent_id'] : null;
		if ( $flow && ! PermissionHelper::owns_agent_resource( $resource_agent_id, (int) ( $flow['user_id'] ?? 0 ) ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to update this flow.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		$ability = wp_get_ability( 'datamachine/update-flow' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$input = array(
			'flow_id' => $flow_id,
		);

		$flow_name         = $request->get_param( 'flow_name' );
		$scheduling_config = $request->get_param( 'scheduling_config' );

		if ( null !== $flow_name ) {
			$input['flow_name'] = $flow_name;
		}
		if ( null !== $scheduling_config ) {
			$input['scheduling_config'] = $scheduling_config;
		}

		$result = $ability->execute( $input );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'update_failed',
				$result['error'] ?? __( 'Failed to update flow', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$flow_id = $result['flow_id'];

		$get_ability = wp_get_ability( 'datamachine/get-flows' );
		if ( $get_ability ) {
			$flow_result = $get_ability->execute( array( 'flow_id' => $flow_id ) );
			if ( ( $flow_result['success'] ?? false ) && ! empty( $flow_result['flows'] ) ) {
				return rest_ensure_response(
					array(
						'success' => true,
						'data'    => $flow_result['flows'][0],
						'message' => __( 'Flow updated successfully', 'data-machine' ),
					)
				);
			}
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result['flow_data'] ?? array( 'flow_id' => $flow_id ),
				'message' => __( 'Flow updated successfully', 'data-machine' ),
			)
		);
	}

	/**
	 * Handle single flow pause request.
	 *
	 * POST /datamachine/v1/flows/{flow_id}/pause
	 *
	 * @since 0.59.0
	 */
	public static function handle_pause_flow( $request ) {
		$flow_id = (int) $request->get_param( 'flow_id' );

		$ability = wp_get_ability( 'datamachine/pause-flow' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$result = $ability->execute( array( 'flow_id' => $flow_id ) );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'pause_failed',
				$result['error'] ?? __( 'Failed to pause flow.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Handle single flow resume request.
	 *
	 * POST /datamachine/v1/flows/{flow_id}/resume
	 *
	 * @since 0.59.0
	 */
	public static function handle_resume_flow( $request ) {
		$flow_id = (int) $request->get_param( 'flow_id' );

		$ability = wp_get_ability( 'datamachine/resume-flow' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$result = $ability->execute( array( 'flow_id' => $flow_id ) );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'resume_failed',
				$result['error'] ?? __( 'Failed to resume flow.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $result );
	}
