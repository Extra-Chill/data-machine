//! sanitize_daily_memory — extracted from Flows.php.


	/**
	 * Handle update memory files request for a flow.
	 *
	 * PUT/POST /datamachine/v1/flows/{flow_id}/memory-files
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function handle_update_memory_files( $request ) {
		$flow_id      = (int) $request->get_param( 'flow_id' );
		$params       = $request->get_json_params();
		$memory_files = $params['memory_files'] ?? array();
		$daily_memory = $params['daily_memory'] ?? null;

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
				__( 'You do not have permission to update this flow.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		// Sanitize filenames.
		$memory_files = array_map( 'sanitize_file_name', $memory_files );
		$memory_files = array_values( array_filter( $memory_files ) );

		// Sanitize daily_memory config if provided.
		if ( null !== $daily_memory ) {
			$daily_memory = self::sanitize_daily_memory( $daily_memory );
		}

		$result = $db_flows->update_flow_memory_files( $flow_id, $memory_files, $daily_memory );

		if ( ! $result ) {
			return new \WP_Error(
				'update_failed',
				__( 'Failed to update memory files.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'memory_files' => $memory_files,
					'daily_memory' => $daily_memory ?? $db_flows->get_flow_daily_memory( $flow_id ),
				),
				'message' => __( 'Flow memory files updated successfully.', 'data-machine' ),
			)
		);
	}

	/**
	 * Sanitize daily memory configuration.
	 *
	 * @since 0.40.0
	 *
	 * @param array $config Raw daily memory config.
	 * @return array Sanitized config.
	 */
	private static function sanitize_daily_memory( array $config ): array {
		$allowed_modes = array( 'none', 'recent_days', 'specific_dates', 'date_range', 'months' );
		$mode          = $config['mode'] ?? 'none';

		if ( ! in_array( $mode, $allowed_modes, true ) ) {
			$mode = 'none';
		}

		$sanitized = array( 'mode' => $mode );

		switch ( $mode ) {
			case 'recent_days':
				$sanitized['days'] = min( max( (int) ( $config['days'] ?? 7 ), 1 ), 90 );
				break;

			case 'specific_dates':
				$dates              = $config['dates'] ?? array();
				$sanitized['dates'] = array_values(
					array_filter(
						array_map(
							function ( $date ) {
								return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : null;
							},
							(array) $dates
						)
					)
				);
				break;

			case 'date_range':
				$from = $config['from'] ?? null;
				$to   = $config['to'] ?? null;
				if ( $from && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
					$sanitized['from'] = $from;
				}
				if ( $to && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
					$sanitized['to'] = $to;
				}
				break;

			case 'months':
				$months              = $config['months'] ?? array();
				$sanitized['months'] = array_values(
					array_filter(
						array_map(
							function ( $month ) {
								return preg_match( '/^\d{4}\/\d{2}$/', $month ) ? $month : null;
							},
							(array) $months
						)
					)
				);
				break;
		}

		return $sanitized;
	}
