<?php
/**
 * Reply Facebook Comment Chat Tool
 *
 * Chat tool for replying to Facebook Page comments.
 * Wraps the datamachine/facebook-comment-reply ability for use by the Data Machine chat agent.
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.20.3
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class ReplyFacebookComment extends AbstractSocialTool {

	protected string $tool_name = 'reply_facebook_comment';

	protected string $platform = 'facebook';

	protected string $platform_label = 'Facebook';

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Reply to a Facebook Page comment. Requires Facebook OAuth to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'comment_id' => array(
						'type'        => 'string',
						'description' => 'Facebook comment ID to reply to.',
					),
					'message'    => array(
						'type'        => 'string',
						'description' => 'Reply text for the Facebook comment.',
					),
				),
				'required'   => array( 'comment_id', 'message' ),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'reply_facebook_comment';

		if ( empty( $parameters['comment_id'] ) ) {
			return $this->buildErrorResponse( 'comment_id is required', $tool_name );
		}

		if ( empty( $parameters['message'] ) ) {
			return $this->buildErrorResponse( 'message is required', $tool_name );
		}

		$auth_error = $this->guardAuth();
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		$ability = wp_get_ability( 'datamachine/facebook-comment-reply' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/facebook-comment-reply ability not registered', $tool_name );
		}

		$result = $ability->execute(
			array(
				'comment_id' => sanitize_text_field( $parameters['comment_id'] ),
				'message'    => sanitize_textarea_field( $parameters['message'] ),
			)
		);

		if ( ! is_wp_error( $result ) && ! empty( $result['success'] ) ) {
			return array(
				'result'     => 'Facebook comment reply posted successfully!',
				'comment_id' => $result['data']['comment_id'] ?? $parameters['comment_id'],
				'reply_id'   => $result['data']['reply_id'] ?? '',
				'data'       => $result['data'],
			);
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Failed to reply to Facebook comment' ), $tool_name );
	}
}
