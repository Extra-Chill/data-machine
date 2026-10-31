<?php
/**
 * Delete Facebook Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class DeleteFacebook extends AbstractSocialTool {

	protected string $tool_name = 'delete_facebook';

	protected string $platform = 'facebook';

	protected string $platform_label = 'Facebook';

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Delete Facebook Page posts. Requires Facebook OAuth to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'string',
						'description' => 'Facebook post ID to delete.',
					),
				),
				'required'   => array( 'post_id' ),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'delete_facebook';

		if ( empty( $parameters['post_id'] ) ) {
			return $this->buildErrorResponse( 'post_id is required', $tool_name );
		}

		$auth_error = $this->guardAuth( false );
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		$ability = wp_get_ability( 'datamachine/facebook-delete' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/facebook-delete ability not registered', $tool_name );
		}
		$result = $ability->execute( array( 'post_id' => $parameters['post_id'] ) );

		if ( ! is_wp_error( $result ) && $result['success'] ) {
			return array(
				'result'  => 'Post deleted!',
				'post_id' => $parameters['post_id'],
			);
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Delete failed' ), $tool_name );
	}
}
