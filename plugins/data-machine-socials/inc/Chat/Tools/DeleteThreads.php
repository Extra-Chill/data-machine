<?php
/**
 * Delete Threads Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class DeleteThreads extends BaseTool {

	public function __construct() {
		$this->registerTool( 'delete_threads', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Delete Threads posts. Requires Threads OAuth to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'thread_id' => array(
						'type'        => 'string',
						'description' => 'Threads thread ID to delete.',
					),
				),
				'required'   => array( 'thread_id' ),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'delete_threads';

		if ( empty( $parameters['thread_id'] ) ) {
			return $this->buildErrorResponse( 'thread_id is required', $tool_name );
		}

		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'threads' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'Threads auth not available',
				'prerequisite_missing',
				$tool_name,
				array( 'provider' => 'threads' ),
				array( 'action' => 'configure_threads' )
			);
		}

		$ability = wp_get_ability( 'datamachine/threads-delete' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/threads-delete ability not registered', $tool_name );
		}
		$result = $ability->execute( array( 'thread_id' => $parameters['thread_id'] ) );

		if ( ! is_wp_error( $result ) && $result['success'] ) {
			return array(
				'result'    => 'Thread deleted!',
				'thread_id' => $parameters['thread_id'],
			);
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Delete failed' ), $tool_name );
	}
}
