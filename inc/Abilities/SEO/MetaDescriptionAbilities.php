<?php
/**
 * Meta Description Abilities
 *
 * Ability endpoints for AI-powered meta description generation and diagnostics.
 * Delegates async execution to the System Agent infrastructure.
 *
 * Meta descriptions are stored in the WordPress post_excerpt field — the
 * standard WordPress field that SEO plugins read from.
 *
 * @package DataMachine\Abilities\SEO
 * @since 0.31.0
 */

namespace DataMachine\Abilities\SEO;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\PluginSettings;
use DataMachine\Engine\Tasks\TaskScheduler;

defined( 'ABSPATH' ) || exit;

class MetaDescriptionAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/generate-meta-description',
				array(
					'label'               => 'Generate Meta Description',
					'description'         => 'Queue system agent generation of meta descriptions (saved to post excerpt)',
					'category'            => 'datamachine-seo',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'post_id'   => array(
								'type'        => 'integer',
								'description' => 'Post ID to generate meta description for',
							),
							'post_type' => array(
								'type'        => 'string',
								'description' => 'Post type to batch process (e.g. "post", "page"). When omitted in batch mode, discovery spans every post type registered via the datamachine_post_types_for_meta_description filter.',
							),
							'limit'     => array(
								'type'        => 'integer',
								'description' => 'Maximum posts to queue (for batch mode)',
								'default'     => 50,
							),
							'force'     => array(
								'type'        => 'boolean',
								'description' => 'Force regeneration even if post excerpt exists',
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
					'execute_callback'    => array( self::class, 'generateMetaDescriptions' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/diagnose-meta-descriptions',
				array(
					'label'               => 'Diagnose Meta Descriptions',
					'description'         => 'Report post excerpt (meta description) coverage for posts',
					'category'            => 'datamachine-seo',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'post_type' => array(
								'type'        => 'string',
								'description' => 'Post type to diagnose (default: post)',
								'default'     => 'post',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'total_posts'   => array( 'type' => 'integer' ),
							'missing_count' => array( 'type' => 'integer' ),
							'has_count'     => array( 'type' => 'integer' ),
							'coverage'      => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'diagnoseMetaDescriptions' ),
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
	 * Generate meta descriptions for posts.
	 *
	 * Supports single post (post_id) or batch mode (post_type + limit).
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function generateMetaDescriptions( array $input ): array {
		$post_id   = absint( $input['post_id'] ?? 0 );
		$post_type = isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : '';
		$limit     = absint( $input['limit'] ?? 50 );
		$force     = ! empty( $input['force'] );

		$user_id         = get_current_user_id();
		$agent_id        = function_exists( 'datamachine_resolve_or_create_agent_id' ) && $user_id > 0 ? datamachine_resolve_or_create_agent_id( $user_id ) : 0;
		$system_defaults = PluginSettings::resolveModelForAgentMode( $agent_id, 'system' );
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

		// Single post mode.
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return array(
					'success'      => false,
					'queued_count' => 0,
					'post_ids'     => array(),
					'error'        => "Post #{$post_id} not found.",
				);
			}

			if ( ! $force && ! self::isExcerptMissing( $post ) ) {
				return array(
					'success'      => true,
					'queued_count' => 0,
					'post_ids'     => array(),
					'message'      => "Post #{$post_id} already has an excerpt. Use --force to regenerate.",
				);
			}

			$eligible = array( $post_id );
		} else {
			// Batch mode — find posts missing excerpts. When no explicit
			// post_type is provided, discover across every eligible post
			// type registered via datamachine_post_types_for_meta_description.
			$post_types = '' !== $post_type
				? array( $post_type )
				: self::getEligiblePostTypes();
			$eligible   = self::findPostsMissingExcerpt( $post_types, $limit, $force );
		}

		if ( empty( $eligible ) ) {
			return array(
				'success'      => true,
				'queued_count' => 0,
				'post_ids'     => array(),
				'message'      => 'No posts found missing excerpts (meta descriptions).',
			);
		}

		// Build per-item params for batch scheduling.
		$item_params = array();
		foreach ( $eligible as $id ) {
			$item_params[] = array(
				'post_id' => $id,
				'force'   => $force,
				'source'  => 'ability',
			);
		}

		$batch = TaskScheduler::scheduleBatch(
			'meta_description_generation',
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
				'error'        => 'Task batch scheduling failed.',
			);
		}

		return array(
			'success'      => true,
			'queued_count' => count( $eligible ),
			'post_ids'     => $eligible,
			'batch_id'     => $batch['batch_id'] ?? null,
			'message'      => sprintf(
				'Meta description generation scheduled for %d post(s).',
				count( $eligible )
			),
		);
	}

	/**
	 * Diagnose meta description coverage by checking post_excerpt.
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function diagnoseMetaDescriptions( array $input = array() ): array {
		global $wpdb;

		$post_type = sanitize_key( $input['post_type'] ?? 'post' );

		$total_posts = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_type = %s AND post_status = 'publish'",
				$post_type
			)
		);

		$missing_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_type = %s
				 AND post_status = 'publish'
				 AND ( post_excerpt IS NULL OR post_excerpt = '' )",
				$post_type
			)
		);

		$has_count = $total_posts - $missing_count;
		$coverage  = $total_posts > 0
			? round( ( $has_count / $total_posts ) * 100, 1 ) . '%'
			: '0%';

		return array(
			'success'       => true,
			'total_posts'   => $total_posts,
			'missing_count' => $missing_count,
			'has_count'     => $has_count,
			'coverage'      => $coverage,
			'post_type'     => $post_type,
		);
	}

	/**
	 * Get post types eligible for meta description generation.
	 *
	 * Plugins register custom post types via the
	 * 'datamachine_post_types_for_meta_description' filter so they
	 * appear in batch discovery alongside the defaults.
	 *
	 * @return string[] Array of post type slugs.
	 */
	public static function getEligiblePostTypes(): array {
		/**
		 * Filter the post types eligible for meta description generation.
		 *
		 * @param string[] $post_types Default: ['post', 'page'].
		 */
		$post_types = apply_filters(
			'datamachine_post_types_for_meta_description',
			array( 'post', 'page' )
		);

		if ( ! is_array( $post_types ) ) {
			return array( 'post', 'page' );
		}

		$post_types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $post_types ) ) ) );

		return ! empty( $post_types ) ? $post_types : array( 'post', 'page' );
	}

	/**
	 * Find published posts missing a post_excerpt.
	 *
	 * Accepts either a single post type slug or an array of slugs so that
	 * batch discovery can span every post type registered through
	 * {@see self::getEligiblePostTypes()}.
	 *
	 * @param string|string[] $post_types Post type slug(s) to query.
	 * @param int             $limit      Maximum results.
	 * @param bool            $force      If true, return all posts regardless of excerpt.
	 * @return int[] Post IDs.
	 */
	private static function findPostsMissingExcerpt( $post_types, int $limit, bool $force ): array {
		global $wpdb;

		$post_types = (array) $post_types;
		$post_types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $post_types ) ) ) );

		if ( empty( $post_types ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

		if ( $force ) {
			$sql  = "SELECT ID FROM {$wpdb->posts}
				 WHERE post_type IN ({$placeholders}) AND post_status = 'publish'
				 ORDER BY ID DESC LIMIT %d";
			$args = array_merge( $post_types, array( $limit ) );
		} else {
			$sql  = "SELECT ID FROM {$wpdb->posts}
				 WHERE post_type IN ({$placeholders})
				 AND post_status = 'publish'
				 AND ( post_excerpt IS NULL OR post_excerpt = '' )
				 ORDER BY ID DESC
				 LIMIT %d";
			$args = array_merge( $post_types, array( $limit ) );
		}

		$results = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );

		return array_map( 'absint', $results ? $results : array() );
	}

	/**
	 * Check if a post's excerpt is missing or empty.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool True if excerpt is missing/empty.
	 */
	private static function isExcerptMissing( \WP_Post $post ): bool {
		return '' === trim( $post->post_excerpt );
	}
}
