<?php
/**
 * Read Threads Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class ReadThreads extends BaseTool {

	public function __construct() {
		$this->registerTool( 'read_threads', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Read Threads posts. List recent threads, get details for a specific thread, or get replies. Requires Threads OAuth to be configured.',
			'parameters'  => array(
				'action'    => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Action: "list" (recent threads), "get" (single thread), "replies" (thread replies). Defaults to "list".',
					'enum'        => array( 'list', 'get', 'replies' ),
				),
				'thread_id' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Threads media ID. Required for "get" and "replies" actions.',
				),
				'limit'     => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Number of items to return (max 100). Defaults to 25.',
				),
				'after'     => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Pagination cursor for next page.',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'read_threads';
		$action    = $parameters['action'] ?? 'list';

		if ( in_array( $action, array( 'get', 'replies' ), true ) && empty( $parameters['thread_id'] ) ) {
			return $this->buildErrorResponse( "thread_id is required for the {$action} action", $tool_name );
		}

		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'threads' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'Threads auth provider not available',
				'prerequisite_missing',
				$tool_name,
				array(
					'provider' => 'threads',
					'status'   => 'not_registered',
				),
				array(
					'action'    => 'configure_threads_auth',
					'message'   => 'Configure Threads OAuth in Data Machine Settings > Auth.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		if ( ! $provider->is_authenticated() ) {
			return $this->buildDiagnosticErrorResponse(
				'Threads is not authenticated',
				'prerequisite_missing',
				$tool_name,
				array(
					'provider' => 'threads',
					'status'   => 'not_authenticated',
				),
				array(
					'action'    => 'authenticate_threads',
					'message'   => 'Connect Threads via Data Machine Settings > Auth > Threads.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		$ability_instance = $ability;
		$input            = array( 'action' => sanitize_text_field( $action ) );

		if ( ! empty( $parameters['thread_id'] ) ) {
			$input['thread_id'] = sanitize_text_field( $parameters['thread_id'] );
		}
		if ( ! empty( $parameters['limit'] ) ) {
			$input['limit'] = absint( $parameters['limit'] );
		}
		if ( ! empty( $parameters['after'] ) ) {
			$input['after'] = sanitize_text_field( $parameters['after'] );
		}

		$result = $ability_instance->execute( $input );

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse( $this->getAbilityError( $result, 'Failed to read from Threads' ), $tool_name );
		}

		return array(
			'success'   => true,
			'data'      => $result['data'] ?? array(),
			'tool_name' => $tool_name,
		);
	}
}
