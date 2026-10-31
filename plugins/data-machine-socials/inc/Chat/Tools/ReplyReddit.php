<?php
/**
 * Reply Reddit Chat Tool
 *
 * Chat tool for replying to Reddit posts and comments.
 * Wraps the datamachine/reply-reddit ability for use by the Data Machine chat agent.
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.7.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class ReplyReddit extends AbstractSocialTool {

	protected string $tool_name = 'reply_reddit';

	protected string $platform = 'reddit';

	protected string $platform_label = 'Reddit';

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Reply to a Reddit post or comment. Requires Reddit OAuth with submit scope. The thing_id is the Reddit fullname: t3_xxx for posts, t1_xxx for comments.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'thing_id' => array(
						'type'        => 'string',
						'description' => 'Reddit fullname: t3_xxx (post) or t1_xxx (comment) to reply to.',
					),
					'text'     => array(
						'type'        => 'string',
						'description' => 'Reply text. Supports Reddit markdown formatting.',
					),
				),
				'required'   => array( 'thing_id', 'text' ),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'reply_reddit';

		if ( empty( $parameters['thing_id'] ) ) {
			return $this->buildErrorResponse( 'thing_id is required (e.g. t3_abc123 or t1_abc123)', $tool_name );
		}

		if ( empty( $parameters['text'] ) ) {
			return $this->buildErrorResponse( 'text is required', $tool_name );
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

		$ability = wp_get_ability( 'datamachine/reply-reddit' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/reply-reddit ability not registered', $tool_name );
		}

		$result = $ability->execute( array(
			'thing_id'     => sanitize_text_field( $parameters['thing_id'] ),
			'text'         => $parameters['text'],
			'access_token' => $access_token,
		) );

		if ( ! is_wp_error( $result ) && ! empty( $result['success'] ) ) {
			return array(
				'result'      => 'Reddit reply posted successfully!',
				'comment_id'  => $result['data']['comment_id'] ?? '',
				'comment_url' => $result['data']['comment_url'] ?? '',
				'parent_id'   => $result['data']['parent_id'] ?? '',
				'data'        => $result['data'],
			);
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Failed to reply on Reddit' ), $tool_name );
	}
}
