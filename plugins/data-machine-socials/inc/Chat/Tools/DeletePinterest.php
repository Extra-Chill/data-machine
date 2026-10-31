<?php
/**
 * Delete Pinterest Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class DeletePinterest extends AbstractSocialTool {

	protected string $tool_name = 'delete_pinterest';

	protected string $platform = 'pinterest';

	protected string $platform_label = 'Pinterest';

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Delete Pinterest pins. Requires Pinterest API token to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'pin_id' => array(
						'type'        => 'string',
						'description' => 'Pinterest pin ID to delete.',
					),
				),
				'required'   => array( 'pin_id' ),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'delete_pinterest';

		if ( empty( $parameters['pin_id'] ) ) {
			return $this->buildErrorResponse( 'pin_id is required', $tool_name );
		}

		$auth_error = $this->guardAuth( false );
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		$ability = wp_get_ability( 'datamachine/pinterest-delete' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/pinterest-delete ability not registered', $tool_name );
		}
		$result = $ability->execute( array( 'pin_id' => $parameters['pin_id'] ) );

		if ( ! is_wp_error( $result ) && $result['success'] ) {
			return array(
				'result' => 'Pin deleted!',
				'pin_id' => $parameters['pin_id'],
			);
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Delete failed' ), $tool_name );
	}
}
