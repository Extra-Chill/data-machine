<?php
/**
 * Update Pinterest Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class UpdatePinterest extends BaseTool {

	public function __construct() {
		$this->registerTool( 'update_pinterest', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Update Pinterest pins. Delete pins. Requires Pinterest API token to be configured.',
			'parameters'  => array(
				'action' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Action: "delete"',
					'enum'        => array( 'delete' ),
				),
				'pin_id' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Pinterest pin ID to delete.',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'update_pinterest';

		if ( empty( $parameters['pin_id'] ) ) {
			return $this->buildErrorResponse( 'pin_id is required', $tool_name );
		}

		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'pinterest' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'Pinterest auth provider not available',
				'prerequisite_missing',
				$tool_name,
				array( 'provider' => 'pinterest', 'status' => 'not_registered' ),
				array( 'action' => 'configure_pinterest_auth', 'message' => 'Pinterest API token needs to be configured.' )
			);
		}

		$ability_instance = new \DataMachineSocials\Abilities\Pinterest\PinterestUpdateAbility();
		$result           = $ability_instance->execute( array(
			'action' => sanitize_text_field( $parameters['action'] ),
			'pin_id'  => sanitize_text_field( $parameters['pin_id'] ),
		) );

		if ( $result['success'] ) {
			return array(
				'result'  => 'Pin deleted successfully!',
				'pin_id'  => $result['data']['pin_id'],
			);
		}

		return $this->buildErrorResponse( $result['error'] ?? 'Delete failed', $tool_name );
	}
}
