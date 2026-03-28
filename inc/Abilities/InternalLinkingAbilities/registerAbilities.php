//! registerAbilities — extracted from InternalLinkingAbilities.php.


	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/internal-linking',
				array(
					'label'               => 'Internal Linking',
					'description'         => 'Queue system agent insertion of semantic internal links into posts',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'post_ids'       => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'integer' ),
								'description' => 'Post IDs to process',
							),
							'category'       => array(
								'type'        => 'string',
								'description' => 'Category slug to process all posts from',
							),
							'links_per_post' => array(
								'type'        => 'integer',
								'description' => 'Maximum internal links to insert per post',
								'default'     => 3,
							),
							'dry_run'        => array(
								'type'        => 'boolean',
								'description' => 'Preview which posts would be queued without processing',
								'default'     => false,
							),
							'force'          => array(
								'type'        => 'boolean',
								'description' => 'Force re-processing even if already linked',
								'default'     => false,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'      => array( 'type' => 'boolean' ),
							'queued_count' => array( 'type' => 'integer' ),
							'post_ids'     => array(
								'type'  => 'array',
								'items' => array( 'type' => 'integer' ),
							),
							'message'      => array( 'type' => 'string' ),
							'error'        => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'queueInternalLinking' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/diagnose-internal-links',
				array(
					'label'               => 'Diagnose Internal Links',
					'description'         => 'Report internal link coverage across published posts',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'             => array( 'type' => 'boolean' ),
							'total_posts'         => array( 'type' => 'integer' ),
							'posts_with_links'    => array( 'type' => 'integer' ),
							'posts_without_links' => array( 'type' => 'integer' ),
							'avg_links_per_post'  => array( 'type' => 'number' ),
							'by_category'         => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
						),
					),
					'execute_callback'    => array( self::class, 'diagnoseInternalLinks' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/audit-internal-links',
				array(
					'label'               => 'Audit Internal Links',
					'description'         => 'Scan post content for internal links, build a link graph, and cache results. Does NOT check for broken links — use datamachine/check-broken-links for that.',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'post_type' => array(
								'type'        => 'string',
								'description' => 'Post type to audit. Default: post.',
								'default'     => 'post',
							),
							'category'  => array(
								'type'        => 'string',
								'description' => 'Category slug to limit audit scope.',
							),
							'post_ids'  => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'integer' ),
								'description' => 'Specific post IDs to audit.',
							),
							'force'     => array(
								'type'        => 'boolean',
								'description' => 'Force rebuild even if cached graph exists.',
								'default'     => false,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'        => array( 'type' => 'boolean' ),
							'total_scanned'  => array( 'type' => 'integer' ),
							'total_links'    => array( 'type' => 'integer' ),
							'orphaned_count' => array( 'type' => 'integer' ),
							'avg_outbound'   => array( 'type' => 'number' ),
							'avg_inbound'    => array( 'type' => 'number' ),
							'orphaned_posts' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
							'top_linked'     => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
							'cached'         => array( 'type' => 'boolean' ),
						),
					),
					'execute_callback'    => array( self::class, 'auditInternalLinks' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/get-orphaned-posts',
				array(
					'label'               => 'Get Orphaned Posts',
					'description'         => 'Return posts with zero inbound internal links from the cached link graph. Runs audit automatically if no cache exists.',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'post_type' => array(
								'type'        => 'string',
								'description' => 'Post type to check. Default: post.',
								'default'     => 'post',
							),
							'limit'     => array(
								'type'        => 'integer',
								'description' => 'Maximum orphaned posts to return. Default: 50.',
								'default'     => 50,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'        => array( 'type' => 'boolean' ),
							'orphaned_count' => array( 'type' => 'integer' ),
							'total_scanned'  => array( 'type' => 'integer' ),
							'orphaned_posts' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
							'from_cache'     => array( 'type' => 'boolean' ),
						),
					),
					'execute_callback'    => array( self::class, 'getOrphanedPosts' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/check-broken-links',
				array(
					'label'               => 'Check Broken Links',
					'description'         => 'HTTP HEAD check links from the cached link graph to find broken URLs. Supports internal, external, or all links via scope. External checks include per-domain rate limiting and HEAD→GET fallback.',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'post_type' => array(
								'type'        => 'string',
								'description' => 'Post type scope. Default: post.',
								'default'     => 'post',
							),
							'scope'     => array(
								'type'        => 'string',
								'description' => 'Link scope: internal, external, or all. Default: internal.',
								'enum'        => array( 'internal', 'external', 'all' ),
								'default'     => 'internal',
							),
							'limit'     => array(
								'type'        => 'integer',
								'description' => 'Maximum unique URLs to check. Default: 200.',
								'default'     => 200,
							),
							'timeout'   => array(
								'type'        => 'integer',
								'description' => 'HTTP timeout per request in seconds. Default: 5.',
								'default'     => 5,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'      => array( 'type' => 'boolean' ),
							'scope'        => array( 'type' => 'string' ),
							'urls_checked' => array( 'type' => 'integer' ),
							'broken_count' => array( 'type' => 'integer' ),
							'broken_links' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
							'from_cache'   => array( 'type' => 'boolean' ),
						),
					),
					'execute_callback'    => array( self::class, 'checkBrokenLinks' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/link-opportunities',
				array(
					'label'               => 'Link Opportunities',
					'description'         => 'Rank internal linking opportunities by combining GSC traffic data with the link graph. High-traffic pages with few inbound links score highest.',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'limit'      => array(
								'type'        => 'integer',
								'description' => 'Number of results to return. Default: 20.',
								'default'     => 20,
							),
							'category'   => array(
								'type'        => 'string',
								'description' => 'Category slug to filter by.',
							),
							'min_clicks' => array(
								'type'        => 'integer',
								'description' => 'Minimum GSC clicks to include a page. Default: 5.',
								'default'     => 5,
							),
							'days'       => array(
								'type'        => 'integer',
								'description' => 'GSC lookback period in days. Default: 28.',
								'default'     => 28,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'            => array( 'type' => 'boolean' ),
							'pages_with_traffic' => array( 'type' => 'integer' ),
							'opportunities'      => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'score'          => array( 'type' => 'number' ),
										'clicks'         => array( 'type' => 'number' ),
										'impressions'    => array( 'type' => 'number' ),
										'position'       => array( 'type' => 'number' ),
										'inbound_links'  => array( 'type' => 'integer' ),
										'outbound_links' => array( 'type' => 'integer' ),
										'post_id'        => array( 'type' => 'integer' ),
										'slug'           => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
					'execute_callback'    => array( self::class, 'getLinkOpportunities' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

	
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Queue internal linking for posts.
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function queueInternalLinking( array $input ): array {
		$post_ids       = array_map( 'absint', $input['post_ids'] ?? array() );
		$category       = sanitize_text_field( $input['category'] ?? '' );
		$links_per_post = absint( $input['links_per_post'] ?? 3 );
		$dry_run        = ! empty( $input['dry_run'] );
		$force          = ! empty( $input['force'] );

		$user_id         = get_current_user_id();
		$agent_id        = function_exists( 'datamachine_resolve_or_create_agent_id' ) && $user_id > 0 ? datamachine_resolve_or_create_agent_id( $user_id ) : 0;
		$system_defaults = PluginSettings::resolveModelForAgentContext( $agent_id, 'system' );
		$provider        = $system_defaults['provider'];
		$model           = $system_defaults['model'];

		if ( empty( $provider ) || empty( $model ) ) {
			return array(
				'success'      => false,
				'queued_count' => 0,
				'post_ids'     => array(),
				'message'      => 'No default AI provider/model configured.',
				'error'        => 'Configure default_provider and default_model in Data Machine settings.',
			);
		}

		// Resolve category to post IDs.
		if ( ! empty( $category ) ) {
			$term = get_term_by( 'slug', $category, 'category' );
			if ( ! $term ) {
				return array(
					'success'      => false,
					'queued_count' => 0,
					'post_ids'     => array(),
					'message'      => "Category '{$category}' not found.",
					'error'        => 'Invalid category slug',
				);
			}

			$cat_posts = get_posts(
				array(
					'post_type'   => 'post',
					'post_status' => 'publish',
					'category'    => $term->term_id,
					'fields'      => 'ids',
					'numberposts' => -1,
				)
			);

			$post_ids = array_merge( $post_ids, $cat_posts );
		}

		$post_ids = array_values( array_unique( array_filter( $post_ids ) ) );

		if ( empty( $post_ids ) ) {
			return array(
				'success'      => false,
				'queued_count' => 0,
				'post_ids'     => array(),
				'message'      => 'No post IDs provided or resolved.',
				'error'        => 'Missing required parameter: post_ids or category',
			);
		}

		if ( $dry_run ) {
			return array(
				'success'      => true,
				'queued_count' => count( $post_ids ),
				'post_ids'     => $post_ids,
				'message'      => sprintf( 'Dry run: %d post(s) would be queued for internal linking.', count( $post_ids ) ),
			);
		}

		// Filter to eligible posts.
		$eligible = array();
		foreach ( $post_ids as $pid ) {
			$post = get_post( $pid );
			if ( $post && 'publish' === $post->post_status ) {
				$eligible[] = $pid;
			}
		}

		if ( empty( $eligible ) ) {
			return array(
				'success'      => true,
				'queued_count' => 0,
				'post_ids'     => array(),
				'message'      => 'No eligible published posts found.',
			);
		}

		// Build per-item params for batch scheduling.
		$item_params = array();
		foreach ( $eligible as $pid ) {
			$item_params[] = array(
				'post_id'        => $pid,
				'links_per_post' => $links_per_post,
				'force'          => $force,
				'source'         => 'ability',
			);
		}

		$batch = TaskScheduler::scheduleBatch(
			'internal_linking',
			$item_params,
			array(
				'user_id'  => $user_id,
				'agent_id' => $agent_id,
			)
		);

		if ( false === $batch ) {
			return array(
				'success'      => false,
				'queued_count' => 0,
				'post_ids'     => array(),
				'message'      => 'Failed to schedule batch.',
				'error'        => 'Task batch scheduling failed.',
			);
		}

		return array(
			'success'      => true,
			'queued_count' => count( $eligible ),
			'post_ids'     => $eligible,
			'batch_id'     => $batch['batch_id'] ?? null,
			'message'      => sprintf(
				'Internal linking batch scheduled for %d post(s) (chunks of %d).',
				count( $eligible ),
				$batch['chunk_size'] ?? TaskScheduler::BATCH_CHUNK_SIZE
			),
		);
	}

	/**
	 * Check a single URL's HTTP status.
	 *
	 * Uses HEAD first for efficiency. For external URLs, falls back to GET
	 * with a range header when HEAD returns 405 or 403 (some servers block HEAD).
	 *
	 * @since 0.42.0
	 *
	 * @param string $url        URL to check.
	 * @param int    $timeout    Request timeout in seconds.
	 * @param bool   $is_external Whether this is an external URL (enables GET fallback).
	 * @return int HTTP status code (0 for connection failures/timeouts).
	 */
	private static function checkUrlStatus( string $url, int $timeout, bool $is_external ): int {
		$response = wp_remote_head(
			$url,
			array(
				'timeout'     => $timeout,
				'redirection' => 3,
				'user-agent'  => 'DataMachine/LinkChecker (WordPress; +' . home_url() . ')',
			)
		);

		if ( is_wp_error( $response ) ) {
			return 0;
		}

		$status = wp_remote_retrieve_response_code( $response );

		// Some external servers block HEAD requests — fall back to GET.
		if ( $is_external && ( 405 === $status || 403 === $status ) ) {
			$get_response = wp_remote_get(
				$url,
				array(
					'timeout'     => $timeout,
					'redirection' => 3,
					'headers'     => array( 'Range' => 'bytes=0-0' ),
					'user-agent'  => 'DataMachine/LinkChecker (WordPress; +' . home_url() . ')',
				)
			);

			if ( ! is_wp_error( $get_response ) ) {
				$get_status = wp_remote_retrieve_response_code( $get_response );
				// 206 (Partial Content) means the server supports Range and the URL is alive.
				if ( 206 === $get_status || ( $get_status >= 200 && $get_status < 400 ) ) {
					return $get_status;
				}
				return $get_status;
			}
		}

		return $status ? $status : 0;
	}
