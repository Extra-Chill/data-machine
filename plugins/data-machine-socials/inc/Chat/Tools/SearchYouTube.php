<?php
/**
 * Search YouTube Chat Tool
 *
 * Chat tool for searching YouTube via the Data API search.list endpoint.
 * Wraps the datamachine/youtube-search ability.
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.17.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class SearchYouTube extends AbstractSocialTool {

	protected string $tool_name = 'search_youtube';

	protected string $platform = 'youtube';

	protected string $platform_label = 'YouTube';

	/**
	 * Get tool definition for AI agent.
	 *
	 * @return array Tool definition.
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Search YouTube for videos, channels, or playlists matching a query. Returns titles, channels, and URLs. Useful for music discovery and cross-referencing artist content.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'query' => array(
						'type'        => 'string',
						'description' => 'Search query string.',
					),
					'type' => array(
						'type'        => 'string',
						'description' => 'Resource type: video, channel, playlist, or any. Defaults to video.',
					),
					'limit' => array(
						'type'        => 'integer',
						'description' => 'Number of results (max 50). Defaults to 10.',
					),
				),
				'required'   => array( 'query' ),
			),
		);
	}

	/**
	 * Handle chat tool call.
	 *
	 * @param array $parameters Tool parameters from AI agent.
	 * @param array $tool_def   Tool definition context.
	 * @return array Result for AI agent.
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'search_youtube';

		if ( empty( $parameters['query'] ) ) {
			return $this->buildErrorResponse( 'query is required', $tool_name );
		}

		$auth_error = $this->guardAuth();
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		$ability = wp_get_ability( 'datamachine/youtube-search' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/youtube-search ability not registered', $tool_name );
		}

		$result = $ability->execute(
			array(
				'query' => sanitize_text_field( $parameters['query'] ),
				'type'  => $parameters['type'] ?? 'video',
				'limit' => isset( $parameters['limit'] ) ? absint( $parameters['limit'] ) : 10,
			)
		);

		if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
			return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'YouTube search failed' ), $tool_name );
		}

		return array(
			'result'  => 'Found ' . count( $result['results'] ?? array() ) . ' YouTube results',
			'results' => $result['results'] ?? array(),
		);
	}
}
