<?php
/**
 * Alt Text Generation Task for System Agent.
 *
 * Generates AI-powered alt text for image attachments. Loads the image file,
 * builds a contextual prompt, sends to the configured AI provider, normalizes
 * the response, and saves it as the attachment's alt text meta.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 * @since 0.23.0
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\RequestBuilder;

class AltTextTask extends SystemTask {

	/**
	 * Execute alt text generation for a specific attachment.
	 *
	 * @param int   $jobId  Job ID from DM Jobs table.
	 * @param array $params Task parameters from engine_data.
	 */
	public function execute( int $jobId, array $params ): void {
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
			$this->completeJob( $jobId, [
				'skipped'       => true,
				'attachment_id' => $attachment_id,
				'reason'        => 'Alt text already exists',
			] );
			return;
		}

		$file_path = get_attached_file( $attachment_id );

		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			$this->failJob( $jobId, "Image file missing for attachment #{$attachment_id}" );
			return;
		}

		$provider = PluginSettings::get( 'default_provider', '' );
		$model    = PluginSettings::get( 'default_model', '' );

		if ( empty( $provider ) || empty( $model ) ) {
			$this->failJob( $jobId, 'No default AI provider/model configured' );
			return;
		}

		$file_info = wp_check_filetype( $file_path );
		$mime_type = $file_info['type'] ?? '';

		$prompt   = $this->buildPrompt( $attachment_id );
		$messages = [
			[
				'role'    => 'user',
				'content' => [
					[
						'type'      => 'file',
						'file_path' => $file_path,
						'mime_type' => $mime_type,
					],
				],
			],
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		];

		$response = RequestBuilder::build(
			$messages,
			$provider,
			$model,
			[],
			'system',
			[ 'attachment_id' => $attachment_id ]
		);

		if ( empty( $response['success'] ) ) {
			$this->failJob( $jobId, 'AI request failed: ' . ( $response['error'] ?? 'Unknown error' ) );
			return;
		}

		$content  = $response['data']['content'] ?? '';
		$alt_text = $this->normalizeAltText( $content );

		if ( empty( $alt_text ) ) {
			$this->failJob( $jobId, 'AI returned empty alt text' );
			return;
		}

		// Save the alt text.
		$current_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$updated     = update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

		if ( ! $updated && $current_alt !== $alt_text ) {
			$this->failJob( $jobId, 'Failed to save alt text to post meta' );
			return;
		}

		$this->completeJob( $jobId, [
			'alt_text'      => $alt_text,
			'attachment_id' => $attachment_id,
			'completed_at'  => current_time( 'mysql' ),
		] );
	}

	/**
	 * Get the task type identifier.
	 *
	 * @return string
	 */
	public function getTaskType(): string {
		return 'alt_text_generation';
	}

	/**
	 * Build the AI prompt with contextual information.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Prompt text.
	 */
	private function buildPrompt( int $attachment_id ): string {
		$context_lines = [];

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

		return $prompt;
	}

	/**
	 * Check if attachment alt text is missing.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function isAltTextMissing( int $attachment_id ): bool {
		$alt_text = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$alt_text = is_string( $alt_text ) ? trim( $alt_text ) : '';

		return '' === $alt_text;
	}

	/**
	 * Normalize AI response to a clean alt text string.
	 *
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

		// Capitalize first character.
		$first_char = mb_substr( $alt_text, 0, 1 );
		$rest       = mb_substr( $alt_text, 1 );
		if ( preg_match( '/[a-z]/', $first_char ) ) {
			$first_char = strtoupper( $first_char );
		}
		$alt_text = $first_char . $rest;

		// Ensure trailing period.
		if ( ! preg_match( '/\.$/', $alt_text ) ) {
			$alt_text .= '.';
		}

		return $alt_text;
	}
}
