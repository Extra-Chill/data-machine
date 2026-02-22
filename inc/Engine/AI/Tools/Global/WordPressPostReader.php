<?php
/**
 * WordPress Post Reader - AI tool for retrieving WordPress post content by URL.
 *
 * Delegates to GetWordPressPostAbility for core logic.
 *
 * @package DataMachine
 */

namespace DataMachine\Engine\AI\Tools\Global;

defined( 'ABSPATH' ) || exit;

use DataMachine\Abilities\Fetch\GetWordPressPostAbility;
use DataMachine\Engine\AI\Tools\BaseTool;

class WordPressPostReader extends BaseTool {

	public function __construct() {
		$this->registerGlobalTool( 'wordpress_post_reader', array( $this, 'getToolDefinition' ) );
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		if ( empty( $parameters['source_url'] ) ) {
			return array(
				'success' => false,
				'error' => 'WordPress Post Reader tool call missing required source_url parameter',
				'tool_name' => 'wordpress_post_reader',
			);
		}

		$source_url = sanitize_url( $parameters['source_url'] );
		$include_meta = ! empty( $parameters['include_meta'] );

		// Delegate to GetWordPressPostAbility
		$ability_input = array(
			'source_url' => $source_url,
			'include_meta' => $include_meta,
			'include_file_info' => false, // Tool doesn't need file_info
		);

		$ability = new GetWordPressPostAbility();
		$result = $ability->execute( $ability_input );

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error' => $result['error'] ?? 'Unknown error retrieving post',
				'tool_name' => 'wordpress_post_reader',
			);
		}

		$data = $result['data'];

		// Format response for AI tool
		$content_length = strlen( $data['content'] );
		$content_word_count = str_word_count( wp_strip_all_tags( $data['content'] ) );

		$response_data = array(
			'post_id' => $data['post_id'],
			'title' => $data['title'],
			'content' => $data['content'],
			'content_length' => $content_length,
			'content_word_count' => $content_word_count,
			'permalink' => $data['permalink'],
			'post_type' => $data['post_type'],
			'post_status' => $data['post_status'],
			'publish_date' => $data['publish_date'],
			'author' => $data['author'],
			'featured_image' => $data['featured_image'],
			'meta_fields' => $data['meta_fields'] ?? array(),
		);

		$message = $content_length > 0
			? "READ COMPLETE: Retrieved WordPress post from \"{$data['permalink']}\". Content Length: {$content_length} characters ({$content_word_count} words)"
			: "READ COMPLETE: WordPress post found at \"{$data['permalink']}\" but has no content.";

		$response_data['message'] = $message;

		return array(
			'success' => true,
			'data' => $response_data,
			'tool_name' => 'wordpress_post_reader',
		);
	}

	/**
	 * Get WordPress Post Reader tool definition.
	 * Called lazily when tool is first accessed to ensure translations are loaded.
	 *
	 * @return array Tool definition array
	 */
	public function getToolDefinition(): array {
		return array(
			'class' => __CLASS__,
			'method' => 'handle_tool_call',
			'name' => 'WordPress Post Reader',
			'description' => 'Read full content and metadata from a specific WordPress post by permalink URL. Use after Local Search when you need complete post content instead of excerpts. Accepts standard WordPress permalinks (e.g., /post-slug/) or shortlinks (?p=123). Does NOT accept REST API URLs (/wp-json/...). Essential for content analysis before WordPress Update operations.',
			'requires_config' => false,
			'parameters' => array(
				'source_url' => array(
					'type' => 'string',
					'required' => true,
					'description' => 'WordPress permalink URL (e.g., https://site.com/post-slug/ or https://site.com/?p=123). Do not use REST API URLs.',
				),
				'include_meta' => array(
					'type' => 'boolean',
					'required' => false,
					'description' => 'Include custom fields in response (default: false)',
				),
			),
		);
	}

	public static function is_configured(): bool {
		return true;
	}

	public function check_configuration( $configured, $tool_id ) {
		if ( 'wordpress_post_reader' !== $tool_id ) {
			return $configured;
		}

		return self::is_configured();
	}
}
