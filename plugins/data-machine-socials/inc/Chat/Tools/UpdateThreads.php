<?php
/**
 * Update Threads Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class UpdateThreads extends BaseTool {

	public function __construct() {
		$this->registerTool( 'chat', 'update_threads', array( $this, 'getToolDefinition' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Update Threads posts. Delete posts. Requires Threads OAuth to be configured.',
			'parameters'  => array(
				'action'    => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Action: "delete"',
					'enum'        => array( 'delete' ),
				),
				'thread_id' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Threads thread ID to delete.',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'update_threads';

		if ( empty( $parameters['thread_id'] ) ) {
			return $this->buildErrorResponse( 'thread_id is required', $tool_name );
		}

		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'threads' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'Threads auth provider not available',
				'prerequisite_missing',
				$tool_name,
				array( 'provider' => 'threads', 'status' => 'not_registered' ),
				array( 'action' => 'configure_threads_auth', 'message' => 'Threads OAuth needs to be configured.' )
			);
		}

		if ( ! $provider->is_authenticated() ) {
			return $this->buildDiagnosticErrorResponse(
				'Threads is not authenticated',
				'prerequisite_missing',
				$tool_name,
				array( 'provider' => 'threads', 'status' => 'not_authenticated' ),
				array( 'action' => 'authenticate_threads', 'message' => 'Threads OAuth needs to be connected.' )
			);
		}

		$ability_instance = new \DataMachineSocials\Abilities\Threads\ThreadsUpdateAbility();
		$result           = $ability_instance->execute( array(
			'action'    => sanitize_text_field( $parameters['action'] ),
			'thread_id' => sanitize_text_field( $parameters['thread_id'] ),
		) );

		if ( $result['success'] ) {
			return array(
				'result'    => 'Thread deleted successfully!',
				'thread_id' => $result['data']['thread_id'],
			);
		}

		return $this->buildErrorResponse( $result['error'] ?? 'Delete failed', $tool_name );
	}
}
