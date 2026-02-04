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

use const DataMachine\Core\WordPress\DATAMACHINE_POST_HANDLER_META_KEY;
use const DataMachine\Core\WordPress\DATAMACHINE_POST_FLOW_ID_META_KEY;
use const DataMachine\Core\WordPress\DATAMACHINE_POST_PIPELINE_ID_META_KEY;

defined( 'ABSPATH' ) || exit;

class PostQueryAbilities {

	private const DEFAULT_PER_PAGE = 20;

	private static bool $registered = false;

	private const FILTER_TYPES = array(
		'handler'  => array(
			'meta_key'   => DATAMACHINE_POST_HANDLER_META_KEY,
			'value_type' => 'string',
		),
		'flow'     => array(
			'meta_key'   => DATAMACHINE_POST_FLOW_ID_META_KEY,
			'value_type' => 'integer',
		),
		'pipeline' => array(
			'meta_key'   => DATAMACHINE_POST_PIPELINE_ID_META_KEY,
			'value_type' => 'integer',
		),
	);

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
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
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

	public function executeQueryPosts( array $input ): array {
		$filter_by    = $input['filter_by'] ?? '';
		$filter_value = $input['filter_value'] ?? '';
		$post_type    = $input['post_type'] ?? 'any';
		$post_status  = $input['post_status'] ?? 'publish';
		$per_page     = (int) ( $input['per_page'] ?? self::DEFAULT_PER_PAGE );
		$offset       = (int) ( $input['offset'] ?? 0 );

		if ( ! isset( self::FILTER_TYPES[ $filter_by ] ) ) {
			return array(
				'posts' => array(),
				'total' => 0,
				'error' => sprintf( 'Invalid filter_by value. Must be one of: %s', implode( ', ', array_keys( self::FILTER_TYPES ) ) ),
			);
		}

		$filter_config = self::FILTER_TYPES[ $filter_by ];
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
			'handler_slug'  => get_post_meta( $post->ID, DATAMACHINE_POST_HANDLER_META_KEY, true ),
			'flow_id'       => (int) get_post_meta( $post->ID, DATAMACHINE_POST_FLOW_ID_META_KEY, true ),
			'pipeline_id'   => (int) get_post_meta( $post->ID, DATAMACHINE_POST_PIPELINE_ID_META_KEY, true ),
			'post_url'      => get_permalink( $post->ID ),
		);
	}
}
