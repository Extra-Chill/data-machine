//! handle_get — extracted from Flows.php.


	/**
	 * Handle flows retrieval request with pagination support
	 */
	public static function handle_get_flows( $request ) {
		$pipeline_id     = $request->get_param( 'pipeline_id' );
		$per_page        = $request->get_param( 'per_page' ) ?? 20;
		$offset          = $request->get_param( 'offset' ) ?? 0;
		$scoped_user_id  = PermissionHelper::resolve_scoped_user_id( $request );
		$scoped_agent_id = PermissionHelper::resolve_scoped_agent_id( $request );

		$ability = wp_get_ability( 'datamachine/get-flows' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$input = array(
			'pipeline_id' => $pipeline_id,
			'per_page'    => $per_page,
			'offset'      => $offset,
		);
		if ( null !== $scoped_agent_id ) {
			$input['agent_id'] = $scoped_agent_id;
		} elseif ( null !== $scoped_user_id ) {
			$input['user_id'] = $scoped_user_id;
		}
		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $result['success'] ) {
			return new \WP_Error( 'ability_error', $result['error'], array( 'status' => 500 ) );
		}

		if ( $pipeline_id ) {
			return rest_ensure_response(
				array(
					'success'  => true,
					'data'     => array(
						'pipeline_id' => $pipeline_id,
						'flows'       => $result['flows'],
					),
					'total'    => $result['total'],
					'per_page' => $result['per_page'],
					'offset'   => $result['offset'],
				)
			);
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'data'     => $result['flows'],
				'total'    => $result['total'] ?? count( $result['flows'] ),
				'per_page' => $result['per_page'] ?? 20,
				'offset'   => $result['offset'] ?? 0,
			)
		);
	}

	/**
	 * Handle single flow retrieval request with scheduling metadata
	 */
	public static function handle_get_single_flow( $request ) {
		$flow_id = (int) $request->get_param( 'flow_id' );

		$ability = wp_get_ability( 'datamachine/get-flows' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$result = $ability->execute( array( 'flow_id' => $flow_id ) );

		if ( ! $result['success'] || empty( $result['flows'] ) ) {
			$status = 400;
			if ( false !== strpos( $result['error'] ?? '', 'not found' ) || empty( $result['flows'] ) ) {
				$status = 404;
			}

			return new \WP_Error(
				'flow_not_found',
				$result['error'] ?? __( 'Flow not found.', 'data-machine' ),
				array( 'status' => $status )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result['flows'][0],
			)
		);
	}

	/**
	 * Handle problem flows retrieval request.
	 *
	 * Returns flows with consecutive failures at or above the threshold.
	 *
	 * GET /datamachine/v1/flows/problems
	 */
	public static function handle_get_problem_flows( $request ) {
		$threshold = $request->get_param( 'threshold' );

		$ability = wp_get_ability( 'datamachine/get-problem-flows' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$input = array();
		if ( null !== $threshold && $threshold > 0 ) {
			$input['threshold'] = (int) $threshold;
		}

		$result = $ability->execute( $input );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'get_problem_flows_error',
				$result['error'] ?? __( 'Failed to get problem flows', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		$problem_flows = array_merge( $result['failing'] ?? array(), $result['idle'] ?? array() );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'problem_flows' => $problem_flows,
					'total'         => $result['count'] ?? count( $problem_flows ),
					'threshold'     => $result['threshold'] ?? 3,
					'failing'       => $result['failing'] ?? array(),
					'idle'          => $result['idle'] ?? array(),
				),
			)
		);
	}

	/**
	 * Handle get memory files request for a flow.
	 *
	 * GET /datamachine/v1/flows/{flow_id}/memory-files
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function handle_get_memory_files( $request ) {
		$flow_id = (int) $request->get_param( 'flow_id' );

		$db_flows = new \DataMachine\Core\Database\Flows\Flows();
		$flow     = $db_flows->get_flow( $flow_id );

		if ( ! $flow ) {
			return new \WP_Error(
				'flow_not_found',
				__( 'Flow not found.', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		$resource_agent_id = isset( $flow['agent_id'] ) ? (int) $flow['agent_id'] : null;
		if ( ! PermissionHelper::owns_agent_resource( $resource_agent_id, (int) ( $flow['user_id'] ?? 0 ) ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this flow.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		$memory_files = $db_flows->get_flow_memory_files( $flow_id );
		$daily_memory = $db_flows->get_flow_daily_memory( $flow_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'memory_files' => $memory_files,
					'daily_memory' => $daily_memory,
				),
			)
		);
	}
