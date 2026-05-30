<?php
/**
 * Read Threads Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class ReadThreads extends AbstractSocialTool {

	protected string $tool_name = 'read_threads';

	protected string $platform = 'threads';

	protected string $platform_label = 'Threads';

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Read Threads posts. List recent threads, get details for a specific thread, or get replies. Requires Threads OAuth to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'action'    => array(
						'type'        => 'string',
						'description' => 'Action: "list" (recent threads), "get" (single thread), "replies" (thread replies). Defaults to "list".',
						'enum'        => array( 'list', 'get', 'replies' ),
					),
					'thread_id' => array(
						'type'        => 'string',
						'description' => 'Threads media ID. Required for "get" and "replies" actions.',
					),
					'limit'     => array(
						'type'        => 'integer',
						'description' => 'Number of items to return (max 100). Defaults to 25.',
					),
					'after'     => array(
						'type'        => 'string',
						'description' => 'Pagination cursor for next page.',
					),
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

		$auth_error = $this->guardAuth();
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		$ability = wp_get_ability( 'datamachine/threads-read' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/threads-read ability not registered', $tool_name );
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

		if ( is_wp_error( $result ) || ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : $this->getAbilityError( $result, 'Failed to read from Threads' ), $tool_name );
		}

		return array(
			'success'   => true,
			'data'      => $result['data'] ?? array(),
			'tool_name' => $tool_name,
		);
	}
}
