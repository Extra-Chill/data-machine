<?php
/**
 * Delete Facebook Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class DeleteFacebook extends BaseTool {

	public function __construct() {
		$this->registerTool( 'delete_facebook', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Delete Facebook Page posts. Requires Facebook OAuth to be configured.',
			'parameters'  => array(
				'post_id' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Facebook post ID to delete.',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'delete_facebook';

		if ( empty( $parameters['post_id'] ) ) {
			return $this->buildErrorResponse( 'post_id is required', $tool_name );
		}

		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'facebook' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'Facebook auth not available',
				'prerequisite_missing',
				$tool_name,
				array( 'provider' => 'facebook' ),
				array( 'action' => 'configure_facebook' )
			);
		}

		$ability = new \DataMachineSocials\Abilities\Facebook\FacebookDeleteAbility();
		$result  = $ability->execute( array( 'post_id' => $parameters['post_id'] ) );

		if ( $result['success'] ) {
			return array( 'result' => 'Post deleted!', 'post_id' => $parameters['post_id'] );
		}

		return $this->buildErrorResponse( $result['error'] ?? 'Delete failed', $tool_name );
	}
}
