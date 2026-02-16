<?php
/**
 * Alt Text Abilities
 *
 * Ability endpoints for AI-powered alt text generation and diagnostics.
 * Delegates async execution to the System Agent infrastructure.
 *
 * @package DataMachine\Abilities\Media
 * @since 0.13.8
 */

namespace DataMachine\Abilities\Media;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\System\SystemAgent;

defined( 'ABSPATH' ) || exit;

class AltTextAbilities {

	private static bool $registered = false;
	private static bool $hooks_registered = false;

	public function __construct() {
		$this->registerHooks();

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
				'datamachine/generate-alt-text',
				array(
					'label'               => 'Generate Alt Text',
					'description'         => 'Queue system agent generation of alt text for images',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'attachment_id' => array(
								'type'        => 'integer',
								'description' => 'Attachment ID to generate alt text for',
							),
							'post_id'       => array(
								'type'        => 'integer',
								'description' => 'Post ID to queue attached images missing alt text',
							),
							'force'         => array(
								'type'        => 'boolean',
								'description' => 'Force regeneration even if alt text exists',
								'default'     => false,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'         => array( 'type' => 'boolean' ),
							'queued_count'    => array( 'type' => 'integer' ),
							'attachment_ids'  => array(
								'type'  => 'array',
								'items' => array( 'type' => 'integer' ),
							),
							'message'         => array( 'type' => 'string' ),
							'error'           => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'generateAltText' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/diagnose-alt-text',
				array(
					'label'               => 'Diagnose Alt Text',
					'description'         => 'Report alt text coverage for image attachments',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'           => array( 'type' => 'boolean' ),
							'total_images'      => array( 'type' => 'integer' ),
							'missing_alt_count' => array( 'type' => 'integer' ),
							'by_mime_type'      => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
						),
					),
					'execute_callback'    => array( self::class, 'diagnoseAltText' ),
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

	/**
	 * Register hooks for auto-queue on attachment upload.
	 */
	private function registerHooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		add_action( 'add_attachment', array( $this, 'queueAttachmentAltText' ), 10, 1 );

		self::$hooks_registered = true;
	}

	/**
	 * Generate alt text for a specific attachment or post.
	 *
	 * Resolves eligible attachment IDs and delegates each to the System Agent.
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function generateAltText( array $input ): array {
		$attachment_id = absint( $input['attachment_id'] ?? 0 );
		$post_id       = absint( $input['post_id'] ?? 0 );
		$force         = ! empty( $input['force'] );

		$provider = PluginSettings::get( 'default_provider', '' );
		$model    = PluginSettings::get( 'default_model', '' );

		if ( empty( $provider ) || empty( $model ) ) {
			return array(
				'success'        => false,
				'queued_count'   => 0,
				'attachment_ids' => array(),
				'message'        => 'No default AI provider/model configured.',
				'error'          => 'Configure default_provider and default_model in Data Machine settings before generating alt text.',
			);
		}

		if ( 0 === $attachment_id && 0 === $post_id ) {
			return array(
				'success'        => false,
				'queued_count'   => 0,
				'attachment_ids' => array(),
				'message'        => 'No attachment_id or post_id provided.',
				'error'          => 'Missing required parameter: attachment_id or post_id',
			);
		}

		$attachment_ids = array();

		if ( $attachment_id > 0 ) {
			$attachment_ids[] = $attachment_id;
		}

		if ( $post_id > 0 ) {
			$attached_ids = get_posts(
				array(
					'post_parent'    => $post_id,
					'post_type'      => 'attachment',
					'post_mime_type' => 'image',
					'fields'         => 'ids',
					'numberposts'    => -1,
					'post_status'    => 'inherit',
				)
			);

			if ( ! empty( $attached_ids ) ) {
				$attachment_ids = array_merge( $attachment_ids, $attached_ids );
			}

			$featured_id = get_post_thumbnail_id( $post_id );
			if ( $featured_id ) {
				$attachment_ids[] = (int) $featured_id;
			}
		}

		$attachment_ids = array_values( array_unique( array_filter( $attachment_ids ) ) );

		if ( empty( $attachment_ids ) ) {
			return array(
				'success'        => false,
				'queued_count'   => 0,
				'attachment_ids' => array(),
				'message'        => 'No attachments found to process.',
				'error'          => 'No eligible attachments found',
			);
		}

		$systemAgent = SystemAgent::getInstance();
		$queued      = array();

		foreach ( $attachment_ids as $id ) {
			if ( ! wp_attachment_is_image( $id ) ) {
				continue;
			}

			if ( ! $force && self::isAltTextMissing( $id ) === false ) {
				continue;
			}

			$jobId = $systemAgent->scheduleTask(
				'alt_text_generation',
				array(
					'attachment_id' => $id,
					'force'         => $force,
					'source'        => 'ability',
				)
			);

			if ( $jobId ) {
				$queued[] = $id;
			}
		}

		return array(
			'success'        => true,
			'queued_count'   => count( $queued ),
			'attachment_ids' => $queued,
			'message'        => ! empty( $queued )
				? sprintf( 'Alt text generation queued for %d attachment(s) via System Agent.', count( $queued ) )
				: 'No attachments queued (alt text already present or no eligible images).',
		);
	}

	/**
	 * Diagnose alt text coverage across image attachments.
	 *
	 * @param array $input Ability input (unused).
	 * @return array Ability response.
	 */
	public static function diagnoseAltText( array $input = array() ): array {
		global $wpdb;

		$image_like = 'image/%';

		$total_images = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_mime_type LIKE %s",
				'attachment',
				$image_like
			)
		);

