<?php
/**
 * Post Query Abilities
 *
 * Unified ability for querying posts by handler, flow, or pipeline.
 * Enables debugging and bulk fixes for Data Machine-created posts.
 *
 * @package DataMachine\Abilities
 * @since 0.12.0
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\WordPress\PostTracking;

defined( 'ABSPATH' ) || exit;

class PostQueryAbilities {

	private const DEFAULT_PER_PAGE = 20;

	private static bool $registered = false;

	private static function get_filter_types(): array {
		return array(
			'handler'  => array(
				'meta_key'   => PostTracking::HANDLER_META_KEY,
				'value_type' => 'string',
			),
			'flow'     => array(
				'meta_key'   => PostTracking::FLOW_ID_META_KEY,
				'value_type' => 'integer',
			),
			'pipeline' => array(
				'meta_key'   => PostTracking::PIPELINE_ID_META_KEY,
				'value_type' => 'integer',
			),
		);
	}

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbility();
		$this->registerChatTool();
		self::$registered = true;
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/query-posts',
				array(
					'label'               => __( 'Query Posts', 'data-machine' ),
					'description'         => __( 'Find posts created by Data Machine, filtered by handler, flow, or pipeline', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'filter_by', 'filter_value' ),
						'properties' => array(
							'filter_by'    => array(
								'type'        => 'string',
								'enum'        => array( 'handler', 'flow', 'pipeline' ),
								'description' => __( 'What to filter posts by', 'data-machine' ),
							),
							'filter_value' => array(
								'type'        => array( 'string', 'integer' ),
								'description' => __( 'Handler slug, flow ID, or pipeline ID', 'data-machine' ),
							),
							'post_type'    => array(
								'type'        => 'string',
								'default'     => 'any',
								'description' => __( 'Post type to query', 'data-machine' ),
							),
							'post_status'  => array(
								'type'        => 'string',
								'default'     => 'publish',
								'description' => __( 'Post status to query', 'data-machine' ),
							),
							'per_page'     => array(
								'type'    => 'integer',
								'default' => self::DEFAULT_PER_PAGE,
								'minimum' => 1,
								'maximum' => 100,
							),
							'offset'       => array(
								'type'    => 'integer',
								'default' => 0,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'posts'    => array( 'type' => 'array' ),
							'total'    => array( 'type' => 'integer' ),
							'per_page' => array( 'type' => 'integer' ),
							'offset'   => array( 'type' => 'integer' ),
						),
					),
					'execute_callback'    => array( $this, 'executeQueryPosts' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/list-posts',
				array(
					'label'               => __( 'List Posts', 'data-machine' ),
					'description'         => __( 'List Data Machine posts with combinable filters (handler, flow, pipeline)', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'handler'     => array(
								'type'        => 'string',
								'description' => __( 'Filter by handler slug', 'data-machine' ),
							),
							'flow_id'     => array(
								'type'        => 'integer',
								'description' => __( 'Filter by flow ID', 'data-machine' ),
							),
							'pipeline_id' => array(
								'type'        => 'integer',
								'description' => __( 'Filter by pipeline ID', 'data-machine' ),
							),
							'post_type'   => array(
								'type'        => 'string',
								'default'     => 'any',
								'description' => __( 'Post type to query', 'data-machine' ),
							),
							'post_status' => array(
								'type'        => 'string',
								'default'     => 'publish',
								'description' => __( 'Post status to query', 'data-machine' ),
							),
							'per_page'    => array(
								'type'    => 'integer',
								'default' => self::DEFAULT_PER_PAGE,
								'minimum' => 1,
								'maximum' => 100,
							),
							'offset'      => array(
								'type'    => 'integer',
								'default' => 0,
							),
							'orderby'     => array(
								'type'    => 'string',
								'default' => 'date',
							),
							'order'       => array(
								'type'    => 'string',
								'default' => 'DESC',
								'enum'    => array( 'ASC', 'DESC' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'posts'    => array( 'type' => 'array' ),
							'total'    => array( 'type' => 'integer' ),
							'per_page' => array( 'type' => 'integer' ),
							'offset'   => array( 'type' => 'integer' ),
						),
					),
					'execute_callback'    => array( $this, 'executeQueryPostsList' ),
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

	private function registerChatTool(): void {
		add_filter(
			'datamachine_chat_tools',
			function ( $tools ) {
				$tools['query_posts'] = array( $this, 'getQueryPostsTool' );
				return $tools;
			}
		);
	}

	public function getQueryPostsTool(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handleQueryPosts',
			'description' => 'Find posts created by Data Machine. Filter by handler slug, flow ID, or pipeline ID. Returns post ID, title, handler, flow ID, pipeline ID, and post date.',
			'parameters'  => array(
				'filter_by'    => array(
					'type'        => 'string',
					'required'    => true,
					'enum'        => array( 'handler', 'flow', 'pipeline' ),
					'description' => 'What to filter by: "handler" (slug), "flow" (ID), or "pipeline" (ID)',
				),
				'filter_value' => array(
					'type'        => array( 'string', 'integer' ),
					'required'    => true,
					'description' => 'Handler slug (e.g., "universal_web_scraper"), flow ID, or pipeline ID',
				),
				'post_type'    => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Post type to query (default: "any")',
				),
				'post_status'  => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Post status (default: "publish")',
				),
				'per_page'     => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Number of posts to return (default: 20)',
				),
			),
		);
	}

	/**
	 * Query recent posts managed by Data Machine across all post types.
	 *
	 * No filter required — returns any post with DM tracking meta.
	 *
	 * @param array $input Query parameters (post_type, post_status, per_page, offset).
	 * @return array Result with posts array and total count.
	 */
	public function executeQueryRecentPosts( array $input ): array {
		$post_type   = $input['post_type'] ?? 'any';
		$post_status = $input['post_status'] ?? 'publish';
		$per_page    = (int) ( $input['per_page'] ?? self::DEFAULT_PER_PAGE );
		$offset      = (int) ( $input['offset'] ?? 0 );

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => $post_status,
			'posts_per_page' => $per_page,
			'offset'         => $offset,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'     => PostTracking::HANDLER_META_KEY,
					'compare' => 'EXISTS',
				),
			),
		);

		$query = new \WP_Query( $args );

		$posts = array();
		foreach ( $query->posts as $post ) {
			$posts[] = $this->format_post_result( $post );
		}

		return array(
			'posts'    => $posts,
			'total'    => $query->found_posts,
			'per_page' => $per_page,
			'offset'   => $offset,
		);
	}

	/**
	 * Query posts with combinable tracking filters.
	 *
	 * Supports any combination of handler, flow_id, and pipeline_id filters
	 * applied as an AND meta_query. Omitted filters are ignored.
	 *
	 * @since 0.34.0
	 *
	 * @param array $input {
	 *     @type string $handler     Handler slug to filter by.
	 *     @type int    $flow_id     Flow ID to filter by.
	 *     @type int    $pipeline_id Pipeline ID to filter by.
	 *     @type string $post_type   Post type (default: 'any').
	 *     @type string $post_status Post status (default: 'publish').
	 *     @type int    $per_page    Results per page (default: 20, max: 100).
	 *     @type int    $offset      Offset for pagination.
	 *     @type string $orderby     Order by field (default: 'date').
	 *     @type string $order       Sort direction (default: 'DESC').
	 * }
	 * @return array Result with posts array, total count, per_page, and offset.
	 */
	public function executeQueryPostsList( array $input ): array {
		$handler     = sanitize_text_field( $input['handler'] ?? '' );
		$flow_id     = (int) ( $input['flow_id'] ?? 0 );
		$pipeline_id = (int) ( $input['pipeline_id'] ?? 0 );
		$post_type   = $input['post_type'] ?? 'any';
		$post_status = $input['post_status'] ?? 'publish';
		$per_page    = min( max( (int) ( $input['per_page'] ?? self::DEFAULT_PER_PAGE ), 1 ), 100 );
		$offset      = max( (int) ( $input['offset'] ?? 0 ), 0 );
		$orderby     = $input['orderby'] ?? 'date';
		$order       = strtoupper( $input['order'] ?? 'DESC' );

		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		// Build meta_query from provided filters.
		$meta_query = array();

		if ( ! empty( $handler ) ) {
			$meta_query[] = array(
				'key'     => PostTracking::HANDLER_META_KEY,
				'value'   => $handler,
				'compare' => '=',
			);
		}

		if ( $flow_id > 0 ) {
			$meta_query[] = array(
				'key'     => PostTracking::FLOW_ID_META_KEY,
				'value'   => $flow_id,
				'compare' => '=',
			);
		}

		if ( $pipeline_id > 0 ) {
			$meta_query[] = array(
				'key'     => PostTracking::PIPELINE_ID_META_KEY,
				'value'   => $pipeline_id,
				'compare' => '=',
			);
		}

		// If no filters provided, require at least the handler meta key to exist
		// (i.e. only show DM-managed posts).
		if ( empty( $meta_query ) ) {
			$meta_query[] = array(
				'key'     => PostTracking::HANDLER_META_KEY,
				'compare' => 'EXISTS',
			);
		}

		if ( count( $meta_query ) > 1 ) {
			$meta_query['relation'] = 'AND';
		}

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => $post_status,
			'posts_per_page' => $per_page,
			'offset'         => $offset,
			'orderby'        => $orderby,
			'order'          => $order,
			'meta_query'     => $meta_query,
		);

		$query = new \WP_Query( $args );

		$posts = array();
		foreach ( $query->posts as $post ) {
			$posts[] = $this->format_post_result( $post );
		}

		return array(
			'posts'    => $posts,
			'total'    => $query->found_posts,
			'per_page' => $per_page,
			'offset'   => $offset,
		);
	}

	public function executeQueryPosts( array $input ): array {
		$filter_by    = $input['filter_by'] ?? '';
		$filter_value = $input['filter_value'] ?? '';
		$post_type    = $input['post_type'] ?? 'any';
		$post_status  = $input['post_status'] ?? 'publish';
		$per_page     = (int) ( $input['per_page'] ?? self::DEFAULT_PER_PAGE );
		$offset       = (int) ( $input['offset'] ?? 0 );

		$filter_types = self::get_filter_types();

		if ( ! isset( $filter_types[ $filter_by ] ) ) {
			return array(
				'posts' => array(),
				'total' => 0,
				'error' => sprintf( 'Invalid filter_by value. Must be one of: %s', implode( ', ', array_keys( $filter_types ) ) ),
			);
		}

		$filter_config = $filter_types[ $filter_by ];
		$meta_key      = $filter_config['meta_key'];
		$value_type    = $filter_config['value_type'];

		if ( 'string' === $value_type ) {
			$filter_value = sanitize_text_field( $filter_value );
			if ( empty( $filter_value ) ) {
				return array(
					'posts' => array(),
					'total' => 0,
					'error' => 'filter_value is required for handler filter',
				);
			}
		} else {
			$filter_value = (int) $filter_value;
			if ( $filter_value <= 0 ) {
				return array(
					'posts' => array(),
					'total' => 0,
					'error' => sprintf( 'filter_value must be a positive integer for %s filter', $filter_by ),
				);
			}
		}

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => $post_status,
			'posts_per_page' => $per_page,
			'offset'         => $offset,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'     => $meta_key,
					'value'   => $filter_value,
					'compare' => '=',
				),
			),
		);

		$query = new \WP_Query( $args );

		$posts = array();
		foreach ( $query->posts as $post ) {
			$posts[] = $this->format_post_result( $post );
		}

		return array(
			'posts'    => $posts,
			'total'    => $query->found_posts,
			'per_page' => $per_page,
			'offset'   => $offset,
		);
	}

	public function handleQueryPosts( array $parameters, array $tool_def = array() ): array {
		$tool_def;
		$result = $this->executeQueryPosts(
			array(
				'filter_by'    => $parameters['filter_by'] ?? '',
				'filter_value' => $parameters['filter_value'] ?? '',
				'post_type'    => $parameters['post_type'] ?? 'any',
				'post_status'  => $parameters['post_status'] ?? 'publish',
				'per_page'     => $parameters['per_page'] ?? self::DEFAULT_PER_PAGE,
			)
		);

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'query_posts',
		);
	}

	private function format_post_result( \WP_Post $post ): array {
		return array(
			'id'            => $post->ID,
			'title'         => $post->post_title,
			'post_type'     => $post->post_type,
			'post_status'   => $post->post_status,
			'post_date'     => $post->post_date,
			'post_date_gmt' => $post->post_date_gmt,
			'post_modified' => $post->post_modified,
			'handler_slug'  => get_post_meta( $post->ID, PostTracking::HANDLER_META_KEY, true ),
			'flow_id'       => (int) get_post_meta( $post->ID, PostTracking::FLOW_ID_META_KEY, true ),
			'pipeline_id'   => (int) get_post_meta( $post->ID, PostTracking::PIPELINE_ID_META_KEY, true ),
			'post_url'      => get_permalink( $post->ID ),
		);
	}
}
