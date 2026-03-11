<?php
/**
 * Update Bluesky Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class UpdateBluesky extends BaseTool {

	public function __construct() {
		$this->registerTool( 'update_bluesky', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Update Bluesky posts. Delete posts or like posts. Requires Bluesky app password to be configured.',
			'parameters'  => array(
				'action'   => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Action: "delete", "like", "unlike"',
					'enum'        => array( 'delete', 'like', 'unlike' ),
				),
				'post_uri' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Bluesky post URI (at://...).',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'update_bluesky';

		if ( empty( $parameters['post_uri'] ) ) {
			return $this->buildErrorResponse( 'post_uri is required', $tool_name );
		}

		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'bluesky' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'Bluesky auth provider not available',
				'prerequisite_missing',
				$tool_name,
				array(
					'provider' => 'bluesky',
					'status'   => 'not_registered',
				),
				array(
					'action'  => 'configure_bluesky_auth',
					'message' => 'Bluesky app password needs to be configured.',
				)
			);
		}

		if ( ! $provider->is_authenticated() ) {
			return $this->buildDiagnosticErrorResponse(
				'Bluesky is not authenticated',
				'prerequisite_missing',
				$tool_name,
				array(
					'provider' => 'bluesky',
					'status'   => 'not_authenticated',
				),
				array(
					'action'  => 'authenticate_bluesky',
					'message' => 'Bluesky app password needs to be connected.',
				)
			);
		}

		$ability_instance = new \DataMachineSocials\Abilities\Bluesky\BlueskyUpdateAbility();
		$result           = $ability_instance->execute( array(
			'action'   => sanitize_text_field( $parameters['action'] ),
			'post_uri' => sanitize_text_field( $parameters['post_uri'] ),
		) );

		if ( $result['success'] ) {
			$action = $parameters['action'];
			$msg    = 'delete' === $action ? 'Post deleted!' : 'Post liked!';
			return array(
				'result'   => $msg,
				'post_uri' => $result['data']['post_uri'],
			);
		}

		return $this->buildErrorResponse( $result['error'] ?? 'Update failed', $tool_name );
	}
}
