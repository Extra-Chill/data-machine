<?php
/**
 * Read Bluesky Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class ReadBluesky extends BaseTool {

	public function __construct() {
		$this->registerTool( 'read_bluesky', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Read Bluesky posts. List recent posts from your feed, get a post thread, or view your profile. Requires Bluesky app password to be configured.',
			'parameters'  => array(
				'action'   => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Action: "list" (author feed), "get" (post thread), "profile" (user profile). Defaults to "list".',
					'enum'        => array( 'list', 'get', 'profile' ),
				),
				'post_uri' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'AT Protocol post URI (at://did:plc:.../app.bsky.feed.post/...). Required for "get" action.',
				),
				'limit'    => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Number of posts to return (max 100). Defaults to 25.',
				),
				'cursor'   => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Pagination cursor for next page.',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'read_bluesky';
		$action    = $parameters['action'] ?? 'list';

		if ( 'get' === $action && empty( $parameters['post_uri'] ) ) {
			return $this->buildErrorResponse( 'post_uri is required for the get action', $tool_name );
		}

		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'bluesky' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'Bluesky auth provider not available',
				'prerequisite_missing',
				$tool_name,
				array(
					'provider' => 'bluesky',
					'status'   => 'not_registered',
				),
				array(
					'action'    => 'configure_bluesky_auth',
					'message'   => 'Configure Bluesky app password in Data Machine Settings > Auth.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		if ( ! $provider->is_authenticated() ) {
			return $this->buildDiagnosticErrorResponse(
				'Bluesky is not authenticated',
				'prerequisite_missing',
				$tool_name,
				array(
					'provider' => 'bluesky',
					'status'   => 'not_authenticated',
				),
				array(
					'action'    => 'authenticate_bluesky',
					'message'   => 'Configure Bluesky handle and app password in Data Machine Settings > Auth.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		$ability_instance = $ability;
		$input            = array( 'action' => sanitize_text_field( $action ) );

		if ( ! empty( $parameters['post_uri'] ) ) {
			$input['post_uri'] = sanitize_text_field( $parameters['post_uri'] );
		}
		if ( ! empty( $parameters['limit'] ) ) {
			$input['limit'] = absint( $parameters['limit'] );
		}
		if ( ! empty( $parameters['cursor'] ) ) {
			$input['cursor'] = sanitize_text_field( $parameters['cursor'] );
		}

		$result = $ability_instance->execute( $input );

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse( $this->getAbilityError( $result, 'Failed to read from Bluesky' ), $tool_name );
		}

		return array(
			'success'   => true,
			'data'      => $result['data'] ?? array(),
			'tool_name' => $tool_name,
		);
	}
}
