<?php
/**
 * Update Threads Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class UpdateThreads extends AbstractSocialTool {

	protected string $tool_name = 'update_threads';

	protected string $platform = 'threads';

	protected string $platform_label = 'Threads';

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Update Threads posts. Delete posts. Requires Threads OAuth to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'action'    => array(
						'type'        => 'string',
						'description' => 'Action: "delete"',
						'enum'        => array( 'delete' ),
					),
					'thread_id' => array(
						'type'        => 'string',
						'description' => 'Threads thread ID to delete.',
					),
				),
				'required'   => array( 'action', 'thread_id' ),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'update_threads';

		if ( empty( $parameters['thread_id'] ) ) {
			return $this->buildErrorResponse( 'thread_id is required', $tool_name );
		}

		$auth_error = $this->guardAuth();
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		$ability = wp_get_ability( 'datamachine/threads-update' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/threads-update ability not registered', $tool_name );
		}
		$ability_instance = $ability;
		$result           = $ability_instance->execute( array(
			'action'    => sanitize_text_field( $parameters['action'] ),
			'thread_id' => sanitize_text_field( $parameters['thread_id'] ),
		) );

		if ( ! is_wp_error( $result ) && $result['success'] ) {
			return array(
				'result'    => 'Thread deleted successfully!',
				'thread_id' => $result['data']['thread_id'],
			);
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Delete failed' ), $tool_name );
	}
}
