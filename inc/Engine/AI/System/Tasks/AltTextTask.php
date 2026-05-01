<?php
/**
 * Alt Text Generation Task for System Agent.
 *
 * Generates AI-powered alt text for image attachments.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 * @since 0.23.0
 * @since 0.72.0 Migrated to getWorkflow() + executeTask() contract.
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\ConversationManager;
use DataMachine\Engine\AI\RequestBuilder;

class AltTextTask extends SystemTask {

	/**
	 * Execute alt text generation for a specific attachment.
	 *
	 * @param int   $jobId  Job ID from DM Jobs table.
	 * @param array $params Task parameters from engine_data.
	 */
	public function executeTask( int $jobId, array $params ): void {
		$attachment_id = absint( $params['attachment_id'] ?? 0 );
		$force         = ! empty( $params['force'] );

		if ( $attachment_id <= 0 ) {
			$this->failJob( $jobId, 'Missing or invalid attachment_id' );
			return;
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			$this->failJob( $jobId, "Attachment #{$attachment_id} is not an image" );
			return;
		}

		if ( ! $force && ! $this->isAltTextMissing( $attachment_id ) ) {
			$this->completeJob( $jobId, array(
				'skipped'       => true,
				'attachment_id' => $attachment_id,
				'reason'        => 'Alt text already exists',
			) );
			return;
		}

		$file_path = get_attached_file( $attachment_id );

		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			$this->failJob( $jobId, "Image file missing for attachment #{$attachment_id}" );
			return;
		}

		$system_defaults = $this->resolveSystemModel( $params );
		$provider        = $system_defaults['provider'];
		$model           = $system_defaults['model'];

		if ( empty( $provider ) || empty( $model ) ) {
			$this->failJob( $jobId, 'No default AI provider/model configured' );
			return;
		}

		$file_info = wp_check_filetype( $file_path );
		$mime_type = is_string( $file_info['type'] ) ? $file_info['type'] : '';

		$prompt   = $this->buildPrompt( $attachment_id );
		$messages = array(
			ConversationManager::buildConversationMessage(
				'user',
				array(
					array(
						'type'      => 'file',
						'file_path' => $file_path,
						'mime_type' => $mime_type,
					),
				)
			),
			ConversationManager::buildConversationMessage( 'user', $prompt ),
		);

		$ai_payload = array( 'attachment_id' => $attachment_id );
		if ( ! empty( $params['agent_id'] ) ) {
			$ai_payload['agent_id'] = (int) $params['agent_id'];
		}
		if ( ! empty( $params['user_id'] ) ) {
			$ai_payload['user_id'] = (int) $params['user_id'];
		}

		$response = RequestBuilder::build(
			$messages,
			$provider,
			$model,
			array(),
			'system',
			$ai_payload
		);

		if ( $response instanceof \WP_Error ) {
			$this->failJob( $jobId, 'AI request failed: ' . $response->get_error_message() );
			return;
		}

		$content  = RequestBuilder::resultText( $response );
		$alt_text = $this->normalizeAltText( $content );

		if ( empty( $alt_text ) ) {
			$this->failJob( $jobId, 'AI returned empty alt text' );
			return;
		}

		$current_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

		$effects = array(
			array(
				'type'           => 'post_meta_set',
				'target'         => array(
					'post_id'  => $attachment_id,
					'meta_key' => '_wp_attachment_image_alt',
				),
				'previous_value' => ! empty( $current_alt ) ? $current_alt : null,
			),
		);

		$this->completeJob( $jobId, array(
			'alt_text'      => $alt_text,
			'attachment_id' => $attachment_id,
			'effects'       => $effects,
			'completed_at'  => current_time( 'mysql' ),
		) );
	}

	/**
	 * @return string
	 */
	public function getTaskType(): string {
		return 'alt_text_generation';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Alt Text Generation',
			'description'     => 'Automatically generate alt text for uploaded images using AI vision.',
			'setting_key'     => 'alt_text_auto_generate_enabled',
			'default_enabled' => true,
			'trigger'         => 'Auto on image upload',
			'trigger_type'    => 'event',
			'supports_run'    => true,
		);
	}

	/**
	 * @return bool
	 */
	public function supportsUndo(): bool {
		return true;
	}

	/**
	 * @return array
	 */
	public function getPromptDefinitions(): array {
		return array(
			'generate' => array(
				'label'       => __( 'Alt Text Prompt', 'data-machine' ),
				'description' => __( 'Prompt used to generate alt text for images.', 'data-machine' ),
				'default'     => "Write alt text for this image.\n\n"
					. "Guidelines:\n"
					. "- 1-2 sentences describing the image\n"
					. "- Don't start with 'Image of' or 'Photo of'\n"
					. "- Capitalize first word, end with period\n"
					. "- Describe what's visually present, focus on its purpose in context\n"
					. "- For charts/diagrams, provide a brief summary only\n"
					. "- Match the voice and tone of this site\n\n"
					. "Return ONLY the alt text, nothing else.\n\n"
					. "Context:\n{{context}}",
				'variables'   => array(
					'context' => 'Attachment title, caption, description, and parent post title',
				),
			),
		);
	}

	/**
	 * @param int $attachment_id Attachment ID.
	 * @return string Prompt text.
	 */
	private function buildPrompt( int $attachment_id ): string {
		$context_lines = array();

		$title       = get_the_title( $attachment_id );
		$caption     = wp_get_attachment_caption( $attachment_id );
		$description = get_post_field( 'post_content', $attachment_id );
		$parent_id   = (int) get_post_field( 'post_parent', $attachment_id );

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

		$context = ! empty( $context_lines ) ? implode( "\n", $context_lines ) : '';

		return $this->buildPromptFromTemplate( 'generate', array( 'context' => $context ) );
	}

	/**
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function isAltTextMissing( int $attachment_id ): bool {
		$alt_text = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$alt_text = is_string( $alt_text ) ? trim( $alt_text ) : '';
		return '' === $alt_text;
	}

	/**
	 * @param string $raw Raw AI response.
	 * @return string Normalized alt text.
	 */
	private function normalizeAltText( string $raw ): string {
		$alt_text = trim( $raw );
		$alt_text = trim( $alt_text, " \t\n\r\0\x0B\"'" );
		$alt_text = sanitize_text_field( $alt_text );

		if ( '' === $alt_text ) {
			return '';
		}

		$first_char = mb_substr( $alt_text, 0, 1 );
		$rest       = mb_substr( $alt_text, 1 );
		if ( preg_match( '/[a-z]/', $first_char ) ) {
			$first_char = strtoupper( $first_char );
		}
		$alt_text = $first_char . $rest;

		if ( ! preg_match( '/\.$/', $alt_text ) ) {
			$alt_text .= '.';
		}

		return $alt_text;
	}
}
