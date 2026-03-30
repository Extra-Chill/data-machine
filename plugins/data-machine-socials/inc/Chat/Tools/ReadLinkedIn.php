<?php
/**
 * Read LinkedIn Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.5.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class ReadLinkedIn extends BaseTool {

	public function __construct() {
		$this->registerTool( 'read_linkedin', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Read posts from LinkedIn. List recent posts or get a specific post by URN. Requires LinkedIn OAuth to be configured.',
			'parameters'  => array(
				'action'  => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Action: "list" (recent posts) or "get" (single post). Defaults to "list".',
					'enum'        => array( 'list', 'get' ),
				),
				'post_id' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Post URN (e.g., urn:li:share:12345). Required for "get" action.',
				),
				'limit'   => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Number of posts to return (max 100). Defaults to 10.',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'read_linkedin';
		$action    = $parameters['action'] ?? 'list';

		if ( 'get' === $action && empty( $parameters['post_id'] ) ) {
			return $this->buildErrorResponse( 'post_id is required for the get action', $tool_name );
		}

		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'linkedin' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'LinkedIn auth provider not available',
				'prerequisite_missing',
				$tool_name,
				array(
					'provider' => 'linkedin',
					'status'   => 'not_registered',
				),
				array(
					'action'    => 'configure_linkedin_auth',
					'message'   => 'Configure LinkedIn OAuth in Data Machine Settings > Auth.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		if ( ! $provider->is_authenticated() ) {
			return $this->buildDiagnosticErrorResponse(
				'LinkedIn is not authenticated',
				'prerequisite_missing',
				$tool_name,
				array(
					'provider' => 'linkedin',
					'status'   => 'not_authenticated',
				),
				array(
					'action'    => 'authenticate_linkedin',
					'message'   => 'Connect LinkedIn via Data Machine Settings > Auth > LinkedIn.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		$ability = wp_get_ability( 'datamachine/linkedin-read' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'LinkedIn read ability not registered', $tool_name );
		}

		$input = array( 'action' => sanitize_text_field( $action ) );

		if ( ! empty( $parameters['post_id'] ) ) {
			$input['post_id'] = sanitize_text_field( $parameters['post_id'] );
		}
		if ( ! empty( $parameters['limit'] ) ) {
			$input['limit'] = absint( $parameters['limit'] );
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) || ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : $this->getAbilityError( $result, 'Failed to read from LinkedIn' ), $tool_name );
		}

		return array(
			'success'   => true,
			'data'      => $result['data'] ?? array(),
			'tool_name' => $tool_name,
		);
	}
}
