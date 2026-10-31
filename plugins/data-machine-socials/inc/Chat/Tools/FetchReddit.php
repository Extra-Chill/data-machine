<?php
/**
 * Fetch Reddit Chat Tool
 *
 * Chat tool for fetching Reddit posts. Wraps the datamachine/fetch-reddit
 * ability for use by the Data Machine chat agent.
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class FetchReddit extends AbstractSocialTool {

	protected string $tool_name = 'fetch_reddit';

	protected string $platform = 'reddit';

	protected string $platform_label = 'Reddit';

	/**
	 * Get tool definition for AI agent.
	 *
	 * @return array Tool definition.
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Fetch posts from Reddit. Provide a subreddit to browse it, or a query to search across all of Reddit. Both can be combined to search within a subreddit. Returns eligible posts matching filters (upvotes, comments, timeframe, keywords). Requires Reddit OAuth to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'subreddit'         => array(
						'type'        => 'string',
						'description' => 'Subreddit name to fetch from (without "r/", e.g. "jambands", "festivals"). Optional when query is provided.',
					),
					'query'             => array(
						'type'        => 'string',
						'description' => 'Search query. Without subreddit, searches all of Reddit. With subreddit, searches within it.',
					),
					'sort_by'           => array(
						'type'        => 'string',
						'description' => 'Sort order: hot, new, top, rising, controversial, relevance. Use "relevance" for search queries.',
						'enum'        => array( 'hot', 'new', 'top', 'rising', 'controversial', 'relevance' ),
					),
					'timeframe_limit'   => array(
						'type'        => 'string',
						'description' => 'Timeframe filter: all_time, 24_hours, 72_hours, 7_days, 30_days, 90_days, 6_months, 1_year',
					),
					'min_upvotes'       => array(
						'type'        => 'integer',
						'description' => 'Minimum upvotes (score) required. 0 to disable.',
					),
					'min_comment_count' => array(
						'type'        => 'integer',
						'description' => 'Minimum comment count required. 0 to disable.',
					),
					'comment_count'     => array(
						'type'        => 'integer',
						'description' => 'Number of top comments to include (0 = none).',
					),
					'search'            => array(
						'type'        => 'string',
						'description' => 'Comma-separated keywords to filter posts by title/content.',
					),
				),
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
		$tool_name = 'fetch_reddit';

		// Validate: must have subreddit or query.
		if ( empty( $parameters['subreddit'] ) && empty( $parameters['query'] ) ) {
			return $this->buildErrorResponse( 'Either subreddit or query parameter is required', $tool_name );
		}

		// Get auth provider and valid token.
		$auth_error = $this->guardAuth();
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		$access_token = $this->provider->get_valid_access_token();
		if ( empty( $access_token ) ) {
			return $this->buildDiagnosticErrorResponse(
				'Reddit access token expired and refresh failed',
				'system',
				$tool_name,
				array(
					'provider' => 'reddit',
					'status'   => 'token_expired',
				),
				array(
					'action'  => 're_authenticate',
					'message' => 'Reddit token refresh failed. User needs to re-authenticate via Settings.',
				)
			);
		}

		// Get the ability.
		$ability = wp_get_ability( 'datamachine/fetch-reddit' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/fetch-reddit ability not registered', $tool_name );
		}

		// Default sort to 'relevance' for search queries.
		$has_query    = ! empty( $parameters['query'] );
		$default_sort = $has_query ? 'relevance' : 'hot';

		// Build ability input.
		$input = array(
			'subreddit'         => sanitize_text_field( $parameters['subreddit'] ?? '' ),
			'query'             => sanitize_text_field( $parameters['query'] ?? '' ),
			'access_token'      => $access_token,
			'sort_by'           => $parameters['sort_by'] ?? $default_sort,
			'timeframe_limit'   => $parameters['timeframe_limit'] ?? 'all_time',
			'min_upvotes'       => absint( $parameters['min_upvotes'] ?? 0 ),
			'min_comment_count' => absint( $parameters['min_comment_count'] ?? 0 ),
			'comment_count'     => absint( $parameters['comment_count'] ?? 0 ),
			'search'            => $parameters['search'] ?? '',
			'fetch_batch_size'  => 100,
			'max_pages'         => 5,
			'download_images'   => false,
		);

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) || ! $this->isAbilitySuccess( $result ) ) {
			$error = is_wp_error( $result ) ? $result->get_error_message() : $this->getAbilityError( $result, 'Failed to fetch from Reddit' );
			return $this->buildErrorResponse( $error, $tool_name );
		}

		// The ability returns an 'items' array for all Reddit fetch results.
		$items = $result['items'] ?? array();

		if ( empty( $items ) ) {
			$context_label = ! empty( $input['subreddit'] ) ? 'r/' . $input['subreddit'] : 'Reddit';
			return array(
				'success'   => true,
				'data'      => null,
				'message'   => 'No eligible posts found on ' . $context_label . ' with the given filters.',
				'tool_name' => $tool_name,
				'guidance'  => array(
					'status'    => 'empty_result',
					'next_step' => 'Try broadening filters (lower min_upvotes, expand timeframe, remove search terms, or adjust query).',
				),
			);
		}

		return array(
			'success'   => true,
			'items'     => $items,
			'count'     => count( $items ),
			'tool_name' => $tool_name,
		);
	}
}
