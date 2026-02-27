<?php
/**
 * Delete Instagram Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class DeleteInstagram extends BaseTool {

	public function __construct() {
		$this->registerTool( 'chat', 'delete_instagram', array( $this, 'getToolDefinition' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Delete Instagram media. Requires Instagram OAuth to be configured.',
			'parameters'  => array(
				'media_id' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Instagram media ID to delete.',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'delete_instagram';

		if ( empty( $parameters['media_id'] ) ) {
			return $this->buildErrorResponse( 'media_id is required', $tool_name );
		}

		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'instagram' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'Instagram auth not available',
				'prerequisite_missing',
				$tool_name,
				array( 'provider' => 'instagram' ),
				array( 'action' => 'configure_instagram' )
			);
		}

		$ability = new \DataMachineSocials\Abilities\Instagram\InstagramDeleteAbility();
		$result  = $ability->execute( array( 'media_id' => $parameters['media_id'] ) );

		if ( $result['success'] ) {
			return array( 'result' => 'Post deleted!', 'media_id' => $parameters['media_id'] );
		}

		return $this->buildErrorResponse( $result['error'] ?? 'Delete failed', $tool_name );
	}
}
