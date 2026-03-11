<?php
/**
 * Delete Twitter Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class DeleteTwitter extends BaseTool {

	public function __construct() {
		$this->registerTool( 'delete_twitter', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Delete tweets. Requires Twitter OAuth to be configured.',
			'parameters'  => array(
				'tweet_id' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Tweet ID to delete.',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'delete_twitter';

		if ( empty( $parameters['tweet_id'] ) ) {
			return $this->buildErrorResponse( 'tweet_id is required', $tool_name );
		}

		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'twitter' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'Twitter auth not available',
				'prerequisite_missing',
				$tool_name,
				array( 'provider' => 'twitter' ),
				array( 'action' => 'configure_twitter' )
			);
		}

		$ability = new \DataMachineSocials\Abilities\Twitter\TwitterDeleteAbility();
		$result  = $ability->execute( array( 'tweet_id' => $parameters['tweet_id'] ) );

		if ( $result['success'] ) {
			return array(
				'result'   => 'Tweet deleted!',
				'tweet_id' => $parameters['tweet_id'],
			);
		}

		return $this->buildErrorResponse( $result['error'] ?? 'Delete failed', $tool_name );
	}
}
