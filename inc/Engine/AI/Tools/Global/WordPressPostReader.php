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

use DataMachine\Engine\AI\Tools\BaseTool;

class WordPressPostReader extends BaseTool {

	public function __construct() {
		if ( ! function_exists( '\datamachine_register_ability_tool' ) ) {
			return;
		}

		\datamachine_register_ability_tool(
			'wordpress_post_reader',
			array_merge(
				$this->getToolDefinition(),
				array(
					'ability' => 'datamachine/get-wordpress-post',
					'modes'   => array( 'chat', 'pipeline' ),
				)
			)
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
			'name'            => 'WordPress Post Reader',
			'description'     => 'Read full content and metadata from a specific WordPress post by permalink URL. Use after Local Search when you need complete post content instead of excerpts. Accepts standard WordPress permalinks (e.g., /post-slug/) or shortlinks (?p=123). Does NOT accept REST API URLs (/wp-json/...). Essential for content analysis before WordPress Update operations.',
			'requires_config' => false,
			'parameters'      => array(
				'type'       => 'object',
				'properties' => array(
					'source_url'   => array(
					'type'        => 'string',
					'description' => 'WordPress permalink URL (e.g., https://site.com/post-slug/ or https://site.com/?p=123). Do not use REST API URLs.',
				),
					'include_meta' => array(
					'type'        => 'boolean',
					'description' => 'Include custom fields in response (default: false)',
				),
				),
				'required'   => array( 'source_url' ),
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
