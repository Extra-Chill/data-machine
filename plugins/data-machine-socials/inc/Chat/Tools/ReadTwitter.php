<?php
/**
 * Read Twitter Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class ReadTwitter extends BaseTool {

	public function __construct() {
		$this->registerTool( 'read_twitter', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Read tweets from Twitter/X. List recent tweets, get a specific tweet, or view mentions. Requires Twitter OAuth to be configured.',
			'parameters'  => array(
				'action'           => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Action: "list" (user timeline), "get" (single tweet), "mentions" (user mentions). Defaults to "list".',
					'enum'        => array( 'list', 'get', 'mentions' ),
				),
				'tweet_id'         => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Tweet ID. Required for "get" action.',
				),
				'limit'            => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Number of tweets to return (min 5, max 100). Defaults to 25.',
				),
				'pagination_token' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Pagination token for next page.',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'read_twitter';
		$action    = $parameters['action'] ?? 'list';

		if ( 'get' === $action && empty( $parameters['tweet_id'] ) ) {
			return $this->buildErrorResponse( 'tweet_id is required for the get action', $tool_name );
		}

		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'twitter' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'Twitter auth provider not available',
				'prerequisite_missing',
				$tool_name,
				array( 'provider' => 'twitter', 'status' => 'not_registered' ),
				array( 'action' => 'configure_twitter_auth', 'message' => 'Configure Twitter OAuth in Data Machine Settings > Auth.', 'tool_hint' => 'authenticate_handler' )
			);
		}

		if ( ! $provider->is_authenticated() ) {
			return $this->buildDiagnosticErrorResponse(
				'Twitter is not authenticated',
				'prerequisite_missing',
				$tool_name,
				array( 'provider' => 'twitter', 'status' => 'not_authenticated' ),
				array( 'action' => 'authenticate_twitter', 'message' => 'Connect Twitter via Data Machine Settings > Auth > Twitter.', 'tool_hint' => 'authenticate_handler' )
			);
		}

		$ability_instance = new \DataMachineSocials\Abilities\Twitter\TwitterReadAbility();
		$input            = array( 'action' => sanitize_text_field( $action ) );

		if ( ! empty( $parameters['tweet_id'] ) ) {
			$input['tweet_id'] = sanitize_text_field( $parameters['tweet_id'] );
		}
		if ( ! empty( $parameters['limit'] ) ) {
			$input['limit'] = absint( $parameters['limit'] );
		}
		if ( ! empty( $parameters['pagination_token'] ) ) {
			$input['pagination_token'] = sanitize_text_field( $parameters['pagination_token'] );
		}

		$result = $ability_instance->execute( $input );

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse( $this->getAbilityError( $result, 'Failed to read from Twitter' ), $tool_name );
		}

		return array(
			'success'   => true,
			'data'      => $result['data'] ?? array(),
			'tool_name' => $tool_name,
		);
	}
}
