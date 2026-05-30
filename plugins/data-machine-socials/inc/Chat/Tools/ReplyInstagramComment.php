<?php
/**
 * Reply Instagram Comment Chat Tool
 *
 * Chat tool for replying to Instagram comments.
 * Wraps the datamachine/instagram-comment-reply ability for use by the Data Machine chat agent.
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class ReplyInstagramComment extends AbstractSocialTool {

	protected string $tool_name = 'reply_instagram_comment';

	protected string $platform = 'instagram';

	protected string $platform_label = 'Instagram';

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Reply to an Instagram comment. Requires Instagram OAuth to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'comment_id' => array(
						'type'        => 'string',
						'description' => 'Instagram comment ID to reply to.',
					),
					'message'    => array(
						'type'        => 'string',
						'description' => 'Reply text for the Instagram comment.',
					),
				),
				'required'   => array( 'comment_id', 'message' ),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'reply_instagram_comment';

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

		$ability = wp_get_ability( 'datamachine/instagram-comment-reply' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/instagram-comment-reply ability not registered', $tool_name );
		}

		$ability = wp_get_ability( 'datamachine/instagram-comment-reply' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/instagram-comment-reply ability not registered', $tool_name );
		}
		$ability_instance = $ability;
		$result           = $ability_instance->execute(
			array(
				'comment_id' => sanitize_text_field( $parameters['comment_id'] ),
				'message'    => sanitize_textarea_field( $parameters['message'] ),
			)
		);

		if ( ! is_wp_error( $result ) && ! empty( $result['success'] ) ) {
			return array(
				'result'     => 'Instagram comment reply posted successfully!',
				'comment_id' => $result['data']['comment_id'] ?? $parameters['comment_id'],
				'reply_id'   => $result['data']['reply_id'] ?? '',
				'data'       => $result['data'],
			);
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Failed to reply to Instagram comment' ), $tool_name );
	}
}
