<?php
/**
 * Delete Bluesky Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class DeleteBluesky extends BaseTool {

	public function __construct() {
		$this->registerTool( 'delete_bluesky', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Delete Bluesky posts. Requires Bluesky app password to be configured.',
			'parameters'  => array(
				'post_uri' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Bluesky post URI to delete.',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'delete_bluesky';

		if ( empty( $parameters['post_uri'] ) ) {
			return $this->buildErrorResponse( 'post_uri is required', $tool_name );
		}

		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'bluesky' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'Bluesky auth not available',
				'prerequisite_missing',
				$tool_name,
				array( 'provider' => 'bluesky' ),
				array( 'action' => 'configure_bluesky' )
			);
		}

		$ability = new \DataMachineSocials\Abilities\Bluesky\BlueskyDeleteAbility();
		$result  = $ability->execute( array( 'post_uri' => $parameters['post_uri'] ) );

		if ( $result['success'] ) {
			return array(
				'result'   => 'Post deleted!',
				'post_uri' => $parameters['post_uri'],
			);
		}

		return $this->buildErrorResponse( $result['error'] ?? 'Delete failed', $tool_name );
	}
}
