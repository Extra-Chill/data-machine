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

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Engine\AI\Tools\BaseTool;

defined( 'ABSPATH' ) || exit;

class ReplyInstagramComment extends BaseTool {

	public function __construct() {
		$this->registerTool( 'reply_instagram_comment', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Reply to an Instagram comment. Requires Instagram OAuth to be configured.',
			'parameters'  => array(
				'comment_id' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Instagram comment ID to reply to.',
				),
				'message'    => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Reply text for the Instagram comment.',
				),
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

		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'instagram' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'Instagram auth provider not available',
				'prerequisite_missing',
				$tool_name,
				array(
					'provider' => 'instagram',
					'status'   => 'not_registered',
				),
				array(
					'action'    => 'configure_instagram_auth',
					'message'   => 'Instagram OAuth needs to be configured in Data Machine Settings > Auth.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		if ( ! $provider->is_authenticated() ) {
			return $this->buildDiagnosticErrorResponse(
				'Instagram is not authenticated',
				'prerequisite_missing',
				$tool_name,
				array(
					'provider' => 'instagram',
					'status'   => 'not_authenticated',
				),
				array(
					'action'    => 'authenticate_instagram',
					'message'   => 'Instagram OAuth needs to be connected. Go to Data Machine Settings > Auth > Instagram.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return $this->buildErrorResponse( 'WordPress Abilities API not available', $tool_name );
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

		if ( ! empty( $result['success'] ) ) {
			return array(
				'result'     => 'Instagram comment reply posted successfully!',
				'comment_id' => $result['data']['comment_id'] ?? $parameters['comment_id'],
				'reply_id'   => $result['data']['reply_id'] ?? '',
				'data'       => $result['data'],
			);
		}

		return $this->buildErrorResponse( $result['error'] ?? 'Failed to reply to Instagram comment', $tool_name );
	}
}
