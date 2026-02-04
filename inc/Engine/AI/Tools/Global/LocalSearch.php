<?php
/**
 * WordPress Local Search AI Tool - Site content discovery for AI agents
 *
 * Delegates to LocalSearchAbilities for search execution.
 * Supports standard WordPress search, title-only matching, and multi-term queries.
 *
 * @package DataMachine\Engine\AI\Tools\Global
 */

namespace DataMachine\Engine\AI\Tools\Global;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\AI\Tools\BaseTool;

class LocalSearch extends BaseTool {

	public function __construct() {
		$this->registerGlobalTool( 'local_search', array( $this, 'getToolDefinition' ) );
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$ability = wp_get_ability( 'datamachine/local-search' );

		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Local Search ability not registered. Ensure WordPress 6.9+ and LocalSearchAbilities is loaded.',
				'tool_name' => 'local_search',
			);
		}

		$result = $ability->execute(
			array(
				'query'      => $parameters['query'] ?? '',
				'post_types' => $parameters['post_types'] ?? array( 'post', 'page' ),
				'title_only' => $parameters['title_only'] ?? false,
			)
		);

		// Handle WP_Error from ability execution (e.g., permission denied).
		if ( is_wp_error( $result ) ) {
			return array(
				'success'   => false,
				'error'     => $result->get_error_message(),
				'tool_name' => 'local_search',
			);
		}

		if ( isset( $result['error'] ) ) {
			return array(
				'success'   => false,
				'error'     => $result['error'],
				'tool_name' => 'local_search',
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'local_search',
		);
	}

	public function getToolDefinition(): array {
		return array(
			'class'           => __CLASS__,
			'method'          => 'handle_tool_call',
			'description'     => 'Search this WordPress site for posts by title or content. Returns up to 10 results with titles, excerpts, permalinks, and metadata. Automatically tries multiple search strategies (standard search, title matching, split queries) if initial search returns no results. For best results, search for ONE item at a time. Use title_only=true for precise title matching.',
			'requires_config' => false,
			'parameters'      => array(
				'query'      => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Search terms to find relevant posts. For best results, use simple queries for one item at a time rather than multiple comma-separated items.',
				),
				'post_types' => array(
					'type'        => 'array',
					'required'    => false,
					'description' => 'Post types to search (default: ["post", "page"]). Use ["datamachine_events"] for events.',
				),
				'title_only' => array(
					'type'        => 'boolean',
					'required'    => false,
					'description' => 'Search only post titles instead of full content (default: false). Use for precise title matching when you know the exact or partial title.',
				),
			),
		);
	}

	public static function is_configured(): bool {
		return true;
	}

	public function check_configuration( $configured, $tool_id ) {
		if ( 'local_search' !== $tool_id ) {
			return $configured;
		}

		return self::is_configured();
	}

	public static function get_searchable_post_types(): array {
		$post_types = get_post_types(
			array(
				'public'              => true,
				'exclude_from_search' => false,
			),
			'names'
		);

		return array_values( $post_types );
	}
}

new LocalSearch();
