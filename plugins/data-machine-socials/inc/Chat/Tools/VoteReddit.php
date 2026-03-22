<?php
/**
 * Vote Reddit Chat Tool
 *
 * Chat tool for upvoting/downvoting Reddit posts and comments.
 * Wraps the datamachine/vote-reddit ability for use by the Data Machine chat agent.
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.8.2
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class VoteReddit extends BaseTool {

	public function __construct() {
		$this->registerTool( 'vote_reddit', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Upvote, downvote, or unvote a Reddit post or comment. Requires Reddit OAuth with vote scope.',
			'parameters'  => array(
				'thing_id'  => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Reddit fullname of the post (t3_xxx) or comment (t1_xxx) to vote on.',
				),
				'direction' => array(
					'type'        => 'integer',
					'required'    => true,
					'description' => 'Vote direction: 1 (upvote), 0 (unvote), -1 (downvote).',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'vote_reddit';

		if ( empty( $parameters['thing_id'] ) ) {
			return $this->buildErrorResponse( 'thing_id is required', $tool_name );
		}

		if ( ! isset( $parameters['direction'] ) ) {
			return $this->buildErrorResponse( 'direction is required (1, 0, or -1)', $tool_name );
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
					'message' => 'Reddit OAuth needs to be connected with vote scope.',
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

		$ability = wp_get_ability( 'datamachine/vote-reddit' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/vote-reddit ability not registered', $tool_name );
		}

		$result = $ability->execute( array(
			'thing_id'     => sanitize_text_field( $parameters['thing_id'] ),
			'direction'    => (int) $parameters['direction'],
			'access_token' => $access_token,
		) );

		if ( ! empty( $result['success'] ) ) {
			$direction_labels = array(
				1  => 'upvoted',
				0  => 'unvoted',
				-1 => 'downvoted',
			);

			return array(
				'result'    => 'Successfully ' . ( $direction_labels[ (int) $parameters['direction'] ] ?? 'voted on' ) . " {$parameters['thing_id']}",
				'thing_id'  => $result['data']['thing_id'] ?? $parameters['thing_id'],
				'direction' => $result['data']['direction'] ?? $parameters['direction'],
				'action'    => $result['data']['action'] ?? '',
			);
		}

		return $this->buildErrorResponse( $result['error'] ?? 'Failed to vote on Reddit', $tool_name );
	}
}