		$missing_alt_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m
					ON p.ID = m.post_id AND m.meta_key = %s
				 WHERE p.post_type = %s
				 AND p.post_mime_type LIKE %s
				 AND ( m.meta_id IS NULL OR m.meta_value = '' OR m.meta_value IS NULL )",
				'_wp_attachment_image_alt',
				'attachment',
				$image_like
			)
		);

		$by_mime_type = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.post_mime_type AS mime_type,
					COUNT(*) AS total,
					SUM( CASE WHEN m.meta_id IS NULL OR m.meta_value = '' OR m.meta_value IS NULL THEN 1 ELSE 0 END ) AS missing
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m
					ON p.ID = m.post_id AND m.meta_key = %s
				 WHERE p.post_type = %s
				 AND p.post_mime_type LIKE %s
				 GROUP BY p.post_mime_type",
				'_wp_attachment_image_alt',
				'attachment',
				$image_like
			),
			ARRAY_A
		);

		$by_mime_type = is_array( $by_mime_type ) ? $by_mime_type : array();
		foreach ( $by_mime_type as $index => $row ) {
			$by_mime_type[ $index ]['total']   = (int) ( $row['total'] ?? 0 );
			$by_mime_type[ $index ]['missing'] = (int) ( $row['missing'] ?? 0 );
		}

		return array(
			'success'           => true,
			'total_images'      => $total_images,
			'missing_alt_count' => $missing_alt_count,
			'by_mime_type'      => $by_mime_type,
		);
	}

	/**
	 * Auto-queue alt text generation when attachments are added.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function queueAttachmentAltText( int $attachment_id ): void {
		$attachment_id = absint( $attachment_id );

		if ( $attachment_id <= 0 ) {
			return;
		}

		$auto_generate_enabled = PluginSettings::get( 'alt_text_auto_generate_enabled', true );

		if ( ! $auto_generate_enabled ) {
			return;
		}

		$provider = PluginSettings::get( 'default_provider', '' );
		$model    = PluginSettings::get( 'default_model', '' );

		if ( empty( $provider ) || empty( $model ) ) {
			return;
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		if ( ! self::isAltTextMissing( $attachment_id ) ) {
			return;
		}

		$systemAgent = SystemAgent::getInstance();
		$systemAgent->scheduleTask(
			'alt_text_generation',
			array(
				'attachment_id' => $attachment_id,
				'force'         => false,
				'source'        => 'add_attachment',
			)
		);
	}

	/**
	 * Check if attachment alt text is missing.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private static function isAltTextMissing( int $attachment_id ): bool {
		$alt_text = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$alt_text = is_string( $alt_text ) ? trim( $alt_text ) : '';

		return '' === $alt_text;
	}
}
