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

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class ReplyReddit extends BaseTool {

	public function __construct() {
		$this->registerTool( 'reply_reddit', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Reply to a Reddit post or comment. Requires Reddit OAuth with submit scope. The thing_id is the Reddit fullname: t3_xxx for posts, t1_xxx for comments.',
			'parameters'  => array(
				'thing_id' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Reddit fullname: t3_xxx (post) or t1_xxx (comment) to reply to.',
				),
				'text'     => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Reply text. Supports Reddit markdown formatting.',
				),
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

		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'reddit' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'Reddit auth provider not available',
				'prerequisite_missing',
				$tool_name,
				array( 'provider' => 'reddit', 'status' => 'not_registered' ),
				array(
					'action'  => 'configure_reddit_auth',
					'message' => 'Reddit OAuth needs to be configured in Data Machine Settings > Auth.',
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
					'action'  => 'authenticate_reddit',
					'message' => 'Reddit OAuth needs to be connected with submit scope.',
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
				array( 'action' => 're_authenticate', 'message' => 'Reddit token refresh failed.' )
			);
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return $this->buildErrorResponse( 'WordPress Abilities API not available', $tool_name );
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

		if ( ! empty( $result['success'] ) ) {
			return array(
				'result'      => 'Reddit reply posted successfully!',
				'comment_id'  => $result['data']['comment_id'] ?? '',
				'comment_url' => $result['data']['comment_url'] ?? '',
				'parent_id'   => $result['data']['parent_id'] ?? '',
				'data'        => $result['data'],
			);
		}

		return $this->buildErrorResponse( $result['error'] ?? 'Failed to reply on Reddit', $tool_name );
	}
}
