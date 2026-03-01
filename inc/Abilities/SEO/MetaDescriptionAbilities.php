<?php
/**
 * Meta Description Abilities
 *
 * Ability endpoints for AI-powered meta description generation and diagnostics.
 * Delegates async execution to the System Agent infrastructure.
 *
 * @package DataMachine\Abilities\SEO
 * @since 0.31.0
 */

namespace DataMachine\Abilities\SEO;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\System\SystemAgent;
use DataMachine\Engine\AI\System\Tasks\MetaDescriptionTask;

defined( 'ABSPATH' ) || exit;

class MetaDescriptionAbilities {

	private static bool $registered = false;

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
				'datamachine/generate-meta-description',
				array(
					'label'               => 'Generate Meta Description',
					'description'         => 'Queue system agent generation of meta descriptions for posts',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'post_id'   => array(
								'type'        => 'integer',
								'description' => 'Post ID to generate meta description for',
							),
							'post_type' => array(
								'type'        => 'string',
								'description' => 'Post type to batch process (e.g. "post", "page")',
								'default'     => 'post',
							),
							'limit'     => array(
								'type'        => 'integer',
								'description' => 'Maximum posts to queue (for batch mode)',
								'default'     => 50,
							),
							'force'     => array(
								'type'        => 'boolean',
								'description' => 'Force regeneration even if meta description exists',
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
					'description'         => 'Report meta description coverage for posts',
					'category'            => 'datamachine',
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
		$post_type = sanitize_key( $input['post_type'] ?? 'post' );
		$limit     = absint( $input['limit'] ?? 50 );
		$force     = ! empty( $input['force'] );

		$system_defaults = PluginSettings::getAgentModel( 'system' );
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

		$meta_key = apply_filters( 'datamachine_meta_description_meta_key', MetaDescriptionTask::DEFAULT_META_KEY );

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

			if ( ! $force && ! self::isDescriptionMissing( $post_id, $meta_key ) ) {
				return array(
					'success'      => true,
					'queued_count' => 0,
					'post_ids'     => array(),
					'message'      => "Post #{$post_id} already has a meta description. Use --force to regenerate.",
				);
			}

			$eligible = array( $post_id );
		} else {
			// Batch mode — find posts missing meta descriptions.
			$eligible = self::findPostsMissingDescription( $post_type, $meta_key, $limit, $force );
		}

		if ( empty( $eligible ) ) {
			return array(
				'success'      => true,
				'queued_count' => 0,
				'post_ids'     => array(),
				'message'      => 'No posts found missing meta descriptions.',
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

		$systemAgent = SystemAgent::getInstance();
		$batch       = $systemAgent->scheduleBatch( 'meta_description_generation', $item_params );

		if ( false === $batch ) {
			return array(
				'success'      => false,
				'queued_count' => 0,
				'post_ids'     => array(),
				'error'        => 'System Agent batch scheduling failed.',
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
	 * Diagnose meta description coverage.
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function diagnoseMetaDescriptions( array $input = array() ): array {
		global $wpdb;

		$post_type = sanitize_key( $input['post_type'] ?? 'post' );
		$meta_key  = apply_filters( 'datamachine_meta_description_meta_key', MetaDescriptionTask::DEFAULT_META_KEY );

		$total_posts = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
				$post_type
			)
		);

		$missing_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m
					ON p.ID = m.post_id AND m.meta_key = %s
				 WHERE p.post_type = %s
				 AND p.post_status = 'publish'
				 AND ( m.meta_id IS NULL OR m.meta_value = '' OR m.meta_value IS NULL )",
				$meta_key,
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
			'meta_key'      => $meta_key,
		);
	}

	/**
	 * Find published posts missing a meta description.
	 *
	 * @param string $post_type Post type to query.
	 * @param string $meta_key  Meta key to check.
	 * @param int    $limit     Maximum results.
	 * @param bool   $force     If true, return all posts regardless of meta.
	 * @return int[] Post IDs.
	 */
	private static function findPostsMissingDescription( string $post_type, string $meta_key, int $limit, bool $force ): array {
		global $wpdb;

		if ( $force ) {
			$results = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					 WHERE post_type = %s AND post_status = 'publish'
					 ORDER BY ID DESC LIMIT %d",
					$post_type,
					$limit
				)
			);
		} else {
			$results = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID
					 FROM {$wpdb->posts} p
					 LEFT JOIN {$wpdb->postmeta} m
						ON p.ID = m.post_id AND m.meta_key = %s
					 WHERE p.post_type = %s
					 AND p.post_status = 'publish'
					 AND ( m.meta_id IS NULL OR m.meta_value = '' OR m.meta_value IS NULL )
					 ORDER BY p.ID DESC
					 LIMIT %d",
					$meta_key,
					$post_type,
					$limit
				)
			);
		}

		return array_map( 'absint', $results ?: array() );
	}

	/**
	 * Check if a post's meta description is missing or empty.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key to check.
	 * @return bool True if description is missing/empty.
	 */
	private static function isDescriptionMissing( int $post_id, string $meta_key ): bool {
		$description = get_post_meta( $post_id, $meta_key, true );
		$description = is_string( $description ) ? trim( $description ) : '';

		return '' === $description;
	}
}
