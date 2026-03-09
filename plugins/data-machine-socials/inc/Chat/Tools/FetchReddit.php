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

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class FetchReddit extends BaseTool {

	public function __construct() {
		$this->registerTool( 'fetch_reddit', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	/**
	 * Get tool definition for AI agent.
	 *
	 * @return array Tool definition.
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Fetch a post from a Reddit subreddit. Returns the first eligible post matching filters (upvotes, comments, timeframe, keywords). Requires Reddit OAuth to be configured.',
			'parameters'  => array(
				'subreddit'         => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Subreddit name to fetch from (without "r/", e.g. "jambands", "festivals")',
				),
				'sort_by'           => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Sort order: hot, new, top, rising, controversial',
					'enum'        => array( 'hot', 'new', 'top', 'rising', 'controversial' ),
				),
				'timeframe_limit'   => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Timeframe filter: all_time, 24_hours, 72_hours, 7_days, 30_days, 90_days, 6_months, 1_year',
				),
				'min_upvotes'       => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Minimum upvotes (score) required. 0 to disable.',
				),
				'min_comment_count' => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Minimum comment count required. 0 to disable.',
				),
				'comment_count'     => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Number of top comments to include (0 = none).',
				),
				'search'            => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Comma-separated keywords to filter posts by title/content.',
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

		// Validate subreddit.
		if ( empty( $parameters['subreddit'] ) ) {
			return $this->buildErrorResponse( 'subreddit parameter is required', $tool_name );
		}

		// Get auth provider and valid token.
		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'reddit' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'Reddit auth provider not available',
				'prerequisite_missing',
				$tool_name,
				array( 'provider' => 'reddit', 'status' => 'not_registered' ),
				array(
					'action'    => 'configure_reddit_auth',
					'message'   => 'Reddit OAuth needs to be configured in Data Machine Settings > Auth.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		if ( ! $provider->is_authenticated() ) {
			return $this->buildDiagnosticErrorResponse(
				'Reddit is not authenticated',
				'prerequisite_missing',
				$tool_name,
				array( 'provider' => 'reddit', 'status' => 'not_authenticated' ),
				array(
					'action'    => 'authenticate_reddit',
					'message'   => 'Reddit OAuth needs to be connected. Go to Data Machine Settings > Auth > Reddit.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		$access_token = $provider->get_valid_access_token();
		if ( empty( $access_token ) ) {
			return $this->buildDiagnosticErrorResponse(
				'Reddit access token expired and refresh failed',
				'system',
				$tool_name,
				array( 'provider' => 'reddit', 'status' => 'token_expired' ),
				array(
					'action'  => 're_authenticate',
					'message' => 'Reddit token refresh failed. User needs to re-authenticate via Settings.',
				)
			);
		}

		// Get the ability.
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return $this->buildErrorResponse( 'WordPress Abilities API not available', $tool_name );
		}

		$ability = wp_get_ability( 'datamachine/fetch-reddit' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/fetch-reddit ability not registered', $tool_name );
		}

		// Build ability input.
		$input = array(
			'subreddit'         => sanitize_text_field( $parameters['subreddit'] ),
			'access_token'      => $access_token,
			'sort_by'           => $parameters['sort_by'] ?? 'hot',
			'timeframe_limit'   => $parameters['timeframe_limit'] ?? 'all_time',
			'min_upvotes'       => absint( $parameters['min_upvotes'] ?? 0 ),
			'min_comment_count' => absint( $parameters['min_comment_count'] ?? 0 ),
			'comment_count'     => absint( $parameters['comment_count'] ?? 0 ),
			'search'            => $parameters['search'] ?? '',
			'processed_items'   => array(),
			'fetch_batch_size'  => 100,
			'max_pages'         => 5,
			'download_images'   => false,
		);

		$result = $ability->execute( $input );

		if ( ! $this->isAbilitySuccess( $result ) ) {
			$error = $this->getAbilityError( $result, 'Failed to fetch from Reddit' );
			return $this->buildErrorResponse( $error, $tool_name );
		}

		if ( empty( $result['data'] ) ) {
			return array(
				'success'   => true,
				'data'      => null,
				'message'   => 'No eligible posts found in r/' . $input['subreddit'] . ' with the given filters.',
				'tool_name' => $tool_name,
				'guidance'  => array(
					'status'    => 'empty_result',
					'next_step' => 'Try broadening filters (lower min_upvotes, expand timeframe, remove search terms).',
				),
			);
		}

		return array(
			'success'    => true,
			'data'       => $result['data'],
			'source_url' => $result['source_url'] ?? '',
			'item_id'    => $result['item_id'] ?? '',
			'tool_name'  => $tool_name,
		);
	}
}
