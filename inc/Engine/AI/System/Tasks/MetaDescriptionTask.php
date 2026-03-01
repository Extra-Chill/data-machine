<?php
/**
 * Meta Description Generation Task for System Agent.
 *
 * Generates AI-powered meta descriptions for posts. Gathers post title,
 * excerpt, content, and taxonomy context, sends to the configured AI
 * provider, normalizes the response, and saves to the configured meta key.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 * @since 0.31.0
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\RequestBuilder;

class MetaDescriptionTask extends SystemTask {

	/**
	 * Meta key for storing the generated description.
	 *
	 * Defaults to _lean_seo_description (Lean SEO). Configurable via the
	 * datamachine_meta_description_meta_key filter for other SEO plugins.
	 */
	const DEFAULT_META_KEY = '_lean_seo_description';

	/**
	 * Maximum character length for meta descriptions.
	 *
	 * Google truncates at ~155-160 characters. Targeting 155 to stay safe.
	 */
	const MAX_LENGTH = 155;

	/**
	 * Maximum content characters to include in the prompt.
	 */
	const CONTENT_EXCERPT_LENGTH = 1500;

	/**
	 * Execute meta description generation for a specific post.
	 *
	 * @param int   $jobId  Job ID from DM Jobs table.
	 * @param array $params Task parameters from engine_data.
	 */
	public function execute( int $jobId, array $params ): void {
		$post_id = absint( $params['post_id'] ?? 0 );
		$force   = ! empty( $params['force'] );

		if ( $post_id <= 0 ) {
			$this->failJob( $jobId, 'Missing or invalid post_id' );
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			$this->failJob( $jobId, "Post #{$post_id} not found" );
			return;
		}

		if ( 'publish' !== $post->post_status && 'future' !== $post->post_status ) {
			$this->failJob( $jobId, "Post #{$post_id} is not published (status: {$post->post_status})" );
			return;
		}

		$meta_key = $this->getMetaKey();

		if ( ! $force && ! $this->isDescriptionMissing( $post_id, $meta_key ) ) {
			$this->completeJob( $jobId, array(
				'skipped' => true,
				'post_id' => $post_id,
				'reason'  => 'Meta description already exists',
			) );
			return;
		}

		$system_defaults = PluginSettings::getAgentModel( 'system' );
		$provider        = $system_defaults['provider'];
		$model           = $system_defaults['model'];

		if ( empty( $provider ) || empty( $model ) ) {
			$this->failJob( $jobId, 'No default AI provider/model configured' );
			return;
		}

		$prompt   = $this->buildPrompt( $post );
		$messages = array(
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
			array( 'post_id' => $post_id )
		);

		if ( empty( $response['success'] ) ) {
			$this->failJob( $jobId, 'AI request failed: ' . ( $response['error'] ?? 'Unknown error' ) );
			return;
		}

		$content     = $response['data']['content'] ?? '';
		$description = $this->normalizeDescription( $content );

		if ( empty( $description ) ) {
			$this->failJob( $jobId, 'AI returned empty meta description' );
			return;
		}

		// Save the meta description.
		$current_value = get_post_meta( $post_id, $meta_key, true );
		$updated       = update_post_meta( $post_id, $meta_key, $description );

		if ( ! $updated && $current_value !== $description ) {
			$this->failJob( $jobId, 'Failed to save meta description to post meta' );
			return;
		}

		// Build standardized effects array for undo.
		$effects = array(
			array(
				'type'           => 'post_meta_set',
				'target'         => array(
					'post_id'  => $post_id,
					'meta_key' => $meta_key,
				),
				'previous_value' => ! empty( $current_value ) ? $current_value : null,
			),
		);

		$this->completeJob( $jobId, array(
			'meta_description' => $description,
			'post_id'          => $post_id,
			'meta_key'         => $meta_key,
			'char_count'       => mb_strlen( $description ),
			'effects'          => $effects,
			'completed_at'     => current_time( 'mysql' ),
		) );
	}

	/**
	 * Get the task type identifier.
	 *
	 * @return string
	 */
	public function getTaskType(): string {
		return 'meta_description_generation';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Meta Description Generation',
			'description'     => 'Generate SEO meta descriptions for posts using AI.',
			'setting_key'     => 'meta_description_auto_generate_enabled',
			'default_enabled' => true,
		);
	}

	/**
	 * Meta description generation supports undo — restores previous value.
	 *
	 * @return bool
	 */
	public function supportsUndo(): bool {
		return true;
	}

	/**
	 * Get the meta key to write descriptions to.
	 *
	 * Filterable so sites using Yoast, Rank Math, etc. can override.
	 *
	 * @return string
	 */
	private function getMetaKey(): string {
		return apply_filters( 'datamachine_meta_description_meta_key', self::DEFAULT_META_KEY );
	}

	/**
	 * Build the AI prompt with post context.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string Prompt text.
	 */
	private function buildPrompt( \WP_Post $post ): string {
		$context_lines = array();

		$title = wp_strip_all_tags( $post->post_title );
		if ( ! empty( $title ) ) {
			$context_lines[] = 'Title: ' . $title;
		}

		$excerpt = wp_strip_all_tags( $post->post_excerpt );
		if ( ! empty( $excerpt ) ) {
			$context_lines[] = 'Excerpt: ' . $excerpt;
		}

		// Get a clean text snippet of the post content.
		$content = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		$content = preg_replace( '/\s+/', ' ', trim( $content ) );
		if ( ! empty( $content ) ) {
			$snippet = mb_substr( $content, 0, self::CONTENT_EXCERPT_LENGTH );
			if ( mb_strlen( $content ) > self::CONTENT_EXCERPT_LENGTH ) {
				$snippet .= '…';
			}
			$context_lines[] = 'Content: ' . $snippet;
		}

		// Gather taxonomy context.
		$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
		if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
			$context_lines[] = 'Categories: ' . implode( ', ', $categories );
		}

		$tags = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );
		if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
			$context_lines[] = 'Tags: ' . implode( ', ', $tags );
		}

		$prompt = "Write a meta description for the following web page.\n\n"
			. "Guidelines:\n"
			. "- Maximum " . self::MAX_LENGTH . " characters (this is strict — do not exceed)\n"
			. "- Lead with the direct answer or hook — what will the reader learn or get?\n"
			. "- Include the primary topic/keyword naturally\n"
			. "- Create curiosity or value to encourage clicks\n"
			. "- Do NOT duplicate the title — expand on it\n"
			. "- Write in a warm, conversational tone\n"
			. "- No quotes around the description\n"
			. "- One or two sentences\n\n"
			. "Return ONLY the meta description text, nothing else.";

		if ( ! empty( $context_lines ) ) {
			$prompt .= "\n\nPage context:\n" . implode( "\n", $context_lines );
		}

		return $prompt;
	}

	/**
	 * Check if a post's meta description is missing or empty.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key to check.
	 * @return bool True if description is missing/empty.
	 */
	private function isDescriptionMissing( int $post_id, string $meta_key ): bool {
		$description = get_post_meta( $post_id, $meta_key, true );
		$description = is_string( $description ) ? trim( $description ) : '';

		return '' === $description;
	}

	/**
	 * Normalize AI response to a clean meta description string.
	 *
	 * @param string $raw Raw AI response.
	 * @return string Normalized meta description.
	 */
	private function normalizeDescription( string $raw ): string {
		$description = trim( $raw );

		// Strip wrapping quotes (AI sometimes wraps in quotes).
		$description = trim( $description, " \t\n\r\0\x0B\"'" );

		// Strip any markdown formatting.
		$description = preg_replace( '/^#+\s*/', '', $description );
		$description = preg_replace( '/\*\*(.*?)\*\*/', '$1', $description );

		$description = sanitize_text_field( $description );

		if ( '' === $description ) {
			return '';
		}

		// Truncate to max length, breaking at word boundary.
		if ( mb_strlen( $description ) > self::MAX_LENGTH ) {
			$description = mb_substr( $description, 0, self::MAX_LENGTH );
			$last_space  = mb_strrpos( $description, ' ' );
			if ( false !== $last_space && $last_space > self::MAX_LENGTH - 30 ) {
				$description = mb_substr( $description, 0, $last_space );
			}
			// Ensure it ends cleanly.
			$description = rtrim( $description, ' ,;:-' );
			if ( ! preg_match( '/[.!?]$/', $description ) ) {
				$description .= '.';
			}
		}

		return $description;
	}
}
