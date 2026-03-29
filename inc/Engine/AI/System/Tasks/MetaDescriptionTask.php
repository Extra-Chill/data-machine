<?php
/**
 * Meta Description Generation Task for System Agent.
 *
 * Generates AI-powered meta descriptions for posts. Gathers post title,
 * content, and taxonomy context, sends to the configured AI provider,
 * normalizes the response, and saves to the WordPress post_excerpt field.
 *
 * WordPress post_excerpt is the standard field for meta descriptions.
 * SEO plugins (including extrachill-seo) read from post_excerpt as their
 * primary source for meta description output.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 * @since 0.31.0
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\RequestBuilder;
use DataMachine\Engine\AI\System\Tasks\Traits\HasSupportsUndo;

class MetaDescriptionTask extends SystemTask {
	use HasSupportsUndo;


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

		$current_excerpt = trim( $post->post_excerpt );

		if ( ! $force && '' !== $current_excerpt ) {
			$this->completeJob( $jobId, array(
				'skipped' => true,
				'post_id' => $post_id,
				'reason'  => 'Post excerpt already exists',
			) );
			return;
		}

		$system_defaults = $this->resolveSystemModel( $params );
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

		// Save to the WordPress post_excerpt field.
		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_excerpt' => $description,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			$this->failJob( $jobId, 'Failed to update post excerpt: ' . $result->get_error_message() );
			return;
		}

		// Build standardized effects array for undo.
		$effects = array(
			array(
				'type'           => 'post_field_set',
				'target'         => array(
					'post_id' => $post_id,
					'field'   => 'post_excerpt',
				),
				'previous_value' => '' !== $current_excerpt ? $current_excerpt : null,
			),
		);

		$this->completeJob( $jobId, array(
			'meta_description' => $description,
			'post_id'          => $post_id,
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
			'description'     => 'Generate SEO meta descriptions and save to post excerpt.',
			'setting_key'     => 'meta_description_auto_generate_enabled',
			'default_enabled' => true,
			'trigger'         => 'On demand via CLI or chat',
			'trigger_type'    => 'manual',
			'supports_run'    => false,
		);
	}

	/**
	 * Get editable prompt definitions for this task.
	 *
	 * @return array Prompt definitions keyed by prompt key.
	 * @since 0.41.0
	 */
	public function getPromptDefinitions(): array {
		return array(
			'generate' => array(
				'label'       => __( 'Meta Description Prompt', 'data-machine' ),
				'description' => __( 'Prompt used to generate meta descriptions for posts.', 'data-machine' ),
				'default'     => "Write a meta description for this page. Use your knowledge of this site's voice and audience.\n\n"
					. "Guidelines:\n"
					. '- Maximum ' . self::MAX_LENGTH . " characters (strict limit)\n"
					. "- Lead with the direct answer or hook — what will the reader learn or get?\n"
					. "- Include the primary topic/keyword naturally\n"
					. "- Create curiosity or value to encourage clicks\n"
					. "- Don't duplicate the title — expand on it\n"
					. "- No quotes around the description\n"
					. "- One or two sentences\n\n"
					. "Return ONLY the meta description text, nothing else.\n\n"
					. "Page context:\n{{context}}",
				'variables'   => array(
					'context' => 'Post title, content excerpt, categories, and tags',
				),
			),
		);
	}

	/**
	 * Build the AI prompt with post context.
	 *
	 * Note: The current excerpt is intentionally excluded from prompt context
	 * since we are generating a replacement for it.
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

		$context = ! empty( $context_lines ) ? implode( "\n", $context_lines ) : '';

		return $this->buildPromptFromTemplate( 'generate', array( 'context' => $context ) );
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
