<?php
/**
 * Submit Reddit Chat Tool
 *
 * Chat tool for submitting new posts to Reddit subreddits.
 * Wraps the datamachine/submit-reddit ability for use by the Data Machine chat agent.
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.7.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class SubmitReddit extends AbstractSocialTool {

	protected string $tool_name = 'submit_reddit';

	protected string $platform = 'reddit';

	protected string $platform_label = 'Reddit';

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Submit a new post to a Reddit subreddit. Supports self (text) posts and link posts. Requires Reddit OAuth with submit scope.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'subreddit' => array(
						'type'        => 'string',
						'description' => 'Subreddit name (without "r/").',
					),
					'title'     => array(
						'type'        => 'string',
						'description' => 'Post title (max 300 characters).',
					),
					'text'      => array(
						'type'        => 'string',
						'description' => 'Self-post body text (markdown). Omit for link posts.',
					),
					'url'       => array(
						'type'        => 'string',
						'description' => 'URL for link posts. Omit for text posts.',
					),
					'flair_id'  => array(
						'type'        => 'string',
						'description' => 'Flair template ID (if required by subreddit).',
					),
					'nsfw'      => array(
						'type'        => 'boolean',
						'description' => 'Mark as NSFW.',
					),
				),
				'required'   => array( 'subreddit', 'title' ),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'submit_reddit';

		if ( empty( $parameters['subreddit'] ) ) {
			return $this->buildErrorResponse( 'subreddit is required', $tool_name );
		}

		if ( empty( $parameters['title'] ) ) {
			return $this->buildErrorResponse( 'title is required', $tool_name );
		}

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
				array( 'provider' => 'reddit', 'status' => 'token_expired' ),
				array( 'action' => 're_authenticate', 'message' => 'Reddit token refresh failed.' )
			);
		}

		$ability = wp_get_ability( 'datamachine/submit-reddit' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/submit-reddit ability not registered', $tool_name );
		}

		$result = $ability->execute( array(
			'subreddit'    => sanitize_text_field( $parameters['subreddit'] ),
			'title'        => sanitize_text_field( $parameters['title'] ),
			'text'         => $parameters['text'] ?? '',
			'url'          => esc_url_raw( $parameters['url'] ?? '' ),
			'flair_id'     => sanitize_text_field( $parameters['flair_id'] ?? '' ),
			'nsfw'         => ! empty( $parameters['nsfw'] ),
			'access_token' => $access_token,
		) );

		if ( ! is_wp_error( $result ) && ! empty( $result['success'] ) ) {
			return array(
				'result'   => 'Reddit post submitted successfully!',
				'post_url' => $result['data']['post_url'] ?? '',
				'post_id'  => $result['data']['post_id'] ?? '',
				'data'     => $result['data'],
			);
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Failed to submit Reddit post' ), $tool_name );
	}
}
