//! register — extracted from Flows.php.


	/**
	 * Register REST API routes
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register flow CRUD endpoints
	 */
	public static function register_routes() {
		register_rest_route(
			'datamachine/v1',
			'/flows',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_create_flow' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'pipeline_id'       => array(
						'required'          => true,
						'type'              => 'integer',
						'description'       => __( 'Parent pipeline ID', 'data-machine' ),
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && $param > 0;
						},
						'sanitize_callback' => function ( $param ) {
							return (int) $param;
						},
					),
					'flow_name'         => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'Flow',
						'description'       => __( 'Flow name', 'data-machine' ),
						'sanitize_callback' => function ( $param ) {
							return sanitize_text_field( $param );
						},
					),
					'flow_config'       => array(
						'required'    => false,
						'type'        => 'array',
						'description' => __( 'Flow configuration (handler settings per step)', 'data-machine' ),
					),
					'scheduling_config' => array(
						'required'    => false,
						'type'        => 'array',
						'description' => __( 'Scheduling configuration', 'data-machine' ),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/flows',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle_get_flows' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'pipeline_id' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Optional pipeline ID to filter flows', 'data-machine' ),
					),
					'per_page'    => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
						'description'       => __( 'Number of flows per page', 'data-machine' ),
					),
					'offset'      => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
						'description'       => __( 'Offset for pagination', 'data-machine' ),
					),
					'user_id'     => array(
						'required'          => false,
						'type'              => 'integer',
						'description'       => __( 'Filter by user ID (admin only, non-admins always see own data)', 'data-machine' ),
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/flows/(?P<flow_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'handle_get_single_flow' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'flow_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Flow ID to retrieve', 'data-machine' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'handle_delete_flow' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'flow_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Flow ID to delete', 'data-machine' ),
						),
					),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( self::class, 'handle_update_flow' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'flow_id'           => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Flow ID to update', 'data-machine' ),
						),
						'flow_name'         => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => __( 'New flow title', 'data-machine' ),
						),
						'scheduling_config' => array(
							'required'    => false,
							'type'        => 'object',
							'description' => __( 'Scheduling configuration', 'data-machine' ),
						),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/flows/(?P<flow_id>\d+)/pause',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_pause_flow' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'flow_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Flow ID to pause', 'data-machine' ),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/flows/(?P<flow_id>\d+)/resume',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_resume_flow' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'flow_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Flow ID to resume', 'data-machine' ),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/flows/pause',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_bulk_pause' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'pipeline_id' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Pause all flows in this pipeline', 'data-machine' ),
					),
					'agent_id'    => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Pause all flows for this agent', 'data-machine' ),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/flows/resume',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_bulk_resume' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'pipeline_id' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Resume all flows in this pipeline', 'data-machine' ),
					),
					'agent_id'    => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Resume all flows for this agent', 'data-machine' ),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/flows/(?P<flow_id>\d+)/duplicate',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_duplicate_flow' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'flow_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Source flow ID to duplicate', 'data-machine' ),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/flows/(?P<flow_id>\d+)/memory-files',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'handle_get_memory_files' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'flow_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Flow ID', 'data-machine' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'handle_update_memory_files' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'flow_id'      => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Flow ID', 'data-machine' ),
						),
						'memory_files' => array(
							'required'    => true,
							'type'        => 'array',
							'description' => __( 'Array of agent memory filenames', 'data-machine' ),
							'items'       => array(
								'type' => 'string',
							),
						),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/flows/problems',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle_get_problem_flows' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'threshold' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Minimum consecutive failures (defaults to problem_flow_threshold setting)', 'data-machine' ),
					),
				),
			)
		);
	}
