<?php
/**
 * Read Instagram Chat Tool
 *
 * Chat tool for reading Instagram media (posts, reels, carousels) and comments.
 * Wraps the datamachine/instagram-read ability for use by the Data Machine chat agent.
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class ReadInstagram extends BaseTool {

	public function __construct() {
		$this->registerTool( 'read_instagram', array( $this, 'getToolDefinition' ), array( 'chat' ) );
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
			'description' => 'Read Instagram media. List recent posts, get details for a specific post, or get comments on a post. Requires Instagram OAuth to be configured.',
			'parameters'  => array(
				'action'   => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Action to perform: "list" (recent posts), "get" (single post details), "comments" (post comments). Defaults to "list".',
					'enum'        => array( 'list', 'get', 'comments' ),
				),
				'media_id' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Instagram media ID. Required for "get" and "comments" actions.',
				),
				'limit'    => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Number of items to return (max 100). Defaults to 25.',
				),
				'after'    => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Pagination cursor for fetching the next page of results.',
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
		$tool_name = 'read_instagram';
		$action    = $parameters['action'] ?? 'list';

		// Validate action-specific requirements.
		if ( in_array( $action, array( 'get', 'comments' ), true ) && empty( $parameters['media_id'] ) ) {
			return $this->buildErrorResponse( "media_id is required for the {$action} action", $tool_name );
		}

		// Get auth provider and valid token.
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

		// Get the ability.
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return $this->buildErrorResponse( 'WordPress Abilities API not available', $tool_name );
		}

		$ability = wp_get_ability( 'datamachine/instagram-read' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/instagram-read ability not registered', $tool_name );
		}

		// Build ability input.
		$input = array(
			'action' => sanitize_text_field( $action ),
		);

		if ( ! empty( $parameters['media_id'] ) ) {
			$input['media_id'] = sanitize_text_field( $parameters['media_id'] );
		}

		if ( ! empty( $parameters['limit'] ) ) {
			$input['limit'] = absint( $parameters['limit'] );
		}

		if ( ! empty( $parameters['after'] ) ) {
			$input['after'] = sanitize_text_field( $parameters['after'] );
		}

		// Execute via ability (which handles token retrieval internally).
		$ability_instance = new \DataMachineSocials\Abilities\Instagram\InstagramReadAbility();
		$result           = $ability_instance->execute( $input );

		if ( ! $this->isAbilitySuccess( $result ) ) {
			$error = $this->getAbilityError( $result, 'Failed to read from Instagram' );
			return $this->buildErrorResponse( $error, $tool_name );
		}

		$data = $result['data'] ?? array();

		// Format response based on action.
		switch ( $action ) {
			case 'list':
				$media = $data['media'] ?? array();
				if ( empty( $media ) ) {
					return array(
						'success'   => true,
						'data'      => null,
						'message'   => 'No Instagram posts found.',
						'tool_name' => $tool_name,
						'guidance'  => array(
							'status'    => 'empty_result',
							'next_step' => 'The Instagram account may have no posts, or try adjusting pagination.',
						),
					);
				}

				return array(
					'success'   => true,
					'data'      => $data,
					'message'   => sprintf( 'Found %d Instagram posts.', $data['count'] ?? count( $media ) ),
					'tool_name' => $tool_name,
					'guidance'  => array(
						'has_next'  => $data['has_next'] ?? false,
						'next_step' => ! empty( $data['has_next'] )
							? 'Use the "after" cursor to get the next page: ' . ( $data['cursors']['after'] ?? '' )
							: 'All available posts have been returned.',
					),
				);

			case 'get':
				return array(
					'success'   => true,
					'data'      => $data,
					'tool_name' => $tool_name,
				);

			case 'comments':
				$comments = $data['comments'] ?? array();
				if ( empty( $comments ) ) {
					return array(
						'success'   => true,
						'data'      => null,
						'message'   => 'No comments found on this post.',
						'tool_name' => $tool_name,
					);
				}

				return array(
					'success'   => true,
					'data'      => $data,
					'message'   => sprintf( 'Found %d comments.', $data['count'] ?? count( $comments ) ),
					'tool_name' => $tool_name,
					'guidance'  => array(
						'has_next'  => $data['has_next'] ?? false,
						'next_step' => ! empty( $data['has_next'] )
							? 'Use the "after" cursor to get the next page: ' . ( $data['cursors']['after'] ?? '' )
							: 'All available comments have been returned.',
					),
				);

			default:
				return array(
					'success'   => true,
					'data'      => $data,
					'tool_name' => $tool_name,
				);
		}
	}
}
