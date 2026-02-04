<?php
/**
 * Alt Text Abilities
 *
 * System agent abilities for generating image alt text and diagnostics.
 * Includes Action Scheduler handling and auto-queue on attachment upload.
 *
 * @package DataMachine\Abilities\Media
 * @since 0.13.8
 */

namespace DataMachine\Abilities\Media;
use DataMachine\Abilities\PermissionHelper;

use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\RequestBuilder;

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

	private function registerHooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		add_action( 'datamachine_generate_image_alt_text', array( $this, 'handleGenerateImageAltText' ), 10, 2 );
		add_action( 'add_attachment', array( $this, 'queueAttachmentAltText' ), 10, 1 );

		self::$hooks_registered = true;
	}

	/**
	 * Generate alt text for a specific attachment or post.
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function generateAltText( array $input ): array {
		$attachment_id = absint( $input['attachment_id'] ?? 0 );
		$post_id       = absint( $input['post_id'] ?? 0 );
		$force         = ! empty( $input['force'] );

		// Gate on provider/model being configured.
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

		$queued = array();
		foreach ( $attachment_ids as $id ) {
			if ( ! self::isImageAttachment( $id ) ) {
				continue;
			}

			if ( ! $force && ! self::isAltTextMissing( $id ) ) {
				continue;
			}

			$scheduled = self::scheduleAltTextGeneration( $id, $force, 'ability' );
			if ( $scheduled ) {
				$queued[] = $id;
			}
		}

		return array(
			'success'        => true,
			'queued_count'   => count( $queued ),
			'attachment_ids' => $queued,
			'message'        => ! empty( $queued )
				? sprintf( 'Alt text generation queued for %d attachment(s).', count( $queued ) )
				: 'No attachments queued (alt text already present or no eligible images).',
		);
	}

	/**
	 * Diagnose alt text coverage across image attachments.
	 *
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
	 * Action Scheduler handler for alt text generation.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $force Force generation even if alt text exists.
	 * @return void
	 */
	public function handleGenerateImageAltText( int $attachment_id, bool $force = false ): void {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return;
		}

		if ( ! self::isImageAttachment( $attachment_id ) ) {
			return;
		}

		if ( ! $force && ! self::isAltTextMissing( $attachment_id ) ) {
			return;
		}

		$file_path = get_attached_file( $attachment_id );
		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Alt text generation skipped - image file missing',
				array(
					'attachment_id' => $attachment_id,
					'agent_type'    => 'system',
				)
			);
			return;
		}

		$provider = PluginSettings::get( 'default_provider', '' );
		$model    = PluginSettings::get( 'default_model', '' );

		if ( empty( $provider ) || empty( $model ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Alt text AI generation skipped - no default provider/model configured',
				array(
					'attachment_id' => $attachment_id,
					'agent_type'    => 'system',
				)
			);
			return;
		}

		$file_info = wp_check_filetype( $file_path );
		$mime_type = $file_info['type'] ?? '';

		$context_lines = array();
		$title         = get_the_title( $attachment_id );
		$caption       = wp_get_attachment_caption( $attachment_id );
		$description   = get_post_field( 'post_content', $attachment_id );
		$parent_id     = (int) get_post_field( 'post_parent', $attachment_id );

		if ( ! empty( $title ) ) {
			$context_lines[] = 'Attachment title: ' . wp_strip_all_tags( $title );
		}
		if ( ! empty( $caption ) ) {
			$context_lines[] = 'Caption: ' . wp_strip_all_tags( $caption );
		}
		if ( ! empty( $description ) ) {
			$context_lines[] = 'Description: ' . wp_strip_all_tags( $description );
		}
		if ( $parent_id > 0 ) {
			$parent_title = get_the_title( $parent_id );
			if ( ! empty( $parent_title ) ) {
				$context_lines[] = 'Parent post title: ' . wp_strip_all_tags( $parent_title );
			}
		}

		$prompt = "Write alt text for the provided image using these guidelines:\n"
			. "- Write 1-2 sentences describing the image\n"
			. "- Don't start with 'Image of' or 'Photo of'\n"
			. "- Capitalize first word, end with period\n"
			. "- Describe what's visually present, focus on purpose\n"
			. "- For complex images (charts/diagrams), provide brief summary only\n\n"
			. 'Return ONLY the alt text, nothing else.';

		if ( ! empty( $context_lines ) ) {
			$prompt .= "\n\nContext:\n" . implode( "\n", $context_lines );
		}

		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type'      => 'file',
						'file_path' => $file_path,
						'mime_type' => $mime_type,
					),
				),
			),
			array(
				'role'    => 'user',
				'content' => $prompt,
			),
		);

		$response = RequestBuilder::build(
			$messages,
			$provider,
			$model,
			array(),
			'system',
			array(
				'attachment_id' => $attachment_id,
			)
		);

		if ( empty( $response['success'] ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Alt text AI generation failed',
				array(
					'attachment_id' => $attachment_id,
					'error'         => $response['error'] ?? 'Unknown error',
					'agent_type'    => 'system',
				)
			);
			return;
		}

		$content = $response['data']['content'] ?? '';
		$alt_text = self::normalizeAltText( $content );

		if ( empty( $alt_text ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Alt text AI generation returned empty content',
				array(
					'attachment_id' => $attachment_id,
					'agent_type'    => 'system',
				)
			);
			return;
		}

		// update_post_meta returns false when value unchanged (e.g., force regenerated same text).
		// Check if current value matches to distinguish "unchanged" from "failed".
		$current_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$updated     = update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

		if ( $updated ) {
			do_action(
				'datamachine_log',
				'info',
				'Alt text generated and saved',
				array(
					'attachment_id' => $attachment_id,
					'alt_text'      => $alt_text,
					'agent_type'    => 'system',
					'success'       => true,
				)
			);
		} elseif ( $current_alt === $alt_text ) {
			// Value unchanged - not an error, just already correct.
			do_action(
				'datamachine_log',
				'info',
				'Alt text generated (unchanged from existing)',
				array(
					'attachment_id' => $attachment_id,
					'alt_text'      => $alt_text,
					'agent_type'    => 'system',
					'success'       => true,
				)
			);
		} else {
			do_action(
				'datamachine_log',
				'error',
				'Alt text generated but failed to save',
				array(
					'attachment_id' => $attachment_id,
					'alt_text'      => $alt_text,
					'agent_type'    => 'system',
					'success'       => false,
				)
			);
		}
	}

	/**
	 * Auto-queue alt text generation when attachments are added.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function queueAttachmentAltText( int $attachment_id ): void {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return;
		}

		// Check if auto-generation is enabled (defaults to true, like chat_ai_titles_enabled).
		$auto_generate_enabled = PluginSettings::get( 'alt_text_auto_generate_enabled', true );
		if ( ! $auto_generate_enabled ) {
			return;
		}

		// Skip scheduling if no provider/model configured - avoid queuing actions that will no-op.
		$provider = PluginSettings::get( 'default_provider', '' );
		$model    = PluginSettings::get( 'default_model', '' );

		if ( empty( $provider ) || empty( $model ) ) {
			return;
		}

		if ( ! self::isImageAttachment( $attachment_id ) ) {
			return;
		}

		if ( ! self::isAltTextMissing( $attachment_id ) ) {
			return;
		}

		self::scheduleAltTextGeneration( $attachment_id, false, 'add_attachment' );
	}

	/**
	 * Schedule Action Scheduler job for alt text generation.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param bool   $force Force regeneration.
	 * @param string $source Source label for logs.
	 * @return bool True if scheduled.
	 */
	private static function scheduleAltTextGeneration( int $attachment_id, bool $force, string $source ): bool {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}

		$args = array(
			'attachment_id' => $attachment_id,
			'force'         => $force,
		);

		if ( ! $force && function_exists( 'as_has_scheduled_action' ) ) {
			$has_scheduled = as_has_scheduled_action( 'datamachine_generate_image_alt_text', $args, 'data-machine' );
			if ( $has_scheduled ) {
				return false;
			}
		}

		$action_id = as_schedule_single_action(
			time(),
			'datamachine_generate_image_alt_text',
			$args,
			'data-machine'
		);

		do_action(
			'datamachine_log',
			'debug',
			'Alt text generation scheduled',
			array(
				'attachment_id' => $attachment_id,
				'action_id'     => $action_id,
				'source'        => $source,
				'agent_type'    => 'system',
				'success'       => ( false !== $action_id ),
			)
		);

		return false !== $action_id;
	}

	/**
	 * Check if attachment is an image.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private static function isImageAttachment( int $attachment_id ): bool {
		return (bool) wp_attachment_is_image( $attachment_id );
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

	/**
	 * Normalize AI response to a clean alt text string.
	 *
	 * @param string $raw Alt text from AI.
	 * @return string Normalized alt text.
	 */
	private static function normalizeAltText( string $raw ): string {
		$alt_text = trim( $raw );
		$alt_text = trim( $alt_text, " \t\n\r\0\x0B\"'" );
		$alt_text = sanitize_text_field( $alt_text );

		if ( '' === $alt_text ) {
			return '';
		}

		$first_char = mb_substr( $alt_text, 0, 1 );
		$rest       = mb_substr( $alt_text, 1 );
		$first_char = preg_match( '/[a-z]/', $first_char ) ? strtoupper( $first_char ) : $first_char;
		$alt_text   = $first_char . $rest;

		if ( ! preg_match( '/\.$/', $alt_text ) ) {
			$alt_text .= '.';
		}

		return $alt_text;
	}
}
