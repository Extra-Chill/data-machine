<?php
/**
 * Delete Bluesky Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class DeleteBluesky extends AbstractSocialTool {

	protected string $tool_name = 'delete_bluesky';

	protected string $platform = 'bluesky';

	protected string $platform_label = 'Bluesky';

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Delete Bluesky posts. Requires Bluesky app password to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'post_uri' => array(
						'type'        => 'string',
						'description' => 'Bluesky post URI to delete.',
					),
				),
				'required'   => array( 'post_uri' ),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'delete_bluesky';

		if ( empty( $parameters['post_uri'] ) ) {
			return $this->buildErrorResponse( 'post_uri is required', $tool_name );
		}

		$auth_error = $this->guardAuth( false );
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		$ability = wp_get_ability( 'datamachine/bluesky-delete' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/bluesky-delete ability not registered', $tool_name );
		}
		$result = $ability->execute( array( 'post_uri' => $parameters['post_uri'] ) );

		if ( ! is_wp_error( $result ) && $result['success'] ) {
			return array(
				'result'   => 'Post deleted!',
				'post_uri' => $parameters['post_uri'],
			);
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Delete failed' ), $tool_name );
	}
}
