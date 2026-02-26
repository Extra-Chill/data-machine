<?php
/**
 * Read Facebook Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class ReadFacebook extends BaseTool {

	public function __construct() {
		$this->registerTool( 'chat', 'read_facebook', array( $this, 'getToolDefinition' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Read Facebook Page posts. List recent posts, get details for a specific post, or get comments. Requires Facebook OAuth to be configured.',
			'parameters'  => array(
				'action'  => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Action: "list" (recent posts), "get" (single post), "comments" (post comments). Defaults to "list".',
					'enum'        => array( 'list', 'get', 'comments' ),
				),
				'post_id' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Facebook post ID. Required for "get" and "comments" actions.',
				),
				'limit'   => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Number of items to return (max 100). Defaults to 25.',
				),
				'after'   => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Pagination cursor for next page.',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'read_facebook';
		$action    = $parameters['action'] ?? 'list';

		if ( in_array( $action, array( 'get', 'comments' ), true ) && empty( $parameters['post_id'] ) ) {
			return $this->buildErrorResponse( "post_id is required for the {$action} action", $tool_name );
		}

		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'facebook' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'Facebook auth provider not available',
				'prerequisite_missing',
				$tool_name,
				array( 'provider' => 'facebook', 'status' => 'not_registered' ),
				array( 'action' => 'configure_facebook_auth', 'message' => 'Configure Facebook OAuth in Data Machine Settings > Auth.', 'tool_hint' => 'authenticate_handler' )
			);
		}

		if ( ! $provider->is_authenticated() ) {
			return $this->buildDiagnosticErrorResponse(
				'Facebook is not authenticated',
				'prerequisite_missing',
				$tool_name,
				array( 'provider' => 'facebook', 'status' => 'not_authenticated' ),
				array( 'action' => 'authenticate_facebook', 'message' => 'Connect Facebook via Data Machine Settings > Auth > Facebook.', 'tool_hint' => 'authenticate_handler' )
			);
		}

		$ability_instance = new \DataMachineSocials\Abilities\Facebook\FacebookReadAbility();
		$input            = array( 'action' => sanitize_text_field( $action ) );

		if ( ! empty( $parameters['post_id'] ) ) {
			$input['post_id'] = sanitize_text_field( $parameters['post_id'] );
		}
		if ( ! empty( $parameters['limit'] ) ) {
			$input['limit'] = absint( $parameters['limit'] );
		}
		if ( ! empty( $parameters['after'] ) ) {
			$input['after'] = sanitize_text_field( $parameters['after'] );
		}

		$result = $ability_instance->execute( $input );

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse( $this->getAbilityError( $result, 'Failed to read from Facebook' ), $tool_name );
		}

		return array(
			'success'   => true,
			'data'      => $result['data'] ?? array(),
			'tool_name' => $tool_name,
		);
	}
}
