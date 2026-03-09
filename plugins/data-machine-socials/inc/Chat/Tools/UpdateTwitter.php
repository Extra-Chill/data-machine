<?php
/**
 * Update Twitter Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class UpdateTwitter extends BaseTool {

	public function __construct() {
		$this->registerTool( 'update_twitter', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Update Twitter/X content. Delete tweets, retweet, unretweet, like, or unlike tweets. Requires Twitter OAuth to be configured.',
			'parameters'  => array(
				'action'   => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Action: "delete" (remove tweet), "retweet", "unretweet", "like", "unlike"',
					'enum'        => array( 'delete', 'retweet', 'unretweet', 'like', 'unlike' ),
				),
				'tweet_id' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Tweet ID to operate on.',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'update_twitter';
		$action    = $parameters['action'] ?? '';

		if ( empty( $parameters['tweet_id'] ) ) {
			return $this->buildErrorResponse( 'tweet_id is required', $tool_name );
		}

		// Get auth provider.
		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'twitter' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'Twitter auth provider not available',
				'prerequisite_missing',
				$tool_name,
				array( 'provider' => 'twitter', 'status' => 'not_registered' ),
				array(
					'action'    => 'configure_twitter_auth',
					'message'   => 'Twitter OAuth needs to be configured in Data Machine Settings > Auth.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		if ( ! $provider->is_authenticated() ) {
			return $this->buildDiagnosticErrorResponse(
				'Twitter is not authenticated',
				'prerequisite_missing',
				$tool_name,
				array( 'provider' => 'twitter', 'status' => 'not_authenticated' ),
				array(
					'action'    => 'authenticate_twitter',
					'message'   => 'Twitter OAuth needs to be connected. Go to Data Machine Settings > Auth > Twitter.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		// Get the ability.
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return $this->buildErrorResponse( 'WordPress Abilities API not available', $tool_name );
		}

		$ability = wp_get_ability( 'datamachine/twitter-update' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/twitter-update ability not registered', $tool_name );
		}

		$ability_instance = new \DataMachineSocials\Abilities\Twitter\TwitterUpdateAbility();
		$result           = $ability_instance->execute( array(
			'action'   => sanitize_text_field( $action ),
			'tweet_id' => sanitize_text_field( $parameters['tweet_id'] ),
		) );

		if ( $result['success'] ) {
			$message = '';
			switch ( $action ) {
				case 'delete':
					$message = 'Tweet deleted successfully!';
					break;
				case 'retweet':
					$message = 'Tweet retweeted successfully!';
					break;
				case 'unretweet':
					$message = 'Tweet unretweeted successfully!';
					break;
				case 'like':
					$message = 'Tweet liked successfully!';
					break;
				case 'unlike':
					$message = 'Tweet unliked successfully!';
					break;
			}

			return array(
				'result'    => $message,
				'tweet_id'  => $result['data']['tweet_id'] ?? $parameters['tweet_id'],
				'data'      => $result['data'],
			);
		}

		return $this->buildErrorResponse( $result['error'] ?? 'Update failed', $tool_name );
	}
}
