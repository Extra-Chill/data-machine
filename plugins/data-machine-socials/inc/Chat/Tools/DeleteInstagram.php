<?php
/**
 * Delete Instagram Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class DeleteInstagram extends AbstractSocialTool {

	protected string $tool_name = 'delete_instagram';

	protected string $platform = 'instagram';

	protected string $platform_label = 'Instagram';

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Delete Instagram media. Requires Instagram OAuth to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'media_id' => array(
						'type'        => 'string',
						'description' => 'Instagram media ID to delete.',
					),
				),
				'required'   => array( 'media_id' ),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'delete_instagram';

		if ( empty( $parameters['media_id'] ) ) {
			return $this->buildErrorResponse( 'media_id is required', $tool_name );
		}

		$auth_error = $this->guardAuth( false );
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		$ability = wp_get_ability( 'datamachine/instagram-delete' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/instagram-delete ability not registered', $tool_name );
		}
		$result  = $ability->execute( array( 'media_id' => $parameters['media_id'] ) );

		if ( ! is_wp_error( $result ) && $result['success'] ) {
			return array(
				'result'   => 'Post deleted!',
				'media_id' => $parameters['media_id'],
			);
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Delete failed' ), $tool_name );
	}
}
